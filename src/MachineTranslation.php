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

    /**
     * Seconds without a sign of life after which a creation lock is treated
     * as abandoned. A long translation keeps its lock fresh: each request it
     * makes to DeepL or Claude renews it ({@see refreshLock()}).
     */
    private const lockTimeout = 5 * MINUTE_IN_SECONDS;

    /** Seconds between two renewals of the lock. */
    private const lockRefresh = 30;

    /** @var array{0: string, 1: string}|null The lock this request holds: its name and value. */
    private ?array $lock = null;

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

        $warnings = [];
        $translationId = self::create(
            Request::id($_GET, 'source_id'),
            Request::key($_GET, 'lang'),
            Request::key($_GET, 'mode') === self::modeCopy ? self::modeCopy : self::modeAi,
            $warnings
        );

        if ($translationId instanceof WP_Error) {
            $this->redirectWithNotice('error', $translationId->get_error_message());
        }

        foreach ($warnings as $warning) {
            Notice::flash('warning', $warning);
        }

        // With a review running, the editor would save over the notes it adds:
        // land on the review instead, which updates itself when it is done.
        if (in_array(TranslationAudit::get($translationId)['status'] ?? '', ['pending', 'queued'], true)) {
            wp_safe_redirect(AuditReview::getUrl($translationId));
            exit;
        }

        $editLink = get_edit_post_link($translationId, 'raw');

        if (! is_string($editLink) || $editLink === '') {
            $this->redirectWithNotice('success', __('Translation created.', 'gds-content-translation'));
        }

        wp_safe_redirect($editLink);
        exit;
    }

    /**
     * Create the translation of a default-language post into another
     * language, as the current user. Shared by the status screen's links and
     * the bulk queue ({@see BulkTranslation}).
     *
     * Everything is checked before anything is written, and a second request
     * for the same post and language is refused while one is running.
     *
     * Error codes: gds_ct_invalid, gds_ct_exists, gds_ct_forbidden,
     * gds_ct_unavailable, gds_ct_locked, gds_ct_failed.
     *
     * @param  list<string>  $warnings  Filled with what went wrong in a translation that was still created.
     * @return int|WP_Error The new translation's ID.
     */
    public static function create(int $sourceId, string $langSlug, string $mode = self::modeAi, ?array &$warnings = null, string $followup = '', string $instructions = ''): int|WP_Error
    {
        $warnings = [];
        $sourcePost = $sourceId > 0 ? get_post($sourceId) : null;
        $language = $langSlug !== '' ? PLL()->model->get_language($langSlug) : false;

        if (! $sourcePost instanceof WP_Post || ! $language) {
            return new WP_Error('gds_ct_invalid', __('Invalid translation request.', 'gds-content-translation'));
        }

        if (! pll_is_translated_post_type($sourcePost->post_type)) {
            return new WP_Error('gds_ct_invalid', __('This post type is not translated with Polylang.', 'gds-content-translation'));
        }

        if ($sourcePost->post_status === 'trash') {
            return new WP_Error('gds_ct_invalid', __('The original is in the trash. Restore it before translating it.', 'gds-content-translation'));
        }

        $sourceLanguage = pll_get_post_language($sourceId);

        if (! $sourceLanguage || $sourceLanguage !== pll_default_language('slug') || $sourceLanguage === $langSlug) {
            return new WP_Error('gds_ct_invalid', __('Translations are created from the default-language original, into another language.', 'gds-content-translation'));
        }

        $postTypeObject = get_post_type_object($sourcePost->post_type);

        if (! current_user_can('edit_post', $sourceId) || ! $postTypeObject || ! current_user_can($postTypeObject->cap->create_posts)) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to translate this post.', 'gds-content-translation'));
        }

        $translations = pll_get_post_translations($sourceId);
        $existing = (int) ($translations[$langSlug] ?? 0);

        if ($existing && get_post_status($existing) !== 'trash') {
            return new WP_Error('gds_ct_exists', __('Translation already exists.', 'gds-content-translation'));
        }

        if ($mode === self::modeCopy && ! self::isCopyAvailable()) {
            return new WP_Error('gds_ct_unavailable', __('Copying the source language is not available.', 'gds-content-translation'));
        }

        if ($mode !== self::modeCopy && ! self::isAvailable()) {
            return new WP_Error('gds_ct_unavailable', __('Machine translation is not available.', 'gds-content-translation'));
        }

        $creator = new self;

        // A double click, or two editors at once, would otherwise create two
        // translations and leave one of them unlinked.
        if (! $creator->acquireLock($sourceId, $langSlug)) {
            return new WP_Error('gds_ct_locked', __('This translation is already being created. Reload the status screen in a moment.', 'gds-content-translation'));
        }

        $unlinked = false;
        $heartbeat = [$creator, 'refreshLock'];
        add_action('http_api_debug', $heartbeat);

        try {
            $client = $mode === self::modeCopy ? new SourceCopyClient : $creator->getServiceClient();

            // A glossary change not yet sent to DeepL goes before this translation.
            if ($mode !== self::modeCopy) {
                DeeplGlossary::syncIfPending();
            }

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

            $translationId = $creator->translatePost($sourcePost, $language, $client, $warnings, TranslationAudit::followup($followup), $instructions);
        } catch (Throwable $e) {
            error_log(sprintf('[gds-content-translation] Creating the %s translation of post %d failed: %s', $langSlug, $sourceId, $e));
            $translationId = new WP_Error('gds_ct_exception', $e->getMessage());
        } finally {
            remove_action('http_api_debug', $heartbeat);
            $creator->releaseLock();
        }

        if ($translationId instanceof WP_Error) {
            // Put the trashed translation back, so it can still be restored.
            if ($unlinked && ! pll_get_post($sourceId, $langSlug)) {
                $group = pll_get_post_translations($sourceId);
                $group[$langSlug] = $existing;
                pll_save_post_translations($group);
            }

            return new WP_Error('gds_ct_failed', sprintf(
                /* translators: %s: error message */
                __('Translation failed: %s', 'gds-content-translation'),
                $translationId->get_error_message()
            ));
        }

        return $translationId;
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
    /**
     * @param  list<string>  $warnings
     */
    private function translatePost(WP_Post $sourcePost, object $targetLang, Client_Interface $client, array &$warnings, string $followup = 'manual', string $instructions = ''): int|WP_Error
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

            $result = self::withoutNotices($processor->translate($container));

            if ($result->has_errors()) {
                return new WP_Error('gds_ct_translation_failed', implode('; ', $result->get_error_messages()));
            }

            $saveResult = self::withoutNotices($processor->save($container));
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
        // wrong rather than calling it a failure.
        if ($saveResult->has_errors()) {
            $messages = implode('; ', $saveResult->get_error_messages());
            error_log(sprintf('[gds-content-translation] The %s translation of post %d was created with errors: %s', $targetLang->slug, $sourcePost->ID, $messages));
            $warnings[] = sprintf(
                /* translators: %s: error messages */
                __('The translation was created, but some parts could not be saved: %s', 'gds-content-translation'),
                $messages
            );
        }

        BlockPostIdTranslation::normalizePostContent($translationId);

        // The scripts and styles of custom HTML blocks, which Polylang Pro's
        // kses took apart, as the original has them.
        DeeplCode::restoreInTranslation($sourcePost->ID, $translationId);

        // A copy is untranslated text: it stays a draft whatever the setting.
        if (! $client instanceof SourceCopyClient && self::publishesTranslations()) {
            self::publishTranslations($container, $targetLang);
        }

        // Soft hyphens where the language's long words in headings and buttons
        // may break; before the review, which then reviews the page as it is.
        if (! $client instanceof SourceCopyClient && Hyphenation::isEnabled()) {
            Hyphenation::applyToPost($translationId);
        }

        // What DeepL did not see: the labels in its SVGs and its forms' texts.
        // Before the review, so it reviews the page as it then is.
        if (! $client instanceof SourceCopyClient) {
            if (SvgTranslation::isAutomatic()) {
                SvgTranslation::translateAll($translationId);
            }

            if (FormTranslation::isAvailable() && DeeplApi::isAvailable()) {
                FormTranslation::translateFormsOf($translationId);
            }
        }

        // Kept to learn the glossary terms an editor changes before proofreading it.
        if (! $client instanceof SourceCopyClient) {
            GlossaryLearning::rememberMachineText($translationId);
        }

        // Reviewed in the background, so the editor opens without waiting for
        // it. A copy of the source has nothing to review.
        if (! $client instanceof SourceCopyClient && TranslationAudit::isEnabled()) {
            TranslationAudit::queue($translationId, $followup, $instructions);
        }

        return $translationId;
    }

    public const publishOption = 'gds_content_translation_publish';

    /**
     * Whether AI translations go live at once: a translation gets its
     * original's status (published when the original is), and patterns
     * are always published, since a draft pattern shows nothing on a page.
     */
    public static function publishesTranslations(): bool
    {
        return (bool) apply_filters('gds_content_translation_publish_translations', (bool) get_option(self::publishOption, true));
    }

    /**
     * Publish the new drafts Polylang Pro saved for the posts in the export:
     * the post itself and what it links to, such as its patterns. A
     * translation that was not a draft is left as it is.
     */
    private static function publishTranslations(PLL_Export_Container $container, object $targetLang): void
    {
        foreach ($container as $data) {
            if (! $data instanceof Data) {
                continue;
            }

            foreach (array_keys($data->get()['post'] ?? []) as $sourceId) {
                $source = get_post((int) $sourceId);
                $translationId = (int) pll_get_post((int) $sourceId, $targetLang->slug);

                if (! $source || $translationId <= 0 || get_post_status($translationId) !== 'draft') {
                    continue;
                }

                if ($source->post_type === 'wp_block' || $source->post_status === 'publish') {
                    wp_update_post(['ID' => $translationId, 'post_status' => 'publish']);
                }
            }
        }
    }

    /**
     * The errors only. Polylang Pro 3.9 also reports how many characters
     * DeepL billed, as an "info" entry in the same WP_Error.
     */
    private static function withoutNotices(WP_Error $result): WP_Error
    {
        $errors = new WP_Error;

        foreach ($result->get_error_codes() as $code) {
            if (in_array($result->get_error_data($code), ['info', 'success'], true)) {
                continue;
            }

            foreach ($result->get_error_messages($code) as $message) {
                $errors->add($code, $message);
            }
        }

        return $errors;
    }

    /**
     * An atomic lock per source and language: the INSERT either creates the
     * row or fails on the unique option name. Not add_option(), whose
     * INSERT ... ON DUPLICATE KEY UPDATE succeeds for both racing requests.
     *
     * The value is "<time>:<owner>": the time it was last renewed, and a
     * token of this request, so only the request that holds the lock renews
     * or releases it, not one that took it over after it was given up for dead.
     */
    private function acquireLock(int $sourceId, string $langSlug): bool
    {
        global $wpdb;

        $name = "gds_ct_lock_{$sourceId}_{$langSlug}";
        $value = time().':'.wp_generate_password(12, false);
        $insert = static fn () => (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $value
        ));

        if ($insert() !== 1) {
            // Left behind by a request that died: take it over.
            $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            $renewedAt = (int) $current;

            if ($renewedAt > 0 && time() - $renewedAt < self::lockTimeout) {
                return false;
            }

            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $current));

            if ($insert() !== 1) {
                return false;
            }
        }

        $this->lock = [$name, $value];

        return true;
    }

    /**
     * Renew the lock, at most every half a minute. Hooked to every HTTP
     * request while the translation is created.
     */
    public function refreshLock(): void
    {
        global $wpdb;

        if ($this->lock === null || time() - (int) $this->lock[1] < self::lockRefresh) {
            return;
        }

        [$name, $value] = $this->lock;
        $renewed = time().substr($value, strpos($value, ':'));

        if ((int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $renewed, $name, $value)) === 1) {
            $this->lock[1] = $renewed;
        }
    }

    private function releaseLock(): void
    {
        global $wpdb;

        if ($this->lock !== null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", ...$this->lock));
            $this->lock = null;
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
