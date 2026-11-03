<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Drafts of documents translated by DeepL (PDF, Word, PowerPoint, Excel),
 * when the setting allows it: off by default, since DeepL bills every
 * document as at least 50,000 characters, a page's worth of translations
 * for a one-page price list, and a designed PDF's layout can break.
 *
 * Asked for one file at a time, after the cost is shown. DeepL translates
 * in the background; the draft is saved in the media library as a copy of
 * the file ("guide-en.pdf") and used only when someone has looked at it
 * and chooses to. One draft per file and language, shared by every
 * translation that links the file.
 */
final class DocumentTranslation
{
    public const optionKey = 'gds_content_translation_documents';

    /** On a translation: file key => the DeepL job for it. */
    public const jobsMetaKey = '_gds_content_translation_document_jobs';

    private const pollHook = 'gds_content_translation_poll_document';

    /** Seconds between asking DeepL how far it is. */
    private const pollInterval = 10;

    /** A job DeepL has not finished by then is given up. */
    private const timeout = 30 * MINUTE_IN_SECONDS;

    public const extensions = ['pdf', 'docx', 'doc', 'pptx', 'xlsx'];

    public static function init(): void
    {
        add_action(self::pollHook, [self::class, 'poll'], 10, 2);
    }

    public static function isEnabled(): bool
    {
        return (bool) apply_filters('gds_content_translation_documents_enabled', (bool) get_option(self::optionKey, false)) && DeeplApi::isAvailable();
    }

    public static function supports(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::extensions, true);
    }

    /**
     * What translating a file costs, as far as can be known before: DeepL's
     * minimum, its price, and what is left of the plan this period.
     *
     * @return array{characters: int, price: float, free: bool, used: int, limit: int, enough: bool}
     */
    public static function estimate(): array
    {
        $characters = DeeplApi::documentMinimumCharacters;
        $pricePerMillion = (float) apply_filters('gds_content_translation_deepl_price_per_million', DeeplApi::defaultPricePerMillion);
        $usage = get_transient('gds_ct_deepl_usage');

        if (! is_array($usage)) {
            $usage = DeeplApi::usage();
            $usage = $usage instanceof WP_Error ? ['count' => 0, 'limit' => 0, 'failed' => true] : $usage;
            set_transient('gds_ct_deepl_usage', $usage, 5 * MINUTE_IN_SECONDS);
        }

        $free = DeeplApi::isFreePlan();

        return [
            'characters' => $characters,
            // The free plan has no price, only its monthly characters.
            'price' => $free ? 0.0 : round($characters / 1_000_000 * $pricePerMillion, 2),
            'free' => $free,
            'used' => (int) $usage['count'],
            'limit' => (int) $usage['limit'],
            'enough' => (int) $usage['limit'] === 0 || (int) $usage['limit'] - (int) $usage['count'] >= $characters,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function jobs(int $postId): array
    {
        $jobs = get_post_meta($postId, self::jobsMetaKey, true);

        return is_array($jobs) ? $jobs : [];
    }

    /**
     * @param  array<string, mixed>|null  $job
     */
    private static function saveJob(int $postId, int $sourceId, ?array $job): void
    {
        $jobs = self::jobs($postId);

        if ($job === null) {
            unset($jobs[(string) $sourceId]);
        } else {
            $jobs[(string) $sourceId] = $job;
        }

        update_post_meta($postId, self::jobsMetaKey, $jobs);
    }

    /**
     * Have DeepL translate a file for a translation, or take the draft made
     * for another translation in the same language.
     */
    public static function start(int $postId, int $sourceId): true|WP_Error
    {
        $language = PLL()->model->post->get_language($postId);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $file = get_attached_file($sourceId);

        if (! self::isEnabled()) {
            return new WP_Error('gds_ct_documents_off', __('Translating documents with DeepL is off in the Content translation settings.', 'gds-content-translation'));
        }

        if (! $language || ! $default || ! $file || ! self::supports($file)) {
            return new WP_Error('gds_ct_document_unsupported', __('DeepL cannot translate this file.', 'gds-content-translation'));
        }

        $existing = MediaCheck::copyOf($sourceId, $language->slug);

        if ($existing > 0) {
            self::saveJob($postId, $sourceId, ['status' => 'ready', 'attachmentId' => $existing, 'billed' => 0, 'error' => '', 'startedAt' => time()]);

            return true;
        }

        $upload = DeeplApi::uploadDocument($file, $default, $language);

        if ($upload instanceof WP_Error) {
            return $upload;
        }

        delete_transient('gds_ct_deepl_usage');
        self::saveJob($postId, $sourceId, ['status' => 'translating', 'id' => $upload['id'], 'key' => $upload['key'], 'startedAt' => time(), 'secondsRemaining' => 0, 'error' => '']);
        wp_schedule_single_event(time() + self::pollInterval, self::pollHook, [$postId, $sourceId]);

        return true;
    }

    /**
     * Ask DeepL how far a job is, and save the draft when it is done.
     */
    public static function poll(int $postId, int $sourceId): void
    {
        $job = self::jobs($postId)[(string) $sourceId] ?? null;

        if (! is_array($job) || ($job['status'] ?? '') !== 'translating') {
            return;
        }

        $status = DeeplApi::documentStatus((string) $job['id'], (string) $job['key']);

        if ($status instanceof WP_Error || $status['status'] === 'error') {
            self::saveJob($postId, $sourceId, array_merge($job, ['status' => 'failed', 'error' => $status instanceof WP_Error ? $status->get_error_message() : $status['error']]));

            return;
        }

        if ($status['status'] !== 'done') {
            if (time() - (int) $job['startedAt'] > self::timeout) {
                self::saveJob($postId, $sourceId, array_merge($job, ['status' => 'failed', 'error' => __('DeepL did not finish the file in 30 minutes.', 'gds-content-translation')]));

                return;
            }

            self::saveJob($postId, $sourceId, array_merge($job, ['secondsRemaining' => $status['secondsRemaining']]));
            wp_schedule_single_event(time() + self::pollInterval, self::pollHook, [$postId, $sourceId]);

            return;
        }

        $bytes = DeeplApi::documentResult((string) $job['id'], (string) $job['key']);
        $attachmentId = $bytes instanceof WP_Error ? $bytes : self::saveDraft($postId, $sourceId, $bytes, $status['billedCharacters']);

        if ($attachmentId instanceof WP_Error) {
            self::saveJob($postId, $sourceId, array_merge($job, ['status' => 'failed', 'error' => $attachmentId->get_error_message()]));

            return;
        }

        self::saveJob($postId, $sourceId, ['status' => 'ready', 'attachmentId' => $attachmentId, 'billed' => $status['billedCharacters'], 'error' => '', 'startedAt' => (int) $job['startedAt']]);
        delete_transient('gds_ct_deepl_usage');
    }

    private static function saveDraft(int $postId, int $sourceId, string $bytes, int $billed): int|WP_Error
    {
        $language = PLL()->model->post->get_language($postId);
        $file = get_attached_file($sourceId);

        if (! $language || ! $file || $bytes === '') {
            return new WP_Error('gds_ct_document_result', __('DeepL sent no file back.', 'gds-content-translation'));
        }

        $info = pathinfo($file);
        $target = path_join($info['dirname'], wp_unique_filename($info['dirname'], $info['filename'].'-'.$language->slug.'.'.$info['extension']));

        if (file_put_contents($target, $bytes) === false) {
            return new WP_Error('gds_ct_document_write', __('The translated file could not be saved in the uploads folder.', 'gds-content-translation'));
        }

        $source = get_post($sourceId);
        $attachmentId = wp_insert_attachment([
            'post_mime_type' => (string) get_post_mime_type($sourceId),
            'post_title' => sprintf(
                /* translators: 1: file title, 2: language code, e.g. EN */
                __('%1$s (%2$s, DeepL)', 'gds-content-translation'),
                $source ? $source->post_title : $info['filename'],
                strtoupper($language->slug)
            ),
            'post_status' => 'inherit',
        ], $target, 0, true);

        if ($attachmentId instanceof WP_Error) {
            return $attachmentId;
        }

        update_post_meta($attachmentId, MediaCheck::copyOfMetaKey, $sourceId);
        update_post_meta($attachmentId, MediaCheck::copyLanguageMetaKey, $language->slug);
        update_post_meta($attachmentId, '_gds_content_translation_deepl_billed', $billed);

        if (! empty(PLL()->options['media_support'])) {
            pll_set_post_language($attachmentId, $language->slug);
        }

        return (int) $attachmentId;
    }

    /**
     * Link the draft instead of the original's file.
     */
    public static function useDraft(int $postId, int $sourceId): true|WP_Error
    {
        $job = self::jobs($postId)[(string) $sourceId] ?? null;
        $draftId = is_array($job) ? (int) ($job['attachmentId'] ?? 0) : 0;

        if ($draftId <= 0 || get_post_type($draftId) !== 'attachment') {
            return new WP_Error('gds_ct_document_no_draft', __('There is no draft to use. Translate the file again.', 'gds-content-translation'));
        }

        $result = MediaReplace::attachment($postId, $sourceId, $draftId);

        if ($result === true) {
            self::saveJob($postId, $sourceId, null);
        }

        return $result;
    }

    /**
     * Forget a job; a draft it made stays in the media library for the
     * other translations in its language.
     */
    public static function discard(int $postId, int $sourceId): true
    {
        self::saveJob($postId, $sourceId, null);

        return true;
    }
}
