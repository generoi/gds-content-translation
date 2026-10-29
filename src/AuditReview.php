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

    private const remarkAction = 'gds_ct_audit_remark';

    private const statusAction = 'gds_ct_audit_status';

    /** Review page views: open remarks, every remark, every text. */
    private const views = ['open', 'remarks', 'all'];

    public static function init(): void
    {
        $review = new self;
        add_action('admin_post_'.self::reviewAction, [$review, 'handleReview']);
        add_action('admin_post_'.self::remarkAction, [$review, 'handleRemark']);
        add_action('wp_ajax_'.self::statusAction, [$review, 'handleStatus']);
    }

    public static function getUrl(int $postId, string $view = ''): string
    {
        return add_query_arg(
            array_filter([
                'page' => Admin::getPageSlug(),
                self::param => $postId,
                'view' => in_array($view, self::views, true) && $view !== 'open' ? $view : null,
            ]),
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
     * Use a suggestion, save an edit, mark a remark fixed or not an issue, or reopen it.
     */
    public function handleRemark(): void
    {
        $postId = Request::id($_POST, 'post');
        $remarkId = Request::id($_POST, 'remark');
        $operation = Request::key($_POST, 'operation');
        $view = Request::key($_POST, 'view');

        check_admin_referer(self::remarkAction.'_'.$postId);

        if ($postId <= 0 || ! current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to review this translation.', 'gds-content-translation'), 403);
        }

        $review = TranslationAudit::get($postId);
        $remark = $review ? (array_column($review['remarks'], null, 'id')[$remarkId] ?? null) : null;
        $text = isset($_POST['text']) && is_string($_POST['text']) ? wp_kses_post(wp_unslash($_POST['text'])) : '';

        $result = match ($operation) {
            'use' => $remark ? TranslationAudit::applyText($postId, $remarkId, (string) $remark['suggestion'], 'suggestion') : false,
            'edit' => TranslationAudit::applyText($postId, $remarkId, $text, 'edited'),
            'fixed' => TranslationAudit::setRemarkState($postId, $remarkId, 'resolved', true, 'manual'),
            'dismiss' => TranslationAudit::setRemarkState($postId, $remarkId, 'dismissed', true, 'not-an-issue'),
            'reopen' => TranslationAudit::setRemarkState($postId, $remarkId, 'open'),
            default => false,
        };

        if ($result instanceof \WP_Error) {
            Notice::flash('error', $result->get_error_message());
        } elseif ($result === false) {
            Notice::flash('error', __('This remark could not be found.', 'gds-content-translation'));
        }

        wp_safe_redirect(self::getUrl($postId, $view).'#gds-ct-remark-'.$remarkId);
        exit;
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
        $view = Request::key($_GET, 'view');
        $view = in_array($view, self::views, true) ? $view : 'open';
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
                <a href="<?php echo esc_url($editUrl); ?>" class="page-title-action">
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
                self::renderDone($postId, $post, $review, $status, $view, $openCounts);
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
                    echo esc_html(sprintf(
                        $published
                            /* translators: %s: language name */
                            ? __('The %s translation is published. The AI review is running, usually for under a minute.', 'gds-content-translation')
                            /* translators: %s: language name */
                            : __('The %s translation is saved as a draft. The AI review is running, usually for under a minute.', 'gds-content-translation'),
                        $languageName
                    ));
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
     * @param  array<string, mixed>  $review
     * @param  array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}  $status
     * @param  array{error: int, warning: int, suggestion: int}  $openCounts
     */
    private static function renderDone(int $postId, \WP_Post $post, array $review, array $status, string $view, array $openCounts): void
    {
        $openTotal = array_sum($openCounts);
        $edited = $status['contentHash'] !== '' && TranslationAudit::contentHash($postId) !== $status['contentHash'];
        $proofread = (bool) get_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY, true);

        ?>
        <table class="form-table gds-content-translation-review__meta" role="presentation">
            <tbody>
                <?php if ($review['summary'] !== '') { ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Summary', 'gds-content-translation'); ?></th>
                        <td><?php echo esc_html($review['summary']); ?></td>
                    </tr>
                <?php } ?>
                <tr>
                    <th scope="row"><?php echo esc_html__('Reviewed', 'gds-content-translation'); ?></th>
                    <td>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: 1: date and time, 2: model name */
                            __('%1$s by %2$s. Reviews the content and the ACF text fields, not the title or excerpt.', 'gds-content-translation'),
                            wp_date(get_option('date_format').' '.get_option('time_format'), (int) ($review['createdAt'] ?? 0)),
                            TranslationAudit::models()[$review['model'] ?? ''] ?? ($review['model'] ?? '')
                        ));
        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Proofreading', 'gds-content-translation'); ?></th>
                    <td>
                        <label class="gds-content-translation__proofread">
                            <input type="checkbox" class="gds-content-translation__proofread-input" data-post-id="<?php echo esc_attr((string) $postId); ?>" <?php checked($proofread); ?>>
                            <span class="gds-content-translation__proofread-label"><?php echo esc_html__('Mark as proofread', 'gds-content-translation'); ?></span>
                        </label>
                        <p class="description">
                            <?php
                            echo esc_html($openCounts['error'] > 0
                                ? sprintf(
                                    /* translators: %d: number of open errors */
                                    _n('Fix the AI error below first, or mark it as not an issue.', 'Fix the %d AI errors below first, or mark them as not an issue.', $openCounts['error'], 'gds-content-translation'),
                                    $openCounts['error']
                                )
                                : __('Tick this once someone who knows the language has read the translation.', 'gds-content-translation'));
        ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

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

        <?php self::renderLinks($post); ?>

        <?php if ($review['segments'] !== []) { ?>
            <p class="gds-content-translation-review__help">
                <?php echo esc_html__('Handle each remark here (use the suggestion, edit it, or mark it as not an issue), or in the editor, where each remark is a note on its block.', 'gds-content-translation'); ?>
            </p>
            <?php self::renderRemarks($postId, $post, $review, $view, $openCounts); ?>
        <?php } ?>

        <?php if (TranslationAudit::isEnabled()) { ?>
            <p class="gds-content-translation-review__rerun">
                <a
                    class="button"
                    href="<?php echo esc_url(self::getReviewActionUrl($postId)); ?>"
                    <?php if ($openTotal > 0) { ?>
                        data-confirm="<?php echo esc_attr(sprintf(
                            /* translators: %d: number of open remarks */
                            _n('Run the review again? Its %d open remark is replaced by the new review’s remarks.', 'Run the review again? Its %d open remarks are replaced by the new review’s remarks.', $openTotal, 'gds-content-translation'),
                            $openTotal
                        )); ?>"
                    <?php } ?>
                ><?php echo esc_html__('Run the review again', 'gds-content-translation'); ?></a>
                <span class="description"><?php echo esc_html__('Reviews the current text, for example after larger edits.', 'gds-content-translation'); ?></span>
            </p>
        <?php } ?>
        <?php
    }

    /**
     * @param  array<string, mixed>  $review
     * @param  array{error: int, warning: int, suggestion: int}  $openCounts
     */
    private static function renderRemarks(int $postId, \WP_Post $post, array $review, string $view, array $openCounts): void
    {
        $remarksBySegment = [];
        $closed = 0;

        foreach ($review['remarks'] as $remark) {
            $remarksBySegment[$remark['segment']][] = $remark;

            if (($remark['state'] ?? 'open') !== 'open') {
                $closed++;
            }
        }

        // What each text says now, to flag remarks whose text was changed in the editor.
        $source = get_post((int) $review['sourceId']);
        $current = $source ? TranslationAudit::currentSegments($source, $post)['segments'] : [];
        $currentById = array_column($current, 'translation', 'id');
        $openTotal = array_sum($openCounts);

        $counts = [];

        foreach (['error', 'warning', 'suggestion'] as $severity) {
            if ($openCounts[$severity] > 0) {
                $counts[] = sprintf('%d %s', $openCounts[$severity], mb_strtolower(self::severityLabel($severity, $openCounts[$severity])));
            }
        }

        $views = [
            'open' => sprintf(
                /* translators: %d: number of open remarks */
                _n('%d open remark', '%d open remarks', $openTotal, 'gds-content-translation'),
                $openTotal
            ),
            'remarks' => sprintf(
                /* translators: %d: number of remarks */
                __('All %d remarks', 'gds-content-translation'),
                count($review['remarks'])
            ),
            'all' => sprintf(
                /* translators: %d: number of texts */
                __('All %d texts', 'gds-content-translation'),
                count($review['segments'])
            ),
        ];

        $links = [];

        foreach ($views as $key => $label) {
            $links[] = $key === $view
                ? '<strong aria-current="page">'.esc_html($label).'</strong>'
                : '<a href="'.esc_url(self::getUrl($postId, $key)).'">'.esc_html($label).'</a>';
        }

        ?>
        <h2 class="gds-content-translation-review__counts">
            <?php
            echo esc_html($counts !== [] ? implode(' · ', $counts) : __('No open remarks', 'gds-content-translation'));

        if ($closed > 0) {
            echo ' <span class="gds-content-translation-review__closed">'.esc_html(sprintf(
                /* translators: %d: number of fixed or dismissed remarks */
                _n('(%d handled)', '(%d handled)', $closed, 'gds-content-translation'),
                $closed
            )).'</span>';
        }
        ?>
        </h2>
        <p class="gds-content-translation-review__filter"><?php echo implode(' | ', $links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.?></p>

        <table class="widefat gds-content-translation-review__table">
            <thead>
                <tr>
                    <th scope="col" class="gds-content-translation-review__number">#</th>
                    <th scope="col"><?php echo esc_html(sprintf(
                        /* translators: %s: language name */
                        __('Original (%s)', 'gds-content-translation'),
                        self::languageName((string) $review['sourceLanguage'])
                    )); ?></th>
                    <th scope="col"><?php echo esc_html(sprintf(
                        /* translators: %s: language name */
                        __('Translation (%s)', 'gds-content-translation'),
                        self::languageName((string) $review['targetLanguage'])
                    )); ?></th>
                    <th scope="col"><?php echo esc_html__('AI remark', 'gds-content-translation'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $shown = 0;

        foreach ($review['segments'] as $segment) {
            $remarks = $remarksBySegment[$segment['id']] ?? [];

            if ($view === 'open') {
                $remarks = array_values(array_filter($remarks, static fn (array $remark): bool => ($remark['state'] ?? 'open') === 'open'));
            }

            if ($view !== 'all' && $remarks === []) {
                continue;
            }

            $shown++;
            $changed = isset($currentById[$segment['id']]) && $currentById[$segment['id']] !== $segment['translation'];
            echo self::renderSegmentRow($postId, $segment, $remarks, $changed, $view); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
        }

        if ($shown === 0) {
            echo '<tr><td colspan="4">'.esc_html($view === 'open' ? __('All remarks are handled.', 'gds-content-translation') : __('The review found nothing to fix.', 'gds-content-translation')).'</td></tr>';
        }
        ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  list<array<string, mixed>>  $remarks
     */
    private static function renderSegmentRow(int $postId, array $segment, array $remarks, bool $changed, string $view): string
    {
        $remarkHtml = '';

        foreach ($remarks as $remark) {
            $state = (string) ($remark['state'] ?? 'open');
            $suggestion = (string) $remark['suggestion'];

            $remarkHtml .= sprintf(
                '<div id="gds-ct-remark-%1$d" class="gds-content-translation-review__remark gds-content-translation-review__remark--%2$s gds-content-translation-review__remark--%3$s"><p><span class="gds-content-translation-review__severity">%4$s</span> <span class="gds-content-translation-review__category">%5$s</span>%6$s</p><p>%7$s</p>%8$s%9$s</div>',
                (int) $remark['id'],
                esc_attr($remark['severity']),
                esc_attr($state),
                esc_html(self::severityLabel($remark['severity'])),
                esc_html(self::categoryLabel($remark['category'])),
                $state !== 'open' ? ' <span class="gds-content-translation-review__state">'.esc_html(self::stateLabel($remark)).'</span>' : '',
                esc_html($remark['issue']),
                $suggestion !== '' && $state === 'open'
                    ? '<p class="gds-content-translation-review__suggestion"><strong>'.esc_html__('Suggestion:', 'gds-content-translation').'</strong> '.TextDiff::render((string) $segment['translation'], $suggestion).'</p>'
                    : '',
                self::renderRemarkActions($postId, $segment, $remark, $changed, $view)
            );
        }

        return sprintf(
            '<tr class="%1$s"><td class="gds-content-translation-review__number">%2$s</td><td>%3$s</td><td>%4$s%5$s</td><td>%6$s</td></tr>'."\n",
            $remarks !== [] ? 'gds-content-translation-review__row--remarks' : '',
            // An ACF field says which one.
            (int) $segment['id'].(! empty($segment['label']) ? '<span class="gds-content-translation-review__field">'.esc_html($segment['label']).'</span>' : ''),
            wp_kses_post($segment['source']),
            wp_kses_post($segment['translation']),
            $changed ? '<p class="gds-content-translation-review__changed">'.esc_html__('Changed since the review', 'gds-content-translation').'</p>' : '',
            $remarkHtml !== '' ? $remarkHtml : '<span class="gds-content-translation-review__ok">'.esc_html__('No remarks', 'gds-content-translation').'</span>'
        );
    }

    /**
     * @param  array<string, mixed>  $segment
     * @param  array<string, mixed>  $remark
     */
    private static function renderRemarkActions(int $postId, array $segment, array $remark, bool $changed, string $view): string
    {
        $hidden = sprintf(
            '<input type="hidden" name="action" value="%1$s"><input type="hidden" name="post" value="%2$d"><input type="hidden" name="remark" value="%3$d"><input type="hidden" name="view" value="%4$s">%5$s',
            esc_attr(self::remarkAction),
            $postId,
            (int) $remark['id'],
            esc_attr($view),
            wp_nonce_field(self::remarkAction.'_'.$postId, '_wpnonce', true, false)
        );
        $form = static fn (string $inner, string $class = ''): string => '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="gds-content-translation-review__actions '.esc_attr($class).'">'.$hidden.$inner.'</form>';
        $button = static fn (string $operation, string $label, string $class = 'button button-small'): string => sprintf(
            '<button type="submit" name="operation" value="%1$s" class="%2$s">%3$s</button>',
            esc_attr($operation),
            esc_attr($class),
            esc_html($label)
        );

        if (($remark['state'] ?? 'open') !== 'open') {
            return $form($button('reopen', __('Reopen', 'gds-content-translation')));
        }

        // A text changed in the editor can no longer be replaced from here.
        if ($changed) {
            return $form($button('fixed', __('Mark as fixed', 'gds-content-translation'), 'button button-small button-primary').$button('dismiss', __('Not an issue', 'gds-content-translation')));
        }

        $actions = $form(
            ($remark['suggestion'] !== '' ? $button('use', __('Use suggestion', 'gds-content-translation'), 'button button-small button-primary') : '')
            .$button('dismiss', __('Not an issue', 'gds-content-translation'))
        );

        $actions .= sprintf(
            '<details class="gds-content-translation-review__edit"><summary>%1$s</summary>%2$s</details>',
            esc_html__('Edit before saving', 'gds-content-translation'),
            $form(sprintf(
                '<label class="screen-reader-text" for="gds-ct-edit-%1$d">%2$s</label><textarea id="gds-ct-edit-%1$d" name="text" rows="4" class="large-text">%3$s</textarea>%4$s',
                (int) $remark['id'],
                esc_html__('Translation', 'gds-content-translation'),
                esc_textarea((string) ($remark['suggestion'] !== '' ? $remark['suggestion'] : $segment['translation'])),
                $button('edit', __('Save to translation', 'gds-content-translation'), 'button button-small button-primary')
            ), 'is-stacked')
        );

        return $actions;
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
            $url = str_starts_with($href, '/') ? home_url($href) : $href;

            if (! str_starts_with($url, home_url())) {
                continue;
            }

            $targetId = url_to_postid($url);

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
        <div class="notice notice-warning inline gds-content-translation-review__links">
            <h3 class="gds-content-translation-review__links-heading"><?php echo esc_html(sprintf(
                /* translators: 1: number of links, 2: language name of the original, e.g. Finnish */
                _n('%1$d link still leads to the %2$s page', '%1$d links still lead to the %2$s pages', count($missing), 'gds-content-translation'),
                count($missing),
                $sourceName
            )); ?></h3>
            <p><?php echo esc_html(sprintf(
                /* translators: 1: language name of the translation, e.g. English */
                __('These pages have no %1$s version yet. Once one is created, the links on this page go to it by themselves; nothing needs to be edited.', 'gds-content-translation'),
                $languageName
            )); ?></p>
            <ul class="gds-content-translation-review__links-list">
                <?php foreach ($missing as $link) { ?>
                    <li>
                        <span title="<?php echo esc_attr($link['href']); ?>"><?php echo esc_html(get_the_title($link['sourceId'])); ?></span>
                        <?php if ($canTranslate) { ?>
                            <a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(MachineTranslation::getActionBaseUrl((string) get_post_type($link['sourceId'])).'&'.http_build_query(['source_id' => $link['sourceId'], 'lang' => $language->slug, 'mode' => MachineTranslation::modeAi], '', '&')); ?>">
                                <?php echo esc_html(sprintf(
                                    /* translators: %s: language name */
                                    __('Translate into %s', 'gds-content-translation'),
                                    $languageName
                                )); ?>
                                <span class="screen-reader-text"><?php echo esc_html__('(opens in a new tab)', 'gds-content-translation'); ?></span>
                            </a>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
        <?php
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
