<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\TranslationAudit;
use WP_UnitTestCase;

/**
 * A suggestion that comes with the element around the text it is for is
 * written without it, so it does not nest.
 */
class SuggestionWrapperTest extends WP_UnitTestCase
{
    public function test_an_element_the_text_does_not_have_is_taken_off(): void
    {
        $this->assertSame('Lätt och <strong>snabb</strong>', TranslationAudit::withoutWrapper('<li>Lätt och <strong>snabb</strong></li>', 'Lätt, snabb'));
        $this->assertSame('Lätt, snabb', TranslationAudit::withoutWrapper("<p>Lätt, snabb</p>\n", 'Lätt och snabb'));
    }

    public function test_markup_the_text_has_or_several_elements_stay(): void
    {
        $this->assertSame('<p>Ny</p>', TranslationAudit::withoutWrapper('<p>Ny</p>', '<p>Gammal</p>'));
        $this->assertSame('<li>a</li><li>b</li>', TranslationAudit::withoutWrapper('<li>a</li><li>b</li>', 'a b'));
        $this->assertSame('<strong>Ny</strong> text', TranslationAudit::withoutWrapper('<strong>Ny</strong> text', 'Gammal text'));
        $this->assertSame('Ingen markup', TranslationAudit::withoutWrapper('Ingen markup', 'Text'));
    }
}
