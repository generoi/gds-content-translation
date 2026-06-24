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
        ];
    }
}
