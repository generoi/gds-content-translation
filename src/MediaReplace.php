<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Point a translation at its own file, image, form or address instead of
 * its original's: in its blocks' attributes and HTML, and its featured
 * image. Only the translation changes; it is saved as a revision, so the
 * editor's revisions undo it.
 */
final class MediaReplace
{
    /**
     * Use another media library item where the translation uses one.
     */
    public static function attachment(int $postId, int $oldId, int $newId): true|WP_Error
    {
        if ($oldId <= 0 || $newId <= 0 || get_post_type($newId) !== 'attachment') {
            return new WP_Error('gds_ct_media_missing', __('Choose a file from the media library.', 'gds-content-translation'));
        }

        $thumbnail = (int) get_post_thumbnail_id($postId) === $oldId;

        if ($thumbnail && self::syncsThumbnail($postId, $newId)) {
            return new WP_Error('gds_ct_thumbnail_synced', __('The image is the featured image, which Polylang keeps the same in every translation (Languages → Settings → Synchronization). Changing it here would change it on the original too. To give the translation its own, turn off that synchronization first.', 'gds-content-translation'));
        }

        $urls = self::urlMap($oldId, $newId);
        $result = self::change($postId, static function (array $block) use ($oldId, $newId, $urls): array {
            // The ID only in a block that shows the item: another block's
            // `id` or `data-id` with the same number is something else.
            if (in_array($oldId, MediaCheck::attachmentIds($block['attrs']), true)) {
                foreach ($block['attrs'] as $name => $value) {
                    if (! is_string($name) || ! preg_match(MediaCheck::attachmentAttributePattern, $name)) {
                        continue;
                    }

                    if (is_array($value)) {
                        $block['attrs'][$name] = array_map(static fn ($id) => is_numeric($id) && (int) $id === $oldId ? (is_string($id) ? (string) $newId : $newId) : $id, $value);
                    } elseif (is_numeric($value) && (int) $value === $oldId) {
                        $block['attrs'][$name] = is_string($value) ? (string) $newId : $newId;
                    }
                }

                $block = self::replaceInHtml($block, [
                    'wp-image-'.$oldId.'"' => 'wp-image-'.$newId.'"',
                    'wp-image-'.$oldId.' ' => 'wp-image-'.$newId.' ',
                    'data-id="'.$oldId.'"' => 'data-id="'.$newId.'"',
                ]);
            }

            foreach ($block['attrs'] as $name => $value) {
                // Core keeps some addresses in its attributes too (a file's href).
                if (is_string($value) && isset($urls[$value])) {
                    $block['attrs'][$name] = $urls[$value];
                }
            }

            return self::replaceInHtml($block, $urls);
        }, static function (string $content) use ($urls): string {
            return strtr($content, $urls);
        });

        if ($result instanceof WP_Error) {
            return $result;
        }

        if ($thumbnail) {
            set_post_thumbnail($postId, $newId);
            $result = true;
        }

        return self::done($postId, $result);
    }

    /**
     * Whether Polylang would give the translation's other languages (the
     * original) this featured image too: its synchronization of featured
     * images copies the value to every translation, the media item's
     * translation in that language when media are translated. Also on the
     * next save in the editor, so it cannot be written around.
     */
    private static function syncsThumbnail(int $postId, int $newId): bool
    {
        if (! in_array('_thumbnail_id', (array) (PLL()->options['sync'] ?? []), true)) {
            return false;
        }

        foreach (pll_get_post_translations($postId) as $slug => $translationId) {
            if ((int) $translationId === $postId) {
                continue;
            }

            $synced = ! empty(PLL()->options['media_support']) ? ((int) pll_get_post($newId, $slug) ?: $newId) : $newId;

            if ($synced !== (int) get_post_thumbnail_id((int) $translationId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Have a query loop show the chosen posts translated since: each place
     * that holds 0 or another language's post gets its translation (0
     * while it has none).
     *
     * @param  int  $index  The loop's place among the query loops of the post.
     * @param  list<array{id: int, field: string}>  $posts  The originals, in the order the check found them.
     */
    public static function query(int $postId, int $index, array $posts): true|WP_Error
    {
        $language = (string) pll_get_post_language($postId);
        $default = (string) pll_default_language('slug');
        $seen = -1;

        $result = self::change($postId, static function (array $block) use ($index, $posts, $language, $default, &$seen): array {
            if ($block['blockName'] !== 'core/query' || ++$seen !== $index || ! is_array($block['attrs']['query'] ?? null)) {
                return $block;
            }

            $byField = [];

            foreach ($posts as $post) {
                $byField[(string) $post['field']][] = (int) $post['id'];
            }

            foreach ($byField as $field => $sourceIds) {
                $ids = (array) ($block['attrs']['query'][$field] ?? []);

                foreach ($ids as $place => $id) {
                    $idLanguage = (int) $id > 0 ? pll_get_post_language((int) $id) : false;

                    if ((int) $id > 0 && (! $idLanguage || $idLanguage === $language)) {
                        continue;
                    }

                    // The check listed the places in order.
                    $sourceId = array_shift($sourceIds) ?? 0;
                    $sourceId = $sourceId > 0 ? $sourceId : ((int) $id > 0 ? ((int) pll_get_post((int) $id, $default) ?: (int) $id) : 0);
                    $translationId = $sourceId > 0 ? (int) pll_get_post($sourceId, $language) : 0;

                    if ($translationId > 0 && get_post_status($translationId) !== 'trash') {
                        $ids[$place] = $translationId;
                    } elseif ($field === 'include') {
                        $ids[$place] = 0;
                    }
                }

                $block['attrs']['query'][$field] = $ids;
            }

            return $block;
        }, static fn (string $content): string => $content);

        return self::done($postId, $result);
    }

    /**
     * Show another Gravity Forms form where the translation shows one.
     */
    public static function form(int $postId, int $oldId, int $newId): true|WP_Error
    {
        if ($newId <= 0) {
            return new WP_Error('gds_ct_form_missing', __('Choose a form.', 'gds-content-translation'));
        }

        $result = self::change($postId, static function (array $block) use ($oldId, $newId): array {
            if ($block['blockName'] === 'gravityforms/form' && (int) ($block['attrs']['formId'] ?? 0) === $oldId) {
                $block['attrs']['formId'] = (string) $newId;
            }

            return $block;
        }, static function (string $content) use ($oldId, $newId): string {
            return (string) preg_replace('/(\[gravityforms?\b[^\]]*\bid=["\']?)'.$oldId.'\b/', '${1}'.$newId, $content);
        });

        return self::done($postId, $result);
    }

    /**
     * Link or embed another address where the translation has one.
     */
    public static function url(int $postId, string $old, string $new): true|WP_Error
    {
        $new = esc_url_raw(trim($new));

        if ($new === '' || ! preg_match('#^https?://#i', $new)) {
            return new WP_Error('gds_ct_url_invalid', __('Enter a full address, starting with https://', 'gds-content-translation'));
        }

        // Whole addresses only: not the same address inside a longer one
        // (`kela.fi` in `kela.fi/fi/asiointi`), nor in the text.
        $html = static function (string $content) use ($old, $new): string {
            $content = (string) preg_replace_callback(
                '/\b(href|src)=(["\'])(.*?)\2/i',
                static fn (array $match): string => html_entity_decode($match[3], ENT_QUOTES, 'UTF-8') === $old ? $match[1].'='.$match[2].esc_url($new).$match[2] : $match[0],
                $content
            );

            // An embed's address, on its own line in the block's wrapper.
            return (string) preg_replace('/(^|\n)\s*'.preg_quote($old, '/').'\s*(\n|$)/', '${1}'.str_replace(['\\', '$'], ['\\\\', '\\$'], $new).'${2}', $content);
        };
        $result = self::change($postId, static function (array $block) use ($old, $new, $html): array {
            foreach ($block['attrs'] as $name => $value) {
                if ($value === $old) {
                    $block['attrs'][$name] = $new;
                }
            }

            $block['innerHTML'] = $html((string) $block['innerHTML']);
            $block['innerContent'] = array_map(static fn ($part) => is_string($part) ? $html($part) : $part, (array) $block['innerContent']);

            return $block;
        }, $html);

        return self::done($postId, $result);
    }

    /**
     * Every address of an item (each image size, the original of a scaled
     * image) and the new item's address of the same size.
     *
     * @return array<string, string>
     */
    private static function urlMap(int $oldId, int $newId): array
    {
        $oldUrl = (string) wp_get_attachment_url($oldId);
        $newUrl = (string) wp_get_attachment_url($newId);
        $map = $oldUrl !== '' ? [$oldUrl => $newUrl] : [];
        $oldMeta = wp_get_attachment_metadata($oldId);
        $oldBase = trailingslashit(dirname($oldUrl));

        if (is_array($oldMeta)) {
            foreach ((array) ($oldMeta['sizes'] ?? []) as $size => $data) {
                if (! empty($data['file'])) {
                    $sized = wp_get_attachment_image_src($newId, (string) $size);
                    $map[$oldBase.$data['file']] = is_array($sized) ? (string) $sized[0] : $newUrl;
                }
            }

            if (! empty($oldMeta['original_image'])) {
                $map[$oldBase.$oldMeta['original_image']] = $newUrl;
            }
        }

        // Longest first, so "photo.jpg" never replaces part of "photo.jpg-1024x768.jpg".
        uksort($map, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        return array_filter($map, static fn (string $url): bool => $url !== '');
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $pairs
     * @return array<string, mixed>
     */
    private static function replaceInHtml(array $block, array $pairs): array
    {
        $block['innerHTML'] = strtr((string) $block['innerHTML'], $pairs);
        $block['innerContent'] = array_map(static fn ($part) => is_string($part) ? strtr($part, $pairs) : $part, (array) $block['innerContent']);

        return $block;
    }

    /**
     * Change a translation's blocks (or classic content), and save it when
     * something changed.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $blockChange
     * @param  callable(string): string  $classicChange  For content without blocks, and shortcodes.
     */
    private static function change(int $postId, callable $blockChange, callable $classicChange): bool|WP_Error
    {
        $post = get_post($postId);

        if (! $post) {
            return new WP_Error('gds_ct_missing', __('The translation no longer exists.', 'gds-content-translation'));
        }

        if (function_exists('wp_check_post_lock') && wp_check_post_lock($postId)) {
            return new WP_Error('gds_ct_locked', __('Someone has this translation open in the editor. Try again when it is closed.', 'gds-content-translation'));
        }

        $content = $post->post_content;

        if (has_blocks($content)) {
            $walk = static function (array $blocks) use (&$walk, $blockChange): array {
                foreach ($blocks as $index => $block) {
                    if ($block['blockName'] !== null) {
                        $block['attrs'] = (array) $block['attrs'];
                        $block = $blockChange($block);
                    }

                    $block['innerBlocks'] = $walk((array) $block['innerBlocks']);
                    $blocks[$index] = $block;
                }

                return $blocks;
            };
            $changed = serialize_blocks($walk(parse_blocks($content)));
            // Shortcodes and addresses in freeform HTML between blocks.
            $changed = $classicChange($changed);
        } else {
            $changed = $classicChange($content);
        }

        if ($changed === $content) {
            return false;
        }

        $result = wp_update_post(['ID' => $postId, 'post_content' => wp_slash($changed)], true);

        return $result instanceof WP_Error ? $result : true;
    }

    private static function done(int $postId, bool|WP_Error $result): true|WP_Error
    {
        if ($result instanceof WP_Error) {
            return $result;
        }

        if (! $result) {
            return new WP_Error('gds_ct_not_found', __('The translation no longer uses it. Reload the page.', 'gds-content-translation'));
        }

        MediaCheck::check($postId);

        return true;
    }
}
