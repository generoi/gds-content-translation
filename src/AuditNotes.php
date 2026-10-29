<?php

namespace GeneroWP\ContentTranslation;

use WP_Comment;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The AI review's remarks as block notes in the editor (WordPress 6.9+).
 *
 * A note is a comment of type "note" on the post, open while unapproved and
 * resolved once approved; the block it belongs to lists its ID in
 * `metadata.noteId`. Each remark gets its own note on the innermost block
 * holding its text. Resolving or deleting the note in the editor closes the
 * remark, and closing the remark on the review page resolves the note.
 */
final class AuditNotes
{
    /** On the note: the ID of the remark it was made for. */
    public const remarkMetaKey = '_gds_content_translation_audit_remark';

    /** Set while this class changes a note itself, so the sync hooks stay out. */
    private static bool $syncing = false;

    public static function init(): void
    {
        add_action('transition_comment_status', [self::class, 'syncFromNote'], 10, 3);
        add_action('delete_comment', [self::class, 'syncFromDeletedNote'], 10, 2);
    }

    /**
     * Add a note for every open remark and link it to its block.
     *
     * @param  array<string, mixed>  $review
     * @return array<string, mixed> The review with each remark's noteId and the new content hash.
     */
    public static function add(int $postId, array $review): array
    {
        $post = get_post($postId);

        if (! $post || ! has_blocks($post->post_content) || ! post_type_supports($post->post_type, 'editor')) {
            return $review;
        }

        $segments = array_column($review['segments'], null, 'id');
        $blocks = parse_blocks($post->post_content);
        $changed = false;

        foreach ($review['remarks'] as $index => $remark) {
            $segment = $segments[$remark['segment']] ?? null;

            if (! $segment || $segment['block'] === null || ! isset($blocks[$segment['block']]) || ($remark['state'] ?? 'open') !== 'open') {
                continue;
            }

            $noteId = (int) wp_insert_comment([
                'comment_post_ID' => $postId,
                'comment_type' => 'note',
                'comment_approved' => 0,
                'comment_content' => self::noteText($remark),
                'comment_author' => __('AI review', 'gds-content-translation'),
                'user_id' => (int) ($review['requestedBy'] ?? 0),
                'comment_meta' => [self::remarkMetaKey => (int) $remark['id']],
            ]);

            if ($noteId <= 0) {
                continue;
            }

            $path = self::blockPath($blocks[$segment['block']], (string) $segment['translation']);
            $blocks[$segment['block']] = self::addNoteId($blocks[$segment['block']], $path, $noteId);
            $review['remarks'][$index]['noteId'] = $noteId;
            $changed = true;
        }

        if ($changed) {
            $content = serialize_blocks($blocks);
            self::saveContent($postId, $content);
            $review['contentHash'] = TranslationAudit::contentHash($postId);
        }

        return $review;
    }

    /**
     * Delete notes made by an earlier review and unlink them from their blocks.
     *
     * @param  list<int>  $noteIds
     */
    public static function remove(int $postId, array $noteIds): void
    {
        $noteIds = array_values(array_filter($noteIds));

        if ($noteIds === []) {
            return;
        }

        self::$syncing = true;

        foreach ($noteIds as $noteId) {
            $note = get_comment($noteId);

            if ($note instanceof WP_Comment && (int) $note->comment_post_ID === $postId && get_comment_meta($noteId, self::remarkMetaKey, true) !== '') {
                // Replies to the note go with it.
                foreach (get_comments(['parent' => $noteId, 'type' => 'note', 'status' => 'all', 'fields' => 'ids']) as $replyId) {
                    wp_delete_comment((int) $replyId, true);
                }

                wp_delete_comment($noteId, true);
            }
        }

        self::$syncing = false;

        $post = get_post($postId);

        if (! $post || ! has_blocks($post->post_content)) {
            return;
        }

        $blocks = array_map(static fn (array $block): array => self::removeNoteIds($block, $noteIds), parse_blocks($post->post_content));
        $content = serialize_blocks($blocks);

        if ($content !== $post->post_content) {
            self::saveContent($postId, $content);
        }
    }

    /**
     * Open the note again for an open remark, resolve it for a closed one.
     */
    public static function setState(int $noteId, string $state): void
    {
        if ($noteId <= 0 || ! get_comment($noteId)) {
            return;
        }

        self::$syncing = true;
        wp_set_comment_status($noteId, $state === 'open' ? 'hold' : 'approve');
        self::$syncing = false;
    }

    /**
     * A note resolved, reopened or trashed in the editor.
     */
    public static function syncFromNote(string $newStatus, string $oldStatus, WP_Comment $comment): void
    {
        if (self::$syncing || $comment->comment_type !== 'note') {
            return;
        }

        $remarkId = (int) get_comment_meta((int) $comment->comment_ID, self::remarkMetaKey, true);

        if ($remarkId <= 0) {
            return;
        }

        $state = match ($newStatus) {
            'approved' => 'resolved',
            'unapproved' => 'open',
            'trash', 'spam' => 'dismissed',
            default => '',
        };

        if ($state !== '') {
            TranslationAudit::setRemarkState((int) $comment->comment_post_ID, $remarkId, $state, false);
        }
    }

    public static function syncFromDeletedNote(int|string $commentId, ?WP_Comment $comment = null): void
    {
        $comment ??= get_comment((int) $commentId);

        if (self::$syncing || ! $comment instanceof WP_Comment || $comment->comment_type !== 'note') {
            return;
        }

        $remarkId = (int) get_comment_meta((int) $comment->comment_ID, self::remarkMetaKey, true);

        if ($remarkId > 0) {
            TranslationAudit::setRemarkState((int) $comment->comment_post_ID, $remarkId, 'dismissed', false);
        }
    }

    /**
     * @param  array<string, mixed>  $remark
     */
    private static function noteText(array $remark): string
    {
        $text = sprintf('AI · %s · %s', AuditReview::severityLabel($remark['severity']), AuditReview::categoryLabel($remark['category']))."\n".$remark['issue'];

        if ($remark['suggestion'] !== '') {
            $text .= "\n\n".__('Suggestion:', 'gds-content-translation').' '.wp_strip_all_tags($remark['suggestion']);
        }

        return $text;
    }

    /**
     * The path of inner block indexes to the innermost block holding the text.
     *
     * @param  array<string, mixed>  $block
     * @return list<int>
     */
    private static function blockPath(array $block, string $text): array
    {
        foreach ($block['innerBlocks'] as $index => $inner) {
            if (in_array($text, TranslationAudit::strings(serialize_block($inner)), true)) {
                return array_merge([$index], self::blockPath($inner, $text));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<int>  $path
     * @return array<string, mixed>
     */
    private static function addNoteId(array $block, array $path, int $noteId): array
    {
        if ($path !== []) {
            $index = array_shift($path);
            $block['innerBlocks'][$index] = self::addNoteId($block['innerBlocks'][$index], $path, $noteId);

            return $block;
        }

        $current = $block['attrs']['metadata']['noteId'] ?? [];
        $ids = array_map('intval', is_array($current) ? $current : [$current]);
        $ids[] = $noteId;
        $block['attrs']['metadata']['noteId'] = array_values(array_unique(array_filter($ids)));

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<int>  $noteIds
     * @return array<string, mixed>
     */
    private static function removeNoteIds(array $block, array $noteIds): array
    {
        if (isset($block['attrs']['metadata']['noteId'])) {
            $current = $block['attrs']['metadata']['noteId'];
            $ids = array_values(array_diff(array_map('intval', is_array($current) ? $current : [$current]), $noteIds));

            if ($ids === []) {
                unset($block['attrs']['metadata']['noteId']);

                if ($block['attrs']['metadata'] === []) {
                    unset($block['attrs']['metadata']);
                }
            } else {
                $block['attrs']['metadata']['noteId'] = $ids;
            }
        }

        $block['innerBlocks'] = array_map(static fn (array $inner): array => self::removeNoteIds($inner, $noteIds), $block['innerBlocks']);

        return $block;
    }

    private static function saveContent(int $postId, string $content): void
    {
        // A direct update: no revision, no modified date, no save hooks that
        // would copy anything to the other translations.
        global $wpdb;

        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $postId]);
        clean_post_cache($postId);
    }
}
