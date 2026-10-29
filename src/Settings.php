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
        add_action('admin_init', [$this, 'handleAuditSave']);
        add_action('admin_init', [self::class, 'ensureOption']);
    }

    /**
     * Store the default once, autoloaded. While the option does not exist,
     * every get_option() for it is its own query, and the admin bar asks for
     * it on every page an administrator sees, front end included.
     */
    public static function ensureOption(): void
    {
        if (get_option(self::optionKey, null) === null) {
            add_option(self::optionKey, self::defaultHiddenPostTypes, '', 'yes');
        }
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

    public function handleAuditSave(): void
    {
        if (! isset($_POST['gds_ct_audit_submit'])) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('gds_content_translation_audit_settings');

        $model = Request::key($_POST, 'gds_ct_audit_model');
        $language = Request::key($_POST, 'gds_ct_audit_language');

        update_option(TranslationAudit::optionKey, [
            'enabled' => isset($_POST['gds_ct_audit_enabled']),
            'model' => isset(TranslationAudit::models()[$model]) ? $model : TranslationAudit::defaultModel,
            'language' => isset(TranslationAudit::remarkLanguages()[$language]) ? $language : 'target',
            'notes' => isset($_POST['gds_ct_audit_notes']),
        ]);
        update_option(MachineTranslation::publishOption, isset($_POST['gds_ct_publish']));

        add_settings_error(
            'gds_content_translation',
            'audit_settings_updated',
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

            <?php $this->renderAuditSettings(); ?>

            <p>
                <a href="<?php echo esc_url(Admin::getPageUrl()); ?>">
                    <?php echo esc_html__('← Back to Content translation status', 'gds-content-translation'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    private function renderAuditSettings(): void
    {
        $settings = TranslationAudit::settings();
        $keySource = TranslationAudit::keySource();
        $keyMessages = [
            'environment' => __('The Claude API key is read from the ANTHROPIC_API_KEY environment variable.', 'gds-content-translation'),
            'constant' => __('The Claude API key is read from the ANTHROPIC_API_KEY constant.', 'gds-content-translation'),
            'option' => __('The Claude API key is read from the WordPress Connectors setting.', 'gds-content-translation'),
        ];

        ?>
        <h2><?php echo esc_html__('AI review of machine translations', 'gds-content-translation'); ?></h2>
        <p>
            <?php echo esc_html__('When a translation is created with AI translation, Claude compares its post content with the original in the background and leaves remarks on the status screen. The translation itself is not changed.', 'gds-content-translation'); ?>
        </p>

        <?php if ($keySource === '') { ?>
            <div class="notice notice-warning inline"><p>
                <?php echo esc_html__('No Claude API key found. Set ANTHROPIC_API_KEY in the site environment (.env) to turn on the AI review.', 'gds-content-translation'); ?>
            </p></div>
        <?php } else { ?>
            <p class="description"><?php echo esc_html($keyMessages[$keySource]); ?></p>
        <?php } ?>

        <form method="post" action="">
            <?php wp_nonce_field('gds_content_translation_audit_settings'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('AI translations', 'gds-content-translation'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="gds_ct_publish" value="1" <?php checked((bool) get_option(MachineTranslation::publishOption, true)); ?>>
                                <?php echo esc_html__('Publish AI translations of published originals at once, and the patterns they use', 'gds-content-translation'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Off: every new translation is a draft until someone publishes it. Copy original always creates drafts.', 'gds-content-translation'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('AI review', 'gds-content-translation'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="gds_ct_audit_enabled" value="1" <?php checked($settings['enabled']); ?>>
                                <?php echo esc_html__('Review machine translations with Claude', 'gds-content-translation'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="gds_ct_audit_notes" value="1" <?php checked($settings['notes']); ?>>
                                <?php echo esc_html__('Add each remark as a note on its block in the editor', 'gds-content-translation'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gds-ct-audit-model"><?php echo esc_html__('Model', 'gds-content-translation'); ?></label></th>
                        <td>
                            <select id="gds-ct-audit-model" name="gds_ct_audit_model">
                                <?php foreach (TranslationAudit::models() as $model => $label) { ?>
                                    <option value="<?php echo esc_attr($model); ?>" <?php selected($settings['model'], $model); ?>><?php echo esc_html($label); ?></option>
                                <?php } ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gds-ct-audit-language"><?php echo esc_html__('Language of the remarks', 'gds-content-translation'); ?></label></th>
                        <td>
                            <select id="gds-ct-audit-language" name="gds_ct_audit_language">
                                <?php foreach (TranslationAudit::remarkLanguages() as $language => $label) { ?>
                                    <option value="<?php echo esc_attr($language); ?>" <?php selected($settings['language'], $language); ?>><?php echo esc_html($label); ?></option>
                                <?php } ?>
                            </select>
                            <p class="description"><?php echo esc_html__('The translation’s language suits a translator working into it.', 'gds-content-translation'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(__('Save AI review settings', 'gds-content-translation'), 'primary', 'gds_ct_audit_submit'); ?>
        </form>
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
