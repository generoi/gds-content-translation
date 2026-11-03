<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\DeeplApi;
use GeneroWP\ContentTranslation\DocumentTranslation;
use GeneroWP\ContentTranslation\FormTranslation;
use GeneroWP\ContentTranslation\MediaCheck;
use GeneroWP\ContentTranslation\Tests\RestTestCase;

/**
 * What a translation shares with its original, where it is shared from
 * several places, rechecked in the background, and images Claude could not
 * check. DeepL and Claude are faked.
 */
class MediaRobustnessTest extends RestTestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<array{url: string, body: mixed}> */
    private array $requests = [];

    /** @var int HTTP status Claude answers with. */
    private int $claudeStatus = 200;

    /** @var mixed */
    private $syncBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'test-key:fx');
        add_filter('gds_content_translation_audit_enabled', '__return_true');
        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
        $this->syncBefore = PLL()->options['sync'];
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        remove_filter('gds_content_translation_audit_enabled', '__return_true');
        PLL()->options['sync'] = $this->syncBefore;
        unset($_POST['gds_ct_lang']);

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
     * DeepL: text outside `translate="no"` spans, tags and entities in
     * capitals; a document is still being translated. Claude: no words, or
     * a failure.
     *
     * @param  false|array<string, mixed>  $response
     * @param  array<string, mixed>  $args
     */
    public function fakeHttp($response, array $args, string $url)
    {
        $json = static fn (array $body, int $code = 200): array => ['response' => ['code' => $code, 'message' => ''], 'body' => (string) wp_json_encode($body), 'headers' => [], 'cookies' => [], 'filename' => null];

        if (str_contains($url, 'api.anthropic.com')) {
            $this->requests[] = ['url' => $url, 'body' => $args['body'] ?? null];

            return $this->claudeStatus !== 200
                ? $json(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Bad image']], $this->claudeStatus)
                : $json(['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => '{"texts":[]}']]]);
        }

        if (! str_contains($url, 'deepl.com')) {
            return $response;
        }

        $this->requests[] = ['url' => $url, 'body' => $args['body'] ?? null];

        if (str_ends_with($url, '/v2/translate')) {
            $body = json_decode((string) $args['body'], true);
            $upper = static function (string $text): string {
                $parts = preg_split('#(<span translate="no"[^>]*>.*?</span>|<[^>]+>|&\w+;)#s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

                return implode('', array_map(static fn (string $part, int $index): string => $index % 2 === 0 ? mb_strtoupper($part) : $part, $parts, array_keys($parts)));
            };

            return $json(['translations' => array_map(static fn (string $text): array => ['text' => $upper($text)], $body['text'])]);
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
            return $json(['status' => 'translating', 'seconds_remaining' => 20]);
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

    private function imageBlock(int $attachmentId): string
    {
        return sprintf('<!-- wp:image {"id":%d} --><figure class="wp-block-image"><img src="%s" class="wp-image-%d"/></figure><!-- /wp:image -->', $attachmentId, wp_get_attachment_url($attachmentId), $attachmentId);
    }

    private function key(int $attachmentId): string
    {
        $file = (string) get_attached_file($attachmentId);
        $base = trailingslashit((string) wp_get_upload_dir()['basedir']);

        return 'file:'.(str_starts_with($file, $base) ? substr($file, strlen($base)) : wp_basename($file));
    }

    /**
     * A pattern in both languages, with this content.
     *
     * @return array<string, int>
     */
    private function patterns(string $content): array
    {
        $patterns = [
            'en' => $this->createPost('en', ['post_type' => 'wp_block', 'post_title' => 'Order', 'post_content' => $content]),
            'fi' => $this->createPost('fi', ['post_type' => 'wp_block', 'post_title' => 'Tilaus', 'post_content' => $content]),
        ];
        pll_save_post_translations($patterns);

        return $patterns;
    }

    private function ref(int $patternId): string
    {
        return sprintf('<!-- wp:block {"ref":%d} /-->', $patternId);
    }

    public function test_a_saved_pattern_checks_itself_now_and_the_pages_that_show_it_in_the_background(): void
    {
        if (! pll_is_translated_post_type('wp_block')) {
            $this->markTestSkipped('Patterns are not translated without Polylang Pro.');
        }

        $form = '<!-- wp:gravityforms/form {"formId":"3"} /-->';
        $patterns = $this->patterns('<!-- wp:paragraph --><p>No form</p><!-- /wp:paragraph -->');
        $pages = [];

        foreach (range(1, 3) as $index) {
            $ids = $this->createTranslatedPost(['post_content' => $this->ref($patterns['en'])]);
            wp_update_post(['ID' => $ids['fi'], 'post_content' => $this->ref($patterns['fi'])]);
            $pages[] = $ids['fi'];
        }

        MediaCheck::checkSaved();

        foreach ($pages as $pageId) {
            $this->assertSame([], MediaCheck::get($pageId)['items']);
        }

        wp_update_post(['ID' => $patterns['en'], 'post_content' => $form]);
        wp_update_post(['ID' => $patterns['fi'], 'post_content' => $form]);
        MediaCheck::checkSaved();

        // The pattern itself at once; the pages only once the sweep runs.
        $this->assertSame(['form:3'], array_column(MediaCheck::get($patterns['fi'])['items'], 'key'));
        $this->assertSame([], MediaCheck::get($pages[0])['items']);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_media_sweep'));

        MediaCheck::sweep();

        foreach ($pages as $pageId) {
            $this->assertSame(['form:3'], array_column(MediaCheck::get($pageId)['items'], 'key'));
        }

        $this->assertSame(['posts' => [], 'patterns' => []], get_option('gds_content_translation_media_recheck'));
    }

    public function test_a_changed_form_rechecks_its_pages_in_the_background_once(): void
    {
        $content = '<!-- wp:gravityforms/form {"formId":"3"} /-->';
        $ids = $this->createTranslatedPost(['post_content' => $content]);
        MediaCheck::checkSaved();
        $this->assertSame('open', MediaCheck::get($ids['fi'])['items'][0]['state']);

        update_option('gds_content_translation_form_3', ['strings' => ['fi' => ['Send' => 'Lähetä']], 'remarks' => ['fi' => ['Send' => ['issue' => 'Odd', 'suggestion' => 'Lähetä nyt']]]], false);
        $stores = 0;
        $count = static function () use (&$stores): void {
            $stores++;
        };
        add_action('update_option_gds_content_translation_form_3', $count);

        FormTranslation::settleRemark(3, 'fi', 'Send', true);

        remove_action('update_option_gds_content_translation_form_3', $count);
        $this->assertSame(1, $stores, 'A used remark is stored once.');
        $this->assertSame(['Send' => 'Lähetä nyt'], FormTranslation::translations(3, 'fi'));
        $this->assertSame([], get_option('gds_content_translation_form_3')['remarks']['fi']);
        $this->assertSame([$ids['fi']], get_option('gds_content_translation_media_recheck')['posts']);
    }

    public function test_an_image_claude_could_not_check_is_listed_with_why_and_asked_again_later(): void
    {
        $image = $this->attachment('poster.png', 'image/png', 'png');
        $large = $this->attachment('huge.png', 'image/png', str_repeat('x', MediaCheck::imageMaxBytes + 1));
        $content = $this->imageBlock($image).$this->imageBlock($large);
        $ids = $this->createTranslatedPost(['post_content' => $content]);

        $this->assertSame(['unchecked', 'unchecked'], array_column(MediaCheck::check($ids['fi'])['items'], 'state'));

        $this->claudeStatus = 400;
        MediaCheck::checkImages();

        $items = array_column(MediaCheck::get($ids['fi'])['items'], null, 'attachmentId');
        $this->assertSame('not-checked', $items[$image]['state']);
        $this->assertSame('failed', $items[$image]['notChecked']);
        $this->assertSame('not-checked', $items[$large]['state']);
        $this->assertSame('too-large', $items[$large]['notChecked']);
        $this->assertSame(0, MediaCheck::get($ids['fi'])['open']);
        $this->assertCount(1, $this->requests, 'Too large a file is not sent.');

        // The failed one is asked again once its wait is over, the other not.
        $retries = get_option('gds_content_translation_image_retry');
        $this->assertSame([$image], array_keys($retries));
        $this->assertGreaterThan(time(), $retries[$image]);
        MediaCheck::checkImages();
        $this->assertCount(1, $this->requests, 'Not before its time.');

        $found = get_post_meta($image, MediaCheck::imageMetaKey, true);
        update_post_meta($image, MediaCheck::imageMetaKey, ['checkedAt' => time() - HOUR_IN_SECONDS] + $found);
        update_option('gds_content_translation_image_retry', [$image => time() - 1], false);
        MediaCheck::checkImages();
        $this->assertCount(2, $this->requests);
        $this->assertSame(2, get_post_meta($image, MediaCheck::imageMetaKey, true)['attempts']);
        $this->assertGreaterThan(time() + HOUR_IN_SECONDS - 5, get_option('gds_content_translation_image_retry')[$image], 'It waits longer each time.');

        $this->claudeStatus = 200;
        update_option('gds_content_translation_image_retry', [$image => time() - 1], false);
        update_post_meta($image, MediaCheck::imageMetaKey, ['checkedAt' => time() - DAY_IN_SECONDS] + get_post_meta($image, MediaCheck::imageMetaKey, true));
        MediaCheck::checkImages();
        $this->assertSame([$large], array_column(MediaCheck::get($ids['fi'])['items'], 'attachmentId'), 'No words: no longer listed.');
    }

    public function test_an_image_is_checked_once_for_every_language_item_of_its_file(): void
    {
        $image = $this->attachment('cover.png', 'image/png', 'png');
        $copy = (int) self::factory()->attachment->create_object(get_attached_file($image), 0, ['post_mime_type' => 'image/png', 'post_title' => 'cover']);
        $translatedMedia = static function (bool $translated, string $type): bool {
            return $type === 'attachment' ? true : $translated;
        };
        add_filter('pll_is_translated_post_type', $translatedMedia, 10, 2);
        add_filter('pll_get_post_types', static fn (array $types): array => $types + ['attachment' => 'attachment']);
        PLL()->model->clean_languages_cache();

        if (! pll_is_translated_post_type('attachment')) {
            remove_filter('pll_is_translated_post_type', $translatedMedia, 10);
            $this->markTestSkipped('Media cannot be translated here.');
        }

        pll_set_post_language($image, 'en');
        pll_set_post_language($copy, 'fi');
        pll_save_post_translations(['en' => $image, 'fi' => $copy]);

        $this->assertSame($image, MediaCheck::imageOwner($copy));
        $file = get_attached_file($image);
        update_post_meta($image, MediaCheck::imageMetaKey, ['status' => 'text', 'texts' => ['OPAS'], 'signature' => md5($file.'|'.filesize($file).'|'.filemtime($file)), 'checkedAt' => time()]);

        $ids = $this->createTranslatedPost(['post_content' => $this->imageBlock($image)]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $this->imageBlock($copy)]);
        $item = MediaCheck::check($ids['fi'])['items'][0];

        remove_filter('pll_is_translated_post_type', $translatedMedia, 10);
        $this->assertSame('open', $item['state']);
        $this->assertSame(['OPAS'], $item['texts']);
        $this->assertSame([], (array) get_option('gds_content_translation_image_queue', []));
    }

    public function test_a_file_on_the_page_and_in_a_pattern_is_changed_in_both_but_not_in_the_originals_pattern(): void
    {
        if (! pll_is_translated_post_type('wp_block')) {
            $this->markTestSkipped('Patterns are not translated without Polylang Pro.');
        }

        $old = $this->attachment('guide.pdf', 'application/pdf');
        $new = $this->attachment('opas.pdf', 'application/pdf');
        $file = sprintf('<!-- wp:file {"id":%1$d,"href":"%2$s"} --><div class="wp-block-file"><a href="%2$s">Guide</a></div><!-- /wp:file -->', $old, wp_get_attachment_url($old));
        $patterns = $this->patterns($file);
        $ids = $this->createTranslatedPost(['post_content' => $file.$this->ref($patterns['en'])]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $file.$this->ref($patterns['fi'])]);

        $item = MediaCheck::check($ids['fi'])['items'][0];
        $this->assertSame([0, $patterns['fi']], $item['places']);
        $this->assertSame($patterns['fi'], $item['in']);
        $this->assertFalse($item['inOriginal']);

        $response = $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => $this->key($old), 'action' => 'replace', 'value' => $new]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertStringContainsString('"id":'.$new, get_post_field('post_content', $ids['fi']));
        $this->assertStringContainsString('"id":'.$new, get_post_field('post_content', $patterns['fi']));
        $this->assertStringContainsString('"id":'.$old, get_post_field('post_content', $patterns['en']));
        $this->assertSame([], MediaCheck::get($ids['fi'])['items']);

        // The page still shows the original's pattern: only the page changes.
        $other = $this->createTranslatedPost(['post_content' => $file.$this->ref($patterns['en'])]);
        $item = MediaCheck::check($other['fi'])['items'][0];
        $this->assertSame([0], $item['places']);
        $this->assertArrayNotHasKey('inOriginal', $item);
        $this->assertSame('Order', $item['inOriginalTitle']);

        $this->assertSame(200, $this->request('POST', '/media/'.$other['fi'].'/action', ['key' => $this->key($old), 'action' => 'replace', 'value' => $new])->get_status());
        $this->assertStringContainsString('"id":'.$new, get_post_field('post_content', $other['fi']));
        $this->assertStringContainsString('"id":'.$old, get_post_field('post_content', $patterns['en']));
    }

    public function test_only_the_blocks_that_show_the_file_get_its_new_id(): void
    {
        $old = $this->attachment('photo.png', 'image/png', 'png');
        $new = $this->attachment('kuva.png', 'image/png', 'png');
        $file = get_attached_file($old);
        update_post_meta($old, MediaCheck::imageMetaKey, ['status' => 'text', 'texts' => ['OPAS'], 'signature' => md5($file.'|'.filesize($file).'|'.filemtime($file)), 'checkedAt' => time()]);
        $other = sprintf('<!-- wp:paragraph --><p><span data-id="%1$d">Order %1$d</span></p><!-- /wp:paragraph --><!-- wp:html --><div class="wp-image-%1$d x">x</div><!-- /wp:html -->', $old);
        $content = $this->imageBlock($old).$other;
        $ids = $this->createTranslatedPost(['post_content' => $content]);
        MediaCheck::check($ids['fi']);

        $response = $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => $this->key($old), 'action' => 'replace', 'value' => $new]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $changed = get_post_field('post_content', $ids['fi']);
        $this->assertStringContainsString('"id":'.$new, $changed);
        $this->assertStringContainsString('class="wp-image-'.$new.'"', $changed);
        $this->assertStringContainsString($other, $changed, 'Other blocks keep their numbers.');
    }

    public function test_a_synced_featured_image_is_not_changed_for_the_original_too(): void
    {
        $old = $this->attachment('hero.png', 'image/png', 'png');
        $new = $this->attachment('hero-fi.png', 'image/png', 'png');
        $file = get_attached_file($old);
        update_post_meta($old, MediaCheck::imageMetaKey, ['status' => 'text', 'texts' => ['OPAS'], 'signature' => md5($file.'|'.filesize($file).'|'.filemtime($file)), 'checkedAt' => time()]);
        $ids = $this->createTranslatedPost(['post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->']);
        PLL()->options['sync'] = [];
        set_post_thumbnail($ids['en'], $old);
        set_post_thumbnail($ids['fi'], $old);
        MediaCheck::check($ids['fi']);

        PLL()->options['sync'] = ['_thumbnail_id'];
        $response = $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => $this->key($old), 'action' => 'replace', 'value' => $new]);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('gds_ct_thumbnail_synced', $response->get_data()['code']);
        $this->assertSame($old, (int) get_post_thumbnail_id($ids['fi']));
        $this->assertSame($old, (int) get_post_thumbnail_id($ids['en']));

        PLL()->options['sync'] = [];
        $this->assertSame(200, $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => $this->key($old), 'action' => 'replace', 'value' => $new])->get_status());
        $this->assertSame($new, (int) get_post_thumbnail_id($ids['fi']));
        $this->assertSame($old, (int) get_post_thumbnail_id($ids['en']));
    }

    public function test_a_forms_merge_tags_are_kept_as_they_are(): void
    {
        if (! DeeplApi::isAvailable()) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $texts = ['Hello {Name:1}, thanks & see {all_fields:noadmin}', '<p>Entry {entry_id} of <a href="{embed_url}">{form_title}</a></p>', 'Send'];
        $translated = FormTranslation::deeplTranslate($texts, PLL()->model->get_language('en'), PLL()->model->get_language('fi'));

        $this->assertSame(['HELLO {Name:1}, THANKS & SEE {all_fields:noadmin}', '<p>ENTRY {entry_id} OF <a href="{embed_url}">{form_title}</a></p>', 'SEND'], $translated);

        $sent = json_decode((string) end($this->requests)['body'], true);
        $this->assertSame('html', $sent['tag_handling']);
        $this->assertStringContainsString('<span translate="no" data-gds-ct-tag="0">{Name:1}</span>', $sent['text'][0]);
        $this->assertStringContainsString('&amp;', $sent['text'][0]);
    }

    public function test_a_submission_switches_to_its_languages_locale_and_back(): void
    {
        $switcher = $GLOBALS['wp_locale_switcher'];
        $available = new \ReflectionProperty($switcher, 'available_languages');
        $available->setAccessible(true);
        $before = $available->getValue($switcher);
        $available->setValue($switcher, array_merge($before, ['fi']));
        $locale = determine_locale();

        $_POST['gds_ct_lang'] = 'fi';
        $form = ['id' => 3];
        $this->assertSame($form, FormTranslation::switchLocale($form));
        $this->assertSame('fi', determine_locale());
        FormTranslation::switchLocale();
        $this->assertSame($form, FormTranslation::restoreLocale($form));
        $this->assertSame($locale, determine_locale());
        $this->assertFalse(is_locale_switched(), 'Switched once, restored once.');

        $_POST['gds_ct_lang'] = 'en';
        FormTranslation::switchLocale();
        $this->assertFalse(is_locale_switched());
        FormTranslation::restoreLocale();

        $available->setValue($switcher, $before);
    }

    public function test_a_document_being_translated_is_not_discarded(): void
    {
        if (! DeeplApi::isAvailable()) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        update_option(DocumentTranslation::optionKey, true);
        $pdf = $this->attachment('hinnasto.pdf', 'application/pdf', '%PDF original');
        $url = wp_get_attachment_url($pdf);
        $ids = $this->createTranslatedPost(['post_content' => sprintf('<!-- wp:file {"id":%1$d,"href":"%2$s"} --><div class="wp-block-file"><a href="%2$s">Guide</a></div><!-- /wp:file -->', $pdf, $url)]);
        MediaCheck::check($ids['fi']);

        $action = fn (string $action): \WP_REST_Response => $this->request('POST', '/media/'.$ids['fi'].'/action', ['key' => $this->key($pdf), 'action' => $action]);
        $this->assertSame(200, $action('document-translate')->get_status());

        $response = $action('document-discard');
        $this->assertSame(400, $response->get_status());
        $this->assertSame('gds_ct_document_running', $response->get_data()['code']);
        $this->assertSame('translating', DocumentTranslation::jobs($ids['fi'])[(string) $pdf]['status']);
    }
}
