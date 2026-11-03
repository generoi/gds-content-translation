<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\DeeplGlossary;
use GeneroWP\ContentTranslation\StringTranslation;
use GeneroWP\ContentTranslation\Tests\RestTestCase;
use PLL_MO;
use PLL_Translate_Slugs;
use PLL_Translate_Slugs_Model;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services\Deepl;

/**
 * Polylang → String translations: Polylang's strings and URL slugs, missing
 * ones translated by DeepL (faked), edited and checked by the AI review
 * (faked), stored where Polylang's own Strings translations screen keeps them.
 */
class StringTranslationTest extends RestTestCase
{
    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $deeplRequests = [];

    /** @var list<array<string, mixed>> */
    private array $claudeRequests = [];

    /** @var array<string, mixed> */
    private array $claudeAnswer = ['remarks' => []];

    /** @var mixed */
    private $translateSlugs = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Deepl::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'test-key:fx');
        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);

        pll_register_string('Read more', 'Read more', 'Test');
        pll_register_string('Tagline', 'Tools for the %%sitename%% team, %s of them', 'Test');
        pll_register_string('date_format', 'F j, Y', 'WordPress');
        pll_register_string('Facebook', 'https://www.facebook.com/example/', 'Test');
        // A theme's text, as Theme Translation for Polylang registers it.
        pll_register_string('Share', 'Share', 'TTfP: theme');

        $this->translateSlugs = PLL()->translate_slugs ?? null;
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        putenv('ANTHROPIC_API_KEY');
        PLL()->translate_slugs = $this->translateSlugs;

        foreach (PLL()->model->get_languages_list() as $language) {
            delete_term_meta($language->term_id, '_pll_strings_translations');
            (new PLL_MO)->export_to_db($language);
        }

        delete_transient('pll_translated_slugs');

        parent::tearDown();
    }

    /**
     * DeepL translates into capitals, except some slugs; Claude answers with
     * the test's remarks.
     *
     * @param  false|array<string, mixed>  $response
     * @param  array<string, mixed>  $args
     */
    public function fakeHttp($response, array $args, string $url)
    {
        $json = static fn (array $body): array => ['response' => ['code' => 200, 'message' => 'OK'], 'body' => (string) wp_json_encode($body), 'headers' => [], 'cookies' => [], 'filename' => null];

        if (str_contains($url, 'api.anthropic.com')) {
            $this->claudeRequests[] = json_decode((string) $args['body'], true);

            return $json(['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1], 'content' => [['type' => 'text', 'text' => wp_json_encode($this->claudeAnswer)]]]);
        }

        if (! str_contains($url, 'deepl.com/v2/translate')) {
            return $response;
        }

        $body = json_decode((string) $args['body'], true);
        $this->deeplRequests[] = ['url' => $url, 'body' => $body];
        $slugs = ['tuotteet' => 'Our Products!', 'category' => 'Kategoria Ä'];

        // What is marked untranslatable comes back as it was.
        $upper = static fn (string $text): string => implode('', array_map(
            static fn (string $part): string => str_starts_with($part, '<span translate="no">') ? $part : mb_strtoupper($part),
            (array) preg_split('#(<span translate="no">.*?</span>)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE)
        ));

        return $json(['translations' => array_map(static fn (string $text): array => ['text' => $slugs[$text] ?? $upper($text)], $body['text'])]);
    }

    private function translation(string $source, string $slug): string
    {
        $mo = new PLL_MO;
        $mo->import_from_db(PLL()->model->get_language($slug));

        return $mo->translate_if_any($source);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function row(array $data, string $source): array
    {
        $rows = array_column($data['rows'], null, 'source');
        $this->assertArrayHasKey($source, $rows);

        return $rows[$source];
    }

    public function test_the_strings_are_listed_with_what_each_language_is_missing(): void
    {
        $response = $this->request('GET', '/strings/fi');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();

        $this->assertSame('', $this->row($data, 'Read more')['translation']);
        $this->assertTrue($this->row($data, 'F j, Y')['fixed'], 'A date format is kept as it is.');
        $this->assertTrue($this->row($data, 'https://www.facebook.com/example/')['fixed'], 'An address is not translated.');
        $this->assertSame('Theme or plugin: theme', $this->row($data, 'Share')['group']);

        // The default language translates only a theme's texts: the others are written in it.
        $default = $this->request('GET', '/strings/en')->get_data();
        $this->assertSame(['Share'], array_values(array_intersect(array_column($default['rows'], 'source'), ['Read more', 'Share', 'F j, Y'])));
        $this->assertTrue($default['language']['isDefault']);

        $this->assertSame(count(array_filter($data['rows'], static fn (array $row): bool => $row['translation'] === '' && ! $row['fixed'])), $data['missing']['fi']['texts']);
        $this->assertGreaterThanOrEqual(3, $data['missing']['fi']['texts']);
        $this->assertGreaterThanOrEqual(1, $data['missing']['en']['texts']);

        $this->assertSame(404, $this->request('GET', '/strings/xx')->get_status());
    }

    public function test_translations_saved_on_polylangs_own_screen_show_and_stay(): void
    {
        // As Polylang's Strings translations screen saves them, an unregistered one too.
        $language = PLL()->model->get_language('fi');
        $mo = new PLL_MO;
        $mo->import_from_db($language);
        $mo->add_entry($mo->make_entry('Read more', 'Lue lisää'));
        $mo->add_entry($mo->make_entry('An old theme text', 'Vanha teksti'));
        $mo->export_to_db($language);

        $data = $this->request('GET', '/strings/fi')->get_data();
        $this->assertSame('Lue lisää', $this->row($data, 'Read more')['translation']);

        $id = $this->row($data, 'Share')['id'];
        $response = $this->request('POST', '/strings/fi', ['translations' => [$id => 'Jaa', md5('Read more') => '']]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(2, $response->get_data()['changed']);

        $this->assertSame('Jaa', $this->translation('Share', 'fi'));
        $this->assertSame('', $this->translation('Read more', 'fi'), 'An emptied translation is removed.');
        $this->assertSame('Vanha teksti', $this->translation('An old theme text', 'fi'), 'Strings not registered now are kept.');
        $this->assertSame('Jaa', pll_translate_string('Share', 'fi'));
    }

    public function test_saving_goes_through_polylangs_sanitizing_and_action(): void
    {
        $seen = [];
        $filter = static function (string $translation, string $name, string $context, string $original) use (&$seen): string {
            $seen[] = [$name, $context, $original];

            return str_replace('<script>', '', $translation);
        };
        add_filter('pll_sanitize_string_translation', $filter, 10, 4);
        $saved = did_action('pll_save_strings_translations');

        StringTranslation::save('fi', [md5('Read more') => '<script>Lue lisää']);

        remove_filter('pll_sanitize_string_translation', $filter, 10);
        $this->assertContains(['Read more', 'Test', 'Read more'], $seen);
        $this->assertSame('Lue lisää', $this->translation('Read more', 'fi'));
        $this->assertSame($saved + 1, did_action('pll_save_strings_translations'));

        // Nothing changed, nothing saved.
        StringTranslation::save('fi', [md5('Read more') => 'Lue lisää']);
        $this->assertSame($saved + 1, did_action('pll_save_strings_translations'));
    }

    public function test_deepl_translates_the_missing_texts_with_the_glossary_and_keeps_placeholders(): void
    {
        update_option(DeeplGlossary::optionKey, ['glossaryId' => 'g-1', 'dictionaries' => [['sourceLang' => 'EN', 'targetLang' => 'FI', 'entries' => 3]]]);
        StringTranslation::save('fi', [md5('Read more') => 'Lue lisää']);

        $response = $this->request('POST', '/strings/fi/translate');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $this->assertSame('Lue lisää', $this->translation('Read more', 'fi'), 'A translation is not replaced.');
        $this->assertSame('TOOLS FOR THE %%sitename%% TEAM, %s OF THEM', $this->translation('Tools for the %%sitename%% team, %s of them', 'fi'));
        $this->assertSame('SHARE', $this->translation('Share', 'fi'));
        $this->assertSame('', $this->translation('F j, Y', 'fi'), 'A date format is not sent to DeepL.');
        $this->assertSame('', $this->translation('https://www.facebook.com/example/', 'fi'));

        $sent = array_merge(...array_map(static fn (array $request): array => $request['body']['text'], $this->deeplRequests));
        $this->assertContains('Tools for the <span translate="no">%%sitename%%</span> team, <span translate="no">%s</span> of them', $sent);
        $this->assertNotContains('Read more', $sent);

        foreach ($this->deeplRequests as $request) {
            // The theme's text without a translation in the default language: DeepL tells its language.
            if (in_array('Share', $request['body']['text'], true)) {
                $this->assertArrayNotHasKey('source_lang', $request['body']);
                $this->assertArrayNotHasKey('glossary_id', $request['body']);
            } else {
                $this->assertSame('EN', $request['body']['source_lang']);
                $this->assertSame('g-1', $request['body']['glossary_id'], 'The glossary is added to the request.');
            }
        }

        delete_option(DeeplGlossary::optionKey);
    }

    public function test_a_theme_text_is_translated_from_its_default_language_translation(): void
    {
        StringTranslation::save('en', [md5('Share') => 'Share this']);

        StringTranslation::translateMissing('fi', [md5('Share')]);

        $this->assertSame('SHARE THIS', $this->translation('Share', 'fi'));
        $this->assertSame('EN', $this->deeplRequests[0]['body']['source_lang']);
        $this->assertCount(1, $this->deeplRequests[0]['body']['text'], 'Only the texts asked for.');
    }

    public function test_the_ai_review_checks_the_translations_and_a_remark_is_used(): void
    {
        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        StringTranslation::save('fi', [md5('Read more') => 'Lue enemmän', md5('Share') => 'Jaa']);
        $this->claudeAnswer = ['remarks' => [['id' => 0, 'issue' => 'Unnatural.', 'suggestion' => 'Lue lisää']]];

        $response = $this->request('POST', '/strings/fi/review');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame(1, $response->get_data()['changed']);

        $question = json_decode($this->claudeRequests[0]['messages'][0]['content'], true);
        $this->assertSame(['Read more', 'Share'], array_column($question['texts'], 'source'));
        $this->assertSame(['issue' => 'Unnatural.', 'suggestion' => 'Lue lisää'], $this->row($response->get_data(), 'Read more')['remark']);

        $used = $this->request('POST', '/strings/fi/remark', ['id' => md5('Read more'), 'use' => true])->get_data();
        $this->assertSame('Lue lisää', $this->translation('Read more', 'fi'));
        $this->assertNull($this->row($used, 'Read more')['remark']);
    }

    private function enableSlugs(): void
    {
        register_post_type('tuote', ['public' => true, 'rewrite' => ['slug' => 'tuotteet'], 'label' => 'Products']);
        add_filter('pll_get_post_types', static fn (array $types): array => $types + ['tuote' => 'tuote']);
        PLL()->model->clean_languages_cache();
        delete_transient('pll_translated_slugs');

        $polylang = PLL();
        $model = new PLL_Translate_Slugs_Model($polylang);
        $curlang = null;
        PLL()->translate_slugs = new PLL_Translate_Slugs($model, $curlang);
        PLL()->translate_slugs->slugs_model->init_translated_slugs();
    }

    public function test_url_slugs_get_url_safe_suggestions_and_saving_flushes_the_rewrite_rules(): void
    {
        if (! class_exists(PLL_Translate_Slugs_Model::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $this->enableSlugs();
        $data = $this->request('GET', '/strings/fi')->get_data();
        $slugs = array_column($data['slugs'], null, 'id');

        $this->assertTrue($data['slugsAvailable']);
        $this->assertSame('tuotteet', $slugs['tuote']['source']);
        $this->assertSame('Products (post type)', $slugs['tuote']['what']);
        $this->assertGreaterThanOrEqual(1, $data['missing']['fi']['slugs']);
        $this->assertSame(0, $data['missing']['en']['slugs'], 'The default language has the originals.');

        $suggested = $this->request('POST', '/strings/fi/slugs', ['keys' => ['tuote', 'category']])->get_data()['suggestions'];
        $this->assertSame('our-products', $suggested->tuote);
        $this->assertSame('kategoria-a', $suggested->category);
        $this->assertSame('', $this->translation('tuotteet', 'fi'), 'Suggestions are not saved.');

        $flushed = did_action('pll_save_strings_translations');
        $response = $this->request('POST', '/strings/fi', ['slugs' => ['tuote' => 'Tuotteet ja palvelut/']]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        $this->assertSame('tuotteet-ja-palvelut', $this->translation('tuotteet', 'fi'));
        $this->assertSame($flushed + 1, did_action('pll_save_strings_translations'));
        // Polylang Pro flushed: the slugs it routes with are read again.
        $this->assertSame('tuotteet-ja-palvelut', PLL()->translate_slugs->slugs_model->translated_slugs['tuote']['translations']['fi'] ?? null);
    }

    public function test_the_screen_needs_the_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertSame(403, $this->request('GET', '/strings/fi')->get_status());
        $this->assertSame(403, $this->request('POST', '/strings/fi/translate')->get_status());
    }
}
