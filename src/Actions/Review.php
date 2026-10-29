<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\TranslationAudit;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Run the AI review of the translations of rows, first or again, on their
 * current text. A translation someone has open in the editor is left out:
 * the review adds notes to its content, which their next save would remove.
 */
final class Review extends BaseAction
{
    public function id(): string
    {
        return 'review';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        if (! TranslationAudit::isEnabled()) {
            return new WP_Error('gds_ct_audit_disabled', __('The AI review is not available. Check the Claude API key and the Content translation settings.', 'gds-content-translation'), ['status' => 400]);
        }

        return parent::sanitizeArgs($args);
    }

    public function can(int $sourceId, array $args): bool
    {
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $queued = 0;
        $locked = 0;
        $running = 0;
        $forbidden = 0;

        if (! function_exists('wp_check_post_lock')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        $statuses = TranslationAudit::statuses(array_values($this->liveTranslations($sourceId, $args['languages'])));

        foreach ($this->liveTranslations($sourceId, $args['languages']) as $translationId) {
            if (! current_user_can('edit_post', $translationId)) {
                $forbidden++;
            } elseif (($statuses[$translationId]['status'] ?? '') === 'pending') {
                $running++;
            } elseif (wp_check_post_lock($translationId)) {
                $locked++;
            } else {
                TranslationAudit::queue($translationId);
                $queued++;
            }
        }

        if ($queued === 0 && $forbidden > 0) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to review these translations.', 'gds-content-translation'));
        }

        return ['queued' => $queued, 'locked' => $locked, 'running' => $running];
    }

    public function summarize(array $results, array $args): string
    {
        $queued = self::total($results, 'queued');
        $locked = self::total($results, 'locked');
        $running = self::total($results, 'running');

        $message = $queued > 0
            /* translators: %d: number of translations */
            ? sprintf(_n('The AI review of %d translation is queued.', 'The AI review of %d translations is queued.', $queued, 'gds-content-translation'), $queued)
            : __('No AI review was queued.', 'gds-content-translation');

        if ($running > 0) {
            /* translators: %d: number of translations */
            $message .= ' '.sprintf(_n('%d is already being reviewed.', '%d are already being reviewed.', $running, 'gds-content-translation'), $running);
        }

        if ($locked > 0) {
            /* translators: %d: number of translations */
            $message .= ' '.sprintf(_n('%d is open in the editor and was left out.', '%d are open in the editor and were left out.', $locked, 'gds-content-translation'), $locked);
        }

        return $message;
    }
}
