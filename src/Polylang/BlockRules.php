<?php

namespace GeneroWP\ContentTranslation\Polylang;

/**
 * Bridges project-specific block rules into Polylang Pro filters.
 */
class BlockRules
{
    public function __construct()
    {
        add_filter('pll_blocks_rules_for_attributes', [$this, 'mergeTranslateAttributeRules']);
        add_filter('pll_sync_block_rules_for_attributes', [$this, 'mergeSyncAttributeRules']);
        add_filter('pll_blocks_xpath_rules', [$this, 'removeLinkUrlRules']);
    }

    /**
     * Keep link URLs out of translation.
     *
     * Polylang Pro marks the href of buttons and linked images as text to
     * translate, so machine translation translated the paths themselves
     * (/ohjeet/ to /instruktioner/) and every internal link broke. The source
     * URL is kept instead, and BlockLinkTranslation points it at the target
     * language's page.
     *
     * @param  array<string, string[]>  $rules  Block name => XPath expressions.
     * @return array<string, string[]>
     */
    public function removeLinkUrlRules(array $rules): array
    {
        if (apply_filters('gds_content_translation_translate_link_urls', false)) {
            return $rules;
        }

        foreach ($rules as $blockName => $expressions) {
            if (! is_array($expressions)) {
                continue;
            }

            $rules[$blockName] = array_values(array_filter(
                $expressions,
                static fn ($expression): bool => ! is_string($expression) || ! preg_match('#/@href$#', $expression)
            ));
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function mergeTranslateAttributeRules(array $rules): array
    {
        $projectRules = apply_filters('gds_content_translation_pll_blocks_rules_for_attributes', $this->defaultTranslateRules());

        if ($projectRules === []) {
            return $rules;
        }

        return array_merge($rules, $projectRules);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function mergeSyncAttributeRules(array $rules): array
    {
        $projectRules = apply_filters('gds_content_translation_pll_sync_block_rules_for_attributes', $this->defaultSyncRules());

        if ($projectRules === []) {
            return $rules;
        }

        return array_merge($rules, $projectRules);
    }

    /**
     * Built-in rules that apply on any site using this plugin.
     *
     * @return array<string, mixed>
     */
    private function defaultTranslateRules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSyncRules(): array
    {
        return [
            'core/query' => [
                'post' => [
                    'query' => [
                        'include' => true,
                    ],
                ],
            ],
            'woocommerce/single-product' => [
                'post' => [
                    'productId' => true,
                ],
            ],
        ];
    }
}
