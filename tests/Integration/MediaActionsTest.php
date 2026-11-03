<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\DeeplApi;
use GeneroWP\ContentTranslation\DocumentTranslation;
use GeneroWP\ContentTranslation\FormTranslation;
use GeneroWP\ContentTranslation\MediaCheck;
use GeneroWP\ContentTranslation\SvgTranslation;
use GeneroWP\ContentTranslation\Tests\RestTestCase;

/**
 * What can be done about what a translation still shows in the original's
 * language: use another file, form or address, translate an SVG's labels,
 * draft a document with DeepL. DeepL is faked.
 */
class MediaActionsTest extends RestTestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<array{url: string, body: mixed}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'test-key:fx');
        add_filter('pre_http_request', [$this, 'fakeDeepl'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeDeepl'], 10);

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (glob(wp_get_upload_dir()['path'].'/*-fi.*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    /**
     * DeepL as these tests need it: texts come back in capitals, a
     * document is done at the first status request.
     *
     * @param  false|array<string, mixed>  $response
     * @param  array<string, mixed>  $args
     */
    public function fakeDeepl($response, array $args, string $url)
    {
        if (! str_contains($url, 'deepl.com')) {
            return $response;
        }

        $this->requests[] = ['url' => $url, 'body' => $args['body'] ?? null];
        $json = static fn (array $body): array => ['response' => ['code' => 200, 'message' => 'OK'], 'body' => (string) wp_json_encode($body), 'headers' => []];

        if (str_ends_with($url, '/v2/translate')) {
            $body = json_decode((string) $args['body'], true);

            return $json(['translations' => array_map(static fn (string $text): array => ['text' => mb_strtoupper($text)], $body['text'])]);
        }

        if (str_ends_with($url, '/v2/usage')) {
            return $json(['character_count' => 100, 'character_limit' => 500000]);
        }

        if (str_ends_with($url, '/v2/document')) {
            return $json(['document_id' => 'doc1', 'document_key' => 'key1']);
        }

        if (str_ends_with($url, '/result')) {
            return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '%PDF translated', 'headers' => []];
        }

        if (str_contains($url, '/v2/document/doc1')) {
            return $json(['status' => 'done', 'billed_characters' => 50000]);
        }

        return $json([]);
    }

    private function attachment(string $name, string $mime, string $contents = 'x'): int
    {
        $uploads = wp_get_upload_dir();
        wp_mkdir_p($uploads['path']);
        $file = $uploads['path'].'/'.$name;
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return (int) self::factory()->attachment->create_object($file, 0, ['post_mime_type' => $mime, 'post_title' => $name]);
    }

    /**
     * @return array<string, int>
     */
    private function translated(string $content): array
    {
        $ids = $this->createTranslatedPost(['post_content' => $content]);
        MediaCheck::check($ids['fi']);

        return $ids;
    }

    private function mediaAction(int $postId, string $key, string $action, array $data = []): \WP_REST_Response
    {
        return $this->request('POST', '/media/'.$postId.'/action', ['key' => $key, 'action' => $action] + $data);
    }

    private function fileBlock(int $attachmentId): string
    {
        $url = wp_get_attachment_url($attachmentId);

        return sprintf('<!-- wp:file {"id":%d,"href":"%s"} --><div class="wp-block-file"><a href="%s">Guide</a></div><!-- /wp:file -->', $attachmentId, $url, $url);
    }

    public function test_another_file_replaces_the_originals_in_the_translation_only(): void
    {
        $original = $this->attachment('guide.pdf', 'application/pdf');
        $translated = $this->attachment('guide-fi-own.pdf', 'application/pdf');
        $ids = $this->translated($this->fileBlock($original));

        $response = $this->mediaAction($ids['fi'], 'file:'.MediaCheckTestPath::of($original), 'replace', ['value' => $translated]);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $content = get_post_field('post_content', $ids['fi']);
        $this->assertStringContainsString('"id":'.$translated, $content);
        $this->assertStringContainsString((string) wp_get_attachment_url($translated), $content);
        $this->assertStringNotContainsString((string) wp_get_attachment_url($original), $content);
        $this->assertStringContainsString((string) wp_get_attachment_url($original), get_post_field('post_content', $ids['en']));
        $this->assertSame(0, $response->get_data()['open']);
    }

    public function test_a_link_and_a_form_are_replaced(): void
    {
        $ids = $this->translated('<!-- wp:paragraph --><p><a href="https://example.com/en/guide?a=1&amp;b=2">Guide</a></p><!-- /wp:paragraph --><!-- wp:gravityforms/form {"formId":"3"} /-->[gravityform id="3" title="false"]');

        $this->assertSame(200, $this->mediaAction($ids['fi'], 'link:example.com/en/guide?a=1&b=2', 'replace', ['value' => 'https://example.com/fi/opas'])->get_status());
        $this->assertSame(200, $this->mediaAction($ids['fi'], 'form:3', 'replace', ['value' => 7])->get_status());

        $content = get_post_field('post_content', $ids['fi']);
        $this->assertStringContainsString('href="https://example.com/fi/opas"', $content);
        $this->assertStringContainsString('"formId":"7"', $content);
        $this->assertStringContainsString('[gravityform id="7"', $content);
        $this->assertSame([], MediaCheck::get($ids['fi'])['items']);
    }

    public function test_a_link_is_replaced_only_where_it_is_the_whole_address(): void
    {
        $ids = $this->translated('<!-- wp:paragraph --><p><a href="https://example.com/en">Home</a> and <a href="https://example.com/en/guide">https://example.com/en</a></p><!-- /wp:paragraph -->');

        $this->assertSame(200, $this->mediaAction($ids['fi'], 'link:example.com/en', 'replace', ['value' => 'https://example.com/fi'])->get_status());

        $content = get_post_field('post_content', $ids['fi']);
        $this->assertStringContainsString('<a href="https://example.com/fi">Home</a>', $content);
        $this->assertStringContainsString('<a href="https://example.com/en/guide">https://example.com/en</a>', $content);
    }

    public function test_an_action_the_item_does_not_offer_is_refused(): void
    {
        $ids = $this->translated('<!-- wp:paragraph --><p><a href="https://example.com/en/guide">Guide</a></p><!-- /wp:paragraph -->');

        $this->assertSame(400, $this->mediaAction($ids['fi'], 'link:example.com/en/guide', 'svg-translate')->get_status());
    }

    public function test_an_address_that_is_not_one_is_refused(): void
    {
        $ids = $this->translated('<!-- wp:paragraph --><p><a href="https://example.com/en/guide">Guide</a></p><!-- /wp:paragraph -->');

        $this->assertSame(400, $this->mediaAction($ids['fi'], 'link:example.com/en/guide', 'replace', ['value' => 'javascript:alert(1)'])->get_status());
    }

    public function test_an_svgs_labels_are_translated_into_a_copy_shared_by_translations(): void
    {
        if (! DeeplApi::isAvailable()) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $svg = $this->attachment('deck.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" aria-label="Deck plan"><title>Deck</title><text x="1">Deck board 28 × 120</text><text><tspan>Two</tspan><tspan>lines</tspan></text></svg>');
        update_post_meta($svg, '_wp_attachment_image_alt', 'A deck');
        $content = sprintf('<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s" class="wp-image-%d"/></figure><!-- /wp:image -->', $svg, wp_get_attachment_url($svg), $svg);
        $ids = $this->translated($content);

        $response = $this->mediaAction($ids['fi'], 'file:'.MediaCheckTestPath::of($svg), 'svg-translate');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $copy = MediaCheck::copyOf($svg, 'fi');
        $this->assertGreaterThan(0, $copy);
        $written = (string) file_get_contents(get_attached_file($copy));
        $this->assertStringContainsString('<text x="1">DECK BOARD 28 × 120</text>', $written);
        $this->assertStringContainsString('<tspan>TWO LINES</tspan><tspan></tspan>', $written);
        $this->assertStringContainsString('<title>DECK</title>', $written);
        $this->assertStringContainsString('aria-label="DECK PLAN"', $written);
        $this->assertSame('A DECK', get_post_meta($copy, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('wp-image-'.$copy, get_post_field('post_content', $ids['fi']));

        $item = $response->get_data()['items'][0];
        $this->assertSame('translated', $item['state']);
        $this->assertContains('svg-labels', $item['actions']);

        // A second translation in the language uses the same copy, without DeepL.
        $other = $this->createPost('fi', ['post_content' => $content]);
        $second = $this->createPost('en', ['post_content' => $content]);
        pll_save_post_translations(['en' => $second, 'fi' => $other]);
        $calls = count($this->requests);
        SvgTranslation::translateFor($other, $svg);
        $this->assertSame($calls, count($this->requests));
        $this->assertStringContainsString('wp-image-'.$copy, get_post_field('post_content', $other));

        // A corrected label is written into the copy.
        $this->mediaAction($ids['fi'], 'file:'.MediaCheckTestPath::of($copy), 'svg-labels', ['labels' => ['Deck board 28 × 120' => 'Terassilauta 28 × 120']]);
        $this->assertStringContainsString('>Terassilauta 28 × 120</text>', (string) file_get_contents(get_attached_file($copy)));

        // And the original's again.
        $this->mediaAction($ids['fi'], 'file:'.MediaCheckTestPath::of($copy), 'restore');
        $this->assertStringContainsString('wp-image-'.$svg.'"', get_post_field('post_content', $ids['fi']));
    }

    public function test_a_document_is_drafted_by_deepl_only_when_the_setting_allows_it(): void
    {
        if (! DeeplApi::isAvailable()) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $pdf = $this->attachment('opas.pdf', 'application/pdf', '%PDF original');
        $ids = $this->translated($this->fileBlock($pdf));
        $key = 'file:'.MediaCheckTestPath::of($pdf);

        $this->assertNotContains('document-translate', $this->request('GET', '/media/'.$ids['fi'])->get_data()['items'][0]['actions']);
        $this->assertSame(400, $this->mediaAction($ids['fi'], $key, 'document-translate')->get_status());

        update_option(DocumentTranslation::optionKey, true);
        $data = $this->request('GET', '/media/'.$ids['fi'])->get_data();
        $this->assertContains('document-translate', $data['items'][0]['actions']);
        $this->assertSame(50000, $data['documentCost']['characters']);
        $this->assertTrue($data['documentCost']['free']);
        $this->assertSame(500000, $data['documentCost']['limit']);

        $this->assertSame(200, $this->mediaAction($ids['fi'], $key, 'document-translate')->get_status());
        $this->assertSame('translating', DocumentTranslation::jobs($ids['fi'])[(string) $pdf]['status']);
        // Not sent (and billed) a second time while DeepL works on it.
        $uploads = count(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['url'], '/v2/document')));
        $this->assertSame(400, $this->mediaAction($ids['fi'], $key, 'document-translate')->get_status());
        $this->assertSame($uploads, count(array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['url'], '/v2/document'))));

        DocumentTranslation::poll($ids['fi'], $pdf);
        $job = DocumentTranslation::jobs($ids['fi'])[(string) $pdf];
        $this->assertSame('ready', $job['status']);
        $this->assertSame(50000, $job['billed']);
        $this->assertSame('%PDF translated', file_get_contents(get_attached_file($job['attachmentId'])));
        // Not used before someone chooses to.
        $this->assertStringContainsString((string) wp_get_attachment_url($pdf), get_post_field('post_content', $ids['fi']));

        $this->assertSame(200, $this->mediaAction($ids['fi'], $key, 'document-use')->get_status());
        $this->assertStringContainsString((string) wp_get_attachment_url($job['attachmentId']), get_post_field('post_content', $ids['fi']));
        $this->assertSame([], DocumentTranslation::jobs($ids['fi']));
    }

    public function test_what_is_in_a_synced_pattern_is_listed_and_changed_in_the_pattern(): void
    {
        if (! pll_is_translated_post_type('wp_block')) {
            $this->markTestSkipped('Patterns are not translated without Polylang Pro.');
        }

        $form = '<!-- wp:gravityforms/form {"formId":"3"} /-->';
        $patterns = [
            'en' => $this->createPost('en', ['post_type' => 'wp_block', 'post_title' => 'Order', 'post_content' => $form]),
            'fi' => $this->createPost('fi', ['post_type' => 'wp_block', 'post_title' => 'Tilaus', 'post_content' => $form]),
        ];
        pll_save_post_translations($patterns);
        $ref = static fn (int $id): string => sprintf('<!-- wp:block {"ref":%d} /-->', $id);

        $ids = $this->createTranslatedPost(['post_content' => $ref($patterns['en'])]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $ref($patterns['fi'])]);
        MediaCheck::check($ids['fi']);

        $item = $this->request('GET', '/media/'.$ids['fi'])->get_data()['items'][0];
        $this->assertSame('form:3', $item['key']);
        $this->assertSame($patterns['fi'], $item['in']);
        $this->assertSame('Tilaus', $item['inTitle']);
        $this->assertFalse($item['inOriginal']);
        $this->assertSame(['replace'], $item['actions']);

        // The pattern changes; the page and the original's pattern do not.
        $this->assertSame(200, $this->mediaAction($ids['fi'], 'form:3', 'replace', ['value' => 7])->get_status());
        $this->assertStringContainsString('"formId":"7"', get_post_field('post_content', $patterns['fi']));
        $this->assertStringContainsString('"formId":"3"', get_post_field('post_content', $patterns['en']));
        $this->assertSame($ref($patterns['fi']), get_post_field('post_content', $ids['fi']));
        $this->assertSame([], MediaCheck::get($ids['fi'])['items']);

        // A change to the pattern rechecks the pages that show it.
        wp_update_post(['ID' => $patterns['fi'], 'post_content' => $form]);
        MediaCheck::checkSaved();
        $this->assertSame(['form:3'], array_column(MediaCheck::get($ids['fi'])['items'], 'key'));

        // The original's pattern is not changed for a translation.
        $other = $this->createTranslatedPost(['post_content' => $ref($patterns['en'])]);
        MediaCheck::check($other['fi']);
        $item = $this->request('GET', '/media/'.$other['fi'])->get_data()['items'][0];
        $this->assertTrue($item['inOriginal']);
        $this->assertSame([], $item['actions']);
        $this->assertSame(400, $this->mediaAction($other['fi'], 'form:3', 'replace', ['value' => 7])->get_status());
        $this->assertStringContainsString('"formId":"3"', get_post_field('post_content', $patterns['en']));
    }

    public function test_an_svg_in_a_synced_pattern_is_translated_in_the_pattern(): void
    {
        if (! DeeplApi::isAvailable() || ! pll_is_translated_post_type('wp_block')) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $svg = $this->attachment('plan.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><text>Deck</text></svg>');
        $content = sprintf('<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s" class="wp-image-%d"/></figure><!-- /wp:image -->', $svg, wp_get_attachment_url($svg), $svg);
        $patterns = [
            'en' => $this->createPost('en', ['post_type' => 'wp_block', 'post_content' => $content]),
            'fi' => $this->createPost('fi', ['post_type' => 'wp_block', 'post_content' => $content]),
        ];
        pll_save_post_translations($patterns);
        $ids = $this->createTranslatedPost(['post_content' => sprintf('<!-- wp:block {"ref":%d} /-->', $patterns['en'])]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => sprintf('<!-- wp:block {"ref":%d} /-->', $patterns['fi'])]);

        $this->assertSame(1, SvgTranslation::translateAll($ids['fi']));

        $copy = MediaCheck::copyOf($svg, 'fi');
        $this->assertStringContainsString('wp-image-'.$copy, get_post_field('post_content', $patterns['fi']));
        $this->assertStringContainsString('wp-image-'.$svg.'"', get_post_field('post_content', $patterns['en']));
        $item = MediaCheck::get($ids['fi'])['items'][0];
        $this->assertSame('translated', $item['state']);
        $this->assertSame($patterns['fi'], $item['in']);
    }

    public function test_a_forms_texts_are_walked_without_adding_any(): void
    {
        $form = [
            'id' => 3,
            'title' => 'Guide',
            'button' => ['type' => 'text', 'text' => 'Send'],
            'confirmations' => ['c1' => ['type' => 'message', 'message' => '<p>Thanks!</p>'], 'c2' => ['type' => 'redirect', 'message' => 'Unused']],
            'notifications' => [
                'n1' => ['toType' => 'field', 'name' => 'Visitor', 'subject' => 'Your guide', 'message' => 'Hello {Name:1}'],
                'n2' => ['toType' => 'email', 'name' => 'Admin', 'subject' => 'New entry', 'message' => 'See it'],
            ],
            'fields' => [
                (object) ['type' => 'email', 'label' => 'Email', 'placeholder' => 'you@example.com', 'description' => ''],
                (object) ['type' => 'radio', 'label' => 'Size', 'choices' => [['text' => 'Small', 'value' => 'small'], ['text' => '28 × 120', 'value' => 'x']]],
            ],
        ];

        $texts = FormTranslation::texts($form);
        $this->assertSame(['Guide', 'Send', '<p>Thanks!</p>', 'Your guide', 'Hello {Name:1}', 'Email', 'you@example.com', 'Size', 'Small'], array_keys($texts));

        $translated = FormTranslation::walk($form, static fn (string $text): string => strtoupper($text));
        $this->assertSame('SEND', $translated['button']['text']);
        $this->assertSame('Unused', $translated['confirmations']['c2']['message']);
        $this->assertSame('SMALL', $translated['fields'][1]->choices[0]['text']);
        $this->assertSame('small', $translated['fields'][1]->choices[0]['value'], 'What a form submits stays the same.');
        $this->assertSame('Size', $form['fields'][1]->label, 'Gravity Forms\' own field objects are not changed.');
        $this->assertArrayNotHasKey('save', $translated);
        $this->assertSame('See it', $translated['notifications']['n2']['message']);
    }

    public function test_a_form_is_shown_in_the_language_of_the_page(): void
    {
        $form = ['id' => 3, 'title' => 'Guide', 'button' => ['text' => 'Send'], 'fields' => []];
        FormTranslation::save(3, 'fi', ['Send' => 'Lähetä', 'Guide' => '']);

        $this->assertSame(['Send' => 'Lähetä'], FormTranslation::translations(3, 'fi'));

        $_POST['gds_ct_lang'] = 'fi';
        $this->assertSame('Lähetä', FormTranslation::translateForm($form)['button']['text']);

        $_POST['gds_ct_lang'] = 'en';
        $this->setCurrentLanguage('en');
        $this->assertSame('Send', FormTranslation::translateForm($form)['button']['text']);
        unset($_POST['gds_ct_lang']);
    }
}

/**
 * The key MediaCheck gives a media library file.
 */
final class MediaCheckTestPath
{
    public static function of(int $attachmentId): string
    {
        $file = (string) get_attached_file($attachmentId);
        $base = trailingslashit((string) wp_get_upload_dir()['basedir']);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : wp_basename($file);
    }
}
