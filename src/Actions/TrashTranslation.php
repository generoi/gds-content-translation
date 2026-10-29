<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\TranslationActions;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Trash, restore or permanently delete the translation of a row in one
 * language (the cell's Trash, Restore and Delete permanently). Never the
 * row's own post: {@see TranslationActions} refuses a default-language post.
 */
final class TrashTranslation extends BaseAction
{
    public const operations = ['trash-translation', 'restore-translation', 'delete-translation'];

    public function __construct(private string $operation) {}

    public function id(): string
    {
        return $this->operation;
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        $sanitized = parent::sanitizeArgs($args);

        if (! $sanitized instanceof WP_Error && (empty($args['languages']) || count($sanitized['languages']) !== 1)) {
            return new WP_Error('gds_ct_invalid_language', __('Name the one language to change.', 'gds-content-translation'), ['status' => 400]);
        }

        return $sanitized;
    }

    public function can(int $sourceId, array $args): bool
    {
        // TranslationActions checks the translation itself.
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $translationId = $this->translationId($sourceId, $args['languages'][0]);

        if ($translationId <= 0) {
            return new WP_Error('gds_ct_not_found', __('That translation no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
        }

        $result = match ($this->operation) {
            'trash-translation' => TranslationActions::trashTranslation($translationId),
            'restore-translation' => TranslationActions::restoreTranslation($translationId),
            default => TranslationActions::deleteTranslation($translationId),
        };

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (! $result['done']) {
            return new WP_Error('gds_ct_failed', $result['message']);
        }

        return ['message' => $result['message'], 'postId' => $translationId];
    }

    public function summarize(array $results, array $args): string
    {
        return self::singleMessage($results) ?? sprintf(
            /* translators: %d: number of translations */
            _n('%d translation changed.', '%d translations changed.', count($results), 'gds-content-translation'),
            count($results)
        );
    }
}
