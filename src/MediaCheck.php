<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a translation shows that machine translation never sees: files,
 * images and other things on the page that are not its text. DeepL
 * translates the words around a PDF, an illustration or a form, and the
 * translation then reads as finished while the reader still gets the
 * original's.
 *
 * Each translation is checked when it or its original is saved, and once
 * over the whole site after an update. What is found:
 *
 * - documents (PDF, Word, Excel…) from the media library;
 * - SVG images with text in them (their `<text>`, `<title>`, `<desc>`);
 * - images with words in their pixels, which Claude looks for, once per
 *   image ({@see checkImages()});
 * - Gravity Forms forms;
 * - embeds (YouTube, Vimeo…);
 * - links to other sites that look like the original's language;
 * - and, from {@see ContentGaps}, query loops whose chosen posts have no
 *   translation, links to pages that have none, and placeholder texts.
 *
 * Only what the original uses too counts (not for {@see ContentGaps}, which
 * are the translation's own): a translation that links its own
 * PDF, or shows another image, has handled it. What is fine as it is (a
 * product code in an image, a PDF that only exists in Finnish) is marked so
 * on the translation and no longer counted.
 */
final class MediaCheck
{
    /** The result of a translation's check. */
    public const metaKey = '_gds_content_translation_media';

    /** Its open count alone, for the status screen's bulk queries. */
    public const countMetaKey = '_gds_content_translation_media_open';

    /** The keys of items marked fine as they are. */
    public const okMetaKey = '_gds_content_translation_media_ok';

    /** One row per image a translation waits on Claude to check. */
    private const waitsMetaKey = '_gds_content_translation_media_waits';

    /** One row per original whose translation the translation links or lists ({@see ContentGaps}). */
    private const referencesMetaKey = '_gds_content_translation_references';

    /** On an attachment: what Claude found in it. */
    public const imageMetaKey = '_gds_content_translation_image_text';

    /** On a translated copy of a file (an SVG, a DeepL document): the file it translates. */
    public const copyOfMetaKey = '_gds_content_translation_copy_of';

    /** On a translated copy: its language's slug. */
    public const copyLanguageMetaKey = '_gds_content_translation_copy_language';

    /** Bumped when what is found changes, so the site is checked again. */
    public const version = 3;

    private const versionOption = 'gds_content_translation_media_version';

    private const imageQueueOption = 'gds_content_translation_image_queue';

    private const sweepHook = 'gds_content_translation_media_sweep';

    private const imagesHook = 'gds_content_translation_check_images';

    /** Translations checked per sweep run. */
    private const sweepBatch = 25;

    /** Images Claude checks per run. */
    private const imageBatch = 5;

    /** Larger files are not sent to Claude (its limit is 5 MB, base64). */
    private const imageMaxBytes = 3_500_000;

    private const imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const documentExtensions = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'key', 'pages', 'numbers', 'epub', 'zip'];

    /** SVGs larger than this are not read for text. */
    private const svgMaxBytes = 2_000_000;

    public const kinds = ['document', 'svg', 'image', 'form', 'embed', 'link', ...ContentGaps::kinds];

    /** Block attributes that hold media library IDs. */
    public const attachmentAttributePattern = '/^(id|ids|mediaId|mediaIds|imageId|imageIds|.+(Image|Media)Ids?)$/';

    /** @var array<int, true> Posts saved in this request, checked at its end. */
    private static array $saved = [];

    /** @var array<int, true> Originals whose translation was deleted in this request. */
    private static array $deleted = [];

    public static function init(): void
    {
        add_action('save_post', [self::class, 'onSave'], 20, 2);
        add_action('shutdown', [self::class, 'checkSaved']);
        add_action(self::sweepHook, [self::class, 'sweep']);
        add_action(self::imagesHook, [self::class, 'checkImages']);
        add_action('admin_init', [self::class, 'scheduleSweep']);
        add_action('delete_post', [self::class, 'onDelete']);
        // Before Polylang unlinks it (10).
        add_action('before_delete_post', [self::class, 'beforeDelete'], 5);
    }

    /**
     * After an update that finds more, check the site again in the background.
     */
    public static function scheduleSweep(): void
    {
        if ((int) get_option(self::versionOption) >= self::version) {
            return;
        }

        update_option(self::versionOption, self::version, false);
        self::spawnSweep();
    }

    private static function spawnSweep(): void
    {
        if (! wp_next_scheduled(self::sweepHook)) {
            wp_schedule_single_event(time(), self::sweepHook);
        }
    }

    public static function onSave(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || ! pll_is_translated_post_type($post->post_type)) {
            return;
        }

        // At the end of the request: a new translation is linked to its
        // original only after it is first saved.
        self::$saved[$postId] = true;
    }

    public static function checkSaved(): void
    {
        $postIds = array_keys(self::$saved);
        $default = (string) pll_default_language('slug');
        // Posts that got, or lost, a translation: the translations that link
        // them or list them in a query loop count them again.
        $needed = self::$deleted;
        self::$saved = [];
        self::$deleted = [];

        foreach ($postIds as $postId) {
            if (get_post_type($postId) === 'wp_block') {
                foreach (self::showing(array_values(array_map('intval', pll_get_post_translations($postId) ?: [$postId]))) as $pageId) {
                    $postIds[] = $pageId;
                }
            }
        }

        foreach (array_unique($postIds) as $postId) {
            $language = pll_get_post_language($postId);

            if (! $language) {
                continue;
            }

            if ($language !== $default) {
                self::check($postId);
                $needed[(int) pll_get_post($postId, $default) ?: $postId] = true;

                continue;
            }

            // An original's change changes what its translations still share with it.
            foreach (pll_get_post_translations($postId) as $slug => $translationId) {
                if ($slug !== $language) {
                    self::check((int) $translationId);
                }
            }
        }

        foreach (array_keys($needed) as $sourceId) {
            foreach (self::referencing($sourceId) as $translationId) {
                self::check($translationId);
            }
        }
    }

    /**
     * A translation deleted for good: the pages that link it count the link again.
     */
    public static function beforeDelete(int $postId): void
    {
        $language = function_exists('pll_get_post_language') ? pll_get_post_language($postId) : false;
        $default = (string) pll_default_language('slug');

        if ($language && $language !== $default && ($sourceId = (int) pll_get_post($postId, $default)) > 0) {
            self::$deleted[$sourceId] = true;
        }
    }

    /**
     * The posts that show any of these synced patterns.
     *
     * @param  list<int>  $patternIds
     * @return list<int>
     */
    public static function showing(array $patternIds): array
    {
        global $wpdb;

        if ($patternIds === []) {
            return [];
        }

        $likes = implode(' OR ', array_fill(0, count($patternIds), 'post_content LIKE %s'));
        $values = array_map(static fn (int $id): string => '%'.$wpdb->esc_like('"ref":'.$id).'%', $patternIds);

        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND ({$likes})",
            ...$values
        )));

        // `"ref":14` matches `"ref":144` too.
        return array_values(array_filter($ids, static function (int $id) use ($patternIds): bool {
            foreach ($patternIds as $patternId) {
                if (preg_match('/"ref":'.$patternId.'\b/', (string) get_post_field('post_content', $id))) {
                    return true;
                }
            }

            return false;
        }));
    }

    public static function onDelete(int $postId): void
    {
        if (get_post_type($postId) === 'attachment') {
            self::dequeueImages([$postId]);
        }
    }

    /**
     * Check a translation and store the result.
     *
     * @return array{version: int, checkedAt: int, items: list<array<string, mixed>>, open: int, unchecked: int}|null
     */
    public static function check(int $postId): ?array
    {
        $post = get_post($postId);
        $default = (string) pll_default_language('slug');
        $language = $post ? pll_get_post_language($postId) : false;
        $sourceId = $language && $language !== $default ? (int) pll_get_post($postId, $default) : 0;
        $source = $sourceId > 0 ? get_post($sourceId) : null;

        if (! $post || ! $source || $post->post_status === 'trash') {
            self::forget($postId);

            return null;
        }

        $sourceItems = self::find($source, $default, $default);
        $items = [];
        $waits = [];
        $ok = self::okKeys($postId);

        // In a pattern: an action changes the pattern, so only its translation's.
        $inPattern = static function (array $item) use ($language): array {
            if (! empty($item['in'])) {
                $item['inTitle'] = get_the_title((int) $item['in']);
                $item['inOriginal'] = pll_get_post_language((int) $item['in']) !== $language;
            }

            return $item;
        };

        foreach (self::find($post, $default, (string) $language) as $key => $item) {
            $item = $inPattern($item);

            // Only what the original shows too: anything else was chosen for this
            // translation. A translated copy made here is listed, to correct it.
            if (! isset($sourceItems[$key])) {
                $copyOf = (int) ($item['attachmentId'] ?? 0) > 0 ? (int) get_post_meta((int) $item['attachmentId'], self::copyOfMetaKey, true) : 0;

                if ($copyOf > 0) {
                    $items[] = $item + ['state' => 'translated', 'copyOf' => $copyOf];
                }

                continue;
            }

            if ($item['kind'] === 'image') {
                $found = self::imageText((int) $item['attachmentId']);

                if ($found === null) {
                    $item['state'] = 'unchecked';
                    $waits[] = (int) $item['attachmentId'];
                } elseif ($found['status'] !== 'text') {
                    continue;
                } else {
                    $item['texts'] = $found['texts'];
                }
            }

            // A form translated into the language is handled where it is shown.
            if ($item['kind'] === 'form' && FormTranslation::isComplete((int) $item['formId'], (string) $language)) {
                $item['state'] = 'translated';
            }

            $item['state'] ??= isset($ok[$key]) ? 'ok' : 'open';
            $items[] = $item;
        }

        // The translation's own: whether the original has the same or not.
        foreach (ContentGaps::find($post, $source, (string) $language, $references) as $key => $item) {
            $items[] = $inPattern($item) + ['state' => isset($ok[$key]) ? 'ok' : 'open'];
        }

        $result = [
            'version' => self::version,
            'checkedAt' => time(),
            'items' => $items,
            'open' => count(array_filter($items, static fn (array $item): bool => $item['state'] === 'open')),
            'unchecked' => count(array_filter($items, static fn (array $item): bool => $item['state'] === 'unchecked')),
        ];

        update_post_meta($postId, self::metaKey, $result);
        update_post_meta($postId, self::countMetaKey, $result['open']);
        delete_post_meta($postId, self::waitsMetaKey);

        foreach (array_unique($waits) as $attachmentId) {
            add_post_meta($postId, self::waitsMetaKey, $attachmentId);
        }

        if (array_map('intval', get_post_meta($postId, self::referencesMetaKey)) !== $references) {
            delete_post_meta($postId, self::referencesMetaKey);

            foreach ($references as $referenceId) {
                add_post_meta($postId, self::referencesMetaKey, $referenceId);
            }
        }

        if ($waits !== []) {
            self::queueImages($waits);
        }

        return $result;
    }

    /**
     * The translated copy of a file in a language, if there is one.
     */
    public static function copyOf(int $sourceId, string $slug): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'lang' => '',
            'meta_query' => [
                ['key' => self::copyOfMetaKey, 'value' => $sourceId],
                ['key' => self::copyLanguageMetaKey, 'value' => $slug],
            ],
        ]);

        return (int) ($ids[0] ?? 0);
    }

    /**
     * Check again the translations whose last check found an item, after
     * something about it changed (a form's translations).
     */
    public static function recheckUsing(string $key): void
    {
        global $wpdb;

        $postIds = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
            self::metaKey,
            '%'.$wpdb->esc_like(serialize($key)).'%'
        ));

        foreach (array_map('intval', $postIds) as $postId) {
            self::check($postId);
        }
    }

    /**
     * The translations that link an original's page or list it in a query loop.
     *
     * @return list<int>
     */
    private static function referencing(int $sourceId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
            self::referencesMetaKey,
            $sourceId
        )));
    }

    private static function forget(int $postId): void
    {
        delete_post_meta($postId, self::referencesMetaKey);
        delete_post_meta($postId, self::metaKey);
        delete_post_meta($postId, self::countMetaKey);
        delete_post_meta($postId, self::waitsMetaKey);
    }

    /**
     * A translation's last check, done now if it never was.
     *
     * @return array{version: int, checkedAt: int, items: list<array<string, mixed>>, open: int, unchecked: int}|null
     */
    public static function get(int $postId): ?array
    {
        $result = get_post_meta($postId, self::metaKey, true);

        if (is_array($result) && (int) ($result['version'] ?? 0) === self::version) {
            return $result;
        }

        return self::check($postId);
    }

    /**
     * Mark items fine as they are, or count them again.
     *
     * @param  list<string>  $keys
     */
    public static function setOk(int $postId, array $keys, bool $ok): ?array
    {
        $stored = self::okKeys($postId);

        foreach ($keys as $key) {
            if ($ok) {
                $stored[$key] = true;
            } else {
                unset($stored[$key]);
            }
        }

        update_post_meta($postId, self::okMetaKey, array_keys($stored));

        return self::check($postId);
    }

    /**
     * @return array<string, true>
     */
    private static function okKeys(int $postId): array
    {
        $keys = get_post_meta($postId, self::okMetaKey, true);

        return is_array($keys) ? array_fill_keys(array_map('strval', $keys), true) : [];
    }

    /**
     * Open counts of some translations, as the status screen needs them.
     *
     * @param  list<int>  $postIds
     * @return array<int, int> Post ID => open items; a translation never checked is missing.
     */
    public static function openCounts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $counts = [];

        foreach (array_chunk($postIds, 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})",
                self::countMetaKey,
                ...$chunk
            ));

            foreach ($rows as $row) {
                $counts[(int) $row->post_id] = (int) $row->meta_value;
            }
        }

        return $counts;
    }

    /**
     * Check, a batch at a time, every translation not yet checked by this
     * version.
     */
    public static function sweep(): void
    {
        $default = (string) pll_default_language('slug');
        $postTypes = array_values(array_filter(get_post_types(), 'pll_is_translated_post_type'));

        if ($default === '' || $postTypes === []) {
            return;
        }

        $languages = array_values(array_filter(pll_languages_list(), static fn (string $slug): bool => $slug !== $default));
        $postIds = get_posts([
            'post_type' => $postTypes,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => self::sweepBatch,
            'lang' => implode(',', $languages),
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::metaKey, 'compare' => 'NOT EXISTS'],
                // Serialised: the version is the first value, "i:N;".
                ['key' => self::metaKey, 'value' => sprintf('s:7:"version";i:%d;', self::version), 'compare' => 'NOT LIKE'],
            ],
        ]);

        foreach ($postIds as $postId) {
            $before = get_post_meta((int) $postId, self::metaKey, true);

            if (self::check((int) $postId) === null && $before === '') {
                // Not a translation of anything: marked, so the sweep moves on.
                update_post_meta((int) $postId, self::metaKey, ['version' => self::version, 'checkedAt' => time(), 'items' => [], 'open' => 0, 'unchecked' => 0]);
            }
        }

        if (count($postIds) === self::sweepBatch) {
            wp_schedule_single_event(time() + 5, self::sweepHook);
        }
    }

    /**
     * How far the site-wide check is: translations checked of all.
     *
     * @return array{checked: int, total: int}
     */
    public static function progress(): array
    {
        $default = (string) pll_default_language('slug');
        $postTypes = array_values(array_filter(get_post_types(), 'pll_is_translated_post_type'));
        $languages = array_values(array_filter(pll_languages_list(), static fn (string $slug): bool => $slug !== $default));

        if ($postTypes === [] || $languages === []) {
            return ['checked' => 0, 'total' => 0];
        }

        $args = [
            'post_type' => $postTypes,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 1,
            'lang' => implode(',', $languages),
            'fields' => 'ids',
        ];
        $total = (int) (new \WP_Query($args))->found_posts;
        $checked = (int) (new \WP_Query($args + ['meta_query' => [['key' => self::metaKey, 'value' => sprintf('s:7:"version";i:%d;', self::version), 'compare' => 'LIKE']]]))->found_posts;

        return ['checked' => min($checked, $total), 'total' => $total];
    }

    /**
     * Everything a post shows that is not its text, by key. The same file,
     * form or address has the same key in the original and its translations.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function find(WP_Post $post, string $sourceSlug, string $targetSlug): array
    {
        $items = [];
        $add = static function (array $item) use (&$items): void {
            $items[$item['key']] ??= $item;
        };

        // A synced pattern's content shows on the page too: what is in it is
        // listed with the pattern it is in (`in`), which an action changes.
        $seen = [$post->ID => true];
        $scan = static function (string $content, int $in) use (&$scan, &$seen, $add, $sourceSlug, $targetSlug): void {
            $addIn = static fn (array $item) => $add($in > 0 ? $item + ['in' => $in] : $item);

            $walk = static function (array $blocks) use (&$walk, &$scan, &$seen, $addIn, $sourceSlug, $targetSlug): void {
                foreach ($blocks as $block) {
                    $name = (string) ($block['blockName'] ?? '');
                    $attrs = (array) ($block['attrs'] ?? []);

                    if ($name === 'core/block' && ! empty($attrs['ref'])) {
                        $pattern = get_post((int) $attrs['ref']);

                        if ($pattern && $pattern->post_type === 'wp_block' && ! isset($seen[$pattern->ID])) {
                            $seen[$pattern->ID] = true;
                            $scan($pattern->post_content, $pattern->ID);
                        }

                        continue;
                    }

                    foreach (self::attachmentIds($attrs) as $attachmentId) {
                        if ($item = self::attachmentItem($attachmentId)) {
                            $addIn($item);
                        }
                    }

                    if ($name === 'gravityforms/form' && ! empty($attrs['formId'])) {
                        $addIn(self::formItem((int) $attrs['formId']));
                    }

                    if ($name === 'core/embed' && ! empty($attrs['url'])) {
                        $addIn(self::embedItem((string) $attrs['url']));
                    }

                    foreach (self::htmlItems((string) ($block['innerHTML'] ?? ''), $sourceSlug, $targetSlug) as $item) {
                        $addIn($item);
                    }

                    $walk((array) ($block['innerBlocks'] ?? []));
                }
            };
            $walk(parse_blocks($content));

            // Classic content, and shortcodes in it or in blocks.
            if (! has_blocks($content)) {
                foreach (self::htmlItems($content, $sourceSlug, $targetSlug) as $item) {
                    $addIn($item);
                }
            }

            if (preg_match_all('/\[gravityforms?\b[^\]]*\bid=["\']?(\d+)/', $content, $matches)) {
                foreach ($matches[1] as $formId) {
                    $addIn(self::formItem((int) $formId));
                }
            }
        };
        $scan($post->post_content, 0);

        $thumbnailId = (int) get_post_thumbnail_id($post);

        if ($thumbnailId > 0 && ($item = self::attachmentItem($thumbnailId))) {
            $add($item);
        }

        return array_filter(
            (array) apply_filters('gds_content_translation_media_items', $items, $post, $sourceSlug, $targetSlug),
            static fn ($item): bool => is_array($item) && isset($item['key'], $item['kind'])
        );
    }

    /**
     * The media library items a block's attributes point to: core's `id`
     * (image, file, cover…), `mediaId` (media & text and the theme's
     * blocks), `ids` (old galleries), and any other `…ImageId`/`…MediaId`.
     *
     * @param  array<string, mixed>  $attrs
     * @return list<int>
     */
    private static function attachmentIds(array $attrs): array
    {
        $ids = [];

        foreach ($attrs as $name => $value) {
            if (! is_string($name) || ! preg_match(self::attachmentAttributePattern, $name)) {
                continue;
            }

            foreach ((array) $value as $id) {
                if (is_numeric($id) && (int) $id > 0 && get_post_type((int) $id) === 'attachment') {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Files linked and images shown in some HTML: from the media library
     * (when it is not already found by its block), and links to other sites.
     *
     * @return list<array<string, mixed>>
     */
    private static function htmlItems(string $html, string $sourceSlug, string $targetSlug): array
    {
        if ($html === '' || ! preg_match_all('/\b(?:href|src)=["\']([^"\']+)["\']/i', $html, $matches)) {
            return [];
        }

        $uploads = wp_get_upload_dir();
        $baseUrl = set_url_scheme((string) $uploads['baseurl'], 'https');
        $siteHosts = array_unique(array_filter([
            wp_parse_url(home_url(), PHP_URL_HOST),
            wp_parse_url(site_url(), PHP_URL_HOST),
            wp_parse_url((string) get_option('home'), PHP_URL_HOST),
        ]));
        $items = [];

        foreach (array_unique($matches[1]) as $url) {
            $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
            $absolute = str_starts_with($url, '/') && ! str_starts_with($url, '//') ? untrailingslashit((string) get_option('home')).$url : $url;
            $host = (string) wp_parse_url($absolute, PHP_URL_HOST);

            if ($host === '' || ! preg_match('#^https?://#i', $absolute)) {
                continue;
            }

            if (str_starts_with(set_url_scheme($absolute, 'https'), $baseUrl.'/')) {
                $attachmentId = self::attachmentIdOfUrl($absolute);
                $item = $attachmentId > 0 ? self::attachmentItem($attachmentId) : self::fileItem($absolute);

                if ($item) {
                    $items[] = $item;
                }

                continue;
            }

            if (in_array(strtolower($host), array_map('strtolower', $siteHosts), true)) {
                // The site's own pages: the review page lists those that have no translation.
                continue;
            }

            if (self::looksLikeLanguage($absolute, $sourceSlug, $targetSlug)) {
                $items[] = [
                    'key' => 'link:'.self::normalizeUrl($absolute),
                    'kind' => 'link',
                    'title' => $host.(string) wp_parse_url($absolute, PHP_URL_PATH),
                    'url' => $absolute,
                ];
            }
        }

        return $items;
    }

    /**
     * An address on another site that reads like the original's language: a
     * `.fi` site, or a `/fi/` path, and not a page for the translation's own
     * language there (`/sv/`).
     */
    private static function looksLikeLanguage(string $url, string $sourceSlug, string $targetSlug): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = '/'.trim(strtolower((string) wp_parse_url($url, PHP_URL_PATH)), '/').'/';
        $query = strtolower((string) wp_parse_url($url, PHP_URL_QUERY));
        $segment = static fn (string $slug): bool => str_contains($path, '/'.$slug.'/') || str_contains($path, '/'.$slug.'-') || preg_match('/(^|&)(lang|language|hl)='.preg_quote($slug, '/').'\b/', $query) === 1;

        if ($targetSlug !== $sourceSlug && $segment($targetSlug)) {
            return false;
        }

        // A country domain only says something for a language that has one of its own.
        $countryDomain = strlen($sourceSlug) === 2 && ! in_array($sourceSlug, ['en', 'eu'], true) && str_ends_with($host, '.'.$sourceSlug);

        return $countryDomain || $segment($sourceSlug);
    }

    private static function normalizeUrl(string $url): string
    {
        return strtolower((string) preg_replace('#^https?://(www\.)?#i', '', untrailingslashit($url)));
    }

    /**
     * The media library item of an uploads URL, also of a resized image
     * ("photo-1024x768.jpg").
     */
    private static function attachmentIdOfUrl(string $url): int
    {
        $url = (string) strtok($url, '?#');
        $id = attachment_url_to_postid($url);

        if ($id === 0) {
            $unsized = (string) preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $url);
            $id = $unsized !== $url ? attachment_url_to_postid($unsized) : 0;
        }

        if ($id === 0) {
            $id = attachment_url_to_postid((string) preg_replace('/(\.\w+)$/', '-scaled$1', (string) preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $url)));
        }

        return $id;
    }

    /**
     * A media library item, when it is something to look at: a document, an
     * SVG with text, or an image (whether it has words is Claude's to say).
     *
     * @return array<string, mixed>|null
     */
    private static function attachmentItem(int $attachmentId): ?array
    {
        $file = get_attached_file($attachmentId);
        $mime = (string) get_post_mime_type($attachmentId);
        $url = (string) wp_get_attachment_url($attachmentId);

        if (! $file || $url === '') {
            return null;
        }

        // The file, not the ID: with Polylang's media translation each
        // language has its own item of the same file.
        $key = 'file:'.self::relativePath($file);
        $base = [
            'key' => $key,
            'attachmentId' => $attachmentId,
            'title' => wp_basename($file),
            'url' => $url,
            'editUrl' => (string) get_edit_post_link($attachmentId, 'raw'),
        ];

        if ($mime === 'image/svg+xml') {
            $texts = self::svgTexts($file);

            return $texts !== [] ? $base + ['kind' => 'svg', 'texts' => $texts] : null;
        }

        if (in_array($mime, self::imageMimes, true)) {
            return $base + ['kind' => 'image', 'thumbnail' => (string) wp_get_attachment_image_url($attachmentId, 'thumbnail')];
        }

        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::documentExtensions, true)) {
            return $base + ['kind' => 'document'];
        }

        return null;
    }

    /**
     * A file in the uploads folder that is not in the media library.
     *
     * @return array<string, mixed>|null
     */
    private static function fileItem(string $url): ?array
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::documentExtensions, true)) {
            return null;
        }

        return [
            'key' => 'file:'.ltrim((string) preg_replace('#^.*?/uploads/#', '', $path), '/'),
            'kind' => 'document',
            'attachmentId' => 0,
            'title' => wp_basename($path),
            'url' => $url,
        ];
    }

    private static function relativePath(string $file): string
    {
        $base = trailingslashit((string) wp_get_upload_dir()['basedir']);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : wp_basename($file);
    }

    /**
     * The words in an SVG: its text elements and its title and description,
     * without what has no letters ("28 × 120" alone).
     *
     * @return list<string>
     */
    public static function svgTexts(string $file): array
    {
        if (! is_readable($file) || filesize($file) > self::svgMaxBytes) {
            return [];
        }

        $svg = (string) file_get_contents($file);
        preg_match_all('#<(text|title|desc)\b[^>]*>(.*?)</\1>#is', $svg, $matches);
        // What a screen reader reads of an SVG shown inline, as the theme does.
        preg_match_all('#\saria-(?:label|description)=(["\'])(.*?)\1#is', $svg, $attributes);

        $texts = [];

        foreach (array_merge($matches[2], $attributes[2]) as $inner) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $inner), ENT_QUOTES | ENT_XML1, 'UTF-8')));

            if ($text !== '' && preg_match('/\p{L}{2,}/u', $text)) {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }

    /**
     * @return array<string, mixed>
     */
    private static function formItem(int $formId): array
    {
        $title = '';

        if (class_exists('GFAPI')) {
            $form = \GFAPI::get_form($formId);
            $title = is_array($form) ? (string) ($form['title'] ?? '') : '';
        }

        return [
            'key' => 'form:'.$formId,
            'kind' => 'form',
            'formId' => $formId,
            'title' => $title !== '' ? $title : sprintf(
                /* translators: %d: form ID */
                __('Form %d', 'gds-content-translation'),
                $formId
            ),
            'url' => class_exists('GFAPI') ? admin_url('admin.php?page=gf_edit_forms&id='.$formId) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function embedItem(string $url): array
    {
        return [
            'key' => 'embed:'.self::normalizeUrl($url),
            'kind' => 'embed',
            'title' => (string) preg_replace('#^https?://(www\.)?#i', '', $url),
            'url' => $url,
        ];
    }

    /**
     * What Claude found in an image: null when it has not looked yet (or
     * the file changed since).
     *
     * @return array{status: string, texts: list<string>}|null
     */
    public static function imageText(int $attachmentId): ?array
    {
        $found = get_post_meta($attachmentId, self::imageMetaKey, true);

        if (! is_array($found) || ($found['signature'] ?? '') !== self::imageSignature($attachmentId)) {
            return null;
        }

        if (($found['status'] ?? '') === 'failed') {
            // Tried again after a day.
            return (int) ($found['checkedAt'] ?? 0) < time() - DAY_IN_SECONDS ? null : ['status' => 'failed', 'texts' => []];
        }

        return ['status' => (string) ($found['status'] ?? ''), 'texts' => array_values(array_map('strval', (array) ($found['texts'] ?? [])))];
    }

    private static function imageSignature(int $attachmentId): string
    {
        $file = get_attached_file($attachmentId);

        return $file && is_readable($file) ? md5($file.'|'.filesize($file).'|'.filemtime($file)) : '';
    }

    /**
     * @param  list<int>  $attachmentIds
     */
    private static function queueImages(array $attachmentIds): void
    {
        if (! TranslationAudit::isEnabled()) {
            return;
        }

        $queue = array_map('intval', (array) get_option(self::imageQueueOption, []));
        $merged = array_values(array_unique(array_merge($queue, $attachmentIds)));

        if ($merged !== $queue) {
            update_option(self::imageQueueOption, $merged, false);
        }

        if (! wp_next_scheduled(self::imagesHook)) {
            wp_schedule_single_event(time(), self::imagesHook);
        }
    }

    /**
     * @param  list<int>  $attachmentIds
     */
    private static function dequeueImages(array $attachmentIds): void
    {
        $queue = array_map('intval', (array) get_option(self::imageQueueOption, []));
        update_option(self::imageQueueOption, array_values(array_diff($queue, $attachmentIds)), false);
    }

    /**
     * Images waiting for Claude to look at them.
     */
    public static function queuedImages(): int
    {
        return count((array) get_option(self::imageQueueOption, []));
    }

    /**
     * Have Claude look at the next few queued images, then check again the
     * translations that waited on them.
     */
    public static function checkImages(): void
    {
        $queue = array_values(array_map('intval', (array) get_option(self::imageQueueOption, [])));
        $batch = array_slice($queue, 0, self::imageBatch);

        if ($batch === []) {
            return;
        }

        self::dequeueImages($batch);

        foreach ($batch as $attachmentId) {
            if (self::imageText($attachmentId) === null) {
                $result = self::askAboutImage($attachmentId);
                update_post_meta($attachmentId, self::imageMetaKey, $result + [
                    'signature' => self::imageSignature($attachmentId),
                    'checkedAt' => time(),
                ]);
            }

            foreach (self::waitingOn($attachmentId) as $postId) {
                self::check($postId);
            }
        }

        if (count($queue) > count($batch)) {
            wp_schedule_single_event(time() + 2, self::imagesHook);
        }
    }

    /**
     * @return list<int>
     */
    private static function waitingOn(int $attachmentId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
            self::waitsMetaKey,
            $attachmentId
        )));
    }

    /**
     * Ask Claude whether an image has words a reader of a translation would
     * need in their own language.
     *
     * @return array{status: string, texts: list<string>, error?: string}
     */
    private static function askAboutImage(int $attachmentId): array
    {
        $file = self::imageFileToSend($attachmentId);

        if ($file === null) {
            return ['status' => 'skipped', 'texts' => []];
        }

        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $languageName = $default ? $default->name : (string) pll_default_language('name');

        $answer = (new TranslationAudit)->ask(
            'You look at images from a website that is translated into other languages. You find the words in an image that a reader of a translation would need in their own language: headings, labels, captions, sentences, words on signs or packaging that carry the message. Not logos, brand or product names, product codes, numbers or units alone, nor text too small to read.',
            [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $file['mime'], 'data' => base64_encode((string) file_get_contents($file['path']))]],
                ['type' => 'text', 'text' => sprintf(
                    'The website\'s original language is %s. List the words in this image that would need translating, each text as it is written. An empty list when there are none.',
                    $languageName
                )],
            ],
            [
                'type' => 'object',
                'properties' => [
                    'texts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['texts'],
                'additionalProperties' => false,
            ]
        );

        if ($answer instanceof WP_Error) {
            error_log(sprintf('[gds-content-translation] Checking image %d for text failed: %s', $attachmentId, $answer->get_error_message()));

            return ['status' => 'failed', 'texts' => [], 'error' => $answer->get_error_message()];
        }

        $texts = array_values(array_filter(array_map(
            static fn ($text): string => is_string($text) ? trim(mb_substr($text, 0, 300)) : '',
            (array) ($answer['texts'] ?? [])
        )));

        return ['status' => $texts !== [] ? 'text' : 'none', 'texts' => array_slice($texts, 0, 30)];
    }

    /**
     * The image file to show Claude: its "large" size when there is one,
     * which is enough to read text and costs less, else the original.
     *
     * @return array{path: string, mime: string}|null
     */
    private static function imageFileToSend(int $attachmentId): ?array
    {
        $original = get_attached_file($attachmentId);

        if (! $original || ! is_readable($original)) {
            return null;
        }

        $path = $original;
        $large = image_get_intermediate_size($attachmentId, 'large');

        if (is_array($large) && ! empty($large['file'])) {
            $candidate = path_join(dirname($original), wp_basename((string) $large['file']));
            $path = is_readable($candidate) ? $candidate : $original;
        }

        $mime = (string) (wp_check_filetype($path)['type'] ?? '');

        if (! in_array($mime, self::imageMimes, true) || filesize($path) > self::imageMaxBytes) {
            return null;
        }

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * The check of a translation as the screens show it.
     *
     * @return array<string, mixed>
     */
    public static function data(int $postId): array
    {
        $result = self::get($postId) ?? ['items' => [], 'open' => 0, 'unchecked' => 0, 'checkedAt' => 0];
        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $language = PLL()->model->post->get_language($postId);
        $items = MediaActions::describe($postId, array_values($result['items']));
        $offers = array_merge(...array_map(static fn (array $item): array => $item['actions'], $items ?: [['actions' => []]]));

        return [
            'items' => $items,
            'open' => (int) $result['open'],
            'unchecked' => (int) $result['unchecked'],
            'canCheckImages' => TranslationAudit::isEnabled(),
            'sourceLanguage' => $default ? StatusList::languageName($default) : '',
            'targetLanguage' => $language ? StatusList::languageName($language) : '',
            // What "AI translation" of a linked page or chosen post needs.
            'targetSlug' => $language ? $language->slug : '',
            'canTranslate' => MachineTranslation::isAvailable(),
            'editUrl' => (string) get_edit_post_link($postId, 'raw'),
            // What the choices need: the forms to pick from, what a DeepL document costs.
            'forms' => in_array('replace', $offers, true) && in_array('form', array_column($items, 'kind'), true) ? MediaActions::forms() : [],
            'documentCost' => in_array('document-translate', $offers, true) ? DocumentTranslation::estimate() : null,
            'formTranslation' => FormTranslation::statusFor($postId, $items),
        ];
    }
}
