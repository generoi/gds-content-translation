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
}
