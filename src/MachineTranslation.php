<?php

namespace GeneroWP\ContentTranslation;

use GeneroWP\ContentTranslation\Polylang\BlockPostIdTranslation;
use PLL_Export_Container;
use PLL_Export_Data_From_Posts;
use Throwable;
use WP_Error;
use WP_Post;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Clients\Client_Interface;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Processor;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services\Service_Interface;

if (! defined('ABSPATH')) {
    exit;
}

class MachineTranslation
{
    /** Translate the source content with the configured machine translation service. */
    public const modeAi = 'ai';

    /** Copy the source content as-is, without machine translation. */
    public const modeCopy = 'copy';

    private const action = 'gds_ct_machine_translate';

    /** Seconds after which a creation lock is treated as abandoned. */
    private const lockTimeout = 120;

    private static ?bool $available = null;

    private static ?bool $copyAvailable = null;

    public static function init(): void
    {
        $handler = new self;
        add_action('admin_post_'.self::action, [$handler, 'handleRequest']);
    }

    /**
     * Whether AI translation can run: Polylang Pro's machine translation
     * module is enabled and has a service configured.
     *
     * Any surprise from Polylang Pro (a renamed class, a changed signature)
     * means "not available", never a fatal on the status screen.
     */
    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            if (! self::isCopyAvailable() || ! class_exists(Factory::class) || ! interface_exists(Service_Interface::class)) {
                return self::$available = false;
            }

            $factory = new Factory(PLL()->model);

            return self::$available = $factory->is_enabled() && $factory->get_active_service() instanceof Service_Interface;
        } catch (Throwable $e) {
            return self::$available = false;
        }
    }

    /**
     * Whether a translation can be created as a copy of the source language.
     *
     * Only requires Polylang Pro's translation pipeline, not a machine
     * translation service.
     */
    public static function isCopyAvailable(): bool
    {
        if (self::$copyAvailable !== null) {
            return self::$copyAvailable;
        }

        try {
            return self::$copyAvailable = class_exists(Processor::class)
                && class_exists(Data::class)
                && class_exists(PLL_Export_Container::class)
                && class_exists(PLL_Export_Data_From_Posts::class)
                && interface_exists(Client_Interface::class)
                && method_exists(PLL_Export_Data_From_Posts::class, 'send_to_export')
                && method_exists(Processor::class, 'translate')
                && method_exists(Processor::class, 'save');
        } catch (Throwable $e) {
            return self::$copyAvailable = false;
        }
    }

    /**
     * Nonce for the create links. One per screen rather than one per link:
     * the handler checks the source, language, mode and capability itself,
     * and a per-link nonce cost a hash per missing cell on a 1,000-row table.
     */
    public static function createNonce(): string
    {
        return wp_create_nonce(self::action);
    }

    /**
     * Base URL of the create links, without the source/language/mode.
     */
    public static function getActionBaseUrl(string $postType = '', string $nonce = ''): string
    {
        return admin_url('admin-post.php').'?'.http_build_query(array_filter([
            'action' => self::action,
            'gds_ct_post_type' => $postType !== '' ? $postType : null,
            '_wpnonce' => $nonce !== '' ? $nonce : self::createNonce(),
        ]), '', '&');
    }

    public function getActionUrl(int $sourceId, string $langSlug, string $postType = '', string $mode = self::modeAi): string
    {
        return self::getActionBaseUrl($postType).'&'.http_build_query([
            'source_id' => $sourceId,
            'lang' => $langSlug,
            'mode' => $mode,
        ], '', '&');
    }

    public function handleRequest(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'gds-content-translation'), 403);
        }

        check_admin_referer(self::action);

        $sourceId = Request::id($_GET, 'source_id');
        $langSlug = Request::key($_GET, 'lang');
        $mode = Request::key($_GET, 'mode') === self::modeCopy ? self::modeCopy : self::modeAi;

        $sourcePost = $sourceId > 0 ? get_post($sourceId) : null;
        $language = $langSlug !== '' ? PLL()->model->get_language($langSlug) : false;

        // Everything is checked before anything is written.
        if (! $sourcePost instanceof WP_Post || ! $language) {
            $this->redirectWithNotice('error', __('Invalid translation request.', 'gds-content-translation'));
        }

        if (! pll_is_translated_post_type($sourcePost->post_type)) {
            $this->redirectWithNotice('error', __('This post type is not translated with Polylang.', 'gds-content-translation'));
        }

        if ($sourcePost->post_status === 'trash') {
            $this->redirectWithNotice('error', __('The original is in the trash. Restore it before translating it.', 'gds-content-translation'));
        }

        $sourceLanguage = pll_get_post_language($sourceId);

        if (! $sourceLanguage || $sourceLanguage !== pll_default_language('slug') || $sourceLanguage === $langSlug) {
            $this->redirectWithNotice('error', __('Translations are created from the default-language original, into another language.', 'gds-content-translation'));
        }

        $postTypeObject = get_post_type_object($sourcePost->post_type);

        if (! current_user_can('edit_post', $sourceId) || ! $postTypeObject || ! current_user_can($postTypeObject->cap->create_posts)) {
            $this->redirectWithNotice('error', __('You are not allowed to translate this post.', 'gds-content-translation'));
        }

        $translations = pll_get_post_translations($sourceId);
        $existing = (int) ($translations[$langSlug] ?? 0);

        if ($existing && get_post_status($existing) !== 'trash') {
            $this->redirectWithNotice('error', __('Translation already exists.', 'gds-content-translation'));
        }

        if ($mode === self::modeCopy && ! self::isCopyAvailable()) {
            $this->redirectWithNotice('error', __('Copying the source language is not available.', 'gds-content-translation'));
        }

        if ($mode === self::modeAi && ! self::isAvailable()) {
            $this->redirectWithNotice('error', __('Machine translation is not available.', 'gds-content-translation'));
        }

        // A double click, or two editors at once, would otherwise create two
        // translations and leave one of them unlinked.
        $lock = $this->acquireLock($sourceId, $langSlug);

        if ($lock === '') {
            $this->redirectWithNotice('error', __('This translation is already being created. Reload the status screen in a moment.', 'gds-content-translation'));
        }

        $unlinked = false;

        try {
            $client = $mode === self::modeCopy ? new SourceCopyClient : $this->getServiceClient();

            // A translation in the trash is still linked to its source, and
            // Polylang would refuse a second one for the same language. Unlink
            // it: it stays in the trash, now on its own, and the new
            // translation takes its place in the group. Done last, so a
            // request that fails validation leaves the group alone.
            if ($existing) {
                unset($translations[$langSlug]);
                pll_save_post_translations($translations);
                $unlinked = true;
            }

            $translationId = $this->translatePost($sourcePost, $language, $client);
        } catch (Throwable $e) {
            error_log(sprintf('[gds-content-translation] Creating the %s translation of post %d failed: %s', $langSlug, $sourceId, $e));
            $translationId = new WP_Error('gds_ct_exception', $e->getMessage());
        } finally {
            $this->releaseLock($lock);
        }

        if ($translationId instanceof WP_Error) {
            // Put the trashed translation back, so it can still be restored.
            if ($unlinked && ! pll_get_post($sourceId, $langSlug)) {
                $group = pll_get_post_translations($sourceId);
                $group[$langSlug] = $existing;
                pll_save_post_translations($group);
            }

            $this->redirectWithNotice('error', sprintf(
                /* translators: %s: error message */
                __('Translation failed: %s', 'gds-content-translation'),
                $translationId->get_error_message()
            ));
        }

        $editLink = get_edit_post_link($translationId, 'raw');

        if (! is_string($editLink) || $editLink === '') {
            $this->redirectWithNotice('success', __('Translation created.', 'gds-content-translation'));
        }

        wp_safe_redirect($editLink);
        exit;
    }

    private function getServiceClient(): Client_Interface
    {
        $factory = new Factory(PLL()->model);
        $service = $factory->get_active_service();

        if (! $service instanceof Service_Interface) {
            throw new \RuntimeException(__('Machine translation service is not configured.', 'gds-content-translation'));
        }

        return $service->get_client();
    }

    /**
     * Create a translation of a post through Polylang Pro's translation pipeline.
     *
     * Runs the translation directly instead of redirecting through post-new.php
     * and relying on per-user meta toggles, which can fail across environments.
     *
     * The client decides what the target strings become: the configured machine
     * translation service translates them, {@see SourceCopyClient} copies them.
     */
    private function translatePost(WP_Post $sourcePost, object $targetLang, Client_Interface $client): int|WP_Error
    {
        $polylang = PLL();

        if (! function_exists('get_default_post_to_edit')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        $currentLangBackup = $polylang->curlang;
        $polylang->curlang = null;

        try {
            $container = new PLL_Export_Container(Data::class);
            $exporter = new PLL_Export_Data_From_Posts($polylang->model);
            $exporter->send_to_export($container, [$sourcePost], $targetLang);

            $processor = new Processor($polylang, $client);

            $result = $processor->translate($container);

            if ($result->has_errors()) {
                return new WP_Error('gds_ct_translation_failed', implode('; ', $result->get_error_messages()));
            }

            $saveResult = $processor->save($container);
        } finally {
            $polylang->curlang = $currentLangBackup;
        }

        $translationId = (int) pll_get_post($sourcePost->ID, $targetLang->slug);

        if ($translationId <= 0) {
            return $saveResult->has_errors()
                ? new WP_Error('gds_ct_save_failed', implode('; ', $saveResult->get_error_messages()))
                : new WP_Error('gds_ct_no_translation', __('Unable to retrieve the translation.', 'gds-content-translation'));
        }

        // Polylang Pro saves entity by entity and carries on past errors (a
        // term, say). The post itself exists, so open it, and say what went
        // wrong on the status screen rather than calling it a failure.
        if ($saveResult->has_errors()) {
            $messages = implode('; ', $saveResult->get_error_messages());
            error_log(sprintf('[gds-content-translation] The %s translation of post %d was created with errors: %s', $targetLang->slug, $sourcePost->ID, $messages));
            Notice::flash('warning', sprintf(
                /* translators: %s: error messages */
                __('The translation was created, but some parts could not be saved: %s', 'gds-content-translation'),
                $messages
            ));
        }

        BlockPostIdTranslation::normalizePostContent($translationId);

        return $translationId;
    }

    /**
     * An atomic lock per source and language: the INSERT either creates the
     * row or fails on the unique option name. Not add_option(), whose
     * INSERT ... ON DUPLICATE KEY UPDATE succeeds for both racing requests.
     *
     * @return string The lock name, or '' when another request holds it.
     */
    private function acquireLock(int $sourceId, string $langSlug): string
    {
        global $wpdb;

        $name = "gds_ct_lock_{$sourceId}_{$langSlug}";
        $insert = static fn () => (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            (string) time()
        ));

        if ($insert() === 1) {
            return $name;
        }

        // Left behind by a request that died: take it over.
        $takenAt = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));

        if ($takenAt > 0 && time() - $takenAt < self::lockTimeout) {
            return '';
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $takenAt));

        return $insert() === 1 ? $name : '';
    }

    private function releaseLock(string $name): void
    {
        global $wpdb;

        if ($name !== '') {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        }
    }

    /**
     * @return never
     */
    private function redirectWithNotice(string $type, string $message): void
    {
        Notice::flash($type, $message);

        wp_safe_redirect(Admin::getPageUrl(Request::key($_GET, 'gds_ct_post_type')));
        exit;
    }
}
