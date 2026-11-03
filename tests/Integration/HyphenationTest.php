<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Hyphenation;
use GeneroWP\ContentTranslation\Tests\RestTestCase;
use GeneroWP\ContentTranslation\TranslationAudit;

/**
 * Soft hyphens in the long words of headings and buttons, as
 * wp-gutenberg-shy writes them. Claude is faked.
 */
class HyphenationTest extends RestTestCase
{
    private const shy = Hyphenation::marker;

    /** @var array<string, mixed>|null */
    private ?array $claudeResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Hyphenation::isAvailable()) {
            $this->markTestSkipped('vanderlee/syllable is not installed.');
        }

        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('pre_http_request', [$this, 'fakeClaude'], 10, 3);
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        remove_filter('pre_http_request', [$this, 'fakeClaude'], 10);

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return false|array<string, mixed>
     */
    public function fakeClaude($pre, array $args, string $url)
    {
        if (str_contains($url, 'admin-ajax.php')) {
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }

        if (! str_contains($url, 'api.anthropic.com') || $this->claudeResponse === null) {
            return $pre;
        }

        return [
            'headers' => [],
            'body' => (string) wp_json_encode([
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                'content' => [['type' => 'text', 'text' => wp_json_encode($this->claudeResponse)]],
            ]),
            'response' => ['code' => 200, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    public function test_long_words_of_headings_and_buttons_get_a_few_soft_hyphens(): void
    {
        $content = '<!-- wp:heading --><h2 class="wp-block-heading">Pilariperustukset ja terassilaudat</h2><!-- /wp:heading -->'
            .'<!-- wp:paragraph --><p>Pilariperustukset tekstissä</p><!-- /wp:paragraph -->'
            .'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/x">Kattotuolirakenteet</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

        $result = Hyphenation::applyToContent($content, 'fi');

        $this->assertStringContainsString('<h2 class="wp-block-heading">Pilari'.self::shy.'perus'.self::shy.'tukset ja terassi'.self::shy.'laudat</h2>', $result);
        // Body text is left to the browser.
        $this->assertStringContainsString('<p>Pilariperustukset tekstissä</p>', $result);
        $this->assertStringContainsString('>Katto'.self::shy, $result);
        $this->assertStringContainsString('<!-- wp:heading -->', $result, 'The block markup stays.');
    }

    public function test_words_an_editor_hyphenated_code_and_abbreviations_are_left_alone(): void
    {
        $edited = '<h2>Pilari'.self::shy.'perustukset, terassi&shy;laudat, <code>kattotuolirakenteet</code> ja SUOMALAISVALMISTEINEN</h2>';

        $this->assertSame($edited, Hyphenation::applyToContent($edited, 'fi'));
    }

    public function test_each_language_breaks_by_its_own_rules(): void
    {
        $this->assertSame('<h2>Terrass'.self::shy.'brädorna</h2>', Hyphenation::applyToContent('<h2>Terrassbrädorna</h2>', 'sv_SE'));
        $this->assertSame('<h2>Interna'.self::shy.'tional'.self::shy.'ization</h2>', Hyphenation::applyToContent('<h2>Internationalization</h2>', 'en_US'));
    }

    public function test_a_post_is_saved_once_and_then_left_as_it_is(): void
    {
        $ids = $this->createTranslatedPost(['post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Pilariperustukset</h2><!-- /wp:heading -->']);

        $this->assertTrue(Hyphenation::applyToPost($ids['fi']));
        $this->assertStringContainsString('Pilari'.self::shy.'perus'.self::shy.'tukset', get_post_field('post_content', $ids['fi']));
        $this->assertFalse(Hyphenation::applyToPost($ids['fi']), 'Nothing to add the second time.');

        update_option(Hyphenation::optionKey, '0');
        $this->assertFalse(Hyphenation::applyToPost($ids['en']), 'Off in the settings.');
    }

    public function test_the_action_hyphenates_originals_and_translations(): void
    {
        $ids = $this->createTranslatedPost(['post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Internationalization</h2><!-- /wp:heading -->']);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Kansainvälistäminen</h2><!-- /wp:heading -->']);

        $data = $this->action('hyphenate', [$ids['en']], ['original' => true, 'languages' => []]);
        $this->assertStringContainsString(self::shy, get_post_field('post_content', $ids['en']));
        $this->assertStringNotContainsString(self::shy, get_post_field('post_content', $ids['fi']), 'Only the original was asked for.');
        $this->assertStringContainsString('1 post', $data['message']);

        $this->action('hyphenate', [$ids['en']], ['original' => false, 'languages' => ['fi']]);
        $this->assertStringContainsString(self::shy, get_post_field('post_content', $ids['fi']));
    }

    public function test_a_used_suggestion_keeps_the_soft_hyphens_and_gets_them_in_new_words(): void
    {
        $ids = $this->createTranslatedPost(['post_title' => 'Page', 'post_excerpt' => '']);
        wp_update_post(['ID' => $ids['en'], 'post_content' => '<!-- wp:paragraph --><p>Read about the deck boards</p><!-- /wp:paragraph -->', 'post_excerpt' => '']);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => '<!-- wp:paragraph --><p>Lue terassi'.self::shy.'laudoista</p><!-- /wp:paragraph -->', 'post_excerpt' => '']);
        $this->claudeResponse = ['summary' => 'One.', 'remarks' => [
            ['segment' => 1, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'More.', 'suggestion' => 'Lue terassilaudoista ja pilariperustuksista'],
        ]];

        TranslationAudit::queue($ids['fi'], 'apply');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $content = get_post_field('post_content', $ids['fi']);
        $this->assertSame(1, TranslationAudit::get($ids['fi'])['autoApplied']);
        $this->assertStringContainsString('terassi'.self::shy.'laudoista', $content, 'The editor’s soft hyphen stays.');
        $this->assertStringContainsString('pilari'.self::shy.'perustuk', $content, 'The new word gets them too.');
    }
}
