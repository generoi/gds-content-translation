<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Typed reads from request arrays ($_GET, $_POST).
 *
 * A crafted query string can turn any parameter into an array
 * (`?post_type[]=x`). Casting that to a string raises "Array to string
 * conversion", which Acorn sites turn into a 500, so every read goes through
 * here and gets an empty value instead.
 */
final class Request
{
    /**
     * A slug-like value (post type, language, action, mode). Empty when
     * missing or not a string.
     *
     * @param  array<string|int, mixed>  $source
     */
    public static function key(array $source, string $name): string
    {
        $value = $source[$name] ?? '';

        return is_string($value) ? sanitize_key(wp_unslash($value)) : '';
    }

    /**
     * A positive ID. 0 when missing, not a whole number, or not a scalar.
     *
     * @param  array<string|int, mixed>  $source
     */
    public static function id(array $source, string $name): int
    {
        $value = $source[$name] ?? '';

        if (is_int($value)) {
            return max(0, $value);
        }

        if (! is_string($value) || preg_match('/^\d{1,19}$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }
}
