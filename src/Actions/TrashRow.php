<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\TranslationActions;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Trash all: rows' posts with all their translations, and its Undo, which
 * restores exactly the posts it trashed (`args.posts`: row ID => post IDs).
 * All or nothing per row on permissions ({@see TranslationActions::trashGroup()}).
 */
final class TrashRow extends BaseAction
{
    public function __construct(private bool $restore = false) {}

    public function id(): string
    {
        return $this->restore ? 'restore' : 'trash';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        if (! $this->restore) {
            return [];
        }

        $posts = [];

        foreach ((array) ($args['posts'] ?? []) as $sourceId => $postIds) {
            $posts[(int) $sourceId] = array_values(array_filter(array_map('intval', (array) $postIds)));
        }

        return ['posts' => $posts];
    }

    public function can(int $sourceId, array $args): bool
    {
        // TranslationActions checks every post of the group.
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $result = $this->restore
            ? TranslationActions::restoreGroup($sourceId, $args['posts'][$sourceId] ?? [])
            : TranslationActions::trashGroup($sourceId);

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (! $result['done']) {
            return new WP_Error('gds_ct_failed', $result['message'], ['changed' => $result['changed']]);
        }

        return [
            'message' => $result['message'],
            'changed' => $result['changed'],
            'translations' => $result['translations'],
        ];
    }

    public function summarize(array $results, array $args): string
    {
        $single = self::singleMessage($results);

        if ($single !== null) {
            return $single;
        }

        $posts = count(array_filter($results, static fn (array $result): bool => $result['ok']));
        $translations = self::total($results, 'translations');

        if ($this->restore) {
            return $translations > 0
                /* translators: 1: number of posts, 2: number of translations */
                ? sprintf(_n('%1$d post and %2$d translations were restored from the trash.', '%1$d posts and %2$d translations were restored from the trash.', $posts, 'gds-content-translation'), $posts, $translations)
                /* translators: %d: number of posts */
                : sprintf(_n('%d post was restored from the trash.', '%d posts were restored from the trash.', $posts, 'gds-content-translation'), $posts);
        }

        return $translations > 0
            /* translators: 1: number of posts, 2: number of translations */
            ? sprintf(_n('%1$d post and %2$d translations were moved to the trash.', '%1$d posts and %2$d translations were moved to the trash.', $posts, 'gds-content-translation'), $posts, $translations)
            /* translators: %d: number of posts */
            : sprintf(_n('%d post was moved to the trash.', '%d posts were moved to the trash.', $posts, 'gds-content-translation'), $posts);
    }
}
