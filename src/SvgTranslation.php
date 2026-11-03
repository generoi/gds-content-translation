<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Translated copies of SVG illustrations: the labels in an SVG's `<text>`
 * (and its `<title>`, `<desc>` and alt text) through DeepL with the
 * glossary, saved as a copy of the file per language ("diagram-en.svg") that
 * the translation then shows instead.
 *
 * One copy per illustration and language, shared by every translation that
 * shows it: translated once, and a label corrected once is corrected
 * everywhere. When the original file changes, the copy is made again, and
 * only labels it has no translation of yet go to DeepL.
 *
 * Done automatically after an AI translation, and from the list of files
 * still in the original's language for translations made before.
 */
final class SvgTranslation
{
    /** On a copy: original label => translation. */
    public const labelsMetaKey = '_gds_content_translation_svg_labels';

    /** On a copy: the original file it was made from. */
    private const signatureMetaKey = '_gds_content_translation_svg_signature';

    public const autoOption = 'gds_content_translation_svg_auto';

    public static function isAvailable(): bool
    {
        return DeeplApi::isAvailable();
    }

    /** Whether AI translations get translated copies of their SVGs at once. */
    public static function isAutomatic(): bool
    {
        return (bool) apply_filters('gds_content_translation_svg_auto', (bool) get_option(self::autoOption, true)) && self::isAvailable();
    }

    /**
     * Translate every SVG a translation still shows in the original's
     * language. Errors are logged: the translation is fine without.
     */
    public static function translateAll(int $postId): int
    {
        $result = MediaCheck::check($postId);
        $count = 0;

        foreach ($result['items'] ?? [] as $item) {
            if ($item['kind'] !== 'svg' || $item['state'] !== 'open') {
                continue;
            }

            // In a pattern, the pattern shows the copy; not the original's pattern.
            if (! empty($item['inOriginal'])) {
                continue;
            }

            // In each of the translation's places: the page, its patterns.
            $translated = 0;

            foreach (array_unique(array_map(static fn ($place): int => (int) $place ?: $postId, (array) ($item['places'] ?? [$item['in'] ?? 0]))) as $place) {
                $translated = self::translateFor($place, (int) $item['attachmentId']);

                if ($translated instanceof WP_Error) {
                    break;
                }
            }

            if ($translated instanceof WP_Error) {
                error_log(sprintf('[gds-content-translation] Translating SVG %d for post %d failed: %s', $item['attachmentId'], $postId, $translated->get_error_message()));
            } else {
                $count++;
            }
        }

        if ($count > 0) {
            MediaCheck::check($postId);
        }

        return $count;
    }

    /**
     * Show the translation the copy of an SVG in its language, made (or
     * brought up to date) first.
     *
     * @param  array<string, string>|null  $labels  Labels as an editor corrected them; null translates what is missing.
     * @return int|WP_Error The copy's attachment ID.
     */
    public static function translateFor(int $postId, int $sourceId, ?array $labels = null): int|WP_Error
    {
        $language = PLL()->model->post->get_language($postId);

        if (! $language) {
            return new WP_Error('gds_ct_not_translation', __('This is not a translation.', 'gds-content-translation'));
        }

        // An editor edits the copy's labels from the copy itself.
        $copyOf = (int) get_post_meta($sourceId, MediaCheck::copyOfMetaKey, true);

        if ($copyOf > 0) {
            $copyId = $sourceId;
            $sourceId = $copyOf;
        } else {
            $copyId = MediaCheck::copyOf($sourceId, $language->slug);
        }

        $copyId = self::make($sourceId, $language, $copyId, $labels);

        if ($copyId instanceof WP_Error) {
            return $copyId;
        }

        if ($copyId !== $sourceId) {
            // Already showing the copy (an edit of its labels): nothing to replace.
            $result = MediaReplace::attachment($postId, $sourceId, $copyId);

            if ($result instanceof WP_Error && $result->get_error_code() !== 'gds_ct_not_found') {
                return $result;
            }
        }

        MediaCheck::check($postId);

        return $copyId;
    }

    /**
     * Make or update the copy.
     *
     * @param  array<string, string>|null  $edited
     */
    private static function make(int $sourceId, \PLL_Language $language, int $copyId, ?array $edited): int|WP_Error
    {
        $file = get_attached_file($sourceId);

        if (! $file || ! is_readable($file) || get_post_mime_type($sourceId) !== 'image/svg+xml') {
            return new WP_Error('gds_ct_svg_missing', __('The SVG file is missing.', 'gds-content-translation'));
        }

        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $texts = MediaCheck::svgTexts($file);
        $alt = (string) get_post_meta($sourceId, '_wp_attachment_image_alt', true);
        $known = $copyId > 0 ? (array) get_post_meta($copyId, self::labelsMetaKey, true) : [];
        $labels = [];

        foreach (array_merge($texts, $alt !== '' ? [$alt] : []) as $text) {
            if ($edited !== null && isset($edited[$text]) && trim($edited[$text]) !== '') {
                $labels[$text] = trim(sanitize_text_field($edited[$text]));
            } elseif (isset($known[$text]) && is_string($known[$text])) {
                $labels[$text] = $known[$text];
            }
        }

        $missing = array_values(array_diff(array_merge($texts, $alt !== '' ? [$alt] : []), array_keys($labels)));

        if ($missing !== []) {
            if (! $default || ! self::isAvailable()) {
                return new WP_Error('gds_ct_deepl_key', __('DeepL is not set up in Polylang’s machine translation settings.', 'gds-content-translation'));
            }

            $translations = DeeplApi::translate($missing, $default, $language);

            if ($translations instanceof WP_Error) {
                return $translations;
            }

            foreach ($missing as $index => $text) {
                $labels[$text] = $translations[$index];
            }
        }

        $svg = self::render((string) file_get_contents($file), $labels);
        $target = $copyId > 0 ? get_attached_file($copyId) : '';

        if (! $target) {
            $info = pathinfo($file);
            $target = path_join($info['dirname'], wp_unique_filename($info['dirname'], $info['filename'].'-'.$language->slug.'.svg'));
        }

        if (file_put_contents($target, $svg) === false) {
            return new WP_Error('gds_ct_svg_write', __('The translated SVG could not be saved in the uploads folder.', 'gds-content-translation'));
        }

        if ($copyId <= 0) {
            $source = get_post($sourceId);
            $copyId = wp_insert_attachment([
                'post_mime_type' => 'image/svg+xml',
                'post_title' => sprintf('%s (%s)', $source ? $source->post_title : wp_basename($file), strtoupper($language->slug)),
                'post_status' => 'inherit',
            ], $target, 0, true);

            if ($copyId instanceof WP_Error) {
                return $copyId;
            }

            update_post_meta($copyId, MediaCheck::copyOfMetaKey, $sourceId);
            update_post_meta($copyId, MediaCheck::copyLanguageMetaKey, $language->slug);

            if (! empty(PLL()->options['media_support'])) {
                pll_set_post_language($copyId, $language->slug);
            }
        }

        update_post_meta($copyId, self::labelsMetaKey, $labels);
        update_post_meta($copyId, self::signatureMetaKey, md5_file($file));

        if ($alt !== '' && isset($labels[$alt])) {
            update_post_meta($copyId, '_wp_attachment_image_alt', $labels[$alt]);
        }

        return (int) $copyId;
    }

    /**
     * Whether a copy was made from the original file as it is now.
     */
    public static function isCurrent(int $copyId): bool
    {
        $sourceId = (int) get_post_meta($copyId, MediaCheck::copyOfMetaKey, true);
        $file = $sourceId > 0 ? get_attached_file($sourceId) : '';

        return $file && is_readable($file) && get_post_meta($copyId, self::signatureMetaKey, true) === md5_file($file);
    }

    /**
     * An SVG with its labels replaced. A label split in `<tspan>`s (lines)
     * is read as one, with spaces between; its translation goes into the
     * first, and the others are emptied.
     *
     * @param  array<string, string>  $labels  Label as {@see MediaCheck::svgTexts()} reads it => translation.
     */
    public static function render(string $svg, array $labels): string
    {
        $svg = (string) preg_replace_callback('#(\saria-(?:label|description)=)(["\'])(.*?)\2#is', static function (array $match) use ($labels): string {
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8')));

            return isset($labels[$plain]) ? $match[1].$match[2].esc_attr($labels[$plain]).$match[2] : $match[0];
        }, $svg);

        return (string) preg_replace_callback('#<(text|title|desc)\b([^>]*)>(.*?)</\1>#is', static function (array $match) use ($labels): string {
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $match[3]), ENT_QUOTES | ENT_XML1, 'UTF-8')));

            if (! isset($labels[$plain])) {
                return $match[0];
            }

            $translation = esc_xml($labels[$plain]);

            if (! str_contains($match[3], '<')) {
                return "<{$match[1]}{$match[2]}>{$translation}</{$match[1]}>";
            }

            $first = true;
            $inner = (string) preg_replace_callback('/>([^<]*)</', static function (array $text) use (&$first, $translation): string {
                if (trim($text[1]) === '') {
                    return $text[0];
                }

                $replacement = $first ? $translation : '';
                $first = false;

                return '>'.$replacement.'<';
            }, '>'.$match[3].'<');

            return "<{$match[1]}{$match[2]}".substr($inner, 0, -1)."</{$match[1]}>";
        }, $svg);
    }
}
