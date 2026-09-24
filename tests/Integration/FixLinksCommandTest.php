<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Polylang\BlockLinkTranslation;
use GeneroWP\ContentTranslation\Polylang\FixLinksCommand;
use GeneroWP\ContentTranslation\Tests\PolylangTestCase;

class FixLinksCommandTest extends PolylangTestCase
{
    /** @var array<string, int> */
    private array $target;

    private int $wrong;

    private int $correct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = $this->createTranslatedPost(['post_name' => 'configurator']);

        // A Finnish post still linking to the English page, and one whose link
        // is already Finnish.
        $this->wrong = $this->createPost('fi', ['post_content' => $this->linkBlock($this->target['en'])]);
        $this->correct = $this->createPost('fi', ['post_content' => $this->linkBlock($this->target['fi'])]);
    }

    private function linkBlock(int $postId): string
    {
        return sprintf(
            '<!-- wp:paragraph --><p><a href="%s">Link</a></p><!-- /wp:paragraph -->',
            esc_attr((string) wp_make_link_relative(get_permalink($postId)))
        );
    }

    private function command(): FixLinksCommand
    {
        return new FixLinksCommand(new BlockLinkTranslation);
    }

    public function test_it_rewrites_links_to_the_posts_own_language(): void
    {
        $this->assertSame([$this->wrong], $this->command()->fix(false));

        $this->assertStringContainsString(
            'href="'.get_permalink($this->target['fi']).'"',
            get_post_field('post_content', $this->wrong)
        );
        $this->assertSame($this->linkBlock($this->target['fi']), get_post_field('post_content', $this->correct));
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $before = get_post_field('post_content', $this->wrong);

        $this->assertSame([$this->wrong], $this->command()->fix(true));
        $this->assertSame($before, get_post_field('post_content', $this->wrong));
    }
}
