<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\MediaCheck;
use GeneroWP\ContentTranslation\Tests\RestTestCase;

/**
 * What a translation shows that is not its text: files, SVGs with text,
 * forms, embeds and links still the original's.
 */
class MediaCheckTest extends RestTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
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

    private function fileBlock(int $attachmentId): string
    {
        $url = wp_get_attachment_url($attachmentId);

        return sprintf('<!-- wp:file {"id":%d,"href":"%s"} --><div class="wp-block-file"><a href="%s">Guide</a></div><!-- /wp:file -->', $attachmentId, $url, $url);
    }

    /**
     * @return array<string, int>
     */
    private function translated(string $source, string $translation): array
    {
        $ids = $this->createTranslatedPost(['post_content' => $source]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $translation]);

        return $ids;
    }

    /**
     * @return array<string, string> Key => kind.
     */
    private function kinds(int $postId): array
    {
        return array_column(MediaCheck::check($postId)['items'], 'kind', 'key');
    }

    public function test_finds_what_the_translation_shares_with_the_original(): void
    {
        $pdf = $this->attachment('guide.pdf', 'application/pdf');
        $svg = $this->attachment('diagram.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><text x="1" y="1">Deck board</text><text>28 × 120</text></svg>');
        $content = $this->fileBlock($pdf)
            .sprintf('<!-- wp:media-text {"mediaId":%d,"mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="%s" alt=""/></figure></div><!-- /wp:media-text -->', $svg, wp_get_attachment_url($svg))
            .'<!-- wp:gravityforms/form {"formId":"3"} /-->'
            .'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=abc","providerNameSlug":"youtube"} --><figure class="wp-block-embed"></figure><!-- /wp:embed -->'
            .'<!-- wp:paragraph --><p><a href="https://example.co.uk/page">Ours</a> <a href="https://www.example.com/en/guide">English</a></p><!-- /wp:paragraph -->';
        $ids = $this->translated($content, $content);

        $result = MediaCheck::check($ids['fi']);
        $items = array_column($result['items'], null, 'kind');

        $this->assertSame(['document', 'svg', 'form', 'embed', 'link'], array_column($result['items'], 'kind'));
        $this->assertSame(['Deck board'], $items['svg']['texts'], 'Text without letters is no text to translate.');
        $this->assertSame('example.com/en/guide', substr($items['link']['title'], -20));
        $this->assertSame(5, $result['open']);
        $this->assertSame('5', get_post_meta($ids['fi'], MediaCheck::countMetaKey, true));
    }

    public function test_what_the_translation_replaced_is_not_counted(): void
    {
        $original = $this->attachment('opas.pdf', 'application/pdf');
        $translated = $this->attachment('guide-en.pdf', 'application/pdf');
        $ids = $this->translated(
            $this->fileBlock($original).'<!-- wp:gravityforms/form {"formId":"3"} /-->',
            $this->fileBlock($translated).'<!-- wp:gravityforms/form {"formId":"4"} /-->'
        );

        $this->assertSame([], $this->kinds($ids['fi']));
    }

    public function test_an_svg_without_text_and_an_unrelated_link_are_not_counted(): void
    {
        $svg = $this->attachment('icon.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');
        $content = sprintf('<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s"/></figure><!-- /wp:image -->', $svg, wp_get_attachment_url($svg))
            .'<!-- wp:paragraph --><p><a href="https://example.org/">A site</a> <a href="mailto:a@example.org">Mail</a></p><!-- /wp:paragraph -->';
        $ids = $this->translated($content, $content);

        $this->assertSame([], $this->kinds($ids['fi']));
    }

    public function test_a_link_for_the_translations_language_is_not_counted(): void
    {
        // The default language is English here: example.com/en/… reads as English.
        $content = '<!-- wp:paragraph --><p><a href="https://example.com/en/guide">Guide</a> <a href="https://example.com/en/guide?lang=fi">Fi</a> <a href="https://example.com/fi/guide">Fi path</a></p><!-- /wp:paragraph -->';
        $ids = $this->translated($content, $content);

        $this->assertSame(['link:example.com/en/guide' => 'link'], $this->kinds($ids['fi']));
    }

    public function test_an_image_is_counted_only_once_claude_found_text_in_it(): void
    {
        $image = $this->attachment('cover.png', 'image/png', 'png');
        $content = sprintf('<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s"/></figure><!-- /wp:image -->', $image, wp_get_attachment_url($image));
        $ids = $this->translated($content, $content);

        $result = MediaCheck::check($ids['fi']);
        $this->assertSame('unchecked', $result['items'][0]['state']);
        $this->assertSame(0, $result['open']);

        $file = get_attached_file($image);
        update_post_meta($image, MediaCheck::imageMetaKey, [
            'status' => 'text',
            'texts' => ['RAKENTAJAN OPAS'],
            'signature' => md5($file.'|'.filesize($file).'|'.filemtime($file)),
            'checkedAt' => time(),
        ]);

        $result = MediaCheck::check($ids['fi']);
        $this->assertSame('open', $result['items'][0]['state']);
        $this->assertSame(['RAKENTAJAN OPAS'], $result['items'][0]['texts']);
        $this->assertSame(1, $result['open']);
    }

    public function test_marking_an_item_fine_takes_it_out_of_the_count_and_the_filter(): void
    {
        $content = '<!-- wp:gravityforms/form {"formId":"3"} /-->';
        $ids = $this->translated($content, $content);
        MediaCheck::check($ids['fi']);

        $filtered = $this->rows(['filters' => ['fi' => ['media']]]);
        $this->assertSame([$ids['en']], array_column($filtered['rows'], 'sourceId'));
        $this->assertSame(1, $filtered['rows'][0]['languages']['fi']['media']);
        $this->assertSame([['name' => 'Finnish', 'slug' => 'fi', 'count' => 1]], $filtered['summary']['media']);

        $response = $this->request('POST', '/media/'.$ids['fi'], ['keys' => ['form:3'], 'ok' => true]);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $response->get_data()['open']);
        $this->assertSame('ok', $response->get_data()['items'][0]['state']);
        $this->assertSame([], $this->rows(['filters' => ['fi' => ['media']]])['rows']);

        $this->request('POST', '/media/'.$ids['fi'], ['keys' => ['form:3'], 'ok' => false]);
        $this->assertSame(1, MediaCheck::get($ids['fi'])['open']);
    }

    public function test_saving_the_original_checks_its_translations_again(): void
    {
        $ids = $this->translated('<!-- wp:gravityforms/form {"formId":"3"} /-->', '<!-- wp:gravityforms/form {"formId":"3"} /-->');
        MediaCheck::check($ids['fi']);

        wp_update_post(['ID' => $ids['en'], 'post_content' => '<!-- wp:paragraph --><p>No form</p><!-- /wp:paragraph -->']);
        MediaCheck::checkSaved();

        $this->assertSame(0, MediaCheck::get($ids['fi'])['open']);
    }

    public function test_the_media_route_needs_a_translation_and_the_right_to_edit_it(): void
    {
        $ids = $this->createTranslatedPost();

        $this->assertSame(404, $this->request('GET', '/media/'.$ids['en'])->get_status());
        $this->assertSame(200, $this->request('GET', '/media/'.$ids['fi'])->get_status());

        wp_set_current_user((int) self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, $this->request('GET', '/media/'.$ids['fi'])->get_status());
    }
}
