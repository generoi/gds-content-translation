<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;
use WP_HTML_Tag_Processor;
use WP_Post;
use WP_Query;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * An image's alt text per language, when Polylang does not translate media.
 *
 * Without media translation an image is one media library item in every
 * language, so its alt text is the default language's everywhere. Here each
 * language gets its own, in a meta of the image
 * (`_gds_content_translation_alt_{slug}`), translated by DeepL with the
 * glossary from the media library's or edited by hand. On a page in that
 * language it replaces the media library's alt text: in
 * `wp_get_attachment_image()` (featured images) and in blocks' images
 * (`render_block`), but only where the alt text is the media library's or
 * empty. An alt text written into the block, as a translation's own, is kept.
 *
 * With Polylang's media translation on, each language has its own media
 * library item and alt text, and this does nothing.
 */
final class ImageAlt
{
    public const metaPrefix = '_gds_content_translation_alt_';

    public const perPageMax = 100;

    public static function init(): void
    {
        if (! self::isActive()) {
            return;
        }

        add_filter('wp_get_attachment_image_attributes', [self::class, 'attachmentAttributes'], 20, 2);
        add_filter('render_block', [self::class, 'renderBlock'], 20, 2);
    }

    public static function isActive(): bool
    {
        if (! function_exists('PLL') || ! isset(PLL()->model->options)) {
            return false;
        }

        return (bool) apply_filters('gds_content_translation_image_alts', empty(PLL()->model->options['media_support']));
    }

    public static function libraryAlt(int $attachmentId): string
    {
        return trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
    }

    public static function get(int $attachmentId, string $slug): string
    {
        return trim((string) get_post_meta($attachmentId, self::metaPrefix.sanitize_key($slug), true));
    }

    /** An empty alt text removes the language's. */
    public static function set(int $attachmentId, string $slug, string $alt): void
    {
        $alt = trim(sanitize_text_field($alt));

        if ($alt === '') {
            delete_post_meta($attachmentId, self::metaPrefix.sanitize_key($slug));
        } else {
            update_post_meta($attachmentId, self::metaPrefix.sanitize_key($slug), $alt);
        }
    }

    /** The page's language when it is not the default one, on the site. */
    private static function language(): string
    {
        if ((is_admin() && ! wp_doing_ajax()) || ! function_exists('pll_current_language') || ! self::isActive()) {
            return '';
        }

        $slug = (string) pll_current_language('slug');

        return $slug !== '' && $slug !== pll_default_language('slug') ? $slug : '';
    }

    /**
     * The alt text of `wp_get_attachment_image()`: the language's, unless
     * the caller gave one of its own.
     *
     * @param  array<string, string>  $attributes
     * @return array<string, string>
     */
    public static function attachmentAttributes($attributes, $attachment)
    {
        $slug = self::language();

        if ($slug === '' || ! is_array($attributes) || ! $attachment instanceof WP_Post) {
            return $attributes;
        }

        $alt = self::get($attachment->ID, $slug);
        $current = trim((string) ($attributes['alt'] ?? ''));

        if ($alt !== '' && ($current === '' || $current === self::libraryAlt($attachment->ID))) {
            $attributes['alt'] = $alt;
        }

        return $attributes;
    }

    /**
     * The alt texts of a block's images from the media library: core/image,
     * the gallery's, media & text, cover, and any block whose image has the
     * `wp-image-{id}` class, or the only image of a block with an `id` or
     * `mediaId`.
     *
     * @param  array<string, mixed>  $block
     */
    public static function renderBlock($html, $block): string
    {
        $html = (string) $html;
        $slug = self::language();

        if ($slug === '' || stripos($html, '<img') === false || ! class_exists(WP_HTML_Tag_Processor::class)) {
            return $html;
        }

        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $blockId = (int) ($attrs['id'] ?? $attrs['mediaId'] ?? 0);
        $onlyImage = $blockId > 0 && substr_count(strtolower($html), '<img') === 1;
        $processor = new WP_HTML_Tag_Processor($html);
        $changed = false;

        while ($processor->next_tag('img')) {
            $class = (string) $processor->get_attribute('class');
            $dataId = $processor->get_attribute('data-id');
            $attachmentId = preg_match('/\bwp-image-(\d+)\b/', $class, $match) ? (int) $match[1] : (is_string($dataId) && ctype_digit($dataId) ? (int) $dataId : ($onlyImage ? $blockId : 0));

            if ($attachmentId <= 0) {
                continue;
            }

            $alt = self::get($attachmentId, $slug);

            if ($alt === '') {
                continue;
            }

            $current = $processor->get_attribute('alt');
            $current = is_string($current) ? trim($current) : '';

            if ($current === '' || $current === self::libraryAlt($attachmentId)) {
                $processor->set_attribute('alt', $alt);
                $changed = true;
            }
        }

        return $changed ? $processor->get_updated_html() : $html;
    }

    /**
     * The images with an alt text in the media library, a page at a time,
     * with the language's alt texts.
     *
     * @param  array{search?: string, missing?: bool, page?: int, perPage?: int}  $args
     * @return array<string, mixed>|WP_Error
     */
    public static function rows(string $slug, array $args = []): array|WP_Error
    {
        $language = self::languageFor($slug);

        if ($language instanceof WP_Error) {
            return $language;
        }

        $perPage = max(1, min(self::perPageMax, (int) ($args['perPage'] ?? 50)));
        $query = new WP_Query(self::queryArgs($slug, (string) ($args['search'] ?? ''), ! empty($args['missing'])) + [
            'posts_per_page' => $perPage,
            'paged' => max(1, (int) ($args['page'] ?? 1)),
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $items = [];

        foreach ($query->posts as $attachment) {
            if ($attachment instanceof WP_Post) {
                $items[] = self::item($attachment, $slug);
            }
        }

        return [
            'items' => $items,
            'total' => (int) $query->found_posts,
            'totalPages' => (int) $query->max_num_pages,
            'missing' => self::missingCounts(),
            'canTranslate' => DeeplApi::isAvailable(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryArgs(string $slug, string $search = '', bool $missing = false): array
    {
        $meta = [['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '!=']];

        if ($missing) {
            $meta[] = ['key' => self::metaPrefix.sanitize_key($slug), 'compare' => 'NOT EXISTS'];
        }

        if ($search !== '') {
            // The alt text, in the media library's language or this one.
            $meta[] = [
                'relation' => 'OR',
                ['key' => '_wp_attachment_image_alt', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => self::metaPrefix.sanitize_key($slug), 'value' => $search, 'compare' => 'LIKE'],
            ];
        }

        return [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'meta_query' => ['relation' => 'AND'] + $meta,
            // Every image, whatever language Polylang once gave it.
            'lang' => '',
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(WP_Post $attachment, string $slug): array
    {
        $thumbnail = wp_get_attachment_image_src($attachment->ID, 'thumbnail');

        return [
            'id' => $attachment->ID,
            'title' => get_the_title($attachment),
            'file' => wp_basename((string) get_attached_file($attachment->ID)),
            'thumbnail' => is_array($thumbnail) ? (string) $thumbnail[0] : '',
            'editUrl' => (string) get_edit_post_link($attachment->ID, 'raw'),
            'source' => self::libraryAlt($attachment->ID),
            'translation' => self::get($attachment->ID, $slug),
        ];
    }

    /**
     * Images with an alt text in the media library and none in each
     * translation language.
     *
     * @return array<string, int>
     */
    public static function missingCounts(): array
    {
        $counts = [];

        foreach (StatusList::translationLanguageSlugs() as $slug) {
            $query = new WP_Query(self::queryArgs($slug, '', true) + ['posts_per_page' => 1, 'fields' => 'ids']);
            $counts[$slug] = (int) $query->found_posts;
        }

        return $counts;
    }

    /**
     * @return \PLL_Language|WP_Error
     */
    private static function languageFor(string $slug)
    {
        $language = self::isActive() ? PLL()->model->get_language($slug) : false;

        if (! $language || $slug === pll_default_language('slug')) {
            return new WP_Error('gds_ct_alts_language', self::isActive() ? __('There is no such language.', 'gds-content-translation') : __('Polylang translates the media: each language has its own images and alt texts.', 'gds-content-translation'), ['status' => 404]);
        }

        return $language;
    }

    /**
     * Save alt texts as an editor wrote them, by image ID.
     *
     * @param  array<int|string, string>  $alts
     * @return array<int, string>|WP_Error What each is now.
     */
    public static function save(string $slug, array $alts): array|WP_Error
    {
        $language = self::languageFor($slug);

        if ($language instanceof WP_Error) {
            return $language;
        }

        $saved = [];

        foreach ($alts as $attachmentId => $alt) {
            $attachmentId = (int) $attachmentId;

            if (! self::isImage($attachmentId) || ! current_user_can('edit_post', $attachmentId)) {
                continue;
            }

            self::set($attachmentId, $slug, (string) $alt);
            $saved[$attachmentId] = self::get($attachmentId, $slug);
        }

        return $saved;
    }

    /** An image of the media library, an SVG too. */
    public static function isImage(int $attachmentId): bool
    {
        return $attachmentId > 0 && get_post_type($attachmentId) === 'attachment' && str_starts_with((string) get_post_mime_type($attachmentId), 'image/');
    }

    /**
     * Have DeepL translate the media library's alt texts that have no
     * translation in a language, with the glossary: those of `$ids`, or every
     * image's.
     *
     * @param  list<int>  $ids
     * @return array<int, string>|WP_Error The new alt texts, by image ID.
     */
    public static function translate(string $slug, array $ids = []): array|WP_Error
    {
        $language = self::languageFor($slug);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));

        if ($language instanceof WP_Error) {
            return $language;
        }

        if (! $default) {
            return new WP_Error('gds_ct_alts_language', __('There is no such language.', 'gds-content-translation'));
        }

        if ($ids === []) {
            $ids = array_map('intval', (new WP_Query(self::queryArgs($slug, '', true) + ['posts_per_page' => -1, 'fields' => 'ids']))->posts);
        }

        $sources = [];

        foreach (array_unique(array_map('intval', $ids)) as $attachmentId) {
            $source = self::libraryAlt($attachmentId);

            if (self::isImage($attachmentId) && $source !== '' && self::get($attachmentId, $slug) === '' && current_user_can('edit_post', $attachmentId)) {
                $sources[$attachmentId] = $source;
            }
        }

        if ($sources === []) {
            return [];
        }

        // The same alt text on several images goes to DeepL once.
        $texts = array_values(array_unique($sources));
        $translated = DeeplApi::translate($texts, $default, $language);

        if ($translated instanceof WP_Error) {
            return $translated;
        }

        $byText = array_combine($texts, $translated);
        $saved = [];

        foreach ($sources as $attachmentId => $source) {
            self::set($attachmentId, $slug, (string) ($byText[$source] ?? ''));
            $saved[$attachmentId] = self::get($attachmentId, $slug);
        }

        return $saved;
    }
}
