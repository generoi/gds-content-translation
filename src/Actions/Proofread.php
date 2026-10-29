<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\TranslationActions;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Mark the translations of rows proof read, or not proof read. A missing or
 * trashed translation has nothing to proof read and is left out.
 */
final class Proofread extends BaseAction
{
    public function id(): string
    {
        return 'proofread';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        $sanitized = parent::sanitizeArgs($args);

        if (! $sanitized instanceof WP_Error) {
            $sanitized['proofread'] = rest_sanitize_boolean($args['proofread'] ?? true);
        }

        return $sanitized;
    }

    public function can(int $sourceId, array $args): bool
    {
        // Checked per translation in run(): the row itself is not changed.
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $changed = 0;
        $forbidden = 0;

        foreach ($this->liveTranslations($sourceId, $args['languages']) as $translationId) {
            if (! current_user_can('edit_post', $translationId)) {
                $forbidden++;

                continue;
            }

            if (TranslationActions::setProofread($translationId, $args['proofread']) === true) {
                $changed++;
            }
        }

        if ($changed === 0 && $forbidden > 0) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to change these translations.', 'gds-content-translation'));
        }

        return ['changed' => $changed, 'forbidden' => $forbidden];
    }

    public function summarize(array $results, array $args): string
    {
        $changed = self::total($results, 'changed');

        if ($changed === 0) {
            return __('There were no translations to change.', 'gds-content-translation');
        }

        return $args['proofread']
            /* translators: %d: number of translations */
            ? sprintf(_n('%d translation marked as proofread.', '%d translations marked as proofread.', $changed, 'gds-content-translation'), $changed)
            /* translators: %d: number of translations */
            : sprintf(_n('%d translation marked as not proofread.', '%d translations marked as not proofread.', $changed, 'gds-content-translation'), $changed);
    }
}
