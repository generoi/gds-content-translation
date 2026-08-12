<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\Polylang\BlockRules;
use WP_UnitTestCase;

class BlockRulesTest extends WP_UnitTestCase
{
    public function test_it_merges_the_built_in_sync_rules(): void
    {
        $rules = (new BlockRules)->mergeSyncAttributeRules(['core/image' => ['post' => ['id' => true]]]);

        $this->assertSame(['post' => ['id' => true]], $rules['core/image']);
        $this->assertTrue($rules['woocommerce/single-product']['post']['productId']);
        $this->assertTrue($rules['core/query']['post']['query']['include']);
    }

    public function test_project_rules_override_the_built_in_ones(): void
    {
        add_filter('gds_content_translation_pll_sync_block_rules_for_attributes', fn () => [
            'gds/post-teaser' => ['post' => ['postId' => true]],
        ]);

        $rules = (new BlockRules)->mergeSyncAttributeRules([]);

        $this->assertSame(['gds/post-teaser' => ['post' => ['postId' => true]]], $rules);
    }

    public function test_it_returns_the_incoming_rules_when_a_project_opts_out(): void
    {
        add_filter('gds_content_translation_pll_sync_block_rules_for_attributes', fn () => []);
        add_filter('gds_content_translation_pll_blocks_rules_for_attributes', fn () => []);

        $blockRules = new BlockRules;
        $incoming = ['core/image' => ['post' => ['id' => true]]];

        $this->assertSame($incoming, $blockRules->mergeSyncAttributeRules($incoming));
        $this->assertSame($incoming, $blockRules->mergeTranslateAttributeRules($incoming));
    }

    public function test_it_merges_project_translate_rules(): void
    {
        add_filter('gds_content_translation_pll_blocks_rules_for_attributes', fn () => [
            'gds/hero' => ['title'],
        ]);

        $rules = (new BlockRules)->mergeTranslateAttributeRules(['core/button' => ['text']]);

        $this->assertSame(['core/button' => ['text'], 'gds/hero' => ['title']], $rules);
    }
}
