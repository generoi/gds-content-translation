<?php

namespace GeneroWP\ContentTranslation\Polylang;

use PLL_Language;
use WP_HTML_Tag_Processor;

/**
 * Rewrites same-site links in block content to the target language equivalent.
 */
class BlockLinkTranslation
{
    private const cacheGroup = 'gds_content_translation';

    private ?bool $translateOnRender = null;

    public function __construct()
    {
        add_filter('pll_translate_blocks_with_context', [$this, 'translateBlocksDuringSync'], 10, 3);
        add_filter('render_block_data', [$this, 'translateBlockOnRender'], 10, 1);
    }

    /**
     * @return array<string, string[]>
     */
    private function urlAttributesByBlock(): array
    {
        $defaults = [
            'core/button' => ['url'],
        ];

        /**
         * Block attributes that store internal URL strings (not post IDs).
         *
         * @param  array<string, string[]>  $attributesByBlock  Block name => attribute names.
         */
        return apply_filters('gds_content_translation_link_url_attributes_by_block', $defaults);
    }

    /**
     * @param  array[]  $blocks
     * @return array[]
     */
    public function translateBlocksDuringSync(array $blocks, PLL_Language $targetLanguage, $sourcePost): array
    {
        foreach ($blocks as $key => $block) {
            if (! is_array($block)) {
                continue;
            }

            $blocks[$key] = $this->translateBlock($block, $targetLanguage);
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $parsedBlock
     * @return array<string, mixed>
     */
    public function translateBlockOnRender(array $parsedBlock): array
    {
        if (is_admin() || ! function_exists('pll_current_language') || ! function_exists('PLL')) {
            return $parsedBlock;
        }

        /**
         * Whether to rewrite internal links on every front-end render.
         *
         * Links are already rewritten when a translation is created or
         * synced; the render pass is a fallback for content that predates
         * that. Sites whose content is clean can switch it off. Read once
         * per request.
         *
         * @param  bool  $enabled
         */
        $this->translateOnRender ??= (bool) apply_filters('gds_content_translation_translate_links_on_render', true);

        if (! $this->translateOnRender) {
            return $parsedBlock;
        }

        $languageSlug = pll_current_language();

        if (! is_string($languageSlug) || $languageSlug === '') {
            return $parsedBlock;
        }

        $targetLanguage = PLL()->model->get_language($languageSlug);

        if (! $targetLanguage instanceof PLL_Language) {
            return $parsedBlock;
        }

        return $this->translateBlock($parsedBlock, $targetLanguage);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function translateBlock(array $block, PLL_Language $targetLanguage): array
    {
        $blockName = $block['blockName'] ?? '';
        $urlAttributes = $this->urlAttributesByBlock();

        if (is_string($blockName) && isset($urlAttributes[$blockName])) {
            foreach ($urlAttributes[$blockName] as $attributeName) {
                if (empty($block['attrs'][$attributeName]) || ! is_string($block['attrs'][$attributeName])) {
                    continue;
                }

                $block['attrs'][$attributeName] = $this->translateUrl(
                    $block['attrs'][$attributeName],
                    $targetLanguage
                );
            }
        }

        if (! empty($block['innerHTML']) && is_string($block['innerHTML'])) {
            $block['innerHTML'] = $this->translateHrefsInHtml($block['innerHTML'], $targetLanguage);
        }

        if (! empty($block['innerContent']) && is_array($block['innerContent'])) {
            foreach ($block['innerContent'] as $index => $part) {
                if (is_string($part) && $part !== '') {
                    $block['innerContent'][$index] = $this->translateHrefsInHtml($part, $targetLanguage);
                }
            }
        }

        return $block;
    }

    private function translateHrefsInHtml(string $html, PLL_Language $targetLanguage): string
    {
        // WP_HTML_Tag_Processor arrived in WordPress 6.2.
        if (stripos($html, 'href=') === false || ! class_exists(WP_HTML_Tag_Processor::class)) {
            return $html;
        }

        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag(['tag_name' => 'A'])) {
            $href = $processor->get_attribute('href');

            if (! is_string($href) || $href === '') {
                continue;
            }

            $translatedHref = $this->translateUrl($href, $targetLanguage);

            if ($translatedHref !== $href) {
                $processor->set_attribute('href', $translatedHref);
            }
        }

        return $processor->get_updated_html();
    }

    private function translateUrl(string $url, PLL_Language $targetLanguage): string
    {
        $url = trim($url);

        if ($url === '' || $url === '#' || str_starts_with($url, '#')) {
            return $url;
        }

        if (preg_match('#^(mailto:|tel:|javascript:)#i', $url)) {
            return $url;
        }

        if (! $this->isInternalUrl($url)) {
            return $url;
        }

        $absoluteUrl = $this->toAbsoluteUrl($url);

        $urlLanguageSlug = $this->getUrlLanguageSlug($absoluteUrl);

        // Already a link in the target language: nothing to resolve.
        if ($urlLanguageSlug === $targetLanguage->slug) {
            return $url;
        }

        $postId = $this->resolvePostId($absoluteUrl, $urlLanguageSlug);

        if ($postId <= 0 || ! function_exists('pll_get_post')) {
            return $url;
        }

        $translatedPostId = (int) pll_get_post($postId, $targetLanguage->slug);

        if ($translatedPostId <= 0) {
            return $url;
        }

        $permalink = $this->getPermalinkInLanguage($translatedPostId, $targetLanguage);

        if ($permalink === '') {
            return $url;
        }

        // url_to_postid() matches on the path alone and the permalink is built
        // from scratch, so carry over anything the path didn't cover — e.g.
        // prefill or filter parameters and in-page anchors.
        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (is_string($query) && $query !== '') {
            $permalink .= (str_contains($permalink, '?') ? '&' : '?').$query;
        }

        $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

        if (is_string($fragment) && $fragment !== '') {
            $permalink .= '#'.$fragment;
        }

        return $permalink;
    }

    /**
     * The language a URL is in, read from the URL itself: a string parse in
     * Polylang, no database. Empty when the URL does not say.
     */
    private function getUrlLanguageSlug(string $absoluteUrl): string
    {
        $polylang = PLL();
        $linksModel = $polylang->links_model ?? null;

        if (! is_object($linksModel) || ! method_exists($linksModel, 'get_language_from_url')) {
            return '';
        }

        $urlLanguage = $linksModel->get_language_from_url($absoluteUrl);

        if (is_string($urlLanguage) && $urlLanguage !== '') {
            return $urlLanguage;
        }

        // No language in the URL. When the language is carried in the URL
        // (directory, subdomain or domain) and the default one is left out,
        // that means the default language. When it is set from the content,
        // the URL says nothing and the post has to be looked up.
        $options = $polylang->options ?? [];

        if (! empty($options['force_lang']) && ! empty($options['hide_default'])) {
            return (string) ($options['default_lang'] ?? '');
        }

        return '';
    }

    /**
     * url_to_postid() runs a WP_Query of its own and nothing caches it. Header
     * and footer links repeat on every page, so the result is cached, keyed on
     * when posts and terms last changed: a new, renamed or retranslated post
     * makes every entry stale at once.
     *
     * On the front end Polylang limits that query to the current language, so
     * a link to a default-language post on a Swedish page would never resolve.
     * The lookup runs in the language the URL is in.
     */
    private function resolvePostId(string $absoluteUrl, string $urlLanguageSlug): int
    {
        $key = md5($absoluteUrl.'|'.$urlLanguageSlug)
            .':'.wp_cache_get_last_changed('posts')
            .':'.wp_cache_get_last_changed('terms');

        $found = false;
        $cached = wp_cache_get($key, self::cacheGroup, false, $found);

        if ($found && is_int($cached)) {
            return $cached;
        }

        $polylang = PLL();
        $previousLanguage = $polylang->curlang;
        $urlLanguage = $urlLanguageSlug !== '' ? $polylang->model->get_language($urlLanguageSlug) : null;

        if ($urlLanguage instanceof PLL_Language) {
            $polylang->curlang = $urlLanguage;
        }

        try {
            $postId = (int) url_to_postid($absoluteUrl);
        } finally {
            $polylang->curlang = $previousLanguage;
        }

        wp_cache_set($key, $postId, self::cacheGroup, DAY_IN_SECONDS);

        return $postId;
    }

    private function isInternalUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        $homeHost = wp_parse_url(home_url(), PHP_URL_HOST);
        $urlHost = wp_parse_url($url, PHP_URL_HOST);

        if (! is_string($homeHost) || ! is_string($urlHost)) {
            return false;
        }

        return strcasecmp($homeHost, $urlHost) === 0;
    }

    private function toAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, '/')) {
            return home_url($url);
        }

        return $url;
    }

    private function getPermalinkInLanguage(int $postId, PLL_Language $targetLanguage): string
    {
        if (! function_exists('PLL')) {
            $permalink = get_permalink($postId);

            return is_string($permalink) ? $permalink : '';
        }

        $polylang = PLL();
        $previousLanguage = $polylang->curlang;
        $polylang->curlang = $targetLanguage;

        $permalink = get_permalink($postId);

        $polylang->curlang = $previousLanguage;

        return is_string($permalink) ? $permalink : '';
    }
}
