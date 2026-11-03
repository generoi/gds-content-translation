<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\Hyphenation;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Soft hyphens in the long words of headings and buttons, for posts that
 * were not AI translated with them: the originals, and translations made
 * before. Words that have one are left as they are.
 */
final class Hyphenate extends BaseAction
{
    public function id(): string
    {
        return 'hyphenate';
    }

    public function sanitizeArgs(array $args): array|WP_Error
    {
        if (! Hyphenation::isEnabled()) {
            return new WP_Error('gds_ct_hyphenation_off', __('Soft hyphens are off in the Content translation settings.', 'gds-content-translation'), ['status' => 400]);
        }

        // Translations in the languages asked for (none: only the original).
        $languages = (array) ($args['languages'] ?? []);
        $sanitized = $languages === [] ? ['languages' => []] : parent::sanitizeArgs($args);

        if (! $sanitized instanceof WP_Error) {
            $sanitized['original'] = rest_sanitize_boolean($args['original'] ?? true);
        }

        return $sanitized;
    }

    public function can(int $sourceId, array $args): bool
    {
        // Checked per post in run().
        return true;
    }

    public function run(int $sourceId, array $args): array|WP_Error
    {
        $postIds = array_merge($args['original'] ? [$sourceId] : [], $args['languages'] !== [] ? $this->liveTranslations($sourceId, $args['languages']) : []);
        $changed = 0;
        $forbidden = 0;
        $locked = 0;

        if (! function_exists('wp_check_post_lock')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        foreach ($postIds as $postId) {
            if (! current_user_can('edit_post', $postId)) {
                $forbidden++;

                continue;
            }

            // Someone's open editor would save over it.
            if (wp_check_post_lock($postId)) {
                $locked++;

                continue;
            }

            if (Hyphenation::applyToPost($postId)) {
                $changed++;
            }
        }

        if ($changed === 0 && $forbidden > 0) {
            return new WP_Error('gds_ct_forbidden', __('You are not allowed to change these posts.', 'gds-content-translation'));
        }

        return ['changed' => $changed, 'forbidden' => $forbidden, 'locked' => $locked];
    }

    public function summarize(array $results, array $args): string
    {
        $changed = self::total($results, 'changed');
        $locked = self::total($results, 'locked');
        $message = $changed === 0
            ? __('No long words without soft hyphens were found.', 'gds-content-translation')
            /* translators: %d: number of posts */
            : sprintf(_n('Soft hyphens were added to %d post.', 'Soft hyphens were added to %d posts.', $changed, 'gds-content-translation'), $changed);

        return $locked > 0
            /* translators: %d: number of posts */
            ? $message.' '.sprintf(_n('%d post was left out: someone has it open in the editor.', '%d posts were left out: someone has them open in the editor.', $locked, 'gds-content-translation'), $locked)
            : $message;
    }
}
