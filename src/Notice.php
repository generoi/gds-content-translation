<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-shot notices for the current user, shown on the next status screen load.
 *
 * Kept server-side rather than in the redirect URL, so a reload (including the
 * automatic one after creating a translation) does not show them a second
 * time, and so the message can name the post and language.
 *
 * A short list, not one slot: an action taken in a second tab before the first
 * one's screen has loaded must not silently replace the first notice.
 */
final class Notice
{
    private const types = ['success', 'error', 'warning', 'info'];

    /** The oldest is dropped beyond this many unread notices. */
    private const limit = 5;

    /**
     * @param  string  $focusRow  Row anchor of the cell the action was taken in.
     * @param  string  $focusLang  Language slug of that cell.
     */
    public static function flash(
        string $type,
        string $message,
        string $actionUrl = '',
        string $actionLabel = '',
        string $focusRow = '',
        string $focusLang = ''
    ): void {
        $notices = self::pending();
        $notices[] = [
            'type' => in_array($type, self::types, true) ? $type : 'error',
            'message' => $message,
            'actionUrl' => $actionUrl,
            'actionLabel' => $actionLabel,
            'focusRow' => $focusRow,
            'focusLang' => $focusLang,
        ];

        set_transient(self::key(), array_slice($notices, -self::limit), 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Print and forget every pending notice, oldest first.
     *
     * Each is a focus target (tabindex="-1") and a status region; the status
     * screen's script moves focus to the cell the action was taken in, or to
     * the notice when there is no such cell, and announces it.
     */
    public static function render(): void
    {
        $notices = self::pending();

        if ($notices === []) {
            return;
        }

        delete_transient(self::key());

        foreach ($notices as $notice) {
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
                '<div class="notice notice-%1$s is-dismissible gds-content-translation__notice" role="status" tabindex="-1" data-gds-ct-notice data-focus-row="%2$s" data-focus-lang="%3$s"><p><span class="gds-content-translation__notice-message">%4$s</span>%5$s</p></div>',
                esc_attr($type),
                esc_attr((string) ($notice['focusRow'] ?? '')),
                esc_attr((string) ($notice['focusLang'] ?? '')),
                esc_html((string) $notice['message']),
                $action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            );
        }
    }

    /**
     * Valid pending notices, oldest first. Reads the single-notice shape
     * stored before this was a list, so a notice flashed across an update is
     * not lost.
     *
     * @return list<array{type: string, message: string, actionUrl: string, actionLabel: string, focusRow: string, focusLang: string}>
     */
    private static function pending(): array
    {
        $stored = get_transient(self::key());

        if (! is_array($stored)) {
            return [];
        }

        if (isset($stored['message'])) {
            $stored = [$stored];
        }

        $notices = [];

        foreach ($stored as $notice) {
            if (! is_array($notice) || empty($notice['message']) || ! is_string($notice['message'])) {
                continue;
            }

            $notices[] = [
                'type' => is_string($notice['type'] ?? null) ? $notice['type'] : 'error',
                'message' => $notice['message'],
                'actionUrl' => is_string($notice['actionUrl'] ?? null) ? $notice['actionUrl'] : '',
                'actionLabel' => is_string($notice['actionLabel'] ?? null) ? $notice['actionLabel'] : '',
                'focusRow' => is_string($notice['focusRow'] ?? null) ? $notice['focusRow'] : '',
                'focusLang' => is_string($notice['focusLang'] ?? null) ? $notice['focusLang'] : '',
            ];
        }

        return $notices;
    }

    private static function key(): string
    {
        return 'gds_content_translation_notice_'.get_current_user_id();
    }
}
