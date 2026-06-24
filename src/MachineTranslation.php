<?php

namespace GeneroWP\ContentTranslation;

use GeneroWP\ContentTranslation\Polylang\BlockPostIdTranslation;
use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use WP_Error;
use WP_Post;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Processor;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services\Service_Interface;

if (! defined('ABSPATH')) {
    exit;
}

class MachineTranslation
{
    public static function init(): void
    {
        $handler = new self;
        add_action('admin_post_gds_ct_machine_translate', [$handler, 'handleRequest']);
        add_action('admin_notices', [$handler, 'renderNotices']);
    }

    public static function isAvailable(): bool
    {
        if (! class_exists(Factory::class)) {
            return false;
        }

        $factory = new Factory(PLL()->model);

        return $factory->is_enabled() && $factory->get_active_service() instanceof Service_Interface;
    }

    public function getActionUrl(int $sourceId, string $langSlug, string $postType = ''): string
    {
        return wp_nonce_url(
            add_query_arg(
                array_filter([
                    'action' => 'gds_ct_machine_translate',
                    'source_id' => $sourceId,
                    'lang' => $langSlug,
                    'gds_ct_post_type' => $postType !== '' ? $postType : null,
                ]),
                admin_url('admin-post.php')
            ),
            'gds_ct_machine_translate_'.$sourceId.'_'.$langSlug
        );
    }

    public function handleRequest(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'gds-content-translation'), 403);
        }

        $sourceId = isset($_GET['source_id']) ? (int) $_GET['source_id'] : 0;
        $langSlug = isset($_GET['lang']) ? sanitize_key((string) $_GET['lang']) : '';

        check_admin_referer('gds_ct_machine_translate_'.$sourceId.'_'.$langSlug);

        $sourcePost = get_post($sourceId);
        $language = PLL()->model->get_language($langSlug);

        if (! $sourcePost instanceof WP_Post || ! $language) {
            $this->redirectWithNotice('error', __('Invalid translation request.', 'gds-content-translation'));
        }

        if (pll_get_post($sourceId, $langSlug)) {
            $this->redirectWithNotice('error', __('Translation already exists.', 'gds-content-translation'));
        }

        if (! self::isAvailable()) {
            $this->redirectWithNotice('error', __('Machine translation is not available.', 'gds-content-translation'));
        }

        $factory = new Factory(PLL()->model);
        $service = $factory->get_active_service();

        if (! $service instanceof Service_Interface) {
            $this->redirectWithNotice('error', __('Machine translation service is not configured.', 'gds-content-translation'));
        }

        $translationId = $this->translatePost($sourcePost, $language, $service);

        if ($translationId instanceof WP_Error) {
            $this->redirectWithNotice('error', $translationId->get_error_message());
        }

        $editLink = get_edit_post_link($translationId, 'raw');

        if (! is_string($editLink) || $editLink === '') {
            $this->redirectWithNotice(
                'success',
                __('Translation created.', 'gds-content-translation')
            );
        }

        wp_safe_redirect($editLink);
        exit;
    }

    /**
     * Machine-translate a post using Polylang Pro's configured service.
     *
     * Runs the translation directly instead of redirecting through post-new.php
     * and relying on per-user meta toggles, which can fail across environments.
     */
    private function translatePost(WP_Post $sourcePost, object $targetLang, Service_Interface $service): int|WP_Error
    {
        $polylang = PLL();

        if (! function_exists('get_default_post_to_edit')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        $currentLangBackup = $polylang->curlang;
        $polylang->curlang = null;

        $container = new PLL_Export_Container(Data::class);
        $exporter = new PLL_Export_Data_From_Posts($polylang->model);
        $exporter->send_to_export($container, [$sourcePost], $targetLang);

        $processor = new Processor($polylang, $service->get_client());

        $result = $processor->translate($container);

        if ($result->has_errors()) {
            $polylang->curlang = $currentLangBackup;

            return new WP_Error(
                'gds_ct_translation_failed',
                implode('; ', $result->get_error_messages())
            );
        }

        $result = $processor->save($container);

        $polylang->curlang = $currentLangBackup;

        if ($result->has_errors()) {
            return new WP_Error(
                'gds_ct_save_failed',
                implode('; ', $result->get_error_messages())
            );
        }

        $translationId = (int) pll_get_post($sourcePost->ID, $targetLang->slug);

        if ($translationId <= 0) {
            return new WP_Error(
                'gds_ct_no_translation',
                __('Unable to retrieve the translation.', 'gds-content-translation')
            );
        }

        BlockPostIdTranslation::normalizePostContent($translationId);

        return $translationId;
    }

    public function renderNotices(): void
    {
        $notice = get_transient($this->getNoticeTransientKey());

        if (! is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient($this->getNoticeTransientKey());

        $class = ($notice['type'] ?? 'error') === 'success' ? 'notice-success' : 'notice-error';

        printf(
            '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($class),
            esc_html($notice['message'])
        );
    }

    private function redirectWithNotice(string $type, string $message): void
    {
        set_transient(
            $this->getNoticeTransientKey(),
            [
                'type' => $type,
                'message' => $message,
            ],
            MINUTE_IN_SECONDS
        );

        wp_safe_redirect($this->getReturnUrl());
        exit;
    }

    private function getReturnUrl(): string
    {
        $postType = isset($_GET['gds_ct_post_type']) ? sanitize_key((string) $_GET['gds_ct_post_type']) : '';

        return Admin::getPageUrl($postType);
    }

    private function getNoticeTransientKey(): string
    {
        return 'gds_content_translation_notice_'.get_current_user_id();
    }
}
