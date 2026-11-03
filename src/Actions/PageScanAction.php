<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\PageScan;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Check the pages of the rows' translations for text still in the
 * original's language ({@see PageScan}).
 *
 * Asked twice: first with `estimate` for what it would cost (the screen
 * sends the rows a few at a time, as each page is fetched), then to queue
 * the checks, which run in the background one at a time.
 */
final class PageScanAction extends BaseAction
{
    public function id(): string
    {
        return 'page-scan';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        if (! PageScan::isAvailable()) {
            return new WP_Error('gds_ct_audit_disabled', __('The page check needs the AI review. Check the Claude API key and the Content translation settings.', 'gds-content-translation'), ['status' => 400]);
        }

        $sanitized = parent::sanitizeArgs($args);

        return $sanitized instanceof WP_Error ? $sanitized : $sanitized + ['estimate' => ! empty($args['estimate'])];
    }

    public function can(int $sourceId, array $args): bool
    {
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $translations = array_filter($this->liveTranslations($sourceId, $args['languages']), static fn (int $postId): bool => current_user_can('edit_post', $postId));

        if ($translations === []) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to check these translations.', 'gds-content-translation'));
        }

        return $args['estimate'] ? $this->estimate($translations) : $this->queue($translations);
    }

    /**
     * @param  array<string, int>  $translations
     * @return array<string, mixed>
     */
    private function estimate(array $translations): array
    {
        $totals = ['pages' => 0, 'texts' => 0, 'characters' => 0, 'tokens' => 0, 'outputTokens' => 0, 'price' => 0.0, 'failed' => 0, 'errors' => []];

        foreach ($translations as $postId) {
            $estimate = PageScan::estimate($postId, get_current_user_id());

            if ($estimate instanceof WP_Error) {
                $totals['failed']++;
                $totals['errors'][] = $estimate->get_error_message();

                continue;
            }

            $totals['pages']++;

            foreach (['texts', 'characters', 'tokens', 'outputTokens', 'price'] as $key) {
                $totals[$key] += $estimate[$key];
            }

            $totals['modelLabel'] = $estimate['modelLabel'];
        }

        $totals['errors'] = array_values(array_unique($totals['errors']));

        return $totals;
    }

    /**
     * @param  array<string, int>  $translations
     * @return array{queued: int, running: int}
     */
    private function queue(array $translations): array
    {
        $queued = 0;
        $running = 0;

        foreach ($translations as $postId) {
            PageScan::queue($postId, get_current_user_id()) ? $queued++ : $running++;
        }

        return ['queued' => $queued, 'running' => $running];
    }

    public function summarize(array $results, array $args): string
    {
        if ($args['estimate']) {
            return '';
        }

        $queued = self::total($results, 'queued');
        $running = self::total($results, 'running');

        $message = $queued > 0
            /* translators: %d: number of translations */
            ? sprintf(_n('The check of %d page is queued. It runs in the background.', 'The checks of %d pages are queued. They run in the background, one at a time.', $queued, 'gds-content-translation'), $queued)
            : __('No page check was queued.', 'gds-content-translation');

        if ($running > 0) {
            /* translators: %d: number of translations */
            $message .= ' '.sprintf(_n('%d is being checked already.', '%d are being checked already.', $running, 'gds-content-translation'), $running);
        }

        return $message;
    }
}
