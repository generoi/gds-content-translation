<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

class Settings
{
    public const optionKey = 'gds_content_translation_hidden_post_types';

    private const pageSlug = 'mlang_content_translation_settings';

    /** @var list<string> */
    private const defaultHiddenPostTypes = [
        'nav_menu_item',
        'wp_navigation',
    ];

    public static function init(): void
    {
        $settings = new self;
        $settings->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu'], 21);
        add_action('admin_init', [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        if (! function_exists('PLL') || ! PLL()->model->has_languages()) {
            return;
        }

        add_submenu_page(
            'mlang',
            __('Content translation settings', 'gds-content-translation'),
            __('Content translation settings', 'gds-content-translation'),
            'manage_options',
            self::pageSlug,
            [$this, 'renderPage']
        );
    }

    public function handleSave(): void
    {
        if (! isset($_POST['gds_ct_settings_submit'])) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('gds_content_translation_settings');

        $allSlugs = self::getPolylangPostTypeSlugs();
        $visibleSlugs = isset($_POST['gds_ct_visible_post_types']) && is_array($_POST['gds_ct_visible_post_types'])
            ? array_map('sanitize_key', wp_unslash($_POST['gds_ct_visible_post_types']))
            : [];

        $visibleSlugs = array_values(array_intersect($visibleSlugs, $allSlugs));
        $hiddenSlugs = array_values(array_diff($allSlugs, $visibleSlugs));

        update_option(self::optionKey, $hiddenSlugs);

        add_settings_error(
            'gds_content_translation',
            'settings_updated',
            __('Settings saved.', 'gds-content-translation'),
            'success'
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $postTypes = self::getAllPostTypes();
        $visibleSlugs = self::getVisiblePostTypeSlugs();

        ?>
        <div class="wrap gds-content-translation">
            <h1><?php echo esc_html__('Content translation settings', 'gds-content-translation'); ?></h1>

            <?php settings_errors('gds_content_translation'); ?>

            <p>
                <?php echo esc_html__('Choose which post types appear on the Content translation status screen.', 'gds-content-translation'); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field('gds_content_translation_settings'); ?>

                <?php if ($postTypes === []) { ?>
                    <p><?php echo esc_html__('No translatable post types are configured in Polylang.', 'gds-content-translation'); ?></p>
                <?php } else { ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Post types', 'gds-content-translation'); ?>
                                </th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text">
                                            <?php echo esc_html__('Post types', 'gds-content-translation'); ?>
                                        </legend>
                                        <?php foreach ($postTypes as $slug => $postType) { ?>
                                            <label style="display: block; margin-bottom: 0.5rem;">
                                                <input
                                                    type="checkbox"
                                                    name="gds_ct_visible_post_types[]"
                                                    value="<?php echo esc_attr($slug); ?>"
                                                    <?php checked(in_array($slug, $visibleSlugs, true)); ?>
                                                >
                                                <?php echo esc_html($postType['label']); ?>
                                                <code><?php echo esc_html($slug); ?></code>
                                            </label>
                                        <?php } ?>
                                        <p class="description">
                                            <?php echo esc_html__('Unchecked post types are hidden from the translation status tabs. Developers can still exclude types via the gds_content_translation_excluded_post_types filter.', 'gds-content-translation'); ?>
                                        </p>
                                    </fieldset>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php submit_button(__('Save settings', 'gds-content-translation'), 'primary', 'gds_ct_settings_submit'); ?>
                <?php } ?>
            </form>

            <p>
                <a href="<?php echo esc_url(Admin::getPageUrl()); ?>">
                    <?php echo esc_html__('← Back to Content translation status', 'gds-content-translation'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * @return list<string>
     */
    public static function getHiddenPostTypes(): array
    {
        $hidden = get_option(self::optionKey, null);

        if (! is_array($hidden)) {
            $hidden = self::defaultHiddenPostTypes;
        }

        $hidden = array_map('sanitize_key', $hidden);

        $filterExcluded = apply_filters('gds_content_translation_excluded_post_types', []);

        if (is_array($filterExcluded) && $filterExcluded !== []) {
            $hidden = array_merge($hidden, array_map('sanitize_key', $filterExcluded));
        }

        return array_values(array_unique(array_filter($hidden)));
    }

    /**
     * @return list<string>
     */
    public static function getVisiblePostTypeSlugs(): array
    {
        $allSlugs = self::getPolylangPostTypeSlugs();
        $hiddenSlugs = self::getHiddenPostTypes();

        return array_values(array_diff($allSlugs, $hiddenSlugs));
    }

    public static function isPostTypeVisible(string $postType): bool
    {
        return in_array($postType, self::getVisiblePostTypeSlugs(), true);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function getAllPostTypes(): array
    {
        if (! function_exists('PLL')) {
            return [];
        }

        $postTypes = [];

        foreach (self::getPolylangPostTypeSlugs() as $slug) {
            $object = get_post_type_object($slug);

            if (! $object) {
                continue;
            }

            $postTypes[$slug] = [
                'label' => $object->labels->name,
                'icon' => is_string($object->menu_icon) ? $object->menu_icon : 'dashicons-admin-post',
            ];
        }

        uasort($postTypes, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $postTypes;
    }

    /**
     * @return list<string>
     */
    private static function getPolylangPostTypeSlugs(): array
    {
        if (! function_exists('PLL')) {
            return [];
        }

        $slugs = PLL()->model->get_translated_post_types();

        return is_array($slugs) ? array_values(array_map('sanitize_key', $slugs)) : [];
    }
}
