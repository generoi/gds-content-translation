<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Polylang\BlockLinkTranslation;
use GeneroWP\ContentTranslation\Tests\PolylangTestCase;
use WP_HTML_Tag_Processor;

class BlockLinkTranslationTest extends PolylangTestCase
{
    private BlockLinkTranslation $translation;

    /** @var array<string, int> */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translation = new BlockLinkTranslation;
        $this->target = $this->createTranslatedPost(['post_name' => 'configurator']);
    }

    /**
     * Translate a paragraph link and return the resulting href.
     */
    private function translateHref(string $href, string $languageSlug): string
    {
        $blocks = parse_blocks(sprintf(
            '<!-- wp:paragraph --><p><a href="%s">Link</a></p><!-- /wp:paragraph -->',
            esc_attr($href)
        ));

        $blocks = $this->translation->translateBlocksDuringSync(
            $blocks,
            PLL()->model->get_language($languageSlug),
            get_post($this->target['en'])
        );

        $processor = new WP_HTML_Tag_Processor($blocks[0]['innerHTML']);
        $processor->next_tag(['tag_name' => 'A']);

        return (string) $processor->get_attribute('href');
    }

    private function relativePermalink(int $postId): string
    {
        return (string) wp_make_link_relative(get_permalink($postId));
    }

    public function test_it_translates_an_internal_link_to_the_target_language(): void
    {
        $href = $this->translateHref($this->relativePermalink($this->target['en']), 'fi');

        $this->assertSame(get_permalink($this->target['fi']), $href);
    }

    public function test_it_keeps_the_query_string(): void
    {
        $href = $this->translateHref(
            $this->relativePermalink($this->target['en']).'?tuote=window&ikkunan-tyyppi=MEKA',
            'fi'
        );

        $this->assertSame(
            get_permalink($this->target['fi']).'?tuote=window&ikkunan-tyyppi=MEKA',
            $href
        );
    }

    public function test_it_keeps_the_fragment(): void
    {
        $href = $this->translateHref($this->relativePermalink($this->target['en']).'#tekniset-tiedot', 'fi');

        $this->assertSame(get_permalink($this->target['fi']).'#tekniset-tiedot', $href);
    }

    public function test_it_keeps_the_query_string_and_the_fragment(): void
    {
        $href = $this->translateHref(
            $this->relativePermalink($this->target['en']).'?tuote=window#tekniset-tiedot',
            'fi'
        );

        $this->assertSame(
            get_permalink($this->target['fi']).'?tuote=window#tekniset-tiedot',
            $href
        );
    }

    public function test_it_keeps_a_link_already_in_the_target_language_as_written(): void
    {
        $lookups = 0;
        add_filter('url_to_postid', function (string $url) use (&$lookups) {
            $lookups++;

            return $url;
        });

        $url = $this->relativePermalink($this->target['fi']).'?tuote=window';

        $this->assertSame($url, $this->translateHref($url, 'fi'));
        $this->assertSame(0, $lookups);
    }

    public function test_it_appends_the_query_string_to_a_permalink_that_already_has_one(): void
    {
        // Polylang carries the language in a query parameter in some link modes.
        add_filter('post_link', fn (string $permalink, $post) => $post->ID === $this->target['fi']
            ? $permalink.'?lang=fi'
            : $permalink, 10, 2);

        $href = $this->translateHref($this->relativePermalink($this->target['en']).'?tuote=window', 'fi');

        $this->assertSame(get_permalink($this->target['fi']).'&tuote=window', $href);
    }

    public function test_it_translates_a_button_url_attribute(): void
    {
        $blocks = parse_blocks(sprintf(
            '<!-- wp:button {"url":"%s"} --><div class="wp-block-button"></div><!-- /wp:button -->',
            esc_attr($this->relativePermalink($this->target['en']).'?tuote=window')
        ));

        $blocks = $this->translation->translateBlocksDuringSync(
            $blocks,
            PLL()->model->get_language('fi'),
            get_post($this->target['en'])
        );

        $this->assertSame(
            get_permalink($this->target['fi']).'?tuote=window',
            $blocks[0]['attrs']['url']
        );
    }

    public function test_it_translates_links_in_nested_blocks_of_saved_content(): void
    {
        $content = sprintf(
            '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p><a href="%s">Link</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->',
            esc_attr($this->relativePermalink($this->target['en']))
        );

        $translated = $this->translation->translateContent($content, PLL()->model->get_language('fi'));

        $this->assertStringContainsString('href="'.get_permalink($this->target['fi']).'"', $translated);
    }

    public function test_it_translates_links_during_sync(): void
    {
        $blocks = parse_blocks(sprintf(
            '<!-- wp:paragraph --><p><a href="%s">Link</a></p><!-- /wp:paragraph -->',
            esc_attr($this->relativePermalink($this->target['en']).'?tuote=window')
        ));

        $translated = $this->translation->translateBlocksDuringSync(
            $blocks,
            PLL()->model->get_language('fi'),
            get_post($this->target['en'])
        );

        $this->assertStringContainsString(
            get_permalink($this->target['fi']).'?tuote=window',
            $translated[0]['innerHTML']
        );
    }

    public function test_it_leaves_external_links_unchanged(): void
    {
        $url = 'https://example.com/configurator/?tuote=window';

        $this->assertSame($url, $this->translateHref($url, 'fi'));
    }

    /**
     * @dataProvider nonPostUrls
     */
    public function test_it_leaves_non_post_links_unchanged(string $url): void
    {
        $this->assertSame($url, $this->translateHref($url, 'fi'));
    }

    /**
     * @return array<string, string[]>
     */
    public function nonPostUrls(): array
    {
        return [
            'anchor' => ['#tekniset-tiedot'],
            'mailto' => ['mailto:info@genero.fi'],
            'tel' => ['tel:+358401234567'],
            'unresolvable path' => ['/no-such-page/?tuote=window'],
        ];
    }

    public function test_it_leaves_links_without_a_translation_unchanged(): void
    {
        $untranslated = $this->createPost('en', ['post_name' => 'english-only']);
        $url = $this->relativePermalink($untranslated).'?tuote=window';

        $this->assertSame($url, $this->translateHref($url, 'fi'));
    }
}
