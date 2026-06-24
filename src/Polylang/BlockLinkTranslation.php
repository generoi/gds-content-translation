<?php

namespace GeneroWP\ContentTranslation\Polylang;

use PLL_Language;
use WP_HTML_Tag_Processor;

/**
 * Rewrites same-site links in block content to the target language equivalent.
 */
class BlockLinkTranslation
{
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
        if (stripos($html, 'href=') === false) {
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

        $postId = url_to_postid($this->toAbsoluteUrl($url));

        if ($postId <= 0 || ! function_exists('pll_get_post')) {
            return $url;
        }

        $translatedPostId = (int) pll_get_post($postId, $targetLanguage->slug);

        if ($translatedPostId <= 0) {
            return $url;
        }

        $permalink = $this->getPermalinkInLanguage($translatedPostId, $targetLanguage);

        return $permalink !== '' ? $permalink : $url;
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
