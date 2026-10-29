<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The page where the remarks of a translation's AI review are worked through.
 * The status screen's marker for a review is drawn by its script, from the
 * status in the rows it loads ({@see StatusList}).
 *
 * The page shows each text's original, translation and remarks side by side.
 * A remark is fixed here (use the suggestion, or edit it) or in the editor,
 * where each remark is a note on its block, or marked as not an issue. After
 * an AI translation the editor lands here while the review runs, and the page
 * updates itself once it is done.
 *
 * The page is the status screen with a `gds_ct_review` parameter, so it
 * needs no menu entry of its own.
 */
final class AuditReview
{
    public const param = 'gds_ct_review';

    private const reviewAction = 'gds_ct_run_review';

    private const statusAction = 'gds_ct_audit_status';

    public static function init(): void
    {
        $review = new self;
        add_action('admin_post_'.self::reviewAction, [$review, 'handleReview']);
        add_action('wp_ajax_'.self::statusAction, [$review, 'handleStatus']);
    }

    public static function getUrl(int $postId): string
    {
        return add_query_arg(
            ['page' => Admin::getPageSlug(), self::param => $postId],
            admin_url('admin.php')
        );
    }

    /**
     * Link that runs the review of a translation, first or again. One nonce
     * per screen: the handler checks the capability per post.
     */
    public static function getReviewActionUrl(int $postId): string
    {
        return add_query_arg(
            ['action' => self::reviewAction, 'post' => $postId, '_wpnonce' => wp_create_nonce(self::reviewAction)],
            admin_url('admin-post.php')
        );
    }

    public static function requestedPostId(): int
    {
        return Request::id($_GET, self::param);
    }

    /**
     * Run the review of a translation, first or again, on its current text.
     */
    public function handleReview(): void
    {
        $postId = Request::id($_REQUEST, 'post');

        check_admin_referer(self::reviewAction);

        if ($postId <= 0 || ! current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to review this translation.', 'gds-content-translation'), 403);
        }

        if (! TranslationAudit::isEnabled()) {
            Notice::flash('error', __('The AI review is not available. Check the Claude API key and the Content translation settings.', 'gds-content-translation'));
        } elseif (function_exists('wp_check_post_lock') && wp_check_post_lock($postId)) {
            // The review adds notes to the content, which an open editor would overwrite.
            Notice::flash('error', __('Someone has this translation open in the editor. Run the review when it is closed.', 'gds-content-translation'));
        } else {
            TranslationAudit::queue($postId);
        }

        wp_safe_redirect(self::getUrl($postId));
        exit;
    }

    /**
     * Use a suggestion, save an edit, mark a remark fixed or not an issue, or
     * reopen it. The caller checks that the user may edit the post.
     */
    public static function changeRemark(int $postId, int $remarkId, string $operation, string $text = ''): true|\WP_Error
    {
        $review = TranslationAudit::get($postId);
        $remark = $review ? (array_column($review['remarks'], null, 'id')[$remarkId] ?? null) : null;

        if (! $remark) {
            return new \WP_Error('gds_ct_audit_missing', __('This remark no longer exists. Reload the page.', 'gds-content-translation'));
        }

        $result = match ($operation) {
            'use' => TranslationAudit::applyText($postId, $remarkId, (string) $remark['suggestion'], 'suggestion'),
            'edit' => TranslationAudit::applyText($postId, $remarkId, wp_kses_post($text), 'edited'),
            'fixed' => TranslationAudit::setRemarkState($postId, $remarkId, 'resolved', true, 'manual'),
            'dismiss' => TranslationAudit::setRemarkState($postId, $remarkId, 'dismissed', true, 'not-an-issue'),
            'reopen' => TranslationAudit::setRemarkState($postId, $remarkId, 'open'),
            default => false,
        };

        if ($result === false) {
            return new \WP_Error('gds_ct_audit_missing', __('That did not work. Reload the page and try again.', 'gds-content-translation'));
        }

        return $result;
    }

    /**
     * The review page's table: a row per remark, and one per text without
     * remarks, with what the text says now to flag the ones changed since.
     *
     * @return array{rows: list<array<string, mixed>>, counts: array{error: int, warning: int, suggestion: int}, sourceLanguage: string, targetLanguage: string}
     */
    public static function remarkRows(int $postId): array
    {
        $review = TranslationAudit::get($postId) ?? ['remarks' => [], 'segments' => []];
        $post = get_post($postId);
        $source = get_post((int) ($review['sourceId'] ?? 0));
        $current = $source && $post ? TranslationAudit::currentSegments($source, $post)['segments'] : [];
        $currentById = array_column($current, 'translation', 'id');
        $remarksBySegment = [];

        foreach ((array) $review['remarks'] as $remark) {
            $remarksBySegment[$remark['segment']][] = $remark;
        }

        $rows = [];

        foreach ((array) $review['segments'] as $segment) {
            $base = [
                'segment' => (int) $segment['id'],
                // An ACF field says which one.
                'label' => (string) ($segment['label'] ?? ''),
                'source' => wp_kses_post($segment['source']),
                'translation' => wp_kses_post($segment['translation']),
                'changed' => isset($currentById[$segment['id']]) && $currentById[$segment['id']] !== $segment['translation'],
            ];

            foreach ($remarksBySegment[$segment['id']] ?? [] as $remark) {
                $suggestion = (string) $remark['suggestion'];
                $rows[] = $base + [
                    'id' => 'r'.(int) $remark['id'],
                    'remarkId' => (int) $remark['id'],
                    'severity' => (string) $remark['severity'],
                    'category' => (string) $remark['category'],
                    'categoryLabel' => self::categoryLabel((string) $remark['category']),
                    'issue' => (string) $remark['issue'],
                    'suggestion' => $suggestion,
                    'suggestionDiff' => $suggestion !== '' ? TextDiff::render((string) $segment['translation'], $suggestion) : '',
                    'state' => (string) ($remark['state'] ?? 'open'),
                    'stateLabel' => ($remark['state'] ?? 'open') !== 'open' ? self::stateLabel($remark) : '',
                ];
            }

            if (! isset($remarksBySegment[$segment['id']])) {
                $rows[] = $base + [
                    'id' => 's'.(int) $segment['id'],
                    'remarkId' => 0,
                    'severity' => '',
                    'category' => '',
                    'categoryLabel' => '',
                    'issue' => '',
                    'suggestion' => '',
                    'suggestionDiff' => '',
                    'state' => 'none',
                    'stateLabel' => '',
                ];
            }
        }

        return [
            'rows' => $rows,
            'counts' => self::openCounts($review),
            'sourceLanguage' => self::languageName((string) ($review['sourceLanguage'] ?? '')),
            'targetLanguage' => self::languageName((string) ($review['targetLanguage'] ?? '')),
        ];
    }

    /**
     * Review status of some translations, for pages waiting on a review.
     */
    public function handleStatus(): void
    {
        check_ajax_referer('gds_content_translation', 'nonce');

        $postIds = array_filter(Request::ids($_GET, 'posts'), static fn (int $postId): bool => current_user_can('edit_post', $postId));
        $statuses = TranslationAudit::statuses(array_values($postIds));

        wp_send_json_success(array_map(static fn (array $status): string => $status['status'], $statuses));
    }

    public static function renderPage(int $postId): void
    {
        $post = get_post($postId);
        $review = $post ? TranslationAudit::get($postId) : null;
        $backUrl = Admin::getPageUrl($post ? $post->post_type : '').'#gds-ct-row-'.(int) ($review['sourceId'] ?? 0);

        if (! $post || ! $review || ! current_user_can('edit_post', $postId)) {
            ?>
            <div class="wrap gds-content-translation gds-content-translation-review">
                <h1><?php echo esc_html__('AI review', 'gds-content-translation'); ?></h1>
                <hr class="wp-header-end">
                <p><?php echo esc_html__('There is no AI review for this post.', 'gds-content-translation'); ?></p>
                <p><a href="<?php echo esc_url($backUrl); ?>"><?php echo esc_html__('← Back to Content translation status', 'gds-content-translation'); ?></a></p>
            </div>
            <?php

            return;
        }

        $status = TranslationAudit::parseStatus((string) get_post_meta($postId, TranslationAudit::statusMetaKey, true));
        $language = PLL()->model->post->get_language($postId);
        $languageName = $language ? StatusList::languageName($language) : '';
        $editUrl = (string) get_edit_post_link($postId, 'raw');
        $openCounts = self::openCounts($review);
        $openTotal = array_sum($openCounts);

        ?>
        <div class="wrap gds-content-translation gds-content-translation-review" data-gds-ct-review="<?php echo esc_attr((string) $postId); ?>" data-status="<?php echo esc_attr($status['status']); ?>">
            <h1 class="wp-heading-inline">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: post title, 2: language name */
                    __('AI review: %1$s (%2$s)', 'gds-content-translation'),
                    get_the_title($post),
                    $languageName
                ));
        ?>
            </h1>
            <?php if ($editUrl !== '' && $status['status'] !== 'pending') { ?>
                <a href="<?php echo esc_url($editUrl); ?>" class="page-title-action" data-gds-ct-editor-link>
                    <?php
                    echo esc_html($openTotal > 0
                        ? sprintf(
                            /* translators: %d: number of open remarks */
                            _n('Fix in editor (%d note)', 'Fix in editor (%d notes)', $openTotal, 'gds-content-translation'),
                            $openTotal
                        )
                        : __('Open in editor', 'gds-content-translation'));
                ?>
                </a>
            <?php } ?>
            <hr class="wp-header-end">
            <p><a href="<?php echo esc_url($backUrl); ?>"><?php echo esc_html__('← Back to Content translation status', 'gds-content-translation'); ?></a></p>

            <?php
            if ($status['status'] === 'pending') {
                self::renderRunning($review, $languageName, $editUrl, $post->post_status === 'publish');
            } elseif ($status['status'] === 'stuck' || $status['status'] === 'failed') {
                self::renderFailed($postId, $review, $status, $editUrl);
            } else {
                self::renderDone($postId, $post, $review, $status, $openCounts);
            }
        ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private static function renderRunning(array $review, string $languageName, string $editUrl, bool $published): void
    {
        $progress = (array) ($review['progress'] ?? []);

        ?>
        <div class="notice notice-info inline gds-content-translation-review__running">
            <p>
                <span class="spinner is-active" aria-hidden="true"></span>
                <strong>
                    <?php
                    echo esc_html__('The AI review is running, usually for under a minute.', 'gds-content-translation');
        ?>
                </strong>
                <?php if (count($progress) === 2) { ?>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: part number, 2: number of parts */
                        __('Checking part %1$d of %2$d.', 'gds-content-translation'),
                        (int) $progress[0],
                        (int) $progress[1]
                    )); ?>
                <?php } ?>
            </p>
            <p><?php echo esc_html__('This page updates by itself. You can also leave it: the result shows on Content translation status.', 'gds-content-translation'); ?></p>
            <?php if ($editUrl !== '') { ?>
                <p>
                    <a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html__('Open in editor now', 'gds-content-translation'); ?></a>
                    — <?php echo esc_html__('the AI notes appear in the editor after the review, when you reload it. Changes you save before that are not reviewed.', 'gds-content-translation'); ?>
                </p>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $review
     * @param  array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}  $status
     */
    private static function renderFailed(int $postId, array $review, array $status, string $editUrl): void
    {
        $message = $status['status'] === 'stuck'
            ? __('The translation is ready, but the AI review did not finish.', 'gds-content-translation')
            : sprintf(
                /* translators: %s: error message */
                __('The translation is ready, but the AI review failed: %s', 'gds-content-translation'),
                (string) ($review['error'] ?? '')
            );

        ?>
        <div class="notice notice-error inline">
            <p><?php echo esc_html($message); ?></p>
            <p>
                <?php if (TranslationAudit::isEnabled()) { ?>
                    <a class="button button-primary" href="<?php echo esc_url(self::getReviewActionUrl($postId)); ?>"><?php echo esc_html__('Try again', 'gds-content-translation'); ?></a>
                <?php } ?>
                <?php if ($editUrl !== '') { ?>
                    <a class="button" href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html__('Open in editor', 'gds-content-translation'); ?></a>
                <?php } ?>
            </p>
        </div>
        <?php
    }

    /**
     * The remarks first: one line to mark the translation proofread, the
     * table of remarks (the script's, {@see resources/review}), and what is
     * only sometimes needed folded away: the review's summary and details,
     * and the links that still lead to the original's pages.
     *
     * @param  array<string, mixed>  $review
     * @param  array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}  $status
     * @param  array{error: int, warning: int, suggestion: int}  $openCounts
     */
    private static function renderDone(int $postId, \WP_Post $post, array $review, array $status, array $openCounts): void
    {
        $openTotal = array_sum($openCounts);
        $edited = $status['contentHash'] !== '' && TranslationAudit::contentHash($postId) !== $status['contentHash'];
        $proofread = (bool) get_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY, true);

        ?>
        <div class="gds-content-translation-review__bar">
            <label class="gds-content-translation__proofread">
                <input type="checkbox" class="gds-content-translation__proofread-input" data-post-id="<?php echo esc_attr((string) $postId); ?>" <?php checked($proofread); ?>>
                <span class="gds-content-translation__proofread-label"><?php echo esc_html__('Mark as proofread', 'gds-content-translation'); ?></span>
            </label>
            <span class="gds-content-translation-review__hint" data-gds-ct-proofread-hint><?php echo esc_html(self::proofreadHint($openCounts['error'])); ?></span>
        </div>

        <?php if ($edited) { ?>
            <div class="notice notice-warning inline"><p>
                <?php echo esc_html__('The translation was edited after this review. Remarks on texts marked “Changed since the review” may already be fixed: mark them fixed, or run the review again.', 'gds-content-translation'); ?>
            </p></div>
        <?php } ?>

        <?php if ((int) ($review['unaligned'] ?? 0) > 0) { ?>
            <div class="notice notice-info inline"><p>
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of texts */
                    _n(
                        '%d text could not be matched with the original (a block was added, removed or moved) and was not reviewed.',
                        '%d texts could not be matched with the original (blocks were added, removed or moved) and were not reviewed.',
                        (int) $review['unaligned'],
                        'gds-content-translation'
                    ),
                    (int) $review['unaligned']
                )); ?>
            </p></div>
        <?php } ?>

        <div class="gds-content-translation-review__more">
            <details class="gds-content-translation-review__about">
                <summary><?php echo esc_html__('About this review', 'gds-content-translation'); ?></summary>
                <div class="gds-content-translation-review__details">
                    <?php if ($review['summary'] !== '') { ?>
                        <p><?php echo esc_html($review['summary']); ?></p>
                    <?php } ?>
                    <p class="description">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: 1: date and time, 2: model name */
                            __('Reviewed %1$s by %2$s. Checks the content and the ACF text fields, not the title or excerpt.', 'gds-content-translation'),
                            wp_date(get_option('date_format').' '.get_option('time_format'), (int) ($review['createdAt'] ?? 0)),
                            TranslationAudit::models()[$review['model'] ?? ''] ?? ($review['model'] ?? '')
                        ));
        ?>
                    </p>
                    <?php if (TranslationAudit::isEnabled()) { ?>
                        <p>
                            <a
                                class="button"
                                href="<?php echo esc_url(self::getReviewActionUrl($postId)); ?>"
                                data-gds-ct-rerun
                                <?php if ($openTotal > 0) { ?>
                                    data-confirm="<?php echo esc_attr(self::rerunConfirm($openTotal)); ?>"
                                <?php } ?>
                            ><?php echo esc_html__('Run the review again', 'gds-content-translation'); ?></a>
                            <span class="description"><?php echo esc_html__('Reviews the current text, for example after larger edits.', 'gds-content-translation'); ?></span>
                        </p>
                    <?php } ?>
                </div>
            </details>
            <?php self::renderLinks($post); ?>
        </div>

        <?php if ($review['segments'] !== []) { ?>
            <div id="gds-ct-remarks-root" class="gds-content-translation-review__remarks" data-post-id="<?php echo esc_attr((string) $postId); ?>">
                <p class="gds-content-translation-app__loading"><span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span><?php echo esc_html__('Loading…', 'gds-content-translation'); ?></p>
                <noscript><p><?php echo esc_html__('The remarks need JavaScript.', 'gds-content-translation'); ?></p></noscript>
            </div>
        <?php } else { ?>
            <p><?php echo esc_html__('The review found no texts to check.', 'gds-content-translation'); ?></p>
        <?php } ?>
        <?php
    }

    /**
     * Under the proofread checkbox; the script says the same as it changes.
     */
    private static function proofreadHint(int $errors): string
    {
        return $errors > 0
            ? sprintf(
                /* translators: %d: number of open errors */
                _n('Fix the AI error first, or mark it as not an issue.', 'Fix the %d AI errors first, or mark them as not an issue.', $errors, 'gds-content-translation'),
                $errors
            )
            : __('Tick this once someone who knows the language has read the translation.', 'gds-content-translation');
    }

    private static function rerunConfirm(int $openTotal): string
    {
        return sprintf(
            /* translators: %d: number of open remarks */
            _n('Run the review again? Its %d open remark is replaced by the new review’s remarks.', 'Run the review again? Its %d open remarks are replaced by the new review’s remarks.', $openTotal, 'gds-content-translation'),
            $openTotal
        );
    }

    /**
     * Internal links that still lead to the original language, because the
     * page they point to has no translation yet. They switch by themselves
     * once it has one; until then, offer to create it.
     */
    private static function renderLinks(\WP_Post $post): void
    {
        $language = PLL()->model->post->get_language($post->ID);

        if (! $language || ! preg_match_all('/href="([^"#][^"]*)"/', $post->post_content, $matches)) {
            return;
        }

        $missing = [];

        foreach (array_unique($matches[1]) as $href) {
            // The site's own address: Polylang turns home_url() into the
            // admin's language (…/en), where the original's path is no page.
            $home = untrailingslashit((string) get_option('home'));
            $url = str_starts_with($href, '/') ? $home.$href : $href;

            if (! str_starts_with($url, $home)) {
                continue;
            }

            $targetId = self::postIdOfUrl($url);

            if ($targetId <= 0 || $targetId === $post->ID || ! pll_is_translated_post_type(get_post_type($targetId))) {
                continue;
            }

            $translations = pll_get_post_translations($targetId);

            if (isset($translations[$language->slug]) && get_post_status($translations[$language->slug]) !== 'trash') {
                continue;
            }

            $default = pll_default_language('slug');
            $missing[$targetId] = [
                'href' => $href,
                'sourceId' => (int) ($translations[$default] ?? $targetId),
            ];
        }

        if ($missing === []) {
            return;
        }

        $canTranslate = MachineTranslation::isAvailable();
        $languageName = StatusList::languageName($language);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $sourceName = $default ? StatusList::languageName($default) : '';

        ?>
        <details class="gds-content-translation-review__links">
            <summary><?php echo esc_html(sprintf(
                /* translators: 1: number of links, 2: language name of the original, e.g. Finnish */
                _n('%1$d link leads to a %2$s page', '%1$d links lead to %2$s pages', count($missing), 'gds-content-translation'),
                count($missing),
                $sourceName
            )); ?></summary>
            <div class="gds-content-translation-review__details">
            <p class="description"><?php echo esc_html(sprintf(
                /* translators: 1: language name of the translation, e.g. English */
                __('These pages have no %1$s version yet. Once one is created, the links on this page go to it by themselves; nothing needs to be edited.', 'gds-content-translation'),
                $languageName
            )); ?></p>
            <ul class="gds-content-translation-review__links-list">
                <?php foreach ($missing as $link) { ?>
                    <li data-gds-ct-link-row>
                        <span class="gds-content-translation-review__link-title" title="<?php echo esc_attr($link['href']); ?>"><?php echo esc_html(get_the_title($link['sourceId'])); ?></span>
                        <?php if ($canTranslate) { ?>
                            <?php // AI translates the page in the background, the way the status screen does; this row follows it.?>
                            <button
                                type="button"
                                class="button button-small gds-content-translation-review__link-translate"
                                data-source-id="<?php echo esc_attr((string) $link['sourceId']); ?>"
                                data-lang="<?php echo esc_attr($language->slug); ?>"
                                data-language-name="<?php echo esc_attr($languageName); ?>"
                            >
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: language name */
                                    __('Translate into %s', 'gds-content-translation'),
                                    $languageName
                                )); ?>
                                <span class="screen-reader-text"><?php echo esc_html(get_the_title($link['sourceId'])); ?></span>
                            </button>
                        <?php } ?>
                        <span class="gds-content-translation-review__link-status" role="status"></span>
                    </li>
                <?php } ?>
            </ul>
            </div>
        </details>
        <?php
    }

    /**
     * The post an internal URL points to, whatever language the admin bar
     * filters the admin by: with "English" chosen there, url_to_postid()
     * looks only among English posts and finds no Finnish page.
     */
    private static function postIdOfUrl(string $url): int
    {
        $polylang = PLL();
        $current = $polylang->curlang ?? null;
        $filter = property_exists($polylang, 'filter_lang') ? $polylang->filter_lang : null;
        $polylang->curlang = null;

        if (property_exists($polylang, 'filter_lang')) {
            $polylang->filter_lang = null;
        }

        try {
            return (int) url_to_postid($url);
        } finally {
            $polylang->curlang = $current;

            if (property_exists($polylang, 'filter_lang')) {
                $polylang->filter_lang = $filter;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array{error: int, warning: int, suggestion: int}
     */
    private static function openCounts(array $review): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'suggestion' => 0];

        foreach ((array) ($review['remarks'] ?? []) as $remark) {
            if (($remark['state'] ?? 'open') === 'open' && isset($counts[$remark['severity']])) {
                $counts[$remark['severity']]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $remark
     */
    private static function stateLabel(array $remark): string
    {
        if (($remark['state'] ?? '') === 'dismissed') {
            return __('Not an issue', 'gds-content-translation');
        }

        return match ($remark['resolution'] ?? '') {
            'suggestion' => __('Fixed: suggestion used', 'gds-content-translation'),
            'edited' => __('Fixed: edited', 'gds-content-translation'),
            default => __('Fixed', 'gds-content-translation'),
        };
    }

    private static function languageName(string $slug): string
    {
        $language = $slug !== '' ? PLL()->model->get_language($slug) : false;

        return $language ? StatusList::languageName($language) : $slug;
    }

    public static function severityLabel(string $severity, int $count = 1): string
    {
        return match ($severity) {
            'error' => _n('Error', 'Errors', $count, 'gds-content-translation'),
            'suggestion' => _n('Suggestion', 'Suggestions', $count, 'gds-content-translation'),
            default => _n('Warning', 'Warnings', $count, 'gds-content-translation'),
        };
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'meaning' => __('Meaning', 'gds-content-translation'),
            'omission' => __('Omission', 'gds-content-translation'),
            'addition' => __('Addition', 'gds-content-translation'),
            'terminology' => __('Terminology', 'gds-content-translation'),
            'grammar' => __('Grammar', 'gds-content-translation'),
            'fluency' => __('Fluency', 'gds-content-translation'),
            'tone' => __('Tone', 'gds-content-translation'),
            'formatting' => __('Formatting', 'gds-content-translation'),
            'untranslated' => __('Untranslated', 'gds-content-translation'),
            default => __('Other', 'gds-content-translation'),
        };
    }
}
