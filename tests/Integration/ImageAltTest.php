<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\DeeplGlossary;
use GeneroWP\ContentTranslation\ImageAlt;
use GeneroWP\ContentTranslation\MediaActions;
use GeneroWP\ContentTranslation\Tests\RestTestCase;

/**
 * An image's alt text per language when Polylang does not translate media:
 * shown on pages in that language where the alt text is the media
 * library's, translated by DeepL (faked).
 */
class ImageAltTest extends RestTestCase
{
    private int $imageId = 0;

    /** @var mixed */
    private $mediaSupport;

    /** @var list<array<string, mixed>> */
    private array $deeplRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaSupport = PLL()->model->options['media_support'] ?? false;
        PLL()->model->options['media_support'] = false;

        $this->imageId = (int) self::factory()->attachment->create_object(['file' => 'cabin.jpg', 'post_mime_type' => 'image/jpeg', 'post_title' => 'Cabin']);
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'A cabin on pillars');

        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'test-key:fx');
        add_filter('pre_http_request', [$this, 'fakeDeepl'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeDeepl'], 10);
        PLL()->model->options['media_support'] = $this->mediaSupport;
        PLL()->curlang = null;

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $response
     * @param  array<string, mixed>  $args
     */
    public function fakeDeepl($response, array $args, string $url)
    {
        if (! str_contains($url, 'deepl.com/v2/translate')) {
            return $response;
        }

        $body = json_decode((string) $args['body'], true);
        $this->deeplRequests[] = $body;

        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => (string) wp_json_encode(['translations' => array_map(static fn (string $text): array => ['text' => 'FI: '.$text], $body['text'])])];
    }

    private function image(string $alt): string
    {
        return '<!-- wp:image {"id":'.$this->imageId.'} --><figure class="wp-block-image"><img src="https://example.org/cabin.jpg" alt="'.esc_attr($alt).'" class="wp-image-'.$this->imageId.'"/></figure><!-- /wp:image -->';
    }

    public function test_a_page_in_the_language_shows_its_alt_text_where_the_block_has_the_media_librarys(): void
    {
        ImageAlt::set($this->imageId, 'fi', 'Mökki pilareiden päällä');
        $this->setCurrentLanguage('fi');

        $this->assertStringContainsString('alt="Mökki pilareiden päällä"', do_blocks($this->image('A cabin on pillars')));
        $this->assertStringContainsString('alt="Mökki pilareiden päällä"', do_blocks($this->image('')));
        // A translation's own alt text wins.
        $this->assertStringContainsString('alt="Oma teksti"', do_blocks($this->image('Oma teksti')));

        // The only image of a block that names it, without the class.
        $cover = '<!-- wp:media-text {"mediaId":'.$this->imageId.'} --><div class="wp-block-media-text"><figure><img src="https://example.org/cabin.jpg" alt=""/></figure></div><!-- /wp:media-text -->';
        $this->assertStringContainsString('alt="Mökki pilareiden päällä"', do_blocks($cover));

        $this->assertSame('Mökki pilareiden päällä', ImageAlt::attachmentAttributes(['alt' => 'A cabin on pillars'], get_post($this->imageId))['alt']);
        $this->assertSame('Given', ImageAlt::attachmentAttributes(['alt' => 'Given'], get_post($this->imageId))['alt']);
    }

    public function test_nothing_changes_in_the_default_language_or_without_a_translation(): void
    {
        ImageAlt::set($this->imageId, 'fi', 'Mökki pilareiden päällä');
        $this->setCurrentLanguage('en');
        $this->assertStringContainsString('alt="A cabin on pillars"', do_blocks($this->image('A cabin on pillars')));

        ImageAlt::set($this->imageId, 'fi', '');
        $this->setCurrentLanguage('fi');
        $this->assertStringContainsString('alt="A cabin on pillars"', do_blocks($this->image('A cabin on pillars')));
        $this->assertFalse(metadata_exists('post', $this->imageId, ImageAlt::metaPrefix.'fi'), 'An emptied alt text is removed.');
    }

    public function test_with_media_translation_on_polylang_handles_alt_texts(): void
    {
        PLL()->model->options['media_support'] = true;

        $this->assertFalse(ImageAlt::isActive());
        $this->assertSame(404, $this->request('GET', '/alts/fi')->get_status());
        $this->assertSame(404, $this->request('POST', '/alts/fi/translate')->get_status());
    }

    public function test_deepl_translates_the_missing_alt_texts_with_the_glossary(): void
    {
        update_option(DeeplGlossary::optionKey, ['glossaryId' => 'g-1', 'dictionaries' => [['sourceLang' => 'EN', 'targetLang' => 'FI', 'entries' => 3]]]);
        $done = (int) self::factory()->attachment->create_object(['file' => 'beam.jpg', 'post_mime_type' => 'image/jpeg']);
        update_post_meta($done, '_wp_attachment_image_alt', 'A beam');
        ImageAlt::set($done, 'fi', 'Palkki');
        $same = (int) self::factory()->attachment->create_object(['file' => 'cabin-2.jpg', 'post_mime_type' => 'image/jpeg']);
        update_post_meta($same, '_wp_attachment_image_alt', 'A cabin on pillars');

        $list = $this->request('GET', '/alts/fi', ['missing' => true])->get_data();
        $this->assertEqualsCanonicalizing([$this->imageId, $same], array_column($list['items'], 'id'));
        $this->assertSame(2, $list['missing']['fi']);

        $response = $this->request('POST', '/alts/fi/translate');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $this->assertSame('FI: A cabin on pillars', ImageAlt::get($this->imageId, 'fi'));
        $this->assertSame('FI: A cabin on pillars', ImageAlt::get($same, 'fi'));
        $this->assertSame('Palkki', ImageAlt::get($done, 'fi'), 'A translated alt text stays.');
        $this->assertSame(0, $response->get_data()['missing']['fi']);
        // One request, the same text once, with the glossary.
        $this->assertCount(1, $this->deeplRequests);
        $this->assertSame(['A cabin on pillars'], $this->deeplRequests[0]['text']);
        $this->assertSame('g-1', $this->deeplRequests[0]['glossary_id']);

        delete_option(DeeplGlossary::optionKey);
    }

    public function test_alt_texts_are_edited_by_hand_and_the_default_language_has_none(): void
    {
        $response = $this->request('POST', '/alts/fi', ['alts' => [$this->imageId => ' <b>Mökki</b> ']]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame('Mökki', ImageAlt::get($this->imageId, 'fi'));

        $search = $this->request('GET', '/alts/fi', ['search' => 'Mökki'])->get_data();
        $this->assertSame([$this->imageId], array_column($search['items'], 'id'));
        $this->assertSame('A cabin on pillars', $search['items'][0]['source']);

        $this->assertSame(404, $this->request('GET', '/alts/en')->get_status());
    }

    public function test_the_files_list_shows_an_images_alt_text_in_the_translations_language(): void
    {
        ImageAlt::set($this->imageId, 'fi', 'Mökki');
        $translation = $this->createPost('fi');
        $items = MediaActions::describe($translation, [['key' => 'image:1', 'kind' => 'image', 'state' => 'open', 'attachmentId' => $this->imageId]]);

        $this->assertSame(['source' => 'A cabin on pillars', 'translation' => 'Mökki'], $items[0]['alt']);
    }
}
