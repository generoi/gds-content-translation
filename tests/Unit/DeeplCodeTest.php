<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\DeeplCode;
use WP_UnitTestCase;

/**
 * DeepL gets no code to translate, and adds no marks after line breaks.
 */
class DeeplCodeTest extends WP_UnitTestCase
{
    private const script = '<script>var a = "x"; function f() { return a || ""; }</script>';

    public function test_scripts_and_styles_are_sent_as_placeholders_and_put_back(): void
    {
        $text = '<p>Free shipping</p>'.self::script.'<style>.a{content:"b"}</style>';
        [$sent, $kept] = DeeplCode::protect($text);

        $this->assertSame('<p>Free shipping</p><gds-keep i="0"></gds-keep><gds-keep i="1"></gds-keep>', $sent);
        $this->assertSame('<p>Fri frakt</p>'.self::script.'<style>.a{content:"b"}</style>', DeeplCode::restore('<p>Fri frakt</p><gds-keep i="0"/><gds-keep i="1"></gds-keep>', $kept));
    }

    public function test_the_request_and_response_of_a_translate_call(): void
    {
        $body = (string) wp_json_encode(['text' => ['<p>Hello</p>'.self::script, 'Plain'], 'target_lang' => 'SV']);
        $args = DeeplCode::protectRequest(['body' => $body], 'https://api.deepl.com/v2/translate');
        $sent = json_decode($args['body'], true);

        $this->assertSame(['<p>Hello</p><gds-keep i="0"></gds-keep>', 'Plain'], $sent['text']);
        $this->assertSame('html', $sent['tag_handling']);
        $this->assertSame(['gds-keep'], $sent['ignore_tags']);

        $response = ['body' => (string) wp_json_encode(['translations' => [['text' => '<p>Hej</p><gds-keep i="0"></gds-keep>'], ['text' => 'Enkel']]])];
        $texts = array_column(json_decode(DeeplCode::restoreResponse($response, $args, 'https://api.deepl.com/v2/translate')['body'], true)['translations'], 'text');

        $this->assertSame(['<p>Hej</p>'.self::script, 'Enkel'], $texts);
        $this->assertSame($body, DeeplCode::protectRequest(['body' => $body], 'https://example.com/v2/translate')['body']);
    }

    public function test_a_comma_deepl_adds_after_a_line_break_is_taken_out(): void
    {
        $this->assertSame('Company Inc<br>30 Main Street<br>Springfield', DeeplCode::removeAddedBreakMarks('Company Inc<br>30 Main Street<br>Springfield', 'Company Inc<br>, 30 Main Street<br>, Springfield'));
        // One the original has stays.
        $this->assertSame('a<br>, b', DeeplCode::removeAddedBreakMarks('a<br>, b', 'a<br>, b'));
    }

    public function test_a_form_text_keeps_the_original_scripts_through_kses(): void
    {
        $source = '<p>Shipping to the address below</p>'.self::script;
        $translation = '<p>Frakt till adressen nedan</p><script>var a = ”x”;</script>';

        $this->assertSame('<p>Frakt till adressen nedan</p>'.self::script, DeeplCode::sanitizeKeepingCode($source, $translation, 'wp_kses_post'));
        $this->assertSame('<p>Hej</p>', DeeplCode::sanitizeKeepingCode('<p>Hi</p>', '<p>Hej</p>', 'wp_kses_post'));
    }

    public function test_a_translation_gets_back_the_code_kses_took_apart(): void
    {
        $style = '<style>.proof{display:flex}.proof > b{opacity:.6}</style>';
        $script = '<script>document.querySelectorAll(".x").forEach(function (e) { if (e && e.dataset.on) return; });</script>';
        $source = self::factory()->post->create(['post_content' => '<!-- wp:html --><p>Trusted by</p>'.$style.$script.'<!-- /wp:html -->']);
        // What wp_kses_post() leaves of each, around the translated text.
        $translation = self::factory()->post->create(['post_content' => '<!-- wp:html --><p>Används av</p>'.wp_kses_post($style).wp_kses_post($script).'<!-- /wp:html -->']);

        $this->assertSame(2, DeeplCode::restoreInTranslation($source, $translation));
        $this->assertSame('<!-- wp:html --><p>Används av</p>'.$style.$script.'<!-- /wp:html -->', get_post($translation)->post_content);
        $this->assertSame(0, DeeplCode::restoreInTranslation($source, $translation));
    }
}
