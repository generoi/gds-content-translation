<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\PageText;
use GeneroWP\ContentTranslation\SoftHyphens;
use WP_UnitTestCase;

/**
 * An editor's soft hyphens split a word for DeepL and Claude: they are
 * taken out of what is sent.
 */
class SoftHyphensTest extends WP_UnitTestCase
{
    public function test_soft_hyphens_are_taken_out_of_a_text(): void
    {
        $this->assertSame('pilariperustus', SoftHyphens::strip('pilari<span class="is-shy-character">&#173;</span>perustus'));
        $this->assertSame('<strong>pilariperustus</strong>', SoftHyphens::strip("<strong>pilari\u{00AD}perus&shy;tus</strong>"));
        $this->assertSame('Ei muutoksia', SoftHyphens::strip('Ei muutoksia'));
        $this->assertSame('pilariperustus', PageText::normalize('pilari&shy;perustus'));
    }

    public function test_they_are_taken_out_of_deepl_translate_requests_only(): void
    {
        $body = (string) wp_json_encode(['text' => ['pilari<span class="is-shy-character">&#173;</span>perustus', 'Ovi'], 'target_lang' => 'SV']);
        $args = SoftHyphens::removeFromRequest(['body' => $body], 'https://api-free.deepl.com/v2/translate');
        $this->assertSame(['pilariperustus', 'Ovi'], json_decode($args['body'], true)['text']);

        $this->assertSame($body, SoftHyphens::removeFromRequest(['body' => $body], 'https://example.com/v2/translate')['body']);
    }

    public function test_they_are_put_back_into_the_words_a_suggestion_kept(): void
    {
        $old = 'Pilari<span class="is-shy-character">&#173;</span>perustus ja terassi&shy;lauta';

        $this->assertSame(
            'Pilari<span class="is-shy-character">&#173;</span>perustus sekä terassi&shy;lauta',
            SoftHyphens::restore($old, 'Pilariperustus sekä terassilauta')
        );
        $this->assertSame('Uusi teksti', SoftHyphens::restore($old, 'Uusi teksti'));
    }
}
