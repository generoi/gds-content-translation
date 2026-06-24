<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    private const pageSlug = 'mlang_content_translation_status';

    private const postTypeUserMetaKey = 'content_translation_status_post_type';

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
        update_user_meta((int) get_current_user_id(), self::postTypeUserMetaKey, sanitize_key($postType));
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
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminBarAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAdminBarAssets']);
        add_action('wp_ajax_gds_ct_save_proofread', [$this, 'saveProofread']);
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

    public function enqueueAdminBarAssets(): void
    {
        if (! is_user_logged_in() || ! current_user_can('manage_options') || ! is_admin_bar_showing()) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'gds-content-translation-admin-bar',
            plugins_url('assets/admin-bar.css', GDS_CONTENT_TRANSLATION_FILE),
            ['dashicons'],
            GDS_CONTENT_TRANSLATION_VERSION
        );
    }

    private function getAdminBarTitle(): string
    {
        return sprintf(
            '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span><span class="ab-label">%s</span>',
            esc_html__('Translation status', 'gds-content-translation')
        );
    }

    private function resolveAdminBarPostType(): string
    {
        global $post;

        if ($post instanceof \WP_Post && function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($post->post_type)) {
            if (Settings::isPostTypeVisible($post->post_type)) {
                return $post->post_type;
            }
        }

        if (is_admin() && isset($_GET['post_type'])) {
            $postType = sanitize_key((string) $_GET['post_type']);

            if ($postType !== '' && pll_is_translated_post_type($postType) && Settings::isPostTypeVisible($postType)) {
                return $postType;
            }
        }

        $savedPostType = self::getSavedPostType();

        if ($savedPostType !== '' && Settings::isPostTypeVisible($savedPostType)) {
            return $savedPostType;
        }

        $visiblePostTypes = Settings::getVisiblePostTypeSlugs();

        return $visiblePostTypes[0] ?? '';
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
            GDS_CONTENT_TRANSLATION_VERSION
        );

        wp_enqueue_script(
            'gds-content-translation',
            plugins_url('assets/admin.js', GDS_CONTENT_TRANSLATION_FILE),
            [],
            GDS_CONTENT_TRANSLATION_VERSION,
            true
        );

        wp_localize_script('gds-content-translation', 'contentTranslationStatus', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gds_content_translation'),
        ]);
    }

    public function saveProofread(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'gds-content-translation')], 403);
        }

        check_ajax_referer('gds_content_translation', 'nonce');

        $postId = isset($_POST['postId']) ? (int) $_POST['postId'] : 0;
        $proofread = isset($_POST['proofread']) && $_POST['proofread'] === '1';

        if ($postId <= 0 || ! get_post($postId)) {
            wp_send_json_error(['message' => __('Invalid post.', 'gds-content-translation')], 400);
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
        $selectedPostType = isset($_GET['gds_ct_post_type']) ? sanitize_key((string) $_GET['gds_ct_post_type']) : '';

        if ($selectedPostType === '') {
            $selectedPostType = self::getSavedPostType();
        }

        if ($selectedPostType === '' && ! empty($postTypes)) {
            $selectedPostType = array_key_first($postTypes);
        }

        if ($selectedPostType !== '' && ! isset($postTypes[$selectedPostType])) {
            $selectedPostType = array_key_first($postTypes) ?: '';
        }

        if ($selectedPostType !== '') {
            self::savePostType($selectedPostType);
        }

        $languages = $this->getLanguages();

        $rows = $selectedPostType !== '' ? $this->getRows($selectedPostType, $languages) : [];
        $summary = $this->buildSummary(
            $rows,
            $languages,
            $selectedPostType !== '' ? ($postTypes[$selectedPostType]['label'] ?? '') : ''
        );
        $machineTranslation = new MachineTranslation;
        $machineTranslationAvailable = MachineTranslation::isAvailable();

        ?>
        <div class="wrap gds-content-translation">
            <h1>
                <?php echo esc_html__('Content translation status', 'gds-content-translation'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=mlang_content_translation_settings')); ?>" class="page-title-action">
                    <?php echo esc_html__('Settings', 'gds-content-translation'); ?>
                </a>
            </h1>

            <?php if (empty($postTypes)) { ?>
                <p><?php echo esc_html__('No translatable post types are configured in Polylang.', 'gds-content-translation'); ?></p>
            <?php } else { ?>
                <nav
                    class="gds-content-translation__tabs nav-tab-wrapper wp-clearfix"
                    aria-label="<?php echo esc_attr__('Post type', 'gds-content-translation'); ?>"
                >
                    <?php foreach ($postTypes as $slug => $postType) { ?>
                        <?php
                        $tabUrl = add_query_arg(
                            [
                                'page' => self::pageSlug,
                                'gds_ct_post_type' => $slug,
                            ],
                            admin_url('admin.php')
                        );
                        $tabClasses = ['nav-tab'];

                        if ($slug === $selectedPostType) {
                            $tabClasses[] = 'nav-tab-active';
                        }
                        ?>
                        <a
                            href="<?php echo esc_url($tabUrl); ?>"
                            class="<?php echo esc_attr(implode(' ', $tabClasses)); ?>"
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
                    <div class="gds-content-translation__summary">
                        <p>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: total count, 2: post type label, 3: default language name */
                                __('%1$d primary %2$s in %3$s.', 'gds-content-translation'),
                                $summary['total'],
                                strtolower($summary['postTypeLabel']),
                                $summary['defaultLanguageName']
                            ));
                    ?>
                        </p>
                        <p>
                            <?php echo esc_html__('Missing translation:', 'gds-content-translation'); ?>
                            <?php foreach ($summary['missing'] as $index => $item) { ?>
                                <?php if ($index > 0) { ?><span class="gds-content-translation__sep">·</span><?php } ?>
                                <span class="gds-content-translation__summary-stat <?php echo $item['count'] > 0 ? 'gds-content-translation__summary-stat--missing' : ''; ?>">
                                    <?php echo esc_html(sprintf(
                                        /* translators: 1: language name, 2: missing count */
                                        __('%1$s %2$d', 'gds-content-translation'),
                                        $item['name'],
                                        $item['count']
                                    )); ?>
                                </span>
                            <?php } ?>
                        </p>
                        <?php if (! empty($summary['notProofread'])) { ?>
                            <p>
                                <?php echo esc_html__('Not proof read:', 'gds-content-translation'); ?>
                                <?php foreach ($summary['notProofread'] as $index => $item) { ?>
                                    <?php if ($index > 0) { ?><span class="gds-content-translation__sep">·</span><?php } ?>
                                    <span class="gds-content-translation__summary-stat">
                                        <?php echo esc_html(sprintf(
                                            /* translators: 1: language code, 2: count */
                                            __('%1$s %2$d', 'gds-content-translation'),
                                            strtoupper($item['slug']),
                                            $item['count']
                                        )); ?>
                                    </span>
                                <?php } ?>
                            </p>
                        <?php } ?>
                    </div>

                    <table class="widefat striped gds-content-translation__table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Title', 'gds-content-translation'); ?></th>
                                <?php foreach ($languages as $language) { ?>
                                    <th><?php echo esc_html($language['name']); ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) { ?>
                                <tr>
                                    <td class="gds-content-translation__title">
                                        <div class="gds-content-translation__title-cell">
                                            <a href="<?php echo esc_url(get_edit_post_link($row['sourceId'], 'raw')); ?>">
                                                <?php echo esc_html($row['title']); ?>
                                            </a>
                                            <?php $this->renderNotesIndicator($row['sourceId'], $row['openNotes']); ?>
                                        </div>
                                    </td>
                                    <?php foreach ($languages as $language) { ?>
                                        <?php
                                        $langSlug = $language['slug'];
                                        $cell = $row['languages'][$langSlug] ?? null;
                                        ?>
                                        <td class="gds-content-translation__lang">
                                            <?php if ($cell === null) { ?>
                                                <div class="gds-content-translation__missing-cell">
                                                    <span class="gds-content-translation__badge gds-content-translation__badge--missing">
                                                        <?php echo esc_html__('Missing', 'gds-content-translation'); ?>
                                                    </span>
                                                    <?php if ($machineTranslationAvailable && ! $language['isDefault']) { ?>
                                                        <a
                                                            class="button button-small gds-content-translation__translate"
                                                            href="<?php echo esc_url($machineTranslation->getActionUrl($row['sourceId'], $langSlug, $selectedPostType)); ?>"
                                                        >
                                                            <span class="dashicons dashicons-translation gds-content-translation__translate-icon" aria-hidden="true"></span>
                                                            <?php echo esc_html__('Translate', 'gds-content-translation'); ?>
                                                        </a>
                                                    <?php } ?>
                                                </div>
                                            <?php } else { ?>
                                                <div class="gds-content-translation__cell">
                                                    <span class="gds-content-translation__badge gds-content-translation__badge--exists" aria-hidden="true">✓</span>
                                                    <span class="gds-content-translation__actions">
                                                        <a class="gds-content-translation__edit" href="<?php echo esc_url(get_edit_post_link($cell['postId'], 'raw')); ?>">
                                                            <?php echo esc_html__('Edit', 'gds-content-translation'); ?>
                                                        </a>
                                                        <label class="gds-content-translation__proofread">
                                                            <input
                                                                type="checkbox"
                                                                class="gds-content-translation__proofread-input"
                                                                data-post-id="<?php echo esc_attr((string) $cell['postId']); ?>"
                                                                <?php checked($cell['proofread']); ?>
                                                            >
                                                            <span class="gds-content-translation__proofread-label">
                                                                <?php echo esc_html__('Proof read', 'gds-content-translation'); ?>
                                                            </span>
                                                        </label>
                                                        <?php $this->renderNotesIndicator($cell['postId'], $cell['openNotes']); ?>
                                                    </span>
                                                </div>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * @param  list<array{sourceId: int, title: string, openNotes: int, languages: array<string, array{postId: int, proofread: bool, openNotes: int}>}>  $rows
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $languages
     * @return array{
     *     total: int,
     *     postTypeLabel: string,
     *     defaultLanguageName: string,
     *     missing: list<array{name: string, slug: string, count: int}>,
     *     notProofread: list<array{name: string, slug: string, count: int}>
     * }
     */
    private function buildSummary(array $rows, array $languages, string $postTypeLabel): array
    {
        $defaultLanguageName = '';
        $missing = [];
        $notProofread = [];

        foreach ($languages as $language) {
            if ($language['isDefault']) {
                $defaultLanguageName = $language['name'];

                continue;
            }

            $missingCount = 0;
            $notProofreadCount = 0;

            foreach ($rows as $row) {
                $cell = $row['languages'][$language['slug']] ?? null;

                if ($cell === null) {
                    $missingCount++;

                    continue;
                }

                if (! $cell['proofread']) {
                    $notProofreadCount++;
                }
            }

            $missing[] = [
                'name' => $language['name'],
                'slug' => $language['slug'],
                'count' => $missingCount,
            ];

            if ($notProofreadCount > 0) {
                $notProofread[] = [
                    'name' => $language['name'],
                    'slug' => $language['slug'],
                    'count' => $notProofreadCount,
                ];
            }
        }

        return [
            'total' => count($rows),
            'postTypeLabel' => $postTypeLabel,
            'defaultLanguageName' => $defaultLanguageName,
            'missing' => $missing,
            'notProofread' => $notProofread,
        ];
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
     * @return list<array{sourceId: int, title: string, openNotes: int, languages: array<string, array{postId: int, proofread: bool, openNotes: int}>}>
     */
    private function getRows(string $postType, array $languages): array
    {
        $defaultSlug = pll_default_language('slug');

        if (! $defaultSlug) {
            return [];
        }

        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'lang' => $defaultSlug,
            'suppress_filters' => false,
        ]);

        $postIds = [];

        foreach ($posts as $post) {
            $postIds[] = (int) $post->ID;

            foreach (pll_get_post_translations($post->ID) as $translationId) {
                $postIds[] = (int) $translationId;
            }
        }

        $openNoteCounts = $this->getOpenNoteCountsByPostId($postIds);
        $rows = [];

        foreach ($posts as $post) {
            $translations = pll_get_post_translations($post->ID);
            $languageCells = [];

            foreach ($languages as $language) {
                $translationId = (int) ($translations[$language['slug']] ?? 0);

                if ($translationId <= 0) {
                    continue;
                }

                $languageCells[$language['slug']] = [
                    'postId' => $translationId,
                    'proofread' => (bool) get_post_meta($translationId, GDS_CONTENT_TRANSLATION_META_KEY, true),
                    'openNotes' => $openNoteCounts[$translationId] ?? 0,
                ];
            }

            $sourceId = (int) $post->ID;

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

    private function renderNotesIndicator(int $postId, int $openNotes): void
    {
        if ($openNotes <= 0) {
            return;
        }

        $editLink = get_edit_post_link($postId, 'raw');

        if (! is_string($editLink) || $editLink === '') {
            return;
        }

        $label = sprintf(
            /* translators: %d: number of open Gutenberg block notes */
            _n('%d open note', '%d open notes', $openNotes, 'gds-content-translation'),
            $openNotes
        );

        printf(
            '<a class="gds-content-translation__notes" href="%1$s" title="%2$s"><span class="dashicons dashicons-admin-comments" aria-hidden="true"></span><span class="gds-content-translation__notes-label">%3$s</span></a>',
            esc_url($editLink),
            esc_attr__('This post has open block editor notes', 'gds-content-translation'),
            esc_html($label)
        );
    }
}
