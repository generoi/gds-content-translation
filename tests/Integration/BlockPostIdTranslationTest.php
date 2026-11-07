<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Polylang\BlockPostIdTranslation;
use GeneroWP\ContentTranslation\Tests\PolylangTestCase;

class BlockPostIdTranslationTest extends PolylangTestCase
{
    /** @var array<string, int> */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = $this->createTranslatedPost(['post_name' => 'teaser-target']);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function teaser(array $attrs): array
    {
        return [
            'blockName' => 'gds/post-teaser',
            'attrs' => $attrs,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ];
    }

    public function test_it_remaps_a_post_id_to_the_translation(): void
    {
        $blocks = BlockPostIdTranslation::translateBlocks(
            [$this->teaser(['postId' => $this->target['en']])],
            'fi'
        );

        $this->assertSame($this->target['fi'], $blocks[0]['attrs']['postId']);
    }

    public function test_it_removes_the_attribute_when_no_translation_exists(): void
    {
        $untranslated = $this->createPost('en', ['post_name' => 'english-only']);

        $blocks = BlockPostIdTranslation::translateBlocks(
            [$this->teaser(['postId' => $untranslated])],
            'fi'
        );

        $this->assertArrayNotHasKey('postId', $blocks[0]['attrs']);
    }

    public function test_it_keeps_an_id_of_an_object_without_a_language(): void
    {
        // Media, when Polylang does not translate media: the same in every language.
        $image = self::factory()->attachment->create_object('photo.jpg', 0, ['post_mime_type' => 'image/jpeg']);
        $block = ['blockName' => 'woocommerce/featured-product', 'attrs' => ['mediaId' => $image], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];

        $blocks = BlockPostIdTranslation::translateBlocks([$block], 'fi');

        $this->assertSame($image, $blocks[0]['attrs']['mediaId']);
    }

    public function test_it_leaves_non_numeric_values_untouched(): void
    {
        $blocks = BlockPostIdTranslation::translateBlocks(
            [$this->teaser(['postId' => ''])],
            'fi'
        );

        $this->assertSame('', $blocks[0]['attrs']['postId']);
    }

    public function test_it_leaves_unmapped_blocks_untouched(): void
    {
        $block = $this->teaser(['postId' => $this->target['en']]);
        $block['blockName'] = 'core/paragraph';

        $blocks = BlockPostIdTranslation::translateBlocks([$block], 'fi');

        $this->assertSame($this->target['en'], $blocks[0]['attrs']['postId']);
    }

    public function test_it_translates_nested_blocks(): void
    {
        $group = [
            'blockName' => 'core/group',
            'attrs' => [],
            'innerBlocks' => [$this->teaser(['postId' => $this->target['en']])],
            'innerHTML' => '',
            'innerContent' => [],
        ];

        $blocks = BlockPostIdTranslation::translateBlocks([$group], 'fi');

        $this->assertSame($this->target['fi'], $blocks[0]['innerBlocks'][0]['attrs']['postId']);
    }

    public function test_it_translates_on_frontend_render(): void
    {
        $this->setCurrentLanguage('fi');

        $block = (new BlockPostIdTranslation)->translateBlockOnRender(
            $this->teaser(['postId' => $this->target['en']])
        );

        $this->assertSame($this->target['fi'], $block['attrs']['postId']);
    }

    public function test_it_normalizes_saved_post_content(): void
    {
        $content = sprintf(
            '<!-- wp:gds/post-teaser {"postId":%d} /-->',
            $this->target['en']
        );

        wp_update_post([
            'ID' => $this->target['fi'],
            'post_content' => $content,
        ]);

        $this->assertTrue(BlockPostIdTranslation::normalizePostContent($this->target['fi']));

        $blocks = parse_blocks(get_post($this->target['fi'])->post_content);

        $this->assertSame($this->target['fi'], $blocks[0]['attrs']['postId']);
    }

    /**
     * wp_update_post() unslashes its input: without wp_slash() every JSON
     * escape in the block comment and every backslash in the HTML was lost.
     */
    public function test_normalizing_keeps_escapes_and_backslashes(): void
    {
        // As on the status screen: an administrator, so kses leaves the
        // block attributes alone and only the slashing is under test.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $translation = $this->target['fi'];
        $content = sprintf(
            '<!-- wp:gds/post-teaser {"postId":%d,"metadata":{"name":"Quote \\u0022x\\u0022 \\u003cy\\u003e \\u002d\\u002d"}} /-->',
            $this->target['en']
        )."\n".'<!-- wp:paragraph --><p>C:\\path\\to</p><!-- /wp:paragraph -->';

        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $translation]);
        clean_post_cache($translation);

        $this->assertTrue(BlockPostIdTranslation::normalizePostContent($translation));

        $saved = get_post_field('post_content', $translation);

        $this->assertStringContainsString(sprintf('"postId":%d', $translation), $saved);
        $this->assertStringContainsString('Quote \\u0022x\\u0022 \\u003cy\\u003e \\u002d\\u002d', $saved);
        $this->assertStringContainsString('<p>C:\\path\\to</p>', $saved);
    }

    public function test_it_translates_a_product_collections_hand_picked_products(): void
    {
        $block = [
            'blockName' => 'woocommerce/product-collection',
            'attrs' => ['query' => ['postType' => 'product', 'woocommerceHandPickedProducts' => [$this->target['en']], 'include' => [$this->target['en']]]],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ];

        $query = BlockPostIdTranslation::translateBlocks([$block], 'fi')[0]['attrs']['query'];

        $this->assertSame([$this->target['fi']], $query['woocommerceHandPickedProducts']);
        $this->assertSame([$this->target['fi']], $query['include']);
    }

    public function test_a_products_own_single_product_block_points_at_the_translation(): void
    {
        // WooCommerce's post type, translated by Polylang, without WooCommerce.
        if (! post_type_exists('product')) {
            register_post_type('product', ['public' => true]);
        }
        add_filter('pll_get_post_types', static fn (array $types): array => $types + ['product' => 'product']);
        PLL()->model->clean_languages_cache();

        $product = $this->createTranslatedPost(['post_type' => 'product', 'post_name' => 'puller']);
        $block = static fn (int $id): array => ['blockName' => 'woocommerce/single-product', 'attrs' => ['productId' => $id], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];

        // Translated before the translation existed: 0.
        $this->assertSame($product['fi'], BlockPostIdTranslation::translateBlocks([$block(0)], 'fi', $product['fi'])[0]['attrs']['productId']);
        $this->assertSame($product['fi'], BlockPostIdTranslation::translateBlocks([$block($product['en'])], 'fi', $product['fi'])[0]['attrs']['productId']);
    }

    public function test_an_id_lost_to_a_later_translation_is_filled_in_from_the_original(): void
    {
        // The page was translated when its teaser's post had no translation: 0.
        $page = $this->createTranslatedPost(['post_name' => 'page-with-teaser']);
        wp_update_post(['ID' => $page['en'], 'post_content' => serialize_blocks([$this->teaser(['postId' => $this->target['en']])])]);
        wp_update_post(['ID' => $page['fi'], 'post_content' => serialize_blocks([$this->teaser(['postId' => 0])])]);

        $this->assertSame([$page['fi']], BlockPostIdTranslation::translationsWaitingFor($this->target['en'], 'fi'));
        $this->assertSame(1, BlockPostIdTranslation::relinkFromOriginal($page['fi']));
        $this->assertSame($this->target['fi'], parse_blocks(get_post_field('post_content', $page['fi']))[0]['attrs']['postId']);
        $this->assertSame(0, BlockPostIdTranslation::relinkFromOriginal($page['fi']));
    }
}
