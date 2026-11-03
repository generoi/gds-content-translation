<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\PageText;
use WP_UnitTestCase;

/**
 * What a visitor of a page sees or hears (PageText), read from a page as the
 * site renders it (tests/fixtures/page-sv.html, after the Swedish home page).
 */
class PageTextTest extends WP_UnitTestCase
{
    /** @var array<string, array{text: string, where: list<string>, kinds: list<string>}> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PageText::extract((string) file_get_contents(dirname(__DIR__).'/fixtures/page-sv.html')) as $item) {
            $this->items[$item['text']] = $item;
        }
    }

    public function test_it_reads_the_head_title_description_sharing_tags_and_json_ld(): void
    {
        $this->assertSame(['head'], $this->items['Hem - bcepilar']['where']);
        $this->assertSame(['title', 'meta', 'jsonld'], $this->items['Hem - bcepilar']['kinds']);
        $this->assertSame(['meta'], $this->items['Färdig betonggrund utan gjutning.']['kinds']);
        // A breadcrumb left in English by the SEO plugin.
        $this->assertSame(['head'], $this->items['Home']['where']);
        $this->assertSame(['jsonld'], $this->items['Home']['kinds']);
    }

    public function test_it_groups_texts_by_the_outermost_landmark(): void
    {
        $this->assertSame(['header'], $this->items['Suunnittele perustus']['where']);
        $this->assertSame(['footer'], $this->items['Perustuksen teko']['where']);
        $this->assertSame(['body'], $this->items['Hoppa till innehållet']['where']);
        // The article's own header and an aside inside the content are the content.
        $this->assertSame(['main'], $this->items['Färdiga grundplintar']['where']);
        $this->assertSame(['main'], $this->items['Läs mer om tjälisolering.']['where']);
        // The same text in the header and the footer is one text in both.
        $this->assertSame(['header', 'footer'], $this->items['bcepilar frontpage']['where']);
    }

    public function test_it_reads_the_attributes_a_visitor_sees_or_hears(): void
    {
        $this->assertSame(['placeholder'], $this->items['Hae tuotteella, koodilla tai käyttökohteella']['kinds']);
        $this->assertSame(['value'], $this->items['Sök']['kinds']);
        $this->assertSame(['aria-label'], $this->items['Språk']['kinds']);
        $this->assertSame(['alt'], $this->items['Kaivinkone tasoittaa sorapohjan']['kinds']);
        $this->assertSame(['title'], $this->items['Grävmaskinen jämnar ut grusunderlaget']['kinds']);
        $this->assertSame(['alt'], $this->items['AAA-luottoluokitus']['kinds']);
        // Its aria-label and its text say the same: one text.
        $this->assertSame(['aria-label', 'text'], $this->items['Avaa valikko']['kinds']);
    }

    public function test_it_reads_inline_markup_as_one_text_and_svg_texts(): void
    {
        $this->assertArrayHasKey('Plintarna levereras färdiggjutna från fabriken, så det behövs ingen egen gjutning.', $this->items);
        $this->assertSame(['svg'], $this->items['Konfiguraattorin tulosnäkymä puhelimessa']['kinds']);
        $this->assertSame(['svg'], $this->items['Placeringsschema']['kinds']);
        $this->assertSame(['svg'], $this->items['Terrassbräda 28 × 120']['kinds']);
    }

    public function test_it_leaves_out_scripts_styles_the_admin_bar_and_texts_without_words(): void
    {
        $texts = array_keys($this->items);

        foreach (['Lataa lisää', 'Muokkaa sivua', 'Aktivoi JavaScript', 'Mallipohja', '48 × 148', 'info@bce.fi', 'https://www.facebook.com/bce', 'https://bcepilar.ddev.site/sv/hem/'] as $hidden) {
            foreach ($texts as $text) {
                $this->assertStringNotContainsString($hidden, $text);
            }
        }

        $this->assertStringNotContainsString('.header{', implode("\n", $texts));
        $this->assertStringNotContainsString('fill:red', implode("\n", $texts));
    }

    public function test_it_lists_each_text_once(): void
    {
        $all = PageText::extract((string) file_get_contents(dirname(__DIR__).'/fixtures/page-sv.html'));
        $texts = array_column($all, 'text');

        $this->assertSame(count($texts), count(array_unique($texts)));
        $this->assertCount(29, $all);
        $this->assertSame(['head' => 4, 'header' => 10, 'main' => 10, 'footer' => 6, 'body' => 1], PageText::countByRegion($all));
    }

    public function test_an_empty_page_has_no_texts(): void
    {
        $this->assertSame([], PageText::extract(''));
        $this->assertSame([], PageText::extract('<html><body><script>var a = "Hei";</script></body></html>'));
    }
}
