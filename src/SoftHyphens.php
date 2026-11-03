<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Soft hyphens an editor put in a word (wp-gutenberg-shy's
 * `<span class="is-shy-character">`, or a bare `&shy;`) split it in two
 * for DeepL and Claude: "pilari&shy;perustus" reads as two words. They are
 * taken out of what is sent; the page keeps them, and a translation gets
 * its own where its words break.
 */
final class SoftHyphens
{
    public static function init(): void
    {
        add_filter('http_request_args', [self::class, 'removeFromRequest'], 9, 2);
    }

    public static function strip(string $text): string
    {
        if (! str_contains($text, 'is-shy-character') && ! str_contains($text, "\u{00AD}") && stripos($text, '&shy;') === false && stripos($text, '&#173;') === false && stripos($text, '&#xad;') === false) {
            return $text;
        }

        $text = (string) preg_replace('#<span\b[^>]*\bis-shy-character\b[^>]*>[^<]*</span>#i', '', $text);

        return str_ireplace(["\u{00AD}", '&shy;', '&#173;', '&#xad;'], '', $text);
    }

    /**
     * DeepL's translate requests, Polylang Pro's and the plugin's own.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function removeFromRequest(array $args, string $url): array
    {
        if (! preg_match('#^https://api(-free)?\.deepl\.com/v2/translate$#', $url) || ! is_string($args['body'] ?? null)) {
            return $args;
        }

        $body = json_decode($args['body'], true);

        if (! is_array($body) || ! is_array($body['text'] ?? null)) {
            return $args;
        }

        $texts = array_map(static fn ($text) => is_string($text) ? self::strip($text) : $text, $body['text']);

        if ($texts !== $body['text']) {
            $body['text'] = $texts;
            $args['body'] = (string) wp_json_encode($body);
        }

        return $args;
    }
}
