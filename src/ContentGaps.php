<?php

namespace GeneroWP\ContentTranslation;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What else a translation may still show wrong, besides files and images
 * ({@see MediaCheck}, which lists these with them):
 *
 * - `query`: a query loop whose chosen posts or parent page have no
 *   translation (Polylang makes a chosen post 0) or are in another
 *   language. The section shows fewer posts, or none.
 * - `page`: a link to one of the site's pages that has no translation in
 *   the language yet, so it leads to the original's page. Polylang points
 *   it at the translation once there is one; the item then goes.
 * - `placeholder`: a text still to be written ("[TÄYTÄ]", "TODO", "lorem
 *   ipsum"), in the translation or in its original.
 *
 * Unlike files, these are the translation's own: they count whether the
 * original shows the same or not. The originals of every page a
 * translation links and every post its loops choose are returned too
 * (`$references`), so it is checked again when one of them gets, or loses,
 * a translation.
 */
final class ContentGaps
{
    public const kinds = ['query', 'page', 'placeholder'];

    /** Placeholder words, also inside brackets ("[TÄYTÄ TÄHÄN]"). */
    private const placeholderWords = 'TODO|TBD|FIXME|XXX+|TÄYTÄ|TÄYTETTÄVÄ|TÄYDENNÄ|LISÄTÄÄN|ATT FYLLA|FYLL I|FILL IN|PLACEHOLDER';

    /**
     * The items of a translation, by key.
     *
     * @param  list<int>  $references  Set to the originals of the pages it links and the posts its loops choose.
     * @return array<string, array<string, mixed>>
     */
    public static function find(WP_Post $post, WP_Post $source, string $language, ?array &$references = null): array
    {
        $blocks = self::blocks($post);
        $sourceBlocks = self::blocks($source);
        $references = [];

        $items = self::queryItems($blocks, $sourceBlocks, $language, $references)
            + self::pageItems($post, $blocks, $language, $references)
            + self::placeholderItems($post, $blocks, $source, $sourceBlocks);

        $references = array_values(array_unique(array_filter($references)));

        return $items;
    }

    /**
     * A post's blocks in the order they show, those of the synced patterns
     * it shows included: each with the pattern it is in (`in`, 0 for the
     * post itself) and, for a query loop, its place among those of the post
     * or pattern (`index`), which an action finds it by.
     *
     * @return list<array{block: array<string, mixed>, in: int, index: int}>
     */
    private static function blocks(WP_Post $post): array
    {
        $list = [];
        $seen = [$post->ID => true];

        $scan = static function (string $content, int $in) use (&$scan, &$list, &$seen): void {
            $queries = 0;
            $walk = static function (array $blocks) use (&$walk, &$scan, &$list, &$seen, &$queries, $in): void {
                foreach ($blocks as $block) {
                    $name = (string) ($block['blockName'] ?? '');
                    $attrs = (array) ($block['attrs'] ?? []);

                    if ($name === 'core/block' && ! empty($attrs['ref'])) {
                        $pattern = get_post((int) $attrs['ref']);

                        if ($pattern && $pattern->post_type === 'wp_block' && ! isset($seen[$pattern->ID])) {
                            $seen[$pattern->ID] = true;
                            $scan($pattern->post_content, $pattern->ID);
                        }

                        continue;
                    }

                    $list[] = ['block' => $block, 'in' => $in, 'index' => $name === 'core/query' ? $queries++ : -1];
                    $walk((array) ($block['innerBlocks'] ?? []));
                }
            };

            if (has_blocks($content)) {
                $walk(parse_blocks($content));
            } elseif (trim($content) !== '') {
                // Classic content: one block of HTML.
                $list[] = ['block' => ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $content], 'in' => $in, 'index' => -1];
            }
        };
        $scan($post->post_content, 0);

        return $list;
    }

    /**
     * Query loops whose chosen posts or parent pages are not this language's.
     * The original's loop of the same shape (at the same place, else the
     * first like it) says which posts were chosen where the translation has
     * only 0.
     *
     * @param  list<array{block: array<string, mixed>, in: int, index: int}>  $blocks
     * @param  list<array{block: array<string, mixed>, in: int, index: int}>  $sourceBlocks
     * @param  list<int>  $references
     * @return array<string, array<string, mixed>>
     */
    private static function queryItems(array $blocks, array $sourceBlocks, string $language, array &$references): array
    {
        $isQuery = static fn (array $entry): bool => ($entry['block']['blockName'] ?? '') === 'core/query';
        $sourceQueries = array_values(array_filter($sourceBlocks, $isQuery));
        $default = (string) pll_default_language('slug');
        $items = [];

        // The original's loop is the same one only when it looks the same: a
        // loop deleted or added in the translation shifts the places.
        $shape = static function (array $entry): string {
            $attrs = (array) ($entry['block']['attrs'] ?? []);
            $query = (array) ($attrs['query'] ?? []);

            return (string) wp_json_encode([
                $query['postType'] ?? 'post',
                count((array) ($query['include'] ?? [])),
                count((array) ($query['parents'] ?? [])),
                $query['perPage'] ?? null,
                $attrs['metadata']['name'] ?? '',
            ]);
        };
        $unused = $sourceQueries;

        foreach (array_values(array_filter($blocks, $isQuery)) as $position => $entry) {
            $query = (array) ($entry['block']['attrs']['query'] ?? []);
            $match = isset($unused[$position]) && $shape($unused[$position]) === $shape($entry) ? $position : null;

            foreach ($match === null ? $unused : [] as $candidate => $sourceEntry) {
                if ($shape($sourceEntry) === $shape($entry)) {
                    $match = $candidate;
                    break;
                }
            }

            $sourceQuery = $match !== null ? (array) ($unused[$match]['block']['attrs']['query'] ?? []) : [];

            if ($match !== null) {
                unset($unused[$match]);
            }

            $posts = [];

            foreach (['include', 'parents'] as $field) {
                $sourceIds = array_values((array) ($sourceQuery[$field] ?? []));

                foreach (array_values(array_filter((array) ($query[$field] ?? []), 'is_numeric')) as $place => $id) {
                    $id = (int) $id;
                    $idLanguage = $id > 0 ? pll_get_post_language($id) : false;
                    $references[] = $id > 0 ? ((int) pll_get_post($id, $default) ?: $id) : (int) ($sourceIds[$place] ?? 0);

                    // This language's, or a post type that is not translated.
                    if ($id > 0 && (! $idLanguage || $idLanguage === $language)) {
                        continue;
                    }

                    $sourceId = $id > 0 ? ((int) pll_get_post($id, $default) ?: $id) : (int) ($sourceIds[$place] ?? 0);
                    $posts[] = self::postNeeded($sourceId, $language, $field);
                }
            }

            if ($posts === []) {
                continue;
            }

            $attrs = (array) $entry['block']['attrs'];
            $postType = get_post_type_object((string) ($query['postType'] ?? 'post'));
            $name = (string) ($attrs['metadata']['name'] ?? '');
            $key = 'query:'.$entry['in'].':'.$entry['index'];

            $items[$key] = [
                'key' => $key,
                'kind' => 'query',
                'title' => $name !== '' ? $name : sprintf(
                    /* translators: %s: post type name, e.g. Products */
                    __('List of %s', 'gds-content-translation'),
                    $postType ? $postType->labels->name : __('posts', 'gds-content-translation')
                ),
                'url' => '',
                'index' => $entry['index'],
                'posts' => $posts,
            ] + ($entry['in'] > 0 ? ['in' => $entry['in']] : []);
        }

        return $items;
    }

    /**
     * A post a query loop chose, as the list shows it: the original, and its
     * translation in the language when it has one by now.
     *
     * @return array{id: int, title: string, url: string, field: string, translationId: int, published: bool}
     */
    private static function postNeeded(int $sourceId, string $language, string $field): array
    {
        $translationId = $sourceId > 0 ? self::translationOf($sourceId, $language) : 0;

        return [
            'id' => $sourceId,
            'title' => $sourceId > 0 ? get_the_title($sourceId) : __('A post no longer known', 'gds-content-translation'),
            'url' => $sourceId > 0 ? (string) get_edit_post_link($sourceId, 'raw') : '',
            'field' => $field,
            'translationId' => $translationId,
            'published' => $translationId > 0 && get_post_status($translationId) === 'publish',
        ];
    }

    /**
     * A post's translation in a language, not one in the trash.
     */
    private static function translationOf(int $postId, string $language): int
    {
        $translationId = (int) pll_get_post($postId, $language);

        return $translationId > 0 && get_post_status($translationId) !== 'trash' ? $translationId : 0;
    }

    /**
     * Links to the site's own pages that have no published translation in
     * the language. They lead to the original's page until one is published.
     *
     * @param  list<array{block: array<string, mixed>, in: int, index: int}>  $blocks
     * @param  list<int>  $references
     * @return array<string, array<string, mixed>>
     */
    private static function pageItems(WP_Post $post, array $blocks, string $language, array &$references): array
    {
        $home = untrailingslashit((string) get_option('home'));
        // A path from the root is the host's, not under the site's own path.
        $parts = wp_parse_url($home);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $urlAttributes = (array) apply_filters('gds_content_translation_link_url_attributes_by_block', ['core/button' => ['url']]);
        $default = (string) pll_default_language('slug');
        $items = [];
        $resolved = [];

        foreach ($blocks as $entry) {
            $block = $entry['block'];
            $urls = preg_match_all('/\bhref=["\']([^"\'#][^"\']*)["\']/i', (string) ($block['innerHTML'] ?? ''), $matches) ? $matches[1] : [];

            foreach ((array) ($urlAttributes[(string) ($block['blockName'] ?? '')] ?? []) as $attribute) {
                if (is_string($block['attrs'][$attribute] ?? null)) {
                    $urls[] = $block['attrs'][$attribute];
                }
            }

            foreach (array_unique($urls) as $href) {
                $href = html_entity_decode((string) $href, ENT_QUOTES, 'UTF-8');
                $url = str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $origin.$href : $href;

                if (! str_starts_with(set_url_scheme($url, 'https'), set_url_scheme($home, 'https'))) {
                    continue;
                }

                $targetId = $resolved[$url] ??= self::postIdOfUrl($url);

                if ($targetId <= 0 || $targetId === $post->ID || (int) $entry['in'] === $targetId || ! pll_is_translated_post_type((string) get_post_type($targetId))) {
                    continue;
                }

                $sourceId = (int) pll_get_post($targetId, $default) ?: $targetId;
                $references[] = $sourceId;
                $translationId = self::translationOf($sourceId, $language);

                if ($translationId > 0 && get_post_status($translationId) === 'publish') {
                    continue;
                }

                $key = 'page:'.$sourceId;
                $items[$key] ??= array_filter([
                    'key' => $key,
                    'kind' => 'page',
                    'title' => get_the_title($sourceId),
                    'url' => (string) get_permalink($sourceId),
                    'editUrl' => (string) get_edit_post_link($sourceId, 'raw'),
                    'sourceId' => $sourceId,
                    // A draft: the links switch once it is published.
                    'translationId' => $translationId,
                    'href' => $href,
                    'in' => $entry['in'],
                ], static fn ($value): bool => $value !== 0);
            }
        }

        return $items;
    }

    /**
     * The post an internal URL points to, whatever language the admin bar
     * filters the admin by: with "English" chosen there, url_to_postid()
     * looks only among English posts and finds no Finnish page.
     */
    public static function postIdOfUrl(string $url): int
    {
        $polylang = PLL();
        $current = $polylang->curlang ?? null;
        $filter = property_exists($polylang, 'filter_lang') ? $polylang->filter_lang : null;
        $polylang->curlang = null;

        if (property_exists($polylang, 'filter_lang')) {
            $polylang->filter_lang = null;
        }

        try {
            return (int) url_to_postid($url);
        } finally {
            $polylang->curlang = $current;

            if (property_exists($polylang, 'filter_lang')) {
                $polylang->filter_lang = $filter;
            }
        }
    }

    /**
     * Texts still to be written, in the translation or in its original
     * (which a translation then carries in other words). One item per
     * placeholder, with the texts it was found in.
     *
     * @param  list<array{block: array<string, mixed>, in: int, index: int}>  $blocks
     * @param  list<array{block: array<string, mixed>, in: int, index: int}>  $sourceBlocks
     * @return array<string, array<string, mixed>>
     */
    private static function placeholderItems(WP_Post $post, array $blocks, WP_Post $source, array $sourceBlocks): array
    {
        $items = [];

        foreach (['texts' => [$post, $blocks], 'sourceTexts' => [$source, $sourceBlocks]] as $field => [$owner, $list]) {
            $texts = array_merge([(string) $owner->post_title, (string) $owner->post_excerpt], array_map(static fn (array $entry): string => self::visibleText($entry['block']), $list));

            foreach ($texts as $text) {
                foreach (self::placeholders($text) as $match) {
                    $key = 'placeholder:'.mb_strtolower($match);
                    $items[$key] ??= ['key' => $key, 'kind' => 'placeholder', 'title' => $match, 'url' => '', 'texts' => [], 'sourceTexts' => []];
                    $items[$key][$field][] = self::excerpt($text, $match);
                }
            }
        }

        foreach ($items as $key => $item) {
            $items[$key]['texts'] = array_values(array_unique($item['texts']));
            $items[$key]['sourceTexts'] = array_values(array_unique($item['sourceTexts']));
            // Only the original has it: the translation may have it in other words.
            $items[$key]['inSource'] = $item['texts'] === [];
        }

        return $items;
    }

    /**
     * The placeholders in a text.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $words = self::placeholderWords;
        $patterns = [
            // "[ATT FYLLA I]", "[TÄYTETTÄVÄ]": words in capitals in brackets. One
            // short word ("[PDF]", "[EU]", "[HTML]") is an abbreviation.
            '/\[\s*(?:\p{Lu}{2,}(?:[ \-]+\p{Lu}+)+|\p{Lu}{5,})\s*\]/u',
            // "[TÄYTÄ]", "[Fill in the price]".
            '/\[[^\[\]]{0,40}(?<![\p{L}\p{N}])(?:'.$words.')(?![\p{L}\p{N}])[^\[\]]{0,40}\]/iu',
            // "TODO", "TBD", "XXX" in capitals, as words.
            '/(?<![\p{L}\p{N}])(?:TODO|TBD|FIXME|XXX+)(?![\p{L}\p{N}])/u',
            '/\blorem ipsum\b/i',
        ];

        /**
         * Patterns (regular expressions) of texts still to be written.
         *
         * @param  list<string>  $patterns
         */
        $patterns = (array) apply_filters('gds_content_translation_placeholder_patterns', $patterns);
        $found = [];

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && @preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $found[] = trim((string) $match);
                }
            }
        }

        // "[TODO]" found as a bracket and as a word: the bracket.
        $found = array_values(array_unique(array_filter($found)));

        return array_values(array_filter($found, static function (string $match) use ($found): bool {
            foreach ($found as $other) {
                if ($other !== $match && mb_stripos($other, $match) !== false) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * What a block shows as text: its HTML's text, not its tags, classes,
     * styles or scripts, and the attributes Polylang translates as text
     * (a theme block's title or button text).
     *
     * @param  array<string, mixed>  $block
     */
    public static function visibleText(array $block): string
    {
        $name = (string) ($block['blockName'] ?? '');

        if (in_array($name, ['core/code', 'core/preformatted', 'core/shortcode'], true)) {
            return '';
        }

        $html = (string) ($block['innerHTML'] ?? '');
        $html = (string) preg_replace('#<(script|style|code|pre|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        $texts = [trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8')))];

        foreach (BlockRulesCheck::translatedValues($block) as $value) {
            $texts[] = trim(wp_strip_all_tags($value));
        }

        return implode("\n", array_filter($texts));
    }

    /**
     * The text around a match, as the list shows it.
     */
    private static function excerpt(string $text, string $match): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $at = (int) mb_stripos($text, $match);
        $start = max(0, $at - 60);
        $excerpt = mb_substr($text, $start, mb_strlen($match) + 120);

        return ($start > 0 ? '…' : '').$excerpt.($start + mb_strlen($excerpt) < mb_strlen($text) ? '…' : '');
    }
}
