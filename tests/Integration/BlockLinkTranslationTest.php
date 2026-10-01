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
        $this->setCurrentLanguage($languageSlug);

        $blocks = parse_blocks(sprintf(
            '<!-- wp:paragraph --><p><a href="%s">Link</a></p><!-- /wp:paragraph -->',
            esc_attr($href)
        ));

        $block = $this->translation->translateBlockOnRender($blocks[0]);

        $processor = new WP_HTML_Tag_Processor($block['innerHTML']);
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

    /**
     * render_block_data runs on every frontend render. A link that is already
     * in the page's language is left exactly as written, parameters and all,
     * without looking the post up.
     */
    public function test_it_leaves_a_link_in_the_source_language_alone(): void
    {
        $url = $this->relativePermalink($this->target['en']).'?tuote=window';

        $this->assertSame($url, $this->translateHref($url, 'en'));
    }

    public function test_it_leaves_a_link_already_in_the_target_language_alone(): void
    {
        global $wpdb;

        // The language is read from the URL; the post is never looked up, so
        // the path does not have to resolve.
        $url = '/fi/configurator-fi/?tuote=window';
        $this->translateHref($url, 'fi'); // Warm Polylang's language cache.
        $queries = $wpdb->num_queries;

        $this->assertSame($url, $this->translateHref($url, 'fi'));
        $this->assertSame($queries, $wpdb->num_queries, 'A same-language link must not be resolved.');
    }

    public function test_it_resolves_a_repeated_link_once(): void
    {
        global $wpdb;

        $url = $this->relativePermalink($this->target['en']);
        $this->translateHref($url, 'fi');
        $queries = $wpdb->num_queries;

        $this->assertSame(get_permalink($this->target['fi']), $this->translateHref($url, 'fi'));
        $this->assertSame($queries, $wpdb->num_queries, 'The URL lookup should be cached.');
    }

    public function test_the_cached_lookup_follows_a_renamed_post(): void
    {
        $url = $this->relativePermalink($this->target['en']);
        $this->translateHref($url, 'fi');

        wp_update_post(['ID' => $this->target['en'], 'post_name' => 'renamed-configurator']);

        // The old path no longer resolves once the post has moved.
        $this->assertSame($url, $this->translateHref($url, 'fi'));
        $this->assertSame(
            get_permalink($this->target['fi']),
            $this->translateHref($this->relativePermalink($this->target['en']), 'fi')
        );
    }

    public function test_render_time_translation_can_be_switched_off(): void
    {
        add_filter('gds_content_translation_translate_links_on_render', '__return_false');

        $url = $this->relativePermalink($this->target['en']);

        $this->assertSame($url, $this->translateHref($url, 'fi'));
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
        $this->setCurrentLanguage('fi');

        $blocks = parse_blocks(sprintf(
            '<!-- wp:button {"url":"%s"} --><div class="wp-block-button"></div><!-- /wp:button -->',
            esc_attr($this->relativePermalink($this->target['en']).'?tuote=window')
        ));

        $block = $this->translation->translateBlockOnRender($blocks[0]);

        $this->assertSame(
            get_permalink($this->target['fi']).'?tuote=window',
            $block['attrs']['url']
        );
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
