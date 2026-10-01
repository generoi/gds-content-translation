<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    private const pageSlug = 'mlang_content_translation_status';

    private const postTypeUserMetaKey = 'content_translation_status_post_type';

    private const trashAction = 'gds_ct_trash_translation';

    private const restoreAction = 'gds_ct_restore_translation';

    private const deleteAction = 'gds_ct_delete_translation';

    private const trashRowAction = 'gds_ct_trash_row';

    private const restoreRowAction = 'gds_ct_restore_row';

    public static function getPageSlug(): string
    {
        return self::pageSlug;
    }

    public static function getPageUrl(string $postType = ''): string
    {
        if ($postType === '') {
            $postType = self::getSavedPostType();
        }

        return add_query_arg(
            array_filter([
                'page' => self::pageSlug,
                'gds_ct_post_type' => $postType !== '' ? $postType : null,
            ]),
            admin_url('admin.php')
        );
    }

    private static function getSavedPostType(): string
    {
        $saved = get_user_meta((int) get_current_user_id(), self::postTypeUserMetaKey, true);

        return is_string($saved) ? sanitize_key($saved) : '';
    }

    private static function savePostType(string $postType): void
    {
        if (self::getSavedPostType() !== $postType) {
            update_user_meta((int) get_current_user_id(), self::postTypeUserMetaKey, sanitize_key($postType));
        }
    }

    private static function isStatusScreen(): bool
    {
        return is_admin() && Request::key($_GET, 'page') === self::pageSlug;
    }

    private static function rowAnchor(int $sourceId): string
    {
        return 'gds-ct-row-'.$sourceId;
    }

    public static function init(): void
    {
        $admin = new self;
        $admin->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('wp_before_admin_bar_render', [$this, 'registerAdminBarMenu'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_gds_ct_save_proofread', [$this, 'saveProofread']);
        add_action('admin_post_'.self::trashAction, [$this, 'handleTranslationAction']);
        add_action('admin_post_'.self::restoreAction, [$this, 'handleTranslationAction']);
        add_action('admin_post_'.self::deleteAction, [$this, 'handleTranslationAction']);
        add_action('admin_post_'.self::trashRowAction, [$this, 'handleRowAction']);
        add_action('admin_post_'.self::restoreRowAction, [$this, 'handleRowAction']);
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    /**
     * Where to see a post on the site: its permalink once published, its
     * preview before that.
     */
    private static function getViewUrl(int $postId): string
    {
        $post = get_post($postId);

        if (! $post) {
            return '';
        }

        $url = $post->post_status === 'publish' ? get_permalink($post) : get_preview_post_link($post);

        return is_string($url) ? $url : '';
    }

    /**
     * Link that trashes, restores or permanently deletes one translation and
     * comes back here. Nonce per action and post.
     */
    private static function getTranslationActionUrl(string $action, int $postId, string $postType): string
    {
        return admin_url('admin-post.php').'?'.http_build_query([
            'action' => $action,
            'post' => $postId,
            'post_type' => $postType,
            '_wpnonce' => wp_create_nonce($action.'_'.$postId),
        ], '', '&');
    }

    /**
     * Link that trashes a source post together with all its translations.
     */
    private static function getRowTrashUrl(int $sourceId, string $postType): string
    {
        return admin_url('admin-post.php').'?'.http_build_query([
            'action' => self::trashRowAction,
            'post' => $sourceId,
            'post_type' => $postType,
            '_wpnonce' => wp_create_nonce(self::trashRowAction.'_'.$sourceId),
        ], '', '&');
    }

    /**
     * Undo for Trash all: restores exactly the posts it trashed. The nonce
     * covers the list, so it cannot be widened to other posts.
     *
     * @param  list<int>  $postIds  Sorted.
     */
    private static function getRowRestoreUrl(int $sourceId, array $postIds, string $postType): string
    {
        return admin_url('admin-post.php').'?'.http_build_query([
            'action' => self::restoreRowAction,
            'post' => $sourceId,
            'ids' => implode(',', $postIds),
            'post_type' => $postType,
            '_wpnonce' => wp_create_nonce(self::rowRestoreNonceAction($sourceId, $postIds)),
        ], '', '&');
    }

    /**
     * @param  list<int>  $postIds
     */
    private static function rowRestoreNonceAction(int $sourceId, array $postIds): string
    {
        return self::restoreRowAction.'_'.$sourceId.'_'.implode(',', $postIds);
    }

    /**
     * Back from the trash with the status it had before it was trashed, not
     * as a draft.
     */
    private static function untrash(int $postId): bool
    {
        $keepPrevious = static fn ($status, $id, $previous) => $previous ?: $status;

        add_filter('wp_untrash_post_status', $keepPrevious, 10, 3);
        $done = get_post_status($postId) === 'trash' && (bool) wp_untrash_post($postId);
        remove_filter('wp_untrash_post_status', $keepPrevious, 10);

        return $done;
    }

    /**
     * Trash, restore or permanently delete a translation from the status
     * screen. Only translations: the source-language post is never touched
     * here, so the row itself cannot disappear by accident. Permanent deletion
     * only for a translation that is already in the trash.
     */
    public function handleTranslationAction(): void
    {
        $action = substr((string) current_action(), strlen('admin_post_'));
        $postId = Request::id($_GET, 'post');
        $postType = Request::key($_GET, 'post_type');

        check_admin_referer($action.'_'.$postId);

        $returnUrl = self::getPageUrl($postType);
        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post) {
            Notice::flash('error', __('That translation no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
            wp_safe_redirect($returnUrl);
            exit;
        }

        if (! current_user_can('delete_post', $postId)) {
            wp_die(esc_html__('You are not allowed to change this translation.', 'gds-content-translation'), '', ['response' => 403, 'back_link' => true]);
        }

        $defaultSlug = pll_default_language('slug');
        $languageSlug = pll_get_post_language($postId);

        if (! $languageSlug || $languageSlug === $defaultSlug) {
            Notice::flash('error', __('Only translations can be trashed, restored or deleted here.', 'gds-content-translation'));
            wp_safe_redirect($returnUrl);
            exit;
        }

        $language = PLL()->model->get_language($languageSlug);
        $languageName = $language ? $language->name : strtoupper($languageSlug);
        $sourceId = (int) (pll_get_post_translations($postId)[$defaultSlug] ?? 0);
        $title = get_the_title($sourceId > 0 ? $sourceId : $postId);
        $title = $title !== '' ? $title : __('(no title)', 'gds-content-translation');

        if ($sourceId > 0) {
            $returnUrl .= '#'.self::rowAnchor($sourceId);
        }

        $undoUrl = '';

        if ($action === self::trashAction) {
            $done = (bool) wp_trash_post($postId);
            $undoUrl = $done ? self::getTranslationActionUrl(self::restoreAction, $postId, $postType) : '';
            /* translators: 1: post title, 2: language name. */
            $message = $done ? __('The %2$s translation of “%1$s” was moved to the trash.', 'gds-content-translation')
                : __('The %2$s translation of “%1$s” could not be moved to the trash.', 'gds-content-translation');
        } elseif ($action === self::restoreAction) {
            $done = self::untrash($postId);
            /* translators: 1: post title, 2: language name. */
            $message = $done ? __('The %2$s translation of “%1$s” was restored from the trash.', 'gds-content-translation')
                : __('The %2$s translation of “%1$s” could not be restored.', 'gds-content-translation');
        } elseif ($action === self::deleteAction) {
            if ($post->post_status !== 'trash') {
                $done = false;
                /* translators: 1: post title, 2: language name. */
                $message = __('The %2$s translation of “%1$s” is not in the trash. Move it to the trash before deleting it permanently.', 'gds-content-translation');
            } else {
                // Polylang drops it from the translation group on deletion,
                // so the cell becomes "Missing".
                $done = wp_delete_post($postId, true) instanceof \WP_Post;
                /* translators: 1: post title, 2: language name. */
                $message = $done ? __('The %2$s translation of “%1$s” was deleted permanently.', 'gds-content-translation')
                    : __('The %2$s translation of “%1$s” could not be deleted.', 'gds-content-translation');
            }
        } else {
            wp_die(esc_html__('Unknown action.', 'gds-content-translation'), '', ['response' => 400, 'back_link' => true]);
        }

        // Focus comes back to the cell the action was taken in.
        Notice::flash(
            $done ? 'success' : 'error',
            sprintf($message, $title, $languageName),
            $undoUrl,
            $undoUrl !== '' ? __('Undo', 'gds-content-translation') : '',
            $sourceId > 0 ? self::rowAnchor($sourceId) : '',
            $sourceId > 0 ? (string) $languageSlug : ''
        );

        wp_safe_redirect($returnUrl);
        exit;
    }

    /**
     * Trash all: a source post and every translation of it, in one go; and
     * its Undo. Separate from handleTranslationAction, which never touches a
     * source post. All or nothing on permissions: if any post in the group may
     * not be deleted by this user, nothing is changed.
     */
    public function handleRowAction(): void
    {
        $action = substr((string) current_action(), strlen('admin_post_'));
        $sourceId = Request::id($_GET, 'post');
        $postType = Request::key($_GET, 'post_type');
        $restoreIds = $action === self::restoreRowAction ? Request::ids($_GET, 'ids') : [];

        check_admin_referer($action === self::restoreRowAction ? self::rowRestoreNonceAction($sourceId, $restoreIds) : $action.'_'.$sourceId);

        if ($action !== self::trashRowAction && $action !== self::restoreRowAction) {
            wp_die(esc_html__('Unknown action.', 'gds-content-translation'), '', ['response' => 400, 'back_link' => true]);
        }

        $returnUrl = self::getPageUrl($postType);
        $source = $sourceId > 0 ? get_post($sourceId) : null;

        if (! $source) {
            Notice::flash('error', __('That post no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
            wp_safe_redirect($returnUrl);
            exit;
        }

        if (pll_get_post_language($sourceId) !== pll_default_language('slug')) {
            Notice::flash('error', __('Only a post in the default language can be trashed together with its translations.', 'gds-content-translation'));
            wp_safe_redirect($returnUrl);
            exit;
        }

        $title = get_the_title($sourceId);
        $title = $title !== '' ? $title : __('(no title)', 'gds-content-translation');
        $rowAnchor = self::rowAnchor($sourceId);
        $group = array_values(array_unique(array_map('intval', array_merge([$sourceId], pll_get_post_translations($sourceId)))));

        foreach ($group as $postId) {
            if (! current_user_can('delete_post', $postId)) {
                /* translators: %s: post title. */
                Notice::flash('error', sprintf(__('You are not allowed to trash every translation of “%s”, so nothing was changed.', 'gds-content-translation'), $title), '', '', $rowAnchor);
                wp_safe_redirect($returnUrl.'#'.$rowAnchor);
                exit;
            }
        }

        if ($action === self::trashRowAction) {
            // Translations first, the source last: if anything fails, the row
            // is still on the screen to try again from.
            $targets = array_values(array_filter(
                array_merge(array_diff($group, [$sourceId]), [$sourceId]),
                static fn (int $postId): bool => ! in_array(get_post_status($postId), [false, 'trash'], true)
            ));
            $changed = [];

            foreach ($targets as $postId) {
                if (wp_trash_post($postId)) {
                    $changed[] = $postId;
                }
            }

            sort($changed);
            $translations = count(array_diff($changed, [$sourceId]));

            if ($targets === []) {
                /* translators: %s: post title. */
                $message = sprintf(__('“%s” is already in the trash.', 'gds-content-translation'), $title);
            } elseif (count($changed) === count($targets)) {
                $message = $translations > 0
                    /* translators: 1: post title, 2: number of translations. */
                    ? sprintf(_n('“%1$s” and its %2$d translation were moved to the trash.', '“%1$s” and its %2$d translations were moved to the trash.', $translations, 'gds-content-translation'), $title, $translations)
                    /* translators: %s: post title. */
                    : sprintf(__('“%s” was moved to the trash.', 'gds-content-translation'), $title);
            } else {
                /* translators: 1: post title, 2: posts trashed, 3: posts to trash. */
                $message = sprintf(__('“%1$s” could not be moved to the trash completely: %2$d of %3$d posts were.', 'gds-content-translation'), $title, count($changed), count($targets));
            }

            $done = $targets !== [] && count($changed) === count($targets);
            $undoUrl = $changed !== [] ? self::getRowRestoreUrl($sourceId, $changed, $postType) : '';

            // The row is gone from the list: focus goes to the notice and its
            // Undo, unless the source is still there.
            $stillListed = get_post_status($sourceId) !== 'trash';
            Notice::flash(
                $done ? 'success' : 'error',
                $message,
                $undoUrl,
                $undoUrl !== '' ? __('Undo', 'gds-content-translation') : '',
                $stillListed ? $rowAnchor : ''
            );
            wp_safe_redirect($returnUrl.($stillListed ? '#'.$rowAnchor : ''));
            exit;
        }

        // Undo: only what Trash all took, while it is still in the trash and
        // still part of this post's translations.
        $targets = array_values(array_filter($restoreIds, static fn (int $postId): bool => in_array($postId, $group, true) && get_post_status($postId) === 'trash'));
        $changed = [];

        foreach ($targets as $postId) {
            if (self::untrash($postId)) {
                $changed[] = $postId;
            }
        }

        $translations = count(array_diff($changed, [$sourceId]));

        if ($targets === []) {
            /* translators: %s: post title. */
            $message = sprintf(__('Nothing of “%s” is in the trash any more.', 'gds-content-translation'), $title);
        } elseif (count($changed) === count($targets)) {
            $message = $translations > 0
                /* translators: 1: post title, 2: number of translations. */
                ? sprintf(_n('“%1$s” and its %2$d translation were restored from the trash.', '“%1$s” and its %2$d translations were restored from the trash.', $translations, 'gds-content-translation'), $title, $translations)
                /* translators: %s: post title. */
                : sprintf(__('“%s” was restored from the trash.', 'gds-content-translation'), $title);
        } else {
            /* translators: 1: post title, 2: posts restored, 3: posts to restore. */
            $message = sprintf(__('“%1$s” could not be restored completely: %2$d of %3$d posts were.', 'gds-content-translation'), $title, count($changed), count($targets));
        }

        $stillListed = get_post_status($sourceId) !== 'trash';
        Notice::flash(
            $targets !== [] && count($changed) === count($targets) ? 'success' : 'error',
            $message,
            '',
            '',
            $stillListed ? $rowAnchor : ''
        );
        wp_safe_redirect($returnUrl.($stillListed ? '#'.$rowAnchor : ''));
        exit;
    }

    public function renderNotices(): void
    {
        if (self::isStatusScreen()) {
            Notice::render();
        }
    }

    public function registerMenu(): void
    {
        if (! function_exists('PLL') || ! PLL()->model->has_languages()) {
            return;
        }

        add_submenu_page(
            'mlang',
            __('Content translation status', 'gds-content-translation'),
            __('Content translation status', 'gds-content-translation'),
            'manage_options',
            self::pageSlug,
            [$this, 'renderPage']
        );
    }

    public function registerAdminBarMenu(): void
    {
        global $wp_admin_bar;

        if (! $wp_admin_bar instanceof \WP_Admin_Bar) {
            return;
        }

        if (! is_user_logged_in() || ! current_user_can('manage_options')) {
            return;
        }

        if (! function_exists('PLL') || ! PLL()->model->has_languages()) {
            return;
        }

        $nodeId = 'gds-content-translation';

        $wp_admin_bar->add_node([
            'id' => $nodeId,
            'title' => $this->getAdminBarTitle(),
            'href' => self::getPageUrl($this->resolveAdminBarPostType()),
            'meta' => [
                'title' => esc_attr__('Content translation status', 'gds-content-translation'),
                'class' => 'gds-content-translation-admin-bar',
            ],
        ]);

        if ($wp_admin_bar->get_node('gform-forms')) {
            $this->placeAdminBarNodeAfter($wp_admin_bar, $nodeId, 'gform-forms');
        }
    }

    private function placeAdminBarNodeAfter(\WP_Admin_Bar $adminBar, string $nodeId, string $afterId): void
    {
        $reflection = new \ReflectionObject($adminBar);
        $property = $reflection->getProperty('nodes');
        $property->setAccessible(true);
        $nodes = $property->getValue($adminBar);

        if (! is_array($nodes) || ! isset($nodes[$nodeId], $nodes[$afterId])) {
            return;
        }

        $node = $nodes[$nodeId];
        unset($nodes[$nodeId]);

        $orderedNodes = [];

        foreach ($nodes as $id => $item) {
            $orderedNodes[$id] = $item;

            if ($id === $afterId) {
                $orderedNodes[$nodeId] = $node;
            }
        }

        $property->setValue($adminBar, $orderedNodes);
    }

    private function getAdminBarTitle(): string
    {
        // Core's admin bar styles size an .ab-icon dashicon; no stylesheet of
        // our own needed (and none loaded on every page an admin views).
        return sprintf(
            '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label">%s</span>',
            esc_html__('Translation status', 'gds-content-translation')
        );
    }

    private function resolveAdminBarPostType(): string
    {
        global $post;

        $visiblePostTypes = Settings::getVisiblePostTypeSlugs();

        if ($post instanceof \WP_Post && in_array($post->post_type, $visiblePostTypes, true)) {
            return $post->post_type;
        }

        if (is_admin()) {
            $postType = Request::key($_GET, 'post_type');

            if ($postType !== '' && in_array($postType, $visiblePostTypes, true)) {
                return $postType;
            }
        }

        $savedPostType = self::getSavedPostType();

        if ($savedPostType !== '' && in_array($savedPostType, $visiblePostTypes, true)) {
            return $savedPostType;
        }

        return $visiblePostTypes[0] ?? '';
    }

    /**
     * Version an asset by its file as well as the plugin version, so an update
     * that changes it can never be served from a stale browser or CDN cache.
     */
    private static function assetVersion(string $relativePath): string
    {
        $mtime = @filemtime(GDS_CONTENT_TRANSLATION_PATH.'/'.$relativePath);

        return GDS_CONTENT_TRANSLATION_VERSION.($mtime ? '.'.$mtime : '');
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'languages_page_'.self::pageSlug && $hookSuffix !== 'languages_page_mlang_content_translation_settings') {
            return;
        }

        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'gds-content-translation',
            plugins_url('assets/admin.css', GDS_CONTENT_TRANSLATION_FILE),
            ['dashicons'],
            self::assetVersion('assets/admin.css')
        );

        wp_enqueue_script(
            'gds-content-translation',
            plugins_url('assets/admin.js', GDS_CONTENT_TRANSLATION_FILE),
            [],
            self::assetVersion('assets/admin.js'),
            true
        );

        wp_localize_script('gds-content-translation', 'contentTranslationStatus', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gds_content_translation'),
            'creating' => __('Creating…', 'gds-content-translation'),
            /* translators: 1: language name, 2: post title. */
            'creatingAnnounce' => __('Creating the %1$s translation of “%2$s” in a new tab.', 'gds-content-translation'),
            'proofread' => [
                'checked' => __('Marked as proof read.', 'gds-content-translation'),
                'unchecked' => __('Marked as not proof read.', 'gds-content-translation'),
                'failed' => __('Could not save. Try again.', 'gds-content-translation'),
                'expired' => __('Your session has expired. Reload the page and try again.', 'gds-content-translation'),
            ],
            'search' => [
                'noMatches' => __('No matches', 'gds-content-translation'),
                'matchCount' => __('1 match', 'gds-content-translation'),
                /* translators: %d: number of matching rows */
                'matchCountPlural' => __('%d matches', 'gds-content-translation'),
                /* translators: 1: visible count, 2: total count */
                'matchCountFiltered' => __('%1$d of %2$d', 'gds-content-translation'),
            ],
        ]);
    }

    public function saveProofread(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'gds-content-translation')], 403);
        }

        check_ajax_referer('gds_content_translation', 'nonce');

        $postId = Request::id($_POST, 'postId');
        $proofread = ($_POST['proofread'] ?? '') === '1';

        if ($postId <= 0 || ! get_post($postId)) {
            wp_send_json_error(['message' => __('Invalid post.', 'gds-content-translation')], 400);
        }

        // Proof reading is about translations; the screen offers it for
        // nothing else.
        $language = pll_get_post_language($postId);

        if (! $language || $language === pll_default_language('slug')) {
            wp_send_json_error(['message' => __('Only translations can be marked as proof read.', 'gds-content-translation')], 400);
        }

        if ($proofread) {
            update_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY, '1');
        } else {
            delete_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY);
        }

        wp_send_json_success(['proofread' => $proofread]);
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $postTypes = $this->getTranslatablePostTypes();
        $selectedPostType = Request::key($_GET, 'gds_ct_post_type');

        if ($selectedPostType === '') {
            $selectedPostType = self::getSavedPostType();
        }

        if ($selectedPostType === '' || ! isset($postTypes[$selectedPostType])) {
            $selectedPostType = (string) (array_key_first($postTypes) ?? '');
        }

        if ($selectedPostType !== '') {
            self::savePostType($selectedPostType);
        }

        $languages = $this->getLanguages();
        $rows = $selectedPostType !== '' ? $this->getRows($selectedPostType, $languages) : [];
        $summary = StatusSummary::build(
            $rows,
            $languages,
            $selectedPostType !== '' ? ($postTypes[$selectedPostType]['label'] ?? '') : ''
        );
        $translationLanguages = array_values(array_filter($languages, static fn (array $language): bool => ! $language['isDefault']));
        $machineTranslationAvailable = MachineTranslation::isAvailable();
        $copyTranslationAvailable = MachineTranslation::isCopyAvailable();
        $sourceLanguageName = $summary['defaultLanguageName'];

        ?>
        <div class="wrap gds-content-translation">
            <h1>
                <?php echo esc_html__('Content translation status', 'gds-content-translation'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mlang_content_translation_settings')); ?>" class="page-title-action">
                    <?php echo esc_html__('Settings', 'gds-content-translation'); ?>
                </a>
            </h1>
            <hr class="wp-header-end">

            <?php if (empty($postTypes)) { ?>
                <p><?php echo esc_html__('No translatable post types are configured in Polylang.', 'gds-content-translation'); ?></p>
            <?php } else { ?>
                <nav
                    class="gds-content-translation__tabs nav-tab-wrapper"
                    aria-label="<?php echo esc_attr__('Post type', 'gds-content-translation'); ?>"
                >
                    <?php foreach ($postTypes as $slug => $postType) { ?>
                        <a
                            href="<?php echo esc_url(self::getPageUrl($slug)); ?>"
                            class="nav-tab<?php echo $slug === $selectedPostType ? ' nav-tab-active' : ''; ?>"
                            <?php echo $slug === $selectedPostType ? 'aria-current="page"' : ''; ?>
                        >
                            <?php echo self::renderPostTypeIcon($postType['icon']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
                            <span><?php echo esc_html($postType['label']); ?></span>
                        </a>
                    <?php } ?>
                </nav>

                <?php if (empty($rows)) { ?>
                    <p><?php echo esc_html__('No posts found for this post type.', 'gds-content-translation'); ?></p>
                <?php } else { ?>
                    <?php $this->renderSummary($summary, $machineTranslationAvailable, $copyTranslationAvailable); ?>

                    <div class="gds-content-translation__search">
                        <label class="screen-reader-text" for="gds-content-translation-search">
                            <?php echo esc_html__('Search titles', 'gds-content-translation'); ?>
                        </label>
                        <span class="dashicons dashicons-search gds-content-translation__search-icon" aria-hidden="true"></span>
                        <input
                            type="search"
                            id="gds-content-translation-search"
                            class="gds-content-translation__search-input"
                            placeholder="<?php echo esc_attr__('Search titles…', 'gds-content-translation'); ?>"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button
                            type="button"
                            class="gds-content-translation__search-clear button-link"
                            hidden
                        >
                            <?php echo esc_html__('Clear', 'gds-content-translation'); ?>
                            <span class="screen-reader-text"><?php echo esc_html__('search and filter', 'gds-content-translation'); ?></span>
                        </button>
                        <span
                            class="gds-content-translation__search-status"
                            aria-live="polite"
                            aria-atomic="true"
                        ></span>
                    </div>

                    <table class="widefat striped gds-content-translation__table">
                        <thead>
                            <tr>
                                <th scope="col" class="gds-content-translation__title-heading">
                                    <?php echo esc_html__('Title', 'gds-content-translation'); ?>
                                    <?php if ($sourceLanguageName !== '') { ?>
                                        <span class="gds-content-translation__source-language">(<?php echo esc_html($sourceLanguageName); ?>)</span>
                                    <?php } ?>
                                </th>
                                <?php foreach ($translationLanguages as $language) { ?>
                                    <th scope="col" data-lang="<?php echo esc_attr($language['slug']); ?>"><?php echo esc_html($language['name']); ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Built as compact strings: the template's own
                            // indentation was two thirds of a 6 MB page.
                            $context = $this->getRowContext(
                                $selectedPostType,
                                $translationLanguages,
                                $sourceLanguageName,
                                $machineTranslationAvailable,
                                $copyTranslationAvailable
                            );

                    foreach ($rows as $row) {
                        echo $this->renderRow($row, $context); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building.
                    }
                    ?>
                            <tr class="gds-content-translation__search-empty" hidden>
                                <td colspan="<?php echo esc_attr((string) (count($translationLanguages) + 1)); ?>">
                                    <?php echo esc_html__('No titles match your search.', 'gds-content-translation'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="screen-reader-text" aria-live="polite" aria-atomic="true" data-gds-ct-live></div>
                    <canvas class="gds-content-translation__celebration-canvas" width="0" height="0" aria-hidden="true"></canvas>
                <?php } ?>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * @param  array{total: int, postTypeLabel: string, defaultLanguageName: string, missing: list<array{name: string, slug: string, count: int}>, notProofread: list<array{name: string, slug: string, count: int}>}  $summary
     */
    private function renderSummary(array $summary, bool $machineTranslationAvailable, bool $copyTranslationAvailable): void
    {
        $filterButton = static function (string $filter, array $item, string $class): string {
            $text = sprintf(
                /* translators: 1: language name, 2: count */
                __('%1$s %2$d', 'gds-content-translation'),
                $item['name'],
                $item['count']
            );

            if ($item['count'] <= 0) {
                return sprintf('<span class="gds-content-translation__summary-stat">%s</span>', esc_html($text));
            }

            return sprintf(
                '<button type="button" class="button-link gds-content-translation__filter %1$s" data-filter="%2$s" data-lang="%3$s" aria-pressed="false" aria-describedby="gds-ct-filter-hint">%4$s</button>',
                esc_attr($class),
                esc_attr($filter),
                esc_attr($item['slug']),
                esc_html($text)
            );
        };

        $separator = '<span class="gds-content-translation__sep" aria-hidden="true">·</span>';
        ?>
        <div class="gds-content-translation__summary">
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: total count, 2: post type label, 3: default language name */
                    __('%1$d primary %2$s in %3$s.', 'gds-content-translation'),
                    $summary['total'],
                    function_exists('mb_strtolower') ? mb_strtolower($summary['postTypeLabel']) : strtolower($summary['postTypeLabel']),
                    $summary['defaultLanguageName']
                ));
        ?>
            </p>
            <p>
                <?php echo esc_html__('Missing translation:', 'gds-content-translation'); ?>
                <?php echo implode($separator, array_map(static fn (array $item): string => $filterButton('missing', $item, 'gds-content-translation__summary-stat--missing'), $summary['missing'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
            </p>
            <?php if (! empty($summary['notProofread'])) { ?>
                <p>
                    <?php echo esc_html__('Not proof read:', 'gds-content-translation'); ?>
                    <?php echo implode($separator, array_map(static fn (array $item): string => $filterButton('unproofread', $item, ''), $summary['notProofread'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
                </p>
            <?php } ?>
            <p class="screen-reader-text" id="gds-ct-filter-hint"><?php echo esc_html__('Shows only these rows. Select again to show all.', 'gds-content-translation'); ?></p>
            <?php if ($machineTranslationAvailable || $copyTranslationAvailable) { ?>
                <p class="description">
                    <?php
            if ($copyTranslationAvailable) {
                echo esc_html(sprintf(
                    /* translators: %s: source language name */
                    __('Copy original creates the translation as an untranslated copy of the %s content.', 'gds-content-translation'),
                    $summary['defaultLanguageName']
                )).' ';
            }

                if ($machineTranslationAvailable) {
                    echo esc_html__('AI translation machine-translates it.', 'gds-content-translation').' ';
                }

                echo esc_html__('Both create a draft and open it in a new tab.', 'gds-content-translation');
                ?>
                </p>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * Everything a row needs that is the same for every row, worked out once.
     *
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $translationLanguages
     * @return array<string, mixed>
     */
    private function getRowContext(string $postType, array $translationLanguages, string $sourceLanguageName, bool $machineTranslationAvailable, bool $copyTranslationAvailable): array
    {
        $postTypeObject = get_post_type_object($postType);

        // One capability check for the screen instead of map_meta_cap per
        // cell. Falls back to the per-post check for anyone who cannot delete
        // every post of the type. The handlers check per post regardless.
        $canDeleteAll = $postTypeObject
            && $postTypeObject->map_meta_cap
            && current_user_can($postTypeObject->cap->delete_posts)
            && current_user_can($postTypeObject->cap->delete_others_posts)
            && current_user_can($postTypeObject->cap->delete_published_posts)
            && current_user_can($postTypeObject->cap->delete_private_posts);

        // The opening tag carries the language name for the stacked phone
        // layout, where there is no column heading to read it from.
        foreach ($translationLanguages as $index => $language) {
            $translationLanguages[$index]['cellOpen'] = sprintf('<td class="gds-content-translation__lang" data-lang-name="%s">', esc_attr($language['name']));
        }

        return [
            'postType' => $postType,
            'languages' => $translationLanguages,
            'viewable' => is_post_type_viewable($postType),
            'canDeleteAll' => $canDeleteAll,
            'ai' => $machineTranslationAvailable,
            'copy' => $copyTranslationAvailable,
            'createBaseUrl' => MachineTranslation::getActionBaseUrl($postType, MachineTranslation::createNonce()),
            'newTab' => __('(opens in a new tab)', 'gds-content-translation'),
            /* translators: %s: source language name */
            'editSource' => sprintf(__('edit the %s original', 'gds-content-translation'), $sourceLanguageName),
        ];
    }

    private function canDelete(int $postId, array $context): bool
    {
        return $context['canDeleteAll'] || current_user_can('delete_post', $postId);
    }

    /**
     * @param  array{sourceId: int, title: string, openNotes: int, languages: array<string, array{postId: int, trashed: bool, proofread: bool, openNotes: int}>}  $row
     * @param  array<string, mixed>  $context
     */
    private function renderRow(array $row, array $context): string
    {
        $missing = [];
        $unproofread = [];
        $cells = '';

        foreach ($context['languages'] as $language) {
            $cell = $row['languages'][$language['slug']] ?? null;
            /* translators: 1: language name, 2: post title. */
            $about = sprintf(__('the %1$s translation of “%2$s”', 'gds-content-translation'), $language['name'], $row['title']);

            if ($cell === null || $cell['trashed']) {
                $missing[] = $language['slug'];
                $cells .= $language['cellOpen'].$this->renderMissingCell($row, $language, $cell, $about, $context).'</td>';

                continue;
            }

            if (! $cell['proofread']) {
                $unproofread[] = $language['slug'];
            }

            $cells .= $language['cellOpen'].$this->renderTranslationCell($row, $language, $cell, $about, $context).'</td>';
        }

        return sprintf(
            '<tr id="%1$s" data-search-title="%2$s" data-missing="%3$s" data-unproofread="%4$s">%5$s%6$s</tr>'."\n",
            esc_attr(self::rowAnchor($row['sourceId'])),
            esc_attr($row['title']),
            esc_attr(implode(' ', $missing)),
            esc_attr(implode(' ', $unproofread)),
            $this->renderTitleCell($row, $context),
            $cells
        );
    }

    /**
     * The source post: its title opens it in the editor, with View and its
     * open notes beside it. It has no column of its own.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     */
    private function renderTitleCell(array $row, array $context): string
    {
        $editUrl = get_edit_post_link($row['sourceId'], 'raw');
        $title = esc_html($row['title']);

        $link = is_string($editUrl) && $editUrl !== ''
            ? sprintf(
                '<a class="gds-content-translation__title-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> (%3$s) %4$s</span></a>',
                esc_url($editUrl),
                $title,
                esc_html($context['editSource']),
                esc_html($context['newTab'])
            )
            : '<span class="gds-content-translation__title-link">'.$title.'</span>';

        $meta = '';
        $viewUrl = $context['viewable'] ? self::getViewUrl($row['sourceId']) : '';

        if ($viewUrl !== '') {
            $meta .= sprintf(
                '<a class="gds-content-translation__edit gds-content-translation__view" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> “%3$s” %4$s</span></a>',
                esc_url($viewUrl),
                esc_html__('View', 'gds-content-translation'),
                $title,
                esc_html($context['newTab'])
            );
        }

        $meta .= $this->renderNotesIndicator((string) $editUrl, $row['openNotes']);
        $meta .= $this->renderRowTrash($row, $context);

        return sprintf(
            '<th scope="row" class="gds-content-translation__title"><div class="gds-content-translation__title-cell">%1$s%2$s</div></th>',
            $link,
            $meta !== '' ? '<span class="gds-content-translation__source-actions">'.$meta.'</span>' : ''
        );
    }

    /**
     * Trash all: the source and every translation of it. Only offered when
     * this user may delete every one of them; the handler checks again.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     */
    private function renderRowTrash(array $row, array $context): string
    {
        if (! $this->canDelete($row['sourceId'], $context)) {
            return '';
        }

        // Counted for the confirmation: the ones not in the trash already.
        $translations = 0;

        // The row's languages include the source language's own cell.
        foreach ($row['languages'] as $cell) {
            if ($cell['postId'] === $row['sourceId']) {
                continue;
            }

            if (! $this->canDelete($cell['postId'], $context)) {
                return '';
            }

            $translations += $cell['trashed'] ? 0 : 1;
        }

        $question = $translations > 0
            /* translators: 1: post title, 2: number of translations. */
            ? sprintf(_n('Move "%1$s" and its %2$d translation to the trash?', 'Move "%1$s" and its %2$d translations to the trash?', $translations, 'gds-content-translation'), $row['title'], $translations)
            /* translators: %s: post title. */
            : sprintf(__('Move "%s" to the trash?', 'gds-content-translation'), $row['title']);

        return sprintf(
            '<a class="gds-content-translation__trash gds-content-translation__trash-row" href="%1$s" data-confirm="%2$s">%3$s<span class="screen-reader-text"> %4$s</span></a>',
            esc_url(self::getRowTrashUrl($row['sourceId'], $context['postType'])),
            esc_attr($question),
            esc_html__('Trash all', 'gds-content-translation'),
            /* translators: %s: post title. */
            esc_html(sprintf(__('“%s” and its translations', 'gds-content-translation'), $row['title']))
        );
    }

    /**
     * An existing translation: status, Edit and View on the first line; the
     * proof-read toggle and Trash, kept apart from them, on the second.
     *
     * @param  array<string, mixed>  $row
     * @param  array{slug: string, name: string, isDefault: bool}  $language
     * @param  array{postId: int, trashed: bool, proofread: bool, openNotes: int}  $cell
     * @param  array<string, mixed>  $context
     */
    private function renderTranslationCell(array $row, array $language, array $cell, string $about, array $context): string
    {
        $postId = $cell['postId'];
        $aboutHtml = esc_html($about);
        $newTab = esc_html($context['newTab']);

        $main = sprintf(
            '<span class="gds-content-translation__badge gds-content-translation__badge--exists" aria-hidden="true">✓</span><span class="screen-reader-text">%s</span>',
            esc_html__('Translated:', 'gds-content-translation')
        );

        $editUrl = get_edit_post_link($postId, 'raw');

        if (is_string($editUrl) && $editUrl !== '') {
            $main .= sprintf(
                '<a class="gds-content-translation__edit" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s %4$s</span></a>',
                esc_url($editUrl),
                esc_html__('Edit', 'gds-content-translation'),
                $aboutHtml,
                $newTab
            );
        }

        $viewUrl = $context['viewable'] ? self::getViewUrl($postId) : '';

        if ($viewUrl !== '') {
            $main .= sprintf(
                '<a class="gds-content-translation__edit gds-content-translation__view" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s %4$s</span></a>',
                esc_url($viewUrl),
                esc_html__('View', 'gds-content-translation'),
                $aboutHtml,
                $newTab
            );
        }

        $main .= $this->renderNotesIndicator((string) $editUrl, $cell['openNotes']);

        $meta = sprintf(
            '<label class="gds-content-translation__proofread"><input type="checkbox" class="gds-content-translation__proofread-input" data-post-id="%1$d"%2$s><span class="gds-content-translation__proofread-label">%3$s<span class="screen-reader-text"> %4$s</span></span></label>',
            $postId,
            $cell['proofread'] ? ' checked' : '',
            esc_html__('Proof read', 'gds-content-translation'),
            $aboutHtml
        );

        if ($this->canDelete($postId, $context)) {
            $meta .= sprintf(
                '<a class="gds-content-translation__trash" href="%1$s" data-confirm="%2$s">%3$s<span class="screen-reader-text"> %4$s</span></a>',
                esc_url(self::getTranslationActionUrl(self::trashAction, $postId, $context['postType'])),
                esc_attr(sprintf(
                    /* translators: 1: post title, 2: language name. */
                    __('Move the %2$s translation of "%1$s" to the trash?', 'gds-content-translation'),
                    $row['title'],
                    $language['name']
                )),
                esc_html__('Trash', 'gds-content-translation'),
                $aboutHtml
            );
        }

        return '<div class="gds-content-translation__cell"><div class="gds-content-translation__cell-main">'.$main.'</div><div class="gds-content-translation__cell-meta">'.$meta.'</div></div>';
    }

    /**
     * A missing translation, or one in the trash. Both can be created anew;
     * a trashed one can also be restored or deleted permanently.
     *
     * @param  array<string, mixed>  $row
     * @param  array{slug: string, name: string, isDefault: bool}  $language
     * @param  array{postId: int, trashed: bool, proofread: bool, openNotes: int}|null  $cell
     * @param  array<string, mixed>  $context
     */
    private function renderMissingCell(array $row, array $language, ?array $cell, string $about, array $context): string
    {
        $trashed = $cell !== null;
        $aboutHtml = esc_html($about);
        $actions = [];

        $badge = $trashed
            ? sprintf('<span class="gds-content-translation__badge gds-content-translation__badge--trashed"><span class="dashicons dashicons-trash" aria-hidden="true"></span>%s</span>', esc_html__('In trash', 'gds-content-translation'))
            : sprintf('<span class="gds-content-translation__badge gds-content-translation__badge--missing">%s</span>', esc_html__('Missing', 'gds-content-translation'));

        $canChangeTrashed = $trashed && $this->canDelete($cell['postId'], $context);

        // Restore first: it is the non-destructive way back.
        if ($canChangeTrashed) {
            $actions[] = sprintf(
                '<a class="button button-small gds-content-translation__translate gds-content-translation__restore" href="%1$s"><span class="dashicons dashicons-undo gds-content-translation__translate-icon" aria-hidden="true"></span>%2$s<span class="screen-reader-text"> %3$s</span></a>',
                esc_url(self::getTranslationActionUrl(self::restoreAction, $cell['postId'], $context['postType'])),
                esc_html__('Restore', 'gds-content-translation'),
                $aboutHtml
            );
        }

        // Beside Restore, creating anew is the secondary choice and looks it.
        $createClass = $trashed ? 'button-link gds-content-translation__translate gds-content-translation__translate--link' : 'button button-small gds-content-translation__translate';
        $createUrl = $context['createBaseUrl'].'&'.http_build_query(['source_id' => $row['sourceId'], 'lang' => $language['slug']], '', '&');

        foreach ([
            MachineTranslation::modeAi => [$context['ai'], 'dashicons-translation', __('AI translation', 'gds-content-translation')],
            MachineTranslation::modeCopy => [$context['copy'], 'dashicons-admin-page', __('Copy original', 'gds-content-translation')],
        ] as $mode => [$available, $icon, $label]) {
            if (! $available) {
                continue;
            }

            $actions[] = sprintf(
                '<a class="%1$s" target="_blank" rel="noopener noreferrer" href="%2$s"><span class="dashicons %3$s gds-content-translation__translate-icon" aria-hidden="true"></span>%4$s<span class="screen-reader-text"> %5$s: %6$s %7$s</span></a>',
                esc_attr($createClass),
                esc_url($createUrl.'&mode='.$mode),
                esc_attr($icon),
                esc_html($label),
                esc_html__('create', 'gds-content-translation'),
                $aboutHtml,
                esc_html($context['newTab'])
            );
        }

        // Last, apart from the rest, and only once it is already in the trash.
        if ($canChangeTrashed) {
            $actions[] = sprintf(
                '<a class="gds-content-translation__delete" href="%1$s" data-confirm="%2$s">%3$s<span class="screen-reader-text"> %4$s</span></a>',
                esc_url(self::getTranslationActionUrl(self::deleteAction, $cell['postId'], $context['postType'])),
                esc_attr(sprintf(
                    /* translators: 1: post title, 2: language name. */
                    __('Permanently delete the %2$s translation of "%1$s"? This cannot be undone.', 'gds-content-translation'),
                    $row['title'],
                    $language['name']
                )),
                esc_html__('Delete permanently', 'gds-content-translation'),
                $aboutHtml
            );
        }

        return sprintf(
            '<div class="gds-content-translation__missing-cell%1$s">%2$s%3$s</div>',
            $trashed ? ' gds-content-translation__missing-cell--trashed' : '',
            $badge,
            $actions !== [] ? '<span class="gds-content-translation__translate-actions">'.implode('', $actions).'</span>' : ''
        );
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    private function getTranslatablePostTypes(): array
    {
        $postTypes = Settings::getAllPostTypes();
        $hiddenPostTypes = Settings::getHiddenPostTypes();

        foreach ($hiddenPostTypes as $slug) {
            unset($postTypes[$slug]);
        }

        return $postTypes;
    }

    private static function renderPostTypeIcon(string $menuIcon): string
    {
        if ($menuIcon === '' || $menuIcon === 'none') {
            return '<span class="dashicons dashicons-admin-post" aria-hidden="true"></span>';
        }

        if (str_starts_with($menuIcon, 'dashicons-')) {
            return sprintf(
                '<span class="dashicons %s" aria-hidden="true"></span>',
                esc_attr($menuIcon)
            );
        }

        if (str_starts_with($menuIcon, 'data:') || filter_var($menuIcon, FILTER_VALIDATE_URL)) {
            return sprintf(
                '<img src="%s" alt="" class="gds-content-translation__tab-icon" aria-hidden="true" />',
                esc_url($menuIcon)
            );
        }

        return sprintf(
            '<span class="dashicons dashicons-%s" aria-hidden="true"></span>',
            esc_attr(sanitize_html_class($menuIcon))
        );
    }

    /**
     * @return list<array{slug: string, name: string, isDefault: bool}>
     */
    private function getLanguages(): array
    {
        $defaultSlug = pll_default_language('slug');
        $languages = [];

        foreach (pll_languages_list() as $slug) {
            $language = PLL()->model->get_language($slug);

            if (! $language) {
                continue;
            }

            $languages[] = [
                'slug' => $language->slug,
                'name' => $language->name,
                'isDefault' => $language->slug === $defaultSlug,
            ];
        }

        return $languages;
    }

    /**
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $languages
     * @return list<array{sourceId: int, title: string, openNotes: int, languages: array<string, array{postId: int, trashed: bool, proofread: bool, openNotes: int}>}>
     */
    private function getRows(string $postType, array $languages): array
    {
        $defaultSlug = pll_default_language('slug');

        if (! $defaultSlug) {
            return [];
        }

        // suppress_filters stays false: sites narrow this list with
        // pre_get_posts (only header and footer of the template parts, say).
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'lang' => $defaultSlug,
            'suppress_filters' => false,
            // Nothing here reads the sources' meta; on a large catalogue it was
            // tens of thousands of rows. Term caches stay: Polylang needs them.
            'update_post_meta_cache' => false,
            'no_found_rows' => true,
        ]);

        $translationsBySource = [];
        $sourceIds = [];
        $translationIds = [];

        foreach ($posts as $post) {
            $sourceId = (int) $post->ID;
            $sourceIds[] = $sourceId;
            $translationsBySource[$sourceId] = array_map('intval', pll_get_post_translations($sourceId));

            foreach ($translationsBySource[$sourceId] as $translationId) {
                if ($translationId !== $sourceId) {
                    $translationIds[] = $translationId;
                }
            }
        }

        // One query each for the translations' rows and their language terms,
        // instead of three per translation (status, meta, permalink language).
        $translationIds = array_values(array_unique($translationIds));

        if ($translationIds !== []) {
            _prime_post_caches($translationIds, true, false);
        }

        $allIds = array_merge($sourceIds, $translationIds);
        $openNoteCounts = $this->getOpenNoteCountsByPostId($allIds);
        $proofread = $this->getProofreadPostIds($translationIds);
        $rows = [];

        foreach ($posts as $post) {
            $sourceId = (int) $post->ID;
            $languageCells = [];

            foreach ($languages as $language) {
                $translationId = $translationsBySource[$sourceId][$language['slug']] ?? 0;
                $translation = $translationId > 0 ? get_post($translationId) : null;

                if (! $translation) {
                    continue;
                }

                $languageCells[$language['slug']] = [
                    'postId' => $translationId,
                    // Polylang keeps a trashed translation linked. It is not a
                    // translation anyone can read, so it counts as missing.
                    'trashed' => $translation->post_status === 'trash',
                    'proofread' => isset($proofread[$translationId]),
                    'openNotes' => $openNoteCounts[$translationId] ?? 0,
                ];
            }

            $rows[] = [
                'sourceId' => $sourceId,
                'title' => $post->post_title !== '' ? $post->post_title : __('(no title)', 'gds-content-translation'),
                'openNotes' => $openNoteCounts[$sourceId] ?? 0,
                'languages' => $languageCells,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, true>
     */
    private function getProofreadPostIds(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($postIds), '%d'));
        $sql = "
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s
              AND meta_value NOT IN ('', '0')
              AND post_id IN ({$placeholders})
        ";

        $ids = $wpdb->get_col($wpdb->prepare($sql, GDS_CONTENT_TRANSLATION_META_KEY, ...$postIds));

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    private function getOpenNoteCountsByPostId(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($postIds), '%d'));
        $sql = "
            SELECT comment_post_ID, COUNT(*) AS note_count
            FROM {$wpdb->comments}
            WHERE comment_type = 'note'
              AND comment_approved = '0'
              AND comment_parent = 0
              AND comment_post_ID IN ({$placeholders})
            GROUP BY comment_post_ID
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$postIds));
        $counts = [];

        foreach ($results as $row) {
            $counts[(int) $row->comment_post_ID] = (int) $row->note_count;
        }

        return $counts;
    }

    private function renderNotesIndicator(string $editLink, int $openNotes): string
    {
        if ($openNotes <= 0 || $editLink === '') {
            return '';
        }

        $label = sprintf(
            /* translators: %d: number of open Gutenberg block notes */
            _n('%d open note', '%d open notes', $openNotes, 'gds-content-translation'),
            $openNotes
        );

        return sprintf(
            '<a class="gds-content-translation__notes" href="%1$s" title="%2$s"><span class="dashicons dashicons-admin-comments" aria-hidden="true"></span><span class="gds-content-translation__notes-label">%3$s</span></a>',
            esc_url($editLink),
            esc_attr__('This post has open block editor notes', 'gds-content-translation'),
            esc_html($label)
        );
    }
}
