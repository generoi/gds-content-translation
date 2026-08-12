<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\Polylang\MetaRules;
use WP_UnitTestCase;

class MetaRulesTest extends WP_UnitTestCase
{
    public function test_it_merges_the_rules_of_the_source_post_type(): void
    {
        $postId = (int) self::factory()->post->create(['post_type' => 'page']);

        add_filter('gds_content_translation_pll_post_metas_to_export', fn () => [
            'page' => ['subtitle' => 1],
            'post' => ['byline' => 1],
        ]);

        $metas = (new MetaRules)->mergePostMetasToExport(['_thumbnail_id' => 1], $postId, 0);

        $this->assertSame(['_thumbnail_id' => 1, 'subtitle' => 1], $metas);
    }

    public function test_it_leaves_metas_untouched_for_unlisted_post_types(): void
    {
        $postId = (int) self::factory()->post->create();

        add_filter('gds_content_translation_pll_post_metas_to_export', fn () => [
            'page' => ['subtitle' => 1],
        ]);

        $this->assertSame([], (new MetaRules)->mergePostMetasToExport([], $postId, 0));
    }

    public function test_it_sets_the_translation_mode_of_a_configured_acf_field(): void
    {
        add_filter('gds_content_translation_acf_field_translations', fn () => [
            'hero_title' => 'translate',
        ]);

        $field = (new MetaRules)->applyAcfFieldTranslationModes(['name' => 'hero_title']);

        $this->assertSame('translate', $field['translations']);
    }

    public function test_it_leaves_unconfigured_acf_fields_untouched(): void
    {
        add_filter('gds_content_translation_acf_field_translations', fn () => [
            'hero_title' => 'translate',
        ]);

        $field = ['name' => 'hero_subtitle'];

        $this->assertSame($field, (new MetaRules)->applyAcfFieldTranslationModes($field));
    }
}
