<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What the status screen does to posts: trash, restore or permanently delete
 * one translation, trash a post with all its translations and undo that, and
 * mark translations proof read.
 *
 * Shared by the admin-post links ({@see Admin}) and the REST actions behind
 * the status screen, so both check and do exactly the same. Nothing here
 * redirects or prints: each returns what happened, in words for a notice.
 */
final class TranslationActions
{
    /**
     * A translation the current user may trash, restore or delete. Error
     * codes: gds_ct_not_found, gds_ct_forbidden, gds_ct_not_translation.
     *
     * @return array{post: \WP_Post, language: string, languageName: string, sourceId: int, title: string}|WP_Error
     */
    public static function translation(int $postId): array|WP_Error
    {
        $post = $postId > 0 ? get_post($postId) : null;

        if (! $post) {
            return new WP_Error('gds_ct_not_found', __('That translation no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
        }

        if (! current_user_can('delete_post', $postId)) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to change this translation.', 'gds-content-translation'));
        }

        $defaultSlug = pll_default_language('slug');
        $languageSlug = pll_get_post_language($postId);

        // Only translations: the source-language post is never touched here,
        // so a row cannot disappear by accident.
        if (! $languageSlug || $languageSlug === $defaultSlug) {
            return new WP_Error('gds_ct_not_translation', __('Only translations can be trashed, restored or deleted here.', 'gds-content-translation'));
        }

        $language = PLL()->model->get_language($languageSlug);
        $sourceId = (int) (pll_get_post_translations($postId)[$defaultSlug] ?? 0);

        return [
            'post' => $post,
            'language' => (string) $languageSlug,
            'languageName' => $language ? StatusList::languageName($language) : strtoupper((string) $languageSlug),
            'sourceId' => $sourceId,
            'title' => self::title($sourceId > 0 ? $sourceId : $postId),
        ];
    }

    /**
     * @return array{done: bool, message: string, sourceId: int, language: string}|WP_Error
     */
    public static function trashTranslation(int $postId): array|WP_Error
    {
        $translation = self::translation($postId);

        if ($translation instanceof WP_Error) {
            return $translation;
        }

        $done = (bool) wp_trash_post($postId);
        /* translators: 1: post title, 2: language name. */
        $message = $done ? __('The %2$s translation of “%1$s” was moved to the trash.', 'gds-content-translation')
            : __('The %2$s translation of “%1$s” could not be moved to the trash.', 'gds-content-translation');

        return self::result($done, $message, $translation);
    }

    /**
     * Back from the trash with the status it had before.
     *
     * @return array{done: bool, message: string, sourceId: int, language: string}|WP_Error
     */
    public static function restoreTranslation(int $postId): array|WP_Error
    {
        $translation = self::translation($postId);

        if ($translation instanceof WP_Error) {
            return $translation;
        }

        $done = self::untrash($postId);
        /* translators: 1: post title, 2: language name. */
        $message = $done ? __('The %2$s translation of “%1$s” was restored from the trash.', 'gds-content-translation')
            : __('The %2$s translation of “%1$s” could not be restored.', 'gds-content-translation');

        return self::result($done, $message, $translation);
    }

    /**
     * Permanent deletion, only for a translation that is already in the trash.
     *
     * @return array{done: bool, message: string, sourceId: int, language: string}|WP_Error
     */
    public static function deleteTranslation(int $postId): array|WP_Error
    {
        $translation = self::translation($postId);

        if ($translation instanceof WP_Error) {
            return $translation;
        }

        if ($translation['post']->post_status !== 'trash') {
            /* translators: 1: post title, 2: language name. */
            return self::result(false, __('The %2$s translation of “%1$s” is not in the trash. Move it to the trash before deleting it permanently.', 'gds-content-translation'), $translation);
        }

        // Polylang drops it from the translation group on deletion, so the
        // cell becomes "Missing".
        $done = wp_delete_post($postId, true) instanceof \WP_Post;
        /* translators: 1: post title, 2: language name. */
        $message = $done ? __('The %2$s translation of “%1$s” was deleted permanently.', 'gds-content-translation')
            : __('The %2$s translation of “%1$s” could not be deleted.', 'gds-content-translation');

        return self::result($done, $message, $translation);
    }

    /**
     * Trash all: a source post and every translation of it, in one go. All or
     * nothing on permissions: if any post in the group may not be deleted by
     * this user, nothing is changed. Posts already in the trash are left alone.
     * Error codes: gds_ct_not_found, gds_ct_not_default, gds_ct_forbidden.
     *
     * @return array{done: bool, message: string, changed: list<int>, translations: int, stillListed: bool}|WP_Error
     */
    public static function trashGroup(int $sourceId): array|WP_Error
    {
        $group = self::group($sourceId);

        if ($group instanceof WP_Error) {
            return $group;
        }

        [$postIds, $title] = $group;

        // Translations first, the source last: if anything fails, the row is
        // still on the screen to try again from.
        $targets = array_values(array_filter(
            array_merge(array_diff($postIds, [$sourceId]), [$sourceId]),
            static fn (int $postId): bool => ! in_array(get_post_status($postId), [false, 'trash'], true)
        ));
        $changed = [];

        foreach ($targets as $postId) {
            if (wp_trash_post($postId)) {
                $changed[] = $postId;
            }
        }

        sort($changed);
        $translations = count(array_diff($changed, [$sourceId]));

        if ($targets === []) {
            /* translators: %s: post title. */
            $message = sprintf(__('“%s” is already in the trash.', 'gds-content-translation'), $title);
        } elseif (count($changed) === count($targets)) {
            $message = $translations > 0
                /* translators: 1: post title, 2: number of translations. */
                ? sprintf(_n('“%1$s” and its %2$d translation were moved to the trash.', '“%1$s” and its %2$d translations were moved to the trash.', $translations, 'gds-content-translation'), $title, $translations)
                /* translators: %s: post title. */
                : sprintf(__('“%s” was moved to the trash.', 'gds-content-translation'), $title);
        } else {
            /* translators: 1: post title, 2: posts trashed, 3: posts to trash. */
            $message = sprintf(__('“%1$s” could not be moved to the trash completely: %2$d of %3$d posts were.', 'gds-content-translation'), $title, count($changed), count($targets));
        }

        return [
            'done' => $targets !== [] && count($changed) === count($targets),
            'message' => $message,
            'changed' => $changed,
            'translations' => $translations,
            'stillListed' => get_post_status($sourceId) !== 'trash',
        ];
    }

    /**
     * Undo of Trash all: only the given posts, while they are still in the
     * trash and still part of this post's translations.
     *
     * @param  list<int>  $postIds
     * @return array{done: bool, message: string, changed: list<int>, translations: int, stillListed: bool}|WP_Error
     */
    public static function restoreGroup(int $sourceId, array $postIds): array|WP_Error
    {
        $group = self::group($sourceId);

        if ($group instanceof WP_Error) {
            return $group;
        }

        [$members, $title] = $group;
        $targets = array_values(array_filter($postIds, static fn (int $postId): bool => in_array($postId, $members, true) && get_post_status($postId) === 'trash'));
        $changed = [];

        foreach ($targets as $postId) {
            if (self::untrash($postId)) {
                $changed[] = $postId;
            }
        }

        $translations = count(array_diff($changed, [$sourceId]));

        if ($targets === []) {
            /* translators: %s: post title. */
            $message = sprintf(__('Nothing of “%s” is in the trash any more.', 'gds-content-translation'), $title);
        } elseif (count($changed) === count($targets)) {
            $message = $translations > 0
                /* translators: 1: post title, 2: number of translations. */
                ? sprintf(_n('“%1$s” and its %2$d translation were restored from the trash.', '“%1$s” and its %2$d translations were restored from the trash.', $translations, 'gds-content-translation'), $title, $translations)
                /* translators: %s: post title. */
                : sprintf(__('“%s” was restored from the trash.', 'gds-content-translation'), $title);
        } else {
            /* translators: 1: post title, 2: posts restored, 3: posts to restore. */
            $message = sprintf(__('“%1$s” could not be restored completely: %2$d of %3$d posts were.', 'gds-content-translation'), $title, count($changed), count($targets));
        }

        return [
            'done' => $targets !== [] && count($changed) === count($targets),
            'message' => $message,
            'changed' => $changed,
            'translations' => $translations,
            'stillListed' => get_post_status($sourceId) !== 'trash',
        ];
    }

    /**
     * Mark a translation proof read, or not. Proof reading is about
     * translations; a source-language post is refused.
     */
    public static function setProofread(int $postId, bool $proofread): true|WP_Error
    {
        $language = $postId > 0 && get_post($postId) ? pll_get_post_language($postId) : false;

        if (! $language) {
            return new WP_Error('gds_ct_not_found', __('Invalid post.', 'gds-content-translation'));
        }

        if ($language === pll_default_language('slug')) {
            return new WP_Error('gds_ct_not_translation', __('Only translations can be marked as proof read.', 'gds-content-translation'));
        }

        if ($proofread) {
            update_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY, '1');
        } else {
            delete_post_meta($postId, GDS_CONTENT_TRANSLATION_META_KEY);
        }

        return true;
    }

    /**
     * Back from the trash with the status it had before it was trashed, not
     * as a draft.
     */
    public static function untrash(int $postId): bool
    {
        $keepPrevious = static fn ($status, $id, $previous) => $previous ?: $status;

        add_filter('wp_untrash_post_status', $keepPrevious, 10, 3);
        $done = get_post_status($postId) === 'trash' && (bool) wp_untrash_post($postId);
        remove_filter('wp_untrash_post_status', $keepPrevious, 10);

        return $done;
    }

    public static function title(int $postId): string
    {
        $title = get_the_title($postId);

        return $title !== '' ? html_entity_decode($title, ENT_QUOTES, 'UTF-8') : __('(no title)', 'gds-content-translation');
    }

    /**
     * A default-language post and its translations, when the current user may
     * delete every one of them.
     *
     * @return array{0: list<int>, 1: string}|WP_Error The post IDs (source included) and the title.
     */
    private static function group(int $sourceId): array|WP_Error
    {
        $source = $sourceId > 0 ? get_post($sourceId) : null;

        if (! $source) {
            return new WP_Error('gds_ct_not_found', __('That post no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
        }

        if (pll_get_post_language($sourceId) !== pll_default_language('slug')) {
            return new WP_Error('gds_ct_not_default', __('Only a post in the default language can be trashed together with its translations.', 'gds-content-translation'));
        }

        $title = self::title($sourceId);
        $postIds = array_values(array_unique(array_map('intval', array_merge([$sourceId], pll_get_post_translations($sourceId)))));

        foreach ($postIds as $postId) {
            if (! current_user_can('delete_post', $postId)) {
                /* translators: %s: post title. */
                return new WP_Error('gds_ct_forbidden', sprintf(__('You are not allowed to trash every translation of “%s”, so nothing was changed.', 'gds-content-translation'), $title));
            }
        }

        return [$postIds, $title];
    }

    /**
     * @param  array{language: string, languageName: string, sourceId: int, title: string}  $translation
     * @return array{done: bool, message: string, sourceId: int, language: string}
     */
    private static function result(bool $done, string $message, array $translation): array
    {
        return [
            'done' => $done,
            'message' => sprintf($message, $translation['title'], $translation['languageName']),
            'sourceId' => $translation['sourceId'],
            'language' => $translation['language'],
        ];
    }
}
