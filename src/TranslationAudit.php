<?php

namespace GeneroWP\ContentTranslation;

use PLL_Language;
use PLL_Translation_Walker_Factory;
use Translation_Entry;
use WP_Error;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Has Claude compare the post content of a machine translation with the
 * original, segment by segment.
 *
 * Queued once the translation is saved and run in the background, so a slow
 * review never holds up the translation. Segments are paired by walking the
 * original and the translation with Polylang Pro's own translation walker,
 * which also makes a review of an edited translation possible. The post
 * content is reviewed, then the ACF text fields, the title, the excerpt and
 * the Yoast SEO texts ({@see pairFields()}). The translation itself is only
 * changed when a suggestion is used.
 */
final class TranslationAudit
{
    /** The whole review, on the translated post. */
    public const metaKey = '_gds_content_translation_audit';

    /**
     * Compact status for the status screen to read in one query:
     * "pending:<time queued or started>", "queued:<time>" (waiting for a free
     * place), "failed", or "done:<open errors>:<other open remarks>:<content hash>".
     */
    public const statusMetaKey = '_gds_content_translation_audit_status';

    public const optionKey = 'gds_content_translation_audit';

    public const defaultModel = 'claude-sonnet-5-5';

    public const remarkStates = ['open', 'resolved', 'dismissed'];

    /**
     * What happens once the review of a new AI translation is done: an editor
     * goes through the remarks, every suggestion is written into the
     * translation, or the suggestions are written in and the translation is
     * reviewed once more (that second review is left to an editor).
     */
    public const followups = ['manual', 'apply', 'apply_review'];

    /** Longest site-wide and per-translation instructions, in characters. */
    public const instructionsMax = 4000;

    /** Seconds after which a queued review that never finished counts as stuck. */
    public const stuckAfter = 600;

    /** One-time token that lets the background request run a queued review. */
    private const tokenMetaKey = '_gds_content_translation_audit_token';

    /**
     * Polylang Pro's stand-in for a nested block inside a text
     * (PLL_Translation_Walker_Blocks::BLOCK_PLACEHOLDER), and the same after
     * wp_kses_post(), which drops its `translate` attribute.
     */
    public const blockPlaceholder = '<pre translate="no">Polylang placeholder do not modify</pre>';

    private const blockPlaceholderKsesed = '<pre>Polylang placeholder do not modify</pre>';

    private const ajaxAction = 'gds_ct_run_audit';

    private const cronHook = 'gds_content_translation_run_audit';

    private const apiUrl = 'https://api.anthropic.com/v1/messages';

    private const apiVersion = '2026-10-21';

    /** Same lookup order as gds-assistant, so one key serves both. */
    private const keyName = 'ANTHROPIC_API_KEY';

    private const keyOption = 'connectors_ai_anthropic_api_key';

    /** Seconds Claude gets for one call. */
    private const timeout = 240;

    /** Texts per call; a longer page is reviewed in several calls. */
    private const chunkSize = 60;

    private const severities = ['error', 'warning', 'suggestion'];

    private const categories = ['meaning', 'omission', 'addition', 'terminology', 'grammar', 'fluency', 'tone', 'formatting', 'untranslated', 'other'];

    /**
     * The glossary terms found in the page under review.
     *
     * @var list<array{source: string, target: string, note?: string}>
     */
    private array $glossary = [];

    public static function init(): void
    {
        $audit = new self;
        add_action('wp_ajax_'.self::ajaxAction, [$audit, 'handleBackgroundRequest']);
        add_action('wp_ajax_nopriv_'.self::ajaxAction, [$audit, 'handleBackgroundRequest']);
        add_action(self::cronHook, [$audit, 'runFromCron']);
    }

    /**
     * @return array<string, string> Model ID => label.
     */
    public static function models(): array
    {
        return [
            'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
            'claude-opus-5-5' => 'Claude Opus 5.5',
            'claude-haiku-5-5' => 'Claude Haiku 5.5',
        ];
    }

    /**
     * Which language Claude writes its remarks in.
     *
     * @return array<string, string>
     */
    /**
     * The choices for what happens after the review of a new AI translation:
     * a short title and one line of help each, in the order they are shown.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function followupOptions(): array
    {
        return [
            'manual' => [
                'label' => __('Translate, I’ll review', 'gds-content-translation'),
                'description' => __('You go through the AI remarks.', 'gds-content-translation'),
            ],
            'apply' => [
                'label' => __('Translate and fix automatically', 'gds-content-translation'),
                'description' => __('Every AI suggestion is applied.', 'gds-content-translation'),
            ],
            'apply_review' => [
                'label' => __('Translate, fix, then check again', 'gds-content-translation'),
                'description' => __('Suggestions applied, then a second review.', 'gds-content-translation'),
            ],
        ];
    }

    /**
     * A follow-up from a request, or the site's default when it is not one.
     */
    public static function followup(mixed $value): string
    {
        return is_string($value) && in_array($value, self::followups, true) ? $value : self::settings()['followup'];
    }

    public static function remarkLanguages(): array
    {
        return [
            'target' => __('The translation’s language', 'gds-content-translation'),
            'source' => __('The original’s language', 'gds-content-translation'),
            'site' => __('The site’s admin language', 'gds-content-translation'),
        ];
    }

    /**
     * @return array{enabled: bool, model: string, language: string, notes: bool}
     */
    public static function settings(): array
    {
        $saved = get_option(self::optionKey, []);
        $saved = is_array($saved) ? $saved : [];
        $model = is_string($saved['model'] ?? null) && isset(self::models()[$saved['model']]) ? $saved['model'] : self::defaultModel;
        $language = is_string($saved['language'] ?? null) && isset(self::remarkLanguages()[$saved['language']]) ? $saved['language'] : 'target';
        $followup = is_string($saved['followup'] ?? null) && in_array($saved['followup'], self::followups, true) ? $saved['followup'] : 'manual';

        return [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'model' => $model,
            'language' => $language,
            'notes' => (bool) ($saved['notes'] ?? true),
            'followup' => $followup,
            // Sent with every review: the site's terms, tone and so on.
            'instructions' => is_string($saved['instructions'] ?? null) ? $saved['instructions'] : '',
        ];
    }

    /**
     * Instructions as typed by an editor: plain text, trimmed, not too long.
     */
    public static function cleanInstructions(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim(sanitize_textarea_field($value)), 0, self::instructionsMax) : '';
    }

    /**
     * Where the API key was found ("environment", "constant", "option"), or ''.
     */
    public static function keySource(): string
    {
        return self::resolveKey()[1];
    }

    public static function isConfigured(): bool
    {
        return self::keySource() !== '';
    }

    public static function isEnabled(): bool
    {
        return (bool) apply_filters(
            'gds_content_translation_audit_enabled',
            self::isConfigured() && self::settings()['enabled']
        );
    }

    /**
     * Queue a review of a translation and start it in the background.
     *
     * A non-blocking request to admin-ajax.php runs it at once; a WP-Cron
     * event two minutes later is the fallback for hosts where that request
     * cannot reach the site. Whichever comes first runs it, the other finds
     * nothing queued.
     *
     * At most a few reviews run at once (a bulk "Run AI review" on a hundred
     * pages would otherwise start a hundred PHP processes): beyond that a
     * review waits, and each finished review starts the next waiting one.
     */
    /**
     * @param  string|null  $instructions  For this translation, on top of the site's; null keeps the previous review's.
     */
    public static function queue(int $postId, string $followup = 'manual', ?string $instructions = null): void
    {
        $sourceId = self::sourceIdOf($postId);

        if ($sourceId === 0) {
            return;
        }

        $token = wp_generate_password(32, false);
        $previous = self::get($postId);
        $waiting = self::runningCount([$postId]) >= self::maxParallel();

        self::store($postId, [
            'status' => $waiting ? 'queued' : 'pending',
            'queuedAt' => time(),
            // Which run the editor notes belong to ({@see AuditNotes}): one left
            // by another run must not close this one's remarks.
            'run' => wp_generate_password(12, false),
            'sourceId' => $sourceId,
            'requestedBy' => get_current_user_id() ?: (int) ($previous['requestedBy'] ?? 0),
            'followup' => in_array($followup, self::followups, true) ? $followup : 'manual',
            // "Run the review again" and the second review of a follow-up keep the first one's.
            'pageInstructions' => $instructions !== null ? self::cleanInstructions($instructions) : (string) ($previous['pageInstructions'] ?? ($previous['instructions']['page'] ?? '')),
            // A review after suggestions were applied automatically says so.
            'appliedBefore' => ($previous['followup'] ?? '') === 'apply_review' ? (int) ($previous['autoApplied'] ?? 0) : 0,
            // The editor notes of the review this one replaces, removed when it runs.
            'previousNotes' => array_merge(
                array_values(array_filter(array_map('intval', array_column((array) ($previous['remarks'] ?? []), 'noteId')))),
                (array) ($previous['previousNotes'] ?? [])
            ),
        ]);
        update_post_meta($postId, self::tokenMetaKey, $token);

        wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::cronHook, [$postId]);

        if (! $waiting) {
            self::start($postId, $token);
        }
    }

    /**
     * How many reviews may run at once.
     */
    private static function maxParallel(): int
    {
        return max(1, (int) apply_filters('gds_content_translation_audit_parallel', 3));
    }

    /**
     * Send the background request that runs a queued review.
     */
    private static function start(int $postId, string $token): void
    {
        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            // Same as WordPress' own cron spawn: the site may not trust its own certificate.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => [
                'action' => self::ajaxAction,
                // Without it Polylang takes an admin-ajax request for a front-end
                // one and loads its front-end filters with no current language,
                // so get_locale() fails while a translation copies its media.
                'pll_ajax_backend' => 1,
                'post' => $postId,
                'token' => $token,
            ],
        ]);
    }

    /**
     * Reviews started (or running) and not yet stuck.
     *
     * @param  list<int>  $except
     */
    private static function runningCount(array $except = []): int
    {
        global $wpdb;

        $count = 0;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            self::statusMetaKey,
            $wpdb->esc_like('pending:').'%'
        ));

        foreach ($rows as $row) {
            if (! in_array((int) $row->post_id, $except, true) && self::parseStatus((string) $row->meta_value)['status'] === 'pending') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Start the review that has waited longest, if there is room for it.
     */
    private static function startNext(): void
    {
        global $wpdb;

        if (self::runningCount() >= self::maxParallel()) {
            return;
        }

        // Unix times have ten digits until 2286, so text order is time order.
        $postId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_value ASC LIMIT 1",
            self::statusMetaKey,
            $wpdb->esc_like('queued:').'%'
        ));
        $review = $postId > 0 ? self::get($postId) : null;
        $token = $postId > 0 ? (string) get_post_meta($postId, self::tokenMetaKey, true) : '';

        if (! $review || ($review['status'] ?? '') !== 'queued' || $token === '') {
            return;
        }

        self::store($postId, array_merge($review, ['status' => 'pending', 'queuedAt' => time()]));
        self::start($postId, $token);
    }

    /**
     * The background request queue() sends. Needs no login: the one-time
     * token stored with the queued review is the authorisation.
     */
    public function handleBackgroundRequest(): void
    {
        $postId = Request::id($_POST, 'post');
        $token = isset($_POST['token']) && is_string($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $stored = $postId > 0 ? (string) get_post_meta($postId, self::tokenMetaKey, true) : '';

        if ($token === '' || $stored === '' || ! hash_equals($stored, $token)) {
            wp_die('', '', 403);
        }

        delete_post_meta($postId, self::tokenMetaKey);
        ignore_user_abort(true);

        $this->runQueued($postId);

        wp_die();
    }

    /**
     * The WP-Cron fallback. A review still waiting for a free place waits on
     * while the places are taken, and is looked at again two minutes later.
     */
    public function runFromCron(int $postId): void
    {
        $queued = self::get($postId);

        if (($queued['status'] ?? '') === 'queued' && self::runningCount() >= self::maxParallel()) {
            wp_schedule_single_event(time() + 2 * MINUTE_IN_SECONDS, self::cronHook, [$postId]);

            return;
        }

        $this->runQueued($postId);
    }

    /**
     * Run the review queued for a translation, if it is still queued.
     */
    public function runQueued(int $postId): void
    {
        $queued = self::get($postId);

        if (! in_array($queued['status'] ?? '', ['pending', 'queued'], true)) {
            return;
        }

        // Claim it, so the cron fallback does not run it a second time.
        self::store($postId, array_merge($queued, ['status' => 'running', 'queuedAt' => time()]));
        delete_post_meta($postId, self::tokenMetaKey);

        AuditNotes::remove($postId, array_map('intval', (array) ($queued['previousNotes'] ?? [])));
        $review = $this->run($postId, (string) ($queued['pageInstructions'] ?? ''));
        $review['requestedBy'] = (int) ($queued['requestedBy'] ?? 0);
        $review['appliedBefore'] = (int) ($queued['appliedBefore'] ?? 0);
        $review['run'] = (string) ($queued['run'] ?? '');

        if ($review['status'] === 'done' && self::settings()['notes']) {
            $review = AuditNotes::add($postId, $review);
        }

        $followup = (string) ($queued['followup'] ?? 'manual');
        $applies = $review['status'] === 'done' && in_array($followup, ['apply', 'apply_review'], true);

        // Still running to the status screen while its suggestions are
        // applied, so it does not show the remarks they fix and stop asking.
        if ($applies) {
            $review['applying'] = time();
        }

        self::store($postId, $review);

        if ($review['status'] === 'done') {
            GlossaryLearning::fromReview($postId, $review);
        }

        if ($applies) {
            // As the editor who asked: in the background there is no user,
            // and WordPress would filter the whole translation's HTML on save
            // (an embed's iframe, an inline SVG).
            $previousUser = get_current_user_id();
            $requestedBy = (int) ($review['requestedBy'] ?? 0);

            if ($requestedBy > 0 && get_userdata($requestedBy)) {
                wp_set_current_user($requestedBy);
            }

            try {
                self::applyAll($postId, $followup);
            } finally {
                wp_set_current_user($previousUser);
            }

            if ($followup === 'apply_review' && self::countApplied($postId) > 0) {
                // Checks the text as it is now; what it finds is left to an editor.
                self::queue($postId, 'manual');
            }
        }

        self::startNext();
    }

    /**
     * Write every open remark's suggestion into the translation, as "Use
     * suggestion" does one at a time. A remark whose text changed since the
     * review (two remarks on one text, say) or that has no suggestion stays
     * open. The review keeps the counts, so the next review can say what was
     * applied before it.
     */
    public static function applyAll(int $postId, string $followup = 'apply'): int
    {
        $review = self::get($postId);
        $applied = 0;
        $skipped = 0;

        // One write per suggestion; a revision for each would bury the
        // translation's history under a few dozen near-identical ones.
        $revisions = has_action('post_updated', 'wp_save_post_revision');

        if ($revisions !== false) {
            remove_action('post_updated', 'wp_save_post_revision', $revisions);
        }

        foreach ((array) ($review['remarks'] ?? []) as $remark) {
            $suggestion = trim((string) ($remark['suggestion'] ?? ''));

            if (($remark['state'] ?? 'open') !== 'open' || $suggestion === '') {
                continue;
            }

            $result = self::applyText($postId, (int) $remark['id'], (string) $remark['suggestion'], 'auto');
            $result === true ? $applied++ : $skipped++;
        }

        if ($revisions !== false) {
            add_action('post_updated', 'wp_save_post_revision', $revisions);
        }

        $review = self::get($postId);

        if ($review) {
            $review['followup'] = $followup;
            $review['autoApplied'] = $applied;
            $review['autoSkipped'] = $skipped;
            // A second review follows at once, and keeps it running.
            if ($followup !== 'apply_review' || $applied === 0) {
                unset($review['applying']);
            }

            self::store($postId, $review);
        }

        return $applied;
    }

    private static function countApplied(int $postId): int
    {
        return (int) (self::get($postId)['autoApplied'] ?? 0);
    }

    /**
     * Review a translation's current post content against its original.
     * Never throws: a failure comes back as a "failed" review with the reason.
     *
     * @return array<string, mixed>
     */
    public function run(int $postId, string $pageInstructions = ''): array
    {
        $translation = get_post($postId);
        $source = get_post(self::sourceIdOf($postId));
        $model = (string) apply_filters('gds_content_translation_audit_model', self::settings()['model']);

        $review = [
            'status' => 'failed',
            'translationId' => $postId,
            'model' => $model,
            'createdAt' => time(),
            'sourceId' => $source ? (int) $source->ID : 0,
            'sourceLanguage' => '',
            'targetLanguage' => '',
            'contentHash' => $translation ? self::contentHash($postId) : '',
            'summary' => '',
            'segments' => [],
            'unaligned' => 0,
            'remarks' => [],
            'error' => '',
            'usage' => [],
            // What Claude was told besides the texts, kept to show on the review page.
            'instructions' => array_filter([
                'site' => self::cleanInstructions(self::settings()['instructions']),
                'page' => self::cleanInstructions($pageInstructions),
            ]),
        ];

        $sourceLanguage = $source ? PLL()->model->post->get_language($source->ID) : false;
        $targetLanguage = $translation ? PLL()->model->post->get_language($translation->ID) : false;

        if (! $translation || ! $source || ! $sourceLanguage || ! $targetLanguage) {
            return array_merge($review, ['error' => __('The translation or its original could not be found.', 'gds-content-translation')]);
        }

        $paired = self::currentSegments($source, $translation);
        $review = array_merge($review, [
            'sourceLanguage' => $sourceLanguage->slug,
            'targetLanguage' => $targetLanguage->slug,
            'segments' => $paired['segments'],
            'unaligned' => $paired['unaligned'],
        ]);

        $this->glossary = Glossary::termsIn(
            implode("\n", array_column($paired['segments'], 'source')),
            Glossary::code($sourceLanguage),
            Glossary::code($targetLanguage)
        );
        $review['glossary'] = count($this->glossary);

        if ($paired['segments'] === []) {
            return array_merge($review, ['status' => 'done', 'summary' => __('Nothing to review: the translation has no text of the original in its content, its ACF text fields, its title, excerpt or SEO texts (a page whose content comes from its template, say).', 'gds-content-translation')]);
        }

        return $this->review($review, $source, $sourceLanguage, $targetLanguage);
    }

    /**
     * Pair the translatable strings of the original and the translation.
     *
     * Top-level blocks are compared one to one, and the strings within each
     * pair of blocks in order, so an edit inside one block does not shift the
     * pairs of every block after it. Strings in blocks that no longer line up
     * (one added, removed or replaced) are counted as unaligned, not reviewed.
     *
     * Each segment keeps where its text is in the translation: the index of
     * its top-level block in parse_blocks() (null for classic content) and its
     * position among that block's texts.
     *
     * @return array{segments: list<array{id: int, source: string, translation: string, block: int|null, index: int}>, unaligned: int}
     */
    public static function pairSegments(string $sourceContent, string $translationContent): array
    {
        $pairs = [];
        $unaligned = 0;

        if (has_blocks($sourceContent) && has_blocks($translationContent)) {
            $sourceBlocks = array_values(self::topLevelBlocks($sourceContent));
            $translationBlocks = self::topLevelBlocks($translationContent);
            $translationKeys = array_keys($translationBlocks);
            $translationBlocks = array_values($translationBlocks);

            foreach ($sourceBlocks as $index => $sourceBlock) {
                $sourceStrings = self::strings(serialize_block($sourceBlock));
                $translationBlock = $translationBlocks[$index] ?? null;

                if (! $translationBlock || $translationBlock['blockName'] !== $sourceBlock['blockName']) {
                    $unaligned += count($sourceStrings);

                    continue;
                }

                $translationStrings = self::strings(serialize_block($translationBlock));
                $unaligned += abs(count($sourceStrings) - count($translationStrings));

                foreach (array_slice($sourceStrings, 0, count($translationStrings)) as $i => $string) {
                    $pairs[] = [$string, $translationStrings[$i], $translationKeys[$index], $i];
                }
            }
        } else {
            $sourceStrings = self::strings($sourceContent);
            $translationStrings = self::strings($translationContent);
            $unaligned = abs(count($sourceStrings) - count($translationStrings));

            foreach (array_slice($sourceStrings, 0, count($translationStrings)) as $i => $string) {
                $pairs[] = [$string, $translationStrings[$i], null, $i];
            }
        }

        $segments = [];

        foreach ($pairs as [$source, $translation, $block, $index]) {
            $segments[] = ['id' => count($segments) + 1, 'source' => $source, 'translation' => $translation, 'block' => $block, 'index' => $index];
        }

        return ['segments' => $segments, 'unaligned' => $unaligned];
    }

    /**
     * Every text of a translation paired with the original's: the post
     * content's, then the ACF text fields', then the title, the excerpt and
     * the SEO texts. Numbered in that order, so the numbers of a review made
     * before the last ones were reviewed still match.
     *
     * @return array{segments: list<array<string, mixed>>, unaligned: int}
     */
    public static function currentSegments(WP_Post $source, WP_Post $translation): array
    {
        $paired = self::pairSegments($source->post_content, $translation->post_content);

        foreach (array_merge(self::pairAcf((int) $source->ID, (int) $translation->ID), self::pairFields($source, $translation)) as $field) {
            $paired['segments'][] = ['id' => count($paired['segments']) + 1] + $field;
        }

        return $paired;
    }

    /**
     * The post's own text fields a review checks besides its content, with
     * the name an editor knows them by: the title and the excerpt (columns
     * of the post), and Yoast SEO's titles and descriptions (its metas,
     * which Polylang copies or translates).
     *
     * @return array<string, string> Column or meta key => label.
     */
    public static function fieldLabels(): array
    {
        return [
            'post_title' => __('Title', 'gds-content-translation'),
            'post_excerpt' => __('Excerpt', 'gds-content-translation'),
            '_yoast_wpseo_title' => __('SEO title', 'gds-content-translation'),
            '_yoast_wpseo_metadesc' => __('Meta description', 'gds-content-translation'),
            '_yoast_wpseo_opengraph-title' => __('Social title', 'gds-content-translation'),
            '_yoast_wpseo_opengraph-description' => __('Social description', 'gds-content-translation'),
            '_yoast_wpseo_twitter-title' => __('X title', 'gds-content-translation'),
            '_yoast_wpseo_twitter-description' => __('X description', 'gds-content-translation'),
        ];
    }

    /**
     * The title, the excerpt and the SEO texts the translation has, unless
     * they are empty or still the original's (a name, or a copy nobody
     * translated): those leave nothing to review.
     *
     * @return list<array{source: string, translation: string, block: null, index: int, field: string, label: string}>
     */
    public static function pairFields(WP_Post $source, WP_Post $translation): array
    {
        $fields = [];

        foreach (self::fieldLabels() as $field => $label) {
            $sourceText = self::fieldValue($source, $field);
            $translationText = self::fieldValue($translation, $field);

            if (trim(wp_strip_all_tags($sourceText)) === '' || trim($translationText) === '' || trim($sourceText) === trim($translationText)) {
                continue;
            }

            $fields[] = [
                'source' => $sourceText,
                'translation' => $translationText,
                'block' => null,
                'index' => -1,
                'field' => $field,
                'label' => $label,
            ];
        }

        return $fields;
    }

    private static function fieldValue(WP_Post $post, string $field): string
    {
        return str_starts_with($field, 'post_') ? (string) $post->{$field} : (string) get_post_meta($post->ID, $field, true);
    }

    /**
     * Which field a text is in, for the review page; '' for the content.
     *
     * @param  array<string, mixed>  $segment
     */
    public static function segmentLabel(array $segment): string
    {
        // In the admin's language now, not the one of the review's time.
        return self::fieldLabels()[$segment['field'] ?? ''] ?? (string) ($segment['label'] ?? '');
    }

    /**
     * The ACF text fields (text, textarea, WYSIWYG) of the original that have
     * a value in the translation, by their meta key, which is the same on
     * both posts (repeater rows included: items_0_text).
     *
     * @return list<array{source: string, translation: string, block: null, index: int, acf: string, label: string}>
     */
    public static function pairAcf(int $sourceId, int $translationId): array
    {
        if (! function_exists('acf_get_field')) {
            return [];
        }

        $fields = [];

        foreach (get_post_meta($sourceId) as $key => $values) {
            $key = (string) $key;
            $fieldKey = $key !== '' && $key[0] !== '_' ? get_post_meta($sourceId, '_'.$key, true) : '';
            $field = is_string($fieldKey) && str_starts_with($fieldKey, 'field_') ? acf_get_field($fieldKey) : null;

            if (! is_array($field) || ! in_array($field['type'] ?? '', ['text', 'textarea', 'wysiwyg'], true)) {
                continue;
            }

            $source = (string) ($values[0] ?? '');
            $translation = (string) get_post_meta($translationId, $key, true);

            if (trim(wp_strip_all_tags($source)) === '' || trim($translation) === '') {
                continue;
            }

            $fields[] = [
                'source' => $source,
                'translation' => $translation,
                'block' => null,
                'index' => -1,
                'acf' => $key,
                'label' => (string) ($field['label'] ?? $key),
            ];
        }

        return $fields;
    }

    /**
     * What a review saw, to tell later whether the translation changed:
     * the post content, the ACF texts, and the title, excerpt and SEO texts.
     *
     * @param  bool  $fields  False for the hash of a review made before the
     *                        title, excerpt and SEO texts were reviewed.
     */
    public static function contentHash(int $postId, bool $fields = true): string
    {
        $post = get_post($postId);

        if (! $post) {
            return '';
        }

        $sourceId = self::sourceIdOf($postId);
        $source = $sourceId ? get_post($sourceId) : null;
        $texts = $sourceId ? array_column(self::pairAcf($sourceId, $postId), 'translation', 'acf') : [];

        if ($fields && $source) {
            $texts += array_column(self::pairFields($source, $post), 'translation', 'field');
        }

        return md5($post->post_content.($texts ? wp_json_encode($texts) : ''));
    }

    /**
     * Whether the translation changed since a review that saw it with this
     * hash, as the review page and the status screen say. A review stored
     * before the title, excerpt and SEO texts were reviewed has the older
     * hash, which is checked too.
     */
    public static function editedSince(int $postId, string $hash): bool
    {
        return $hash !== '' && ! in_array($hash, [self::contentHash($postId), self::contentHash($postId, false)], true);
    }

    /**
     * @param  array<string, mixed>  $review
     */
    public static function store(int $postId, array $review): void
    {
        update_post_meta($postId, self::metaKey, wp_slash($review));
        update_post_meta($postId, self::statusMetaKey, self::compactStatus($review));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $postId): ?array
    {
        $review = get_post_meta($postId, self::metaKey, true);

        return is_array($review) ? $review : null;
    }

    /**
     * Mark a remark open, resolved or dismissed, and its editor note with it.
     *
     * @param  bool  $syncNote  False when the change comes from the note itself.
     */
    public static function setRemarkState(int $postId, int $remarkId, string $state, bool $syncNote = true, string $resolution = ''): bool
    {
        return self::locked($postId, static fn (): bool => self::changeRemarkState($postId, $remarkId, $state, $syncNote, $resolution));
    }

    /**
     * Changes to one translation's remarks, one at a time: each reads the
     * review (and the translation) and writes it back, so two at once would
     * undo each other, or the first's text.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private static function locked(int $postId, callable $change): mixed
    {
        global $wpdb;

        $name = 'gds_ct_review_'.$postId;
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 20)', $name)) === 1;

        try {
            // What another request wrote while this one waited.
            clean_post_cache($postId);
            wp_cache_delete($postId, 'post_meta');

            return $change();
        } finally {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    private static function changeRemarkState(int $postId, int $remarkId, string $state, bool $syncNote, string $resolution): bool
    {
        $review = self::get($postId);

        if (! $review || ! in_array($state, self::remarkStates, true)) {
            return false;
        }

        foreach ((array) ($review['remarks'] ?? []) as $index => $remark) {
            if ((int) $remark['id'] === $remarkId) {
                $review['remarks'][$index]['state'] = $state;
                $review['remarks'][$index]['resolution'] = $state === 'open' ? '' : $resolution;
                self::store($postId, $review);

                if ($syncNote) {
                    AuditNotes::setState((int) ($remark['noteId'] ?? 0), $state);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Write new text for a remark's segment into the translation and mark the
     * remark fixed.
     *
     * Refused when the text in the translation is no longer what the review
     * saw, or when someone has the translation open in the editor: their next
     * save would overwrite the change.
     */
    public static function applyText(int $postId, int $remarkId, string $text, string $resolution): true|WP_Error
    {
        return self::locked($postId, static fn (): true|WP_Error => self::writeText($postId, $remarkId, $text, $resolution));
    }

    private static function writeText(int $postId, int $remarkId, string $text, string $resolution): true|WP_Error
    {
        $review = self::get($postId);
        $post = get_post($postId);
        $remark = $review ? (array_column((array) ($review['remarks'] ?? []), null, 'id')[$remarkId] ?? null) : null;
        $segmentIndex = $remark ? array_search($remark['segment'], array_column((array) ($review['segments'] ?? []), 'id'), true) : false;

        if (! $post || ! $remark || $segmentIndex === false || trim($text) === '') {
            return new WP_Error('gds_ct_audit_apply', __('This remark could not be found.', 'gds-content-translation'));
        }

        // Not loaded in cron, where an automatic apply can run.
        if (! function_exists('wp_check_post_lock')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        // An automatic apply runs as the editor who asked, who may have the
        // translation open: their lock counts too.
        $lock = explode(':', (string) get_post_meta($postId, '_edit_lock', true));
        $openByAnyone = count($lock) === 2 && time() - (int) $lock[0] < (int) apply_filters('wp_check_post_lock_window', 150);

        if ($resolution === 'auto' ? $openByAnyone : wp_check_post_lock($postId)) {
            return new WP_Error('gds_ct_audit_locked', __('Someone has this translation open in the editor. Make the change there, or try again when it is closed.', 'gds-content-translation'));
        }

        $segment = $review['segments'][$segmentIndex];

        // A suggestion is written for the text as reviewed: once another
        // remark on the same text was used, this one would undo that.
        $changedByOther = isset($segment['reviewed']) && $segment['translation'] !== $segment['reviewed'] && ($remark['appliedText'] ?? null) !== $segment['translation'];

        if ($changedByOther && $resolution !== 'edited') {
            return new WP_Error('gds_ct_audit_other_remark', __('Another remark on this text was used, and this suggestion was written for the text before it. Edit the text to combine them.', 'gds-content-translation'));
        }

        // wp_kses_post() drops the `translate` attribute of a nested block's placeholder,
        // and a text without all of them would delete those blocks.
        $text = str_replace(self::blockPlaceholderKsesed, self::blockPlaceholder, $text);

        if (substr_count($text, self::blockPlaceholder) !== substr_count((string) $segment['translation'], self::blockPlaceholder)) {
            return new WP_Error('gds_ct_audit_nested', __('Keep every “Polylang placeholder do not modify” line in the text: each stands for a nested block.', 'gds-content-translation'));
        }

        // A review stored before texts kept their place: find it the way the
        // review page pairs the texts now, if it still says the same.
        if (empty($segment['acf']) && empty($segment['field']) && ! array_key_exists('index', $segment)) {
            $source = get_post((int) ($review['sourceId'] ?? 0));
            $current = $source ? (array_column(self::currentSegments($source, $post)['segments'], null, 'id')[$segment['id']] ?? null) : null;

            if ($current && $current['translation'] === $segment['translation']) {
                $segment['block'] = $current['block'] ?? null;
                $segment['index'] = $current['index'] ?? -1;
            }
        }

        $changed = new WP_Error('gds_ct_audit_changed', __('This text has changed in the translation since the review. Make the change in the editor.', 'gds-content-translation'));

        // Whether the translation is still as the review saw it, apart from
        // what was used here: only then is the change below not an edit.
        // Someone's save meanwhile keeps the old hash, so it still shows.
        $unedited = ! self::editedSince($postId, (string) ($review['contentHash'] ?? ''));

        if (! empty($segment['acf'])) {
            // An ACF field: its value is the whole text.
            if ((string) get_post_meta($postId, $segment['acf'], true) !== $segment['translation']) {
                return $changed;
            }

            update_post_meta($postId, $segment['acf'], wp_slash($text));
        } elseif (! empty($segment['field'])) {
            // The title, the excerpt or an SEO text: its value is the whole text.
            $field = (string) $segment['field'];

            if (! isset(self::fieldLabels()[$field]) || self::fieldValue($post, $field) !== $segment['translation']) {
                return $changed;
            }

            if (str_starts_with($field, 'post_')) {
                wp_update_post(['ID' => $postId, $field => wp_slash($text)]);
            } else {
                update_post_meta($postId, $field, wp_slash($text));
            }
        } else {
            $content = self::replaceString($post->post_content, $segment['block'] ?? null, (int) ($segment['index'] ?? -1), $segment['translation'], $text);

            if ($content === null) {
                return $changed;
            }

            wp_update_post(['ID' => $postId, 'post_content' => wp_slash($content)]);
        }

        $review['segments'][$segmentIndex]['reviewed'] ??= $segment['translation'];
        $review['segments'][$segmentIndex]['translation'] = $text;

        if ($unedited) {
            $review['contentHash'] = self::contentHash($postId);
        }

        foreach ($review['remarks'] as $index => $candidate) {
            if ((int) $candidate['id'] === $remarkId) {
                $review['remarks'][$index]['state'] = 'resolved';
                $review['remarks'][$index]['resolution'] = $resolution;
                $review['remarks'][$index]['appliedText'] = $text;
            }
        }

        self::store($postId, $review);
        AuditNotes::setState((int) ($remark['noteId'] ?? 0), 'resolved');

        // An editor used the suggestion, and with it the remark's term: the
        // glossary's suggestion of the term counts one more.
        if ($resolution === 'suggestion' && ! empty($remark['term'])) {
            Glossary::confirm(Glossary::code(self::localeOf((string) $review['sourceLanguage'])), Glossary::code(self::localeOf((string) $review['targetLanguage'])), $remark['term']['source'], $remark['term']['target']);
        }

        return true;
    }

    /**
     * Replace one text, found by its place, if it is still what was expected.
     *
     * @return string|null The new content, or null when the text is not there.
     */
    public static function replaceString(string $content, ?int $block, int $index, string $expected, string $text): ?string
    {
        if ($block === null) {
            return self::replaceNth($content, $index, $expected, $text);
        }

        $blocks = parse_blocks($content);

        if (! isset($blocks[$block])) {
            return null;
        }

        $replaced = self::replaceNth(serialize_block($blocks[$block]), $index, $expected, $text);

        if ($replaced === null) {
            return null;
        }

        $blocks[$block] = parse_blocks($replaced)[0];

        return serialize_blocks($blocks);
    }

    private static function replaceNth(string $content, int $index, string $expected, string $text): ?string
    {
        $position = -1;
        $found = false;

        $result = PLL_Translation_Walker_Factory::create_from($content)->walk(static function (Translation_Entry $entry) use (&$position, &$found, $index, $expected, $text): Translation_Entry {
            if (trim((string) $entry->singular) === '') {
                return $entry;
            }

            $position++;

            if ($position === $index && (string) $entry->singular === $expected) {
                $found = true;
                $entry->translations = [$text];
            }

            return $entry;
        });

        return $found ? $result : null;
    }

    /**
     * Review status per translation, for the status screen.
     *
     * @param  list<int>  $postIds
     * @return array<int, array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}>
     */
    public static function statuses(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($postIds), '%d'));
        $sql = "
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s
              AND post_id IN ({$placeholders})
        ";

        $statuses = [];

        foreach ($wpdb->get_results($wpdb->prepare($sql, self::statusMetaKey, ...$postIds)) as $row) {
            $statuses[(int) $row->post_id] = self::parseStatus((string) $row->meta_value);
        }

        return $statuses;
    }

    /**
     * @return array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}
     */
    public static function parseStatus(string $value): array
    {
        $parts = explode(':', $value);
        $status = ['status' => 'failed', 'errors' => 0, 'other' => 0, 'contentHash' => '', 'queuedAt' => 0];

        if ($parts[0] === 'done') {
            return array_merge($status, [
                'status' => 'done',
                'errors' => (int) ($parts[1] ?? 0),
                'other' => (int) ($parts[2] ?? 0),
                'contentHash' => (string) ($parts[3] ?? ''),
            ]);
        }

        if ($parts[0] === 'pending' || $parts[0] === 'queued') {
            $queuedAt = (int) ($parts[1] ?? 0);
            // One waiting for a free place in line is not stuck that soon.
            $stuckAfter = $parts[0] === 'queued' ? self::stuckAfter * 6 : self::stuckAfter;

            return array_merge($status, [
                'status' => time() - $queuedAt > $stuckAfter ? 'stuck' : 'pending',
                'queuedAt' => $queuedAt,
            ]);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private static function compactStatus(array $review): string
    {
        if ($review['status'] === 'queued') {
            return 'queued:'.(int) $review['queuedAt'];
        }

        if ($review['status'] === 'pending' || $review['status'] === 'running') {
            return 'pending:'.(int) $review['queuedAt'];
        }

        if ($review['status'] !== 'done') {
            return 'failed';
        }

        if (! empty($review['applying'])) {
            return 'pending:'.(int) $review['applying'];
        }

        $errors = 0;
        $other = 0;

        foreach ($review['remarks'] as $remark) {
            if (($remark['state'] ?? 'open') !== 'open') {
                continue;
            }

            $remark['severity'] === 'error' ? $errors++ : $other++;
        }

        return sprintf('done:%d:%d:%s', $errors, $other, $review['contentHash'] ?? '');
    }

    private static function sourceIdOf(int $postId): int
    {
        $default = pll_default_language('slug');
        $sourceId = $default ? (int) pll_get_post($postId, $default) : 0;

        return $sourceId !== $postId ? $sourceId : 0;
    }

    /**
     * Top-level blocks keyed by their index in parse_blocks().
     *
     * @return array<int, array<string, mixed>>
     */
    private static function topLevelBlocks(string $content): array
    {
        // Whitespace between blocks parses as nameless blocks; they hold no text.
        return array_filter(
            parse_blocks($content),
            static fn (array $block): bool => $block['blockName'] !== null || trim((string) $block['innerHTML']) !== ''
        );
    }

    /**
     * The translatable strings of some content, in document order, repeats included.
     *
     * @return list<string>
     */
    public static function strings(string $content): array
    {
        $strings = [];

        PLL_Translation_Walker_Factory::create_from($content)->walk(static function (Translation_Entry $entry) use (&$strings): Translation_Entry {
            if (trim((string) $entry->singular) !== '') {
                $strings[] = (string) $entry->singular;
            }

            return $entry;
        });

        return $strings;
    }

    /**
     * Review the segments, in several calls for a long page.
     *
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private function review(array $review, WP_Post $source, PLL_Language $sourceLanguage, PLL_Language $targetLanguage): array
    {
        // Even parts: 61 texts go as 31 and 30, not as 60 and a lone 1.
        $parts = (int) ceil(count($review['segments']) / self::chunkSize);
        $chunks = array_chunk($review['segments'], (int) ceil(count($review['segments']) / max(1, $parts)));
        $remarks = [];
        $summaries = [];
        $usage = ['input' => 0, 'output' => 0];

        foreach ($chunks as $number => $chunk) {
            if (count($chunks) > 1 && $review['status'] !== 'done') {
                // Progress for the review page while a long page is reviewed.
                self::store($review['translationId'], [
                    'status' => 'running',
                    'queuedAt' => time(),
                    'sourceId' => $review['sourceId'],
                    'progress' => [$number + 1, count($chunks)],
                ]);
            }

            $result = $this->reviewChunk($review['model'], $source, $sourceLanguage, $targetLanguage, $chunk, $number + 1, count($chunks), $review['instructions']);

            if ($result instanceof WP_Error) {
                return array_merge($review, ['usage' => $usage, 'error' => $result->get_error_message()]);
            }

            $usage['input'] += $result['usage']['input'];
            $usage['output'] += $result['usage']['output'];
            $summaries[] = $result['summary'];
            // Only about the texts this part sent: a remark on another part's
            // text was written without seeing it.
            $sent = array_map('intval', array_column($chunk, 'id'));
            $remarks = array_merge($remarks, array_filter((array) $result['remarks'], static fn ($remark): bool => is_array($remark) && in_array((int) ($remark['segment'] ?? 0), $sent, true)));
        }

        return array_merge($review, [
            'status' => 'done',
            'usage' => $usage,
            'summary' => implode(' ', array_filter($summaries)),
            'remarks' => $this->normalizeRemarks($remarks, $review['segments']),
        ]);
    }

    /**
     * One call to Claude for some of the segments.
     *
     * @param  list<array<string, mixed>>  $segments
     * @return array{summary: string, remarks: array<mixed>, usage: array{input: int, output: int}}|WP_Error
     */
    /**
     * @param  array{site?: string, page?: string}  $instructions
     */
    private function reviewChunk(string $model, WP_Post $source, PLL_Language $sourceLanguage, PLL_Language $targetLanguage, array $segments, int $part, int $parts, array $instructions = []): array|WP_Error
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(self::timeout + 60);
        }

        // On a refusal, the API retries on the model's default fallback. Haiku has none.
        $fallback = ! str_starts_with($model, 'claude-haiku');
        $message = $this->request(array_filter([
            'model' => $model,
            'max_tokens' => 16000,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $this->userPrompt($source, $sourceLanguage, $targetLanguage, $segments, $part, $parts, $instructions)],
            ],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'fallbacks' => $fallback ? 'default' : null,
        ]), $fallback ? ['server-side-fallback-2026-07-01'] : []);

        if ($message instanceof WP_Error) {
            return $message;
        }

        $stopReason = (string) ($message['stop_reason'] ?? '');

        if ($stopReason !== 'end_turn') {
            return new WP_Error('gds_ct_audit_stopped', sprintf('Claude stopped before finishing the review (%s).', $stopReason));
        }

        $text = '';

        foreach ((array) ($message['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $result = json_decode($text, true);

        if (! is_array($result) || ! is_array($result['remarks'] ?? null)) {
            return new WP_Error('gds_ct_audit_unreadable', 'Claude returned a review that could not be read.');
        }

        return [
            'summary' => (string) ($result['summary'] ?? ''),
            'remarks' => $result['remarks'],
            'usage' => [
                'input' => (int) ($message['usage']['input_tokens'] ?? 0),
                'output' => (int) ($message['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} The key and where it was found.
     */
    private static function resolveKey(): array
    {
        $env = getenv(self::keyName);

        if (is_string($env) && $env !== '') {
            return [$env, 'environment'];
        }

        if (defined(self::keyName) && is_string(constant(self::keyName)) && constant(self::keyName) !== '') {
            return [constant(self::keyName), 'constant'];
        }

        $option = get_option(self::keyOption);

        if (is_string($option) && $option !== '') {
            return [$option, 'option'];
        }

        return ['', ''];
    }

    /**
     * One Messages API call, retried once when the API is busy.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $betas
     * @return array<string, mixed>|WP_Error The decoded message.
     */
    private function request(array $body, array $betas): array|WP_Error
    {
        $headers = [
            'content-type' => 'application/json',
            'x-api-key' => self::resolveKey()[0],
            'anthropic-version' => self::apiVersion,
        ];

        if ($betas !== []) {
            $headers['anthropic-beta'] = implode(',', $betas);
        }

        for ($attempt = 1; ; $attempt++) {
            $response = wp_remote_post(self::apiUrl, [
                'headers' => $headers,
                'body' => (string) wp_json_encode($body),
                'timeout' => self::timeout,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('gds_ct_audit_connection', 'Could not reach the Claude API: '.$response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $decoded = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && is_array($decoded)) {
                return $decoded;
            }

            if ($attempt < 2 && ($code === 429 || $code >= 500)) {
                sleep(2);

                continue;
            }

            return new WP_Error('gds_ct_audit_api', sprintf(
                'Claude API error %d: %s',
                $code,
                is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : ''
            ));
        }
    }

    private static function localeOf(string $slug): string
    {
        $language = $slug !== '' ? PLL()->model->get_language($slug) : false;

        return $language ? $language->locale : '';
    }

    /**
     * One structured answer from Claude, with the review's model and key.
     *
     * @param  string|list<array<string, mixed>>  $user  The question, or its content blocks (an image and a question).
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|WP_Error
     */
    public function ask(string $system, string|array $user, array $schema): array|WP_Error
    {
        $model = (string) apply_filters('gds_content_translation_audit_model', self::settings()['model']);
        $message = $this->request([
            'model' => $model,
            'max_tokens' => 4000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ], []);

        if ($message instanceof WP_Error) {
            return $message;
        }

        $text = '';

        foreach ((array) ($message['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        $result = json_decode($text, true);

        return is_array($result) ? $result : new WP_Error('gds_ct_audit_unreadable', 'Claude returned an answer that could not be read.');
    }

    /**
     * A text for the review page, its nested blocks' placeholders shown as
     * a marker instead of Polylang's internal wording.
     */
    public static function displayText(string $html): string
    {
        return str_replace(
            [self::blockPlaceholder, self::blockPlaceholderKsesed],
            sprintf('<span class="gds-ct-review__nested">%s</span>', esc_html__('[nested block]', 'gds-content-translation')),
            $html
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You review machine translations of website content for a translation editor.

You get the texts of one web page as numbered segments: the page content in document order, then the page's other text fields, each with its field name. Each segment has the original text and its current translation. Segments can contain inline HTML such as links and bold text. The same text can appear in several segments.

Compare each translation with its original and report the problems a careful professional translator would fix before publishing:
- meaning: the translation says something different from the original
- omission / addition: content is missing or added
- terminology: a term is translated wrongly or inconsistently across segments
- grammar and fluency: errors, or phrasing a native reader would find unnatural
- tone: the register or formality does not match the original or the rest of the page
- formatting: inline HTML tags, numbers, names or units were changed, dropped or broken
- untranslated: text left in the original language that should be translated

Rules for remarks:
- One problem per remark. If a segment has two separate problems, make two remarks.
- Only report something that needs to change. If a segment is acceptable, make no remark about it. Never write a remark that says no change is needed, that the translation is acceptable, or that something is not an error: when you conclude there is no problem, leave the remark out entirely. This includes text the editor's instructions say stays as it is.
- Do not report personal style preferences or wording that is already correct and natural. A page with a good translation should get few or no remarks.
- Leave URLs, link targets and HTML attributes alone; links are handled separately.
- `<pre translate="no">Polylang placeholder do not modify</pre>` stands for a nested block. It is not text: never report it, and keep it exactly where it is in every suggestion.
- The SEO fields can contain variables such as `%%title%%`, `%%sep%%` and `%%sitename%%`, which the site fills in. They are not text: never translate or report them, and keep every one of them in every suggestion.
- Make sure the explanation is factually correct, for example about grammatical gender.
- Keep remarks consistent with each other: do not suggest a term in one remark that another remark advises against.

For every remark, give the segment number, a short explanation of the problem, and the full corrected translation of that segment as the suggestion, keeping every HTML tag and attribute exactly as in the current translation. Every remark must have a suggestion that differs from the current translation, so the editor can fix it with one click. When you cannot give one, leave the remark out.

The user message can include the site's glossary: terms of this page with the translation they must have. A glossary term translated differently is a terminology problem. Inflected and compound forms of the glossary's translation are correct, as the grammar of the sentence needs them. Suggestions must use the glossary's translations.

Give a remark a term when it is about how a single term (a product name, a technical word, a fixed phrase) should be translated across the whole site: the term in the original and its correct translation, both in their base form as a glossary lists them. Leave both empty for every other remark, and for a term the glossary has already.

The user message can include the editor's instructions: for the whole site, and for this page. Follow them. They come first where they conflict with the general rules above, for example a required term or a tone, and a translation that does not follow them is a problem to report. Suggestions must follow them too. Do not report anything only because an instruction mentions it when the translation already follows it.

Write the summary and the explanations in the language the user message names for the remarks. The summary is one or two sentences on the overall quality of the translation. When the user message says you get one part of a longer page, the summary covers that part in one sentence, and does not mention parts or segment counts.
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    /**
     * @param  array{site?: string, page?: string}  $instructions
     */
    private function userPrompt(WP_Post $source, PLL_Language $sourceLanguage, PLL_Language $targetLanguage, array $segments, int $part, int $parts, array $instructions = []): string
    {
        $remarkLanguage = match (self::settings()['language']) {
            'source' => sprintf('%s (%s)', $sourceLanguage->name, $sourceLanguage->locale),
            'site' => get_locale(),
            default => sprintf('%s (%s)', $targetLanguage->name, $targetLanguage->locale),
        };

        return (string) wp_json_encode([
            'page_title' => $source->post_title,
            'source_language' => sprintf('%s (%s)', $sourceLanguage->name, $sourceLanguage->locale),
            'target_language' => sprintf('%s (%s)', $targetLanguage->name, $targetLanguage->locale),
            'remark_language' => $remarkLanguage,
            'instructions' => array_filter([
                'site' => $instructions['site'] ?? null,
                'this_page' => $instructions['page'] ?? null,
            ]) ?: null,
            // The site's required translations of the terms in this page.
            'glossary' => $this->glossary ?: null,
            'part' => $parts > 1 ? sprintf('Part %d of %d of the page; the segment numbers continue across parts.', $part, $parts) : null,
            'segments' => array_map(static fn (array $segment): array => array_filter([
                'id' => $segment['id'],
                // ACF fields, the title and so on: which one, as the editor knows it.
                'field' => $segment['label'] ?? null,
                'source' => $segment['source'],
                'translation' => $segment['translation'],
            ], static fn ($value): bool => $value !== null), $segments),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'remarks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'segment' => ['type' => 'integer'],
                            'severity' => ['type' => 'string', 'enum' => self::severities],
                            'category' => ['type' => 'string', 'enum' => self::categories],
                            'issue' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                            'term' => [
                                'type' => 'object',
                                'properties' => [
                                    'source' => ['type' => 'string'],
                                    'target' => ['type' => 'string'],
                                ],
                                'required' => ['source', 'target'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'required' => ['segment', 'severity', 'category', 'issue', 'suggestion', 'term'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'remarks'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Keep the remarks that point at a segment and propose a change.
     *
     * @param  array<mixed>  $remarks
     * @param  list<array{id: int, source: string, translation: string}>  $segments
     * @return list<array{id: int, segment: int, severity: string, category: string, issue: string, suggestion: string, state: string}>
     */
    private function normalizeRemarks(array $remarks, array $segments): array
    {
        $normalized = [];

        foreach ($remarks as $remark) {
            if (! is_array($remark)) {
                continue;
            }

            $segment = (int) ($remark['segment'] ?? 0);
            $suggestion = trim((string) ($remark['suggestion'] ?? ''));

            if ($segment < 1 || $segment > count($segments)) {
                continue;
            }

            $translation = $segments[$segment - 1]['translation'];

            // Every remark is fixed with one click: one without a suggestion,
            // or with one identical to the current translation, is a remark
            // that found nothing to change ("acceptable", "not an error").
            if ($suggestion === '' || $suggestion === trim($translation)) {
                continue;
            }

            // Using a suggestion that lost a nested block would delete it.
            if (substr_count($suggestion, self::blockPlaceholder) !== substr_count($translation, self::blockPlaceholder)) {
                continue;
            }

            // Nor may it lose one of an SEO text's variables (%%title%%).
            if (self::seoVariables($suggestion) !== self::seoVariables($translation)) {
                continue;
            }

            $term = [
                'source' => Glossary::cleanTerm($remark['term']['source'] ?? ''),
                'target' => Glossary::cleanTerm($remark['term']['target'] ?? ''),
            ];

            $normalized[] = array_filter([
                'segment' => $segment,
                'severity' => in_array($remark['severity'] ?? '', self::severities, true) ? $remark['severity'] : 'warning',
                'category' => in_array($remark['category'] ?? '', self::categories, true) ? $remark['category'] : 'other',
                'issue' => (string) ($remark['issue'] ?? ''),
                'suggestion' => $suggestion,
                'state' => 'open',
                // For the glossary: which term the remark settles.
                'term' => $term['source'] !== '' && $term['target'] !== '' ? $term : null,
            ], static fn ($value): bool => $value !== null);
        }

        usort($normalized, static fn (array $a, array $b): int => $a['segment'] <=> $b['segment']);

        foreach ($normalized as $index => $remark) {
            $normalized[$index] = ['id' => $index + 1] + $remark;
        }

        return $normalized;
    }

    /**
     * Yoast SEO's variables in a text, sorted.
     *
     * @return list<string>
     */
    private static function seoVariables(string $text): array
    {
        preg_match_all('/%%[a-z0-9_-]+%%/i', $text, $matches);
        $variables = $matches[0];
        sort($variables);

        return $variables;
    }
}
