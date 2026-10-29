<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\BulkTranslation;
use GeneroWP\ContentTranslation\MachineTranslation;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Queue the AI translation of rows into the languages they are missing in.
 * The translations are created in the background, one at a time
 * ({@see BulkTranslation}); this only queues them.
 */
final class Translate extends BaseAction
{
    public function id(): string
    {
        return 'translate';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        if (! MachineTranslation::isAvailable()) {
            return new WP_Error('gds_ct_unavailable', __('Machine translation is not available.', 'gds-content-translation'), ['status' => 400]);
        }

        return parent::sanitizeArgs($args);
    }

    public function can(int $sourceId, array $args): bool
    {
        $postType = get_post_type_object((string) get_post_type($sourceId));

        return current_user_can('edit_post', $sourceId) && $postType && current_user_can($postType->cap->create_posts);
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        if (get_post_status($sourceId) === 'trash') {
            return new WP_Error('gds_ct_invalid', __('The original is in the trash. Restore it before translating it.', 'gds-content-translation'));
        }

        $outcome = BulkTranslation::enqueue([$sourceId], $args['languages'])[$sourceId] ?? [];
        $counts = array_count_values($outcome);

        return [
            'queued' => $counts['queued'] ?? 0,
            'exists' => $counts['exists'] ?? 0,
            'waiting' => ($counts['running'] ?? 0),
            'languages' => $outcome,
        ];
    }

    public function summarize(array $results, array $args): string
    {
        $queued = self::total($results, 'queued');
        $exists = self::total($results, 'exists');

        $message = $queued > 0
            /* translators: %d: number of translations */
            ? sprintf(_n('%d AI translation queued. It is created in the background.', '%d AI translations queued. They are created in the background, one at a time.', $queued, 'gds-content-translation'), $queued)
            : __('No AI translation was queued.', 'gds-content-translation');

        if ($exists > 0) {
            /* translators: %d: number of translations */
            $message .= ' '.sprintf(_n('%d translation exists already.', '%d translations exist already.', $exists, 'gds-content-translation'), $exists);
        }

        return $message;
    }
}
