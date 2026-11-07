<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What DeepL must not change in a text it translates.
 *
 * - Code. A custom HTML block or a form's HTML field can hold a <script> or
 *   <style>, and DeepL translates what is in it: straight quotes become
 *   typographic ones, and the script breaks. Each one is swapped for an empty
 *   placeholder element before the request and put back in the response.
 * - Line breaks. DeepL often starts the line after a <br> with a comma or a
 *   semicolon the original does not have ("Company Inc<br>, 30 Main Street"),
 *   reading the lines of an address as a list. Such a mark is taken out when
 *   the text sent had none there.
 *
 * Both apply to every DeepL translate request on the site, Polylang Pro's
 * and the plugin's own.
 */
final class DeeplCode
{
    private const codePattern = '#<(script|style)\b[^>]*>.*?</\1>#is';

    private const placeholderPattern = '#<gds-keep i="(\d+)"\s*(?:/>|></gds-keep>)#';

    private const breakMarkPattern = '#(<br\s*/?>)(\s*)[,;]\s*#i';

    public static function init(): void
    {
        // Before the soft hyphens are taken out (9) and the glossary is added (10).
        add_filter('http_request_args', [self::class, 'protectRequest'], 8, 2);
        add_filter('http_response', [self::class, 'restoreResponse'], 5, 3);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function protectRequest(array $args, string $url): array
    {
        if (! self::isTranslateUrl($url) || ! is_string($args['body'] ?? null)) {
            return $args;
        }

        $body = json_decode($args['body'], true);

        if (! is_array($body) || ! is_array($body['text'] ?? null)) {
            return $args;
        }

        $kept = [];

        foreach ($body['text'] as $index => $text) {
            if (is_string($text)) {
                [$body['text'][$index], $kept[$index]] = self::protect($text);
            }
        }

        $kept = array_filter($kept);

        // Read back by restoreResponse(), which gets the same arguments.
        $args['gds_ct_sent'] = array_map(static fn ($text) => is_string($text) ? $text : '', $body['text']);

        if ($kept !== []) {
            // As plain text DeepL would read the placeholder as words.
            $body['tag_handling'] ??= 'html';
            $body['ignore_tags'] = array_values(array_unique(array_merge((array) ($body['ignore_tags'] ?? []), ['gds-keep'])));
            $args['gds_ct_kept'] = $kept;
        }

        $args['body'] = (string) wp_json_encode($body);

        return $args;
    }

    /**
     * @param  array<string, mixed>|\WP_Error  $response
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function restoreResponse($response, array $args, string $url)
    {
        if (is_wp_error($response) || ! self::isTranslateUrl($url) || ! isset($args['gds_ct_sent'])) {
            return $response;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($data) || ! is_array($data['translations'] ?? null)) {
            return $response;
        }

        $kept = (array) ($args['gds_ct_kept'] ?? []);

        foreach ($data['translations'] as $index => $translation) {
            $text = self::removeAddedBreakMarks((string) ($args['gds_ct_sent'][$index] ?? ''), (string) ($translation['text'] ?? ''));
            $data['translations'][$index]['text'] = self::restore($text, (array) ($kept[$index] ?? []));
        }

        $response['body'] = (string) wp_json_encode($data);

        return $response;
    }

    /**
     * A text with its scripts and styles swapped for placeholders.
     *
     * @return array{0: string, 1: list<string>} The text, and the code it held in order.
     */
    public static function protect(string $text): array
    {
        $kept = [];
        $text = (string) preg_replace_callback(self::codePattern, static function (array $match) use (&$kept): string {
            $kept[] = $match[0];

            return '<gds-keep i="'.(count($kept) - 1).'"></gds-keep>';
        }, $text);

        return [$text, $kept];
    }

    /**
     * @param  list<string>  $kept
     */
    public static function restore(string $text, array $kept): string
    {
        if ($kept === []) {
            return $text;
        }

        return (string) preg_replace_callback(self::placeholderPattern, static fn (array $match): string => $kept[(int) $match[1]] ?? '', $text);
    }

    public static function removeAddedBreakMarks(string $source, string $translation): string
    {
        if (! preg_match(self::breakMarkPattern, $translation) || preg_match(self::breakMarkPattern, $source)) {
            return $translation;
        }

        return (string) preg_replace(self::breakMarkPattern, '$1$2', $translation);
    }

    /**
     * A translation cleaned with a sanitizer that would take its code apart
     * (wp_kses_post() leaves a script's text on the page), with the
     * original's scripts and styles as they were.
     *
     * @param  callable(string): string  $sanitize
     */
    public static function sanitizeKeepingCode(string $source, string $translation, callable $sanitize): string
    {
        [, $code] = self::protect($source);

        if ($code === []) {
            return $sanitize($translation);
        }

        // The translation's own code (if DeepL left it) gives way to the original's.
        [$translation] = self::protect($translation);
        $translation = (string) preg_replace(self::placeholderPattern, '<!--gds-keep-$1-->', $translation);
        $clean = $sanitize($translation);

        return (string) preg_replace_callback('#<!--gds-keep-(\d+)-->#', static fn (array $match): string => $code[(int) $match[1]] ?? '', $clean);
    }

    private static function isTranslateUrl(string $url): bool
    {
        return (bool) preg_match('#^https://api(-free)?\.deepl\.com/v2/translate$#', $url);
    }
}
