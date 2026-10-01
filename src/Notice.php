<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-shot notice for the current user, shown on the next status screen load.
 *
 * Kept server-side rather than in the redirect URL, so a reload (including the
 * automatic one after creating a translation) does not show it a second time,
 * and so the message can name the post and language.
 */
final class Notice
{
    private const types = ['success', 'error', 'warning', 'info'];

    public static function flash(string $type, string $message, string $actionUrl = '', string $actionLabel = ''): void
    {
        set_transient(self::key(), [
            'type' => in_array($type, self::types, true) ? $type : 'error',
            'message' => $message,
            'actionUrl' => $actionUrl,
            'actionLabel' => $actionLabel,
        ], 5 * MINUTE_IN_SECONDS);
    }

    public static function render(): void
    {
        $notice = get_transient(self::key());

        if (! is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient(self::key());

        $type = in_array($notice['type'] ?? '', self::types, true) ? $notice['type'] : 'error';
        $action = '';

        if (! empty($notice['actionUrl']) && ! empty($notice['actionLabel'])) {
            $action = sprintf(
                ' <a class="gds-content-translation__notice-action" href="%1$s">%2$s</a>',
                esc_url((string) $notice['actionUrl']),
                esc_html((string) $notice['actionLabel'])
            );
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
            esc_attr($type),
            esc_html((string) $notice['message']),
            $action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
        );
    }

    private static function key(): string
    {
        return 'gds_content_translation_notice_'.get_current_user_id();
    }
}
