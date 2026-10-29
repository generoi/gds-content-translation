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

    public static function savePostType(string $postType): void
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
     * Trash, restore or permanently delete a translation from a link, and
     * come back to its row with a notice. The status screen itself does the
     * same through REST; both go through {@see TranslationActions}, which
     * never touches a source-language post.
     */
    public function handleTranslationAction(): void
    {
        $action = substr((string) current_action(), strlen('admin_post_'));
        $postId = Request::id($_GET, 'post');
        $postType = Request::key($_GET, 'post_type');

        check_admin_referer($action.'_'.$postId);

        $returnUrl = self::getPageUrl($postType);

        $result = match ($action) {
            self::trashAction => TranslationActions::trashTranslation($postId),
            self::restoreAction => TranslationActions::restoreTranslation($postId),
            self::deleteAction => TranslationActions::deleteTranslation($postId),
            default => wp_die(esc_html__('Unknown action.', 'gds-content-translation'), '', ['response' => 400, 'back_link' => true]),
        };

        if ($result instanceof \WP_Error) {
            if ($result->get_error_code() === 'gds_ct_forbidden') {
                wp_die(esc_html($result->get_error_message()), '', ['response' => 403, 'back_link' => true]);
            }

            Notice::flash('error', $result->get_error_message());
            wp_safe_redirect($returnUrl);
            exit;
        }

        $sourceId = $result['sourceId'];
        $undoUrl = $action === self::trashAction && $result['done'] ? self::getTranslationActionUrl(self::restoreAction, $postId, $postType) : '';

        // Focus comes back to the cell the action was taken in.
        Notice::flash(
            $result['done'] ? 'success' : 'error',
            $result['message'],
            $undoUrl,
            $undoUrl !== '' ? __('Undo', 'gds-content-translation') : '',
            $sourceId > 0 ? self::rowAnchor($sourceId) : '',
            $sourceId > 0 ? $result['language'] : ''
        );

        wp_safe_redirect($returnUrl.($sourceId > 0 ? '#'.self::rowAnchor($sourceId) : ''));
        exit;
    }

    /**
     * Trash all from a link: a source post and every translation of it, in
     * one go; and its Undo, whose nonce covers exactly the posts it trashed.
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
        $rowAnchor = self::rowAnchor($sourceId);
        $result = $action === self::trashRowAction
            ? TranslationActions::trashGroup($sourceId)
            : TranslationActions::restoreGroup($sourceId, $restoreIds);

        if ($result instanceof \WP_Error) {
            $forbidden = $result->get_error_code() === 'gds_ct_forbidden';
            Notice::flash('error', $result->get_error_message(), '', '', $forbidden ? $rowAnchor : '');
            wp_safe_redirect($returnUrl.($forbidden ? '#'.$rowAnchor : ''));
            exit;
        }

        $undoUrl = $action === self::trashRowAction && $result['changed'] !== [] ? self::getRowRestoreUrl($sourceId, $result['changed'], $postType) : '';

        // A row moved to the trash is gone from the list: focus goes to the
        // notice and its Undo, unless the source is still there.
        Notice::flash(
            $result['done'] ? 'success' : 'error',
            $result['message'],
            $undoUrl,
            $undoUrl !== '' ? __('Undo', 'gds-content-translation') : '',
            $result['stillListed'] ? $rowAnchor : ''
        );
        wp_safe_redirect($returnUrl.($result['stillListed'] ? '#'.$rowAnchor : ''));
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

        // The status list is the DataViews app. The AI review page is
        // server-rendered around its table of remarks, which is the app's too;
        // the settings are server-rendered.
        if ($hookSuffix === 'languages_page_'.self::pageSlug) {
            $this->enqueueApp();

            if (AuditReview::requestedPostId() <= 0) {
                return;
            }
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
            ['wp-api-fetch'],
            self::assetVersion('assets/admin.js'),
            true
        );

        wp_localize_script('gds-content-translation', 'contentTranslationStatus', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gds_content_translation'),
            'restNamespace' => Rest\StatusController::namespace,
            'linkTranslate' => [
                'translating' => __('Translating…', 'gds-content-translation'),
                'queued' => __('Queued…', 'gds-content-translation'),
                /* translators: %s: language name */
                'done' => __('Translated. The link now goes to the %s page.', 'gds-content-translation'),
                'edit' => __('Edit', 'gds-content-translation'),
                'review' => __('AI review', 'gds-content-translation'),
                /* translators: %s: error message */
                'failed' => __('Translation failed: %s', 'gds-content-translation'),
            ],
            'proofread' => [
                'checked' => __('Marked as proofread.', 'gds-content-translation'),
                'unchecked' => __('Marked as not proofread.', 'gds-content-translation'),
                'failed' => __('Could not save. Try again.', 'gds-content-translation'),
                'expired' => __('Your session has expired. Reload the page and try again.', 'gds-content-translation'),
            ],
        ]);
    }

    /**
     * The built app (build/, committed: the plugin is installed without a
     * build step). wp-scripts lists the WordPress script handles it uses in
     * index.asset.php; DataViews itself is bundled, WordPress does not ship it.
     */
    private function enqueueApp(): void
    {
        $assetFile = GDS_CONTENT_TRANSLATION_PATH.'/build/index.asset.php';
        $asset = is_file($assetFile) ? include $assetFile : [];
        $dependencies = is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [];
        $version = (string) ($asset['version'] ?? GDS_CONTENT_TRANSLATION_VERSION);

        wp_enqueue_script(
            'gds-content-translation-status',
            plugins_url('build/index.js', GDS_CONTENT_TRANSLATION_FILE),
            array_values(array_unique(array_merge($dependencies, ['wp-api-fetch', 'wp-dom-ready']))),
            $version,
            ['in_footer' => true]
        );

        wp_set_script_translations('gds-content-translation-status', 'gds-content-translation');

        // HEX flags as wp_localize_script() uses them: a `</script>` in a
        // post type label cannot end the script block.
        wp_add_inline_script(
            'gds-content-translation-status',
            'window.gdsContentTranslation = '.wp_json_encode($this->appSettings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';',
            'before'
        );

        // DataViews' stylesheet reads the design tokens that core's
        // wp-components stylesheet defines, so it comes after it.
        wp_enqueue_style(
            'gds-content-translation-status',
            plugins_url('build/style-index.css', GDS_CONTENT_TRANSLATION_FILE),
            ['wp-components', 'dashicons'],
            $version
        );
    }

    /**
     * What the app needs that does not change while it runs.
     *
     * @return array<string, mixed>
     */
    private function appSettings(): array
    {
        $postTypes = [];

        foreach (StatusList::postTypes() as $slug => $postType) {
            $postTypes[] = ['slug' => $slug, 'label' => $postType['label'], 'icon' => self::postTypeIcon($postType['icon'])];
        }

        $selected = Request::key($_GET, 'gds_ct_post_type') ?: self::getSavedPostType();
        $slugs = array_column($postTypes, 'slug');

        if (! in_array($selected, $slugs, true)) {
            $selected = $slugs[0] ?? '';
        }

        return [
            'postTypes' => $postTypes,
            'postType' => $selected,
            'languages' => StatusList::languages(),
            'machineTranslation' => MachineTranslation::isAvailable(),
            'copyTranslation' => MachineTranslation::isCopyAvailable(),
            'review' => TranslationAudit::isEnabled(),
            'publish' => MachineTranslation::publishesTranslations(),
            // One nonce per screen for the create and review links: their
            // handlers check the post, language and capability themselves.
            'createUrl' => MachineTranslation::getActionBaseUrl(),
            'reviewRunUrl' => add_query_arg(['action' => 'gds_ct_run_review', '_wpnonce' => wp_create_nonce('gds_ct_run_review')], admin_url('admin-post.php')),
            'pageUrl' => admin_url('admin.php?page='.self::pageSlug),
            'settingsUrl' => admin_url('admin.php?page=mlang_content_translation_settings'),
            'userId' => get_current_user_id(),
        ];
    }

    /**
     * A post type's menu icon for the tabs: a dashicon class or an image URL.
     *
     * @return array{type: string, value: string}
     */
    private static function postTypeIcon(string $menuIcon): array
    {
        if ($menuIcon === '' || $menuIcon === 'none') {
            return ['type' => 'dashicon', 'value' => 'dashicons-admin-post'];
        }

        if (str_starts_with($menuIcon, 'dashicons-')) {
            return ['type' => 'dashicon', 'value' => sanitize_html_class($menuIcon)];
        }

        if (str_starts_with($menuIcon, 'data:') || filter_var($menuIcon, FILTER_VALIDATE_URL)) {
            return ['type' => 'image', 'value' => $menuIcon];
        }

        return ['type' => 'dashicon', 'value' => 'dashicons-'.sanitize_html_class($menuIcon)];
    }

    /**
     * The proof-read checkbox of the AI review page.
     */
    public function saveProofread(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'gds-content-translation')], 403);
        }

        check_ajax_referer('gds_content_translation', 'nonce');

        $postId = Request::id($_POST, 'postId');
        $proofread = ($_POST['proofread'] ?? '') === '1';

        if ($postId > 0 && get_post($postId) && ! current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('Permission denied.', 'gds-content-translation')], 403);
        }

        $result = TranslationActions::setProofread($postId, $proofread);

        if ($result instanceof \WP_Error) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['proofread' => $proofread]);
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (AuditReview::requestedPostId() > 0) {
            AuditReview::renderPage(AuditReview::requestedPostId());

            return;
        }

        // The app replaces the placeholder when it mounts.
        ?>
        <div class="wrap gds-content-translation-app">
            <h1 class="wp-heading-inline"><?php echo esc_html__('Content translation status', 'gds-content-translation'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mlang_content_translation_settings')); ?>" class="page-title-action"><?php echo esc_html__('Settings', 'gds-content-translation'); ?></a>
            <hr class="wp-header-end">
            <div id="gds-content-translation-root">
                <p class="gds-content-translation-app__loading"><span class="spinner is-active" style="float:none;margin:0 8px 0 0"></span><?php echo esc_html__('Loading…', 'gds-content-translation'); ?></p>
                <noscript><p><?php echo esc_html__('The translation status screen needs JavaScript.', 'gds-content-translation'); ?></p></noscript>
            </div>
        </div>
        <?php
    }
}
