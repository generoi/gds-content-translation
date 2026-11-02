<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\AuditReview;
use GeneroWP\ContentTranslation\DeeplGlossary;
use GeneroWP\ContentTranslation\Glossary;
use GeneroWP\ContentTranslation\GlossaryLearning;
use GeneroWP\ContentTranslation\Tests\AdminActionTestCase;
use GeneroWP\ContentTranslation\TranslationActions;
use GeneroWP\ContentTranslation\TranslationAudit;
use PLL_Translation_Walker_Factory;
use WP_REST_Request;

/**
 * The translation glossary (Glossary, DeeplGlossary, GlossaryLearning): the
 * CSV import and export, DeepL's copy and its use in translate requests, the
 * terms the AI review gets, and the suggestions from the review and from
 * proofread translations. The test languages are English (EN) and Finnish (FI).
 */
class GlossaryTest extends AdminActionTestCase
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    private array $deeplRequests = [];

    private bool $deeplRefuses = false;

    /** @var list<array<string, mixed>> */
    private array $claudeRequests = [];

    /** @var array<string, mixed> */
    private array $claudeResult = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $wpdb->query('DELETE FROM '.Glossary::table());
        delete_option(DeeplGlossary::optionKey);
        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        remove_all_filters('gds_content_translation_deepl_api_key');
        wp_clear_scheduled_hook('gds_content_translation_sync_glossary');

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return false|array<string, mixed>
     */
    public function fakeHttp($pre, array $args, string $url)
    {
        if (str_contains($url, 'deepl.com/v3/glossaries')) {
            $this->deeplRequests[] = ['method' => $args['method'], 'url' => $url, 'body' => json_decode((string) ($args['body'] ?? ''), true)];

            if ($this->deeplRefuses) {
                return $this->response(400, '{"message":"Bad request","detail":"Invalid entries"}');
            }
            $id = 'glossary-'.count($this->deeplRequests);

            return $this->response($args['method'] === 'DELETE' ? 204 : 201, $args['method'] === 'DELETE' ? '' : (string) wp_json_encode(['glossary_id' => $id]));
        }

        if (str_contains($url, 'api.anthropic.com')) {
            $this->claudeRequests[] = json_decode($args['body'], true);

            return $this->response(200, (string) wp_json_encode([
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                'content' => [['type' => 'text', 'text' => wp_json_encode($this->claudeResult)]],
            ]));
        }

        if (str_contains($url, 'admin-ajax.php') || str_contains($url, 'wp-cron.php')) {
            return $this->response(200, '');
        }

        return $pre;
    }

    /**
     * @return array<string, mixed>
     */
    private function response(int $code, string $body): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    private function csv(string $content): string
    {
        $path = (string) wp_tempnam('glossary.csv');
        file_put_contents($path, $content);

        return $path;
    }

    private function term(string $source, string $target, string $status = 'approved', string $origin = 'manual'): int
    {
        $id = Glossary::add(['targetLang' => 'FI', 'source' => $source, 'target' => $target, 'status' => $status, 'origin' => $origin]);
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * @return array<string, string> source => target of the approved terms
     */
    private function approved(string $targetLang = 'FI'): array
    {
        return Glossary::dictionaries()['EN-'.$targetLang] ?? [];
    }

    public function test_languages_are_codes_of_their_locales(): void
    {
        $this->assertSame('EN', Glossary::sourceCode());
        $this->assertSame('SV', Glossary::code('sv_SE'));
        $this->assertSame('EN', Glossary::code('EN-GB'));
        $this->assertSame([['code' => 'FI', 'name' => 'Finnish']], Glossary::targetLanguages());

        // A language with terms but none on the site is named after its code.
        Glossary::add(['targetLang' => 'SV', 'source' => 'wall brace', 'target' => 'väggstag']);
        $this->assertSame([['code' => 'FI', 'name' => 'Finnish'], ['code' => 'SV', 'name' => 'Swedish']], Glossary::targetLanguages());
    }

    public function test_it_imports_deepl_csv_and_merges_a_second_file(): void
    {
        $result = Glossary::import($this->csv("\xEF\xBB\xBF\"wall brace\",\"seinätuki\",\"EN\",\"FI\"\n\"Stedox\",\"Stedox\",\"EN\",\"FI\"\n\"brace\",\"tuki\",\"EN\",\"SV\"\n\"no translation\",\"\",\"EN\",\"FI\"\n"));

        $this->assertSame(['added' => 3, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1, 'pairs' => ['EN-FI', 'EN-SV']], $result);
        $this->assertSame(['Stedox' => 'Stedox', 'wall brace' => 'seinätuki'], $this->approved());

        // The same term again is unchanged; a new translation replaces the old.
        $result = Glossary::import($this->csv("\"Stedox\",\"Stedox\",\"EN\",\"FI\"\n\"wall brace\",\"seinäpönkkä\",\"EN\",\"FI\"\n"));

        $this->assertSame(['added' => 0, 'updated' => 1, 'unchanged' => 1, 'skipped' => 0, 'pairs' => ['EN-FI']], $result);
        $this->assertSame(['Stedox' => 'Stedox', 'wall brace' => 'seinäpönkkä'], $this->approved());
        $this->assertSame(['brace' => 'tuki'], $this->approved('SV'));
    }

    public function test_replace_removes_the_terms_of_the_languages_in_the_file_only(): void
    {
        $this->term('old term', 'vanha');
        $rejected = $this->term('brace', 'stöd', 'rejected');
        Glossary::import($this->csv("\"brace\",\"stag\",\"EN\",\"SV\"\n"));

        // A two-column file takes the language chosen.
        $result = Glossary::import($this->csv("source,target\nwall brace,seinätuki\n"), 'replace', 'fi');

        $this->assertSame(1, $result['added']);
        $this->assertSame(['wall brace' => 'seinätuki'], $this->approved());
        $this->assertSame(['brace' => 'stag'], $this->approved('SV'));
        $this->assertSame('rejected', Glossary::find($rejected)['status']);
    }

    public function test_the_export_is_deepl_csv_of_the_approved_terms(): void
    {
        $this->term('wall brace', 'seinätuki');
        $this->term('quote, "free"', 'tarjous');
        $this->term('suggested', 'ehdotettu', 'suggested');

        $csv = Glossary::export();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame("\"quote, \"\"free\"\"\",tarjous,EN,FI\n\"wall brace\",seinätuki,EN,FI\n", substr($csv, 3));

        // What it exports, it imports.
        $result = Glossary::import($this->csv($csv), 'replace');
        $this->assertSame(['added' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'pairs' => ['EN-FI']], $result);
    }

    public function test_terms_need_both_texts_and_are_unique(): void
    {
        $this->term('wall brace', 'seinätuki');

        $this->assertWPError(Glossary::add(['targetLang' => 'FI', 'source' => 'wall brace', 'target' => 'seinätuki']));
        $this->assertWPError(Glossary::add(['targetLang' => 'FI', 'source' => 'wall brace', 'target' => ' ']));
        $this->assertWPError(Glossary::add(['targetLang' => 'EN', 'source' => 'a', 'target' => 'b']));
        $this->assertIsInt(Glossary::add(['targetLang' => 'FI', 'source' => "wall\tbrace ", 'target' => 'seinäpönkkä']));
    }

    public function test_a_second_translation_is_a_conflict_until_one_is_chosen(): void
    {
        $first = $this->term('brace', 'tuki');
        $second = $this->term('brace', 'pönkkä');
        $suggestion = $this->term('brace', 'jäykiste', 'suggested', 'review');

        $conflicts = Glossary::query(['conflicts' => true]);
        $this->assertSame(2, $conflicts['total']);
        $this->assertTrue($conflicts['items'][0]['conflict']);
        $this->assertNotSame('', Glossary::getItem($suggestion)['replaces']);

        // Accepting the suggestion retires both.
        $this->assertSame(1, Glossary::bulk([$suggestion], 'approve'));
        $this->assertSame('rejected', Glossary::find($first)['status']);
        $this->assertSame('rejected', Glossary::find($second)['status']);
        $this->assertSame(['brace' => 'jäykiste'], $this->approved());
        $this->assertSame(0, Glossary::query(['conflicts' => true])['total']);
    }

    public function test_the_query_searches_filters_and_counts(): void
    {
        $this->term('wall brace', 'seinätuki');
        $this->term('beam puller', 'palkinvetäjä');
        $this->term('cart', 'ostoskori', 'suggested', 'proofread');

        $this->assertSame(['wall brace'], array_column(Glossary::query(['search' => 'seinä'])['items'], 'source'));
        $this->assertSame(['cart'], array_column(Glossary::query(['status' => ['suggested'], 'origin' => ['proofread']])['items'], 'source'));
        $this->assertSame(['wall brace', 'cart', 'beam puller'], array_column(Glossary::query(['orderby' => 'source', 'order' => 'desc'])['items'], 'source'));
        $this->assertSame(['approved' => 2, 'draft' => 0, 'suggested' => 1, 'rejected' => 0], Glossary::counts());
        $this->assertSame([['targetLang' => 'FI', 'approved' => 2]], Glossary::pairs());
    }

    public function test_the_terms_of_a_text_are_whole_words_in_any_case_longest_first(): void
    {
        $this->term('brace', 'tuki');
        $this->term('wall brace', 'seinätuki');
        $this->term('wall', 'seinä');
        $this->term('embrace', 'syleillä');
        $this->term('cart', 'ostoskori', 'suggested');

        $terms = Glossary::termsIn('<p>Two <strong>Wall Braces</strong> or one wall brace in the cart.</p>', 'EN', 'FI');

        $this->assertSame(['wall brace', 'brace', 'wall'], array_column($terms, 'source'));
        $this->assertSame([], Glossary::termsIn('Wall brace', 'EN', 'SV'));
    }

    public function test_changes_are_sent_to_deepl_as_one_glossary_and_the_old_one_deleted(): void
    {
        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'key:fx');
        $this->term('wall brace', 'seinätuki');
        $this->term('Stedox', 'Stedox');

        $this->assertTrue(DeeplGlossary::status()['pending']);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_sync_glossary'));

        $this->assertTrue(DeeplGlossary::sync());

        $this->assertSame('POST', $this->deeplRequests[0]['method']);
        $this->assertSame('https://api-free.deepl.com/v3/glossaries', $this->deeplRequests[0]['url']);
        $this->assertSame([[
            'source_lang' => 'EN',
            'target_lang' => 'FI',
            'entries' => "Stedox\tStedox\nwall brace\tseinätuki",
            'entries_format' => 'tsv',
        ]], $this->deeplRequests[0]['body']['dictionaries']);

        $status = DeeplGlossary::status();
        $this->assertSame('glossary-1', $status['glossaryId']);
        $this->assertFalse($status['pending']);
        $this->assertSame([['sourceLang' => 'EN', 'targetLang' => 'FI', 'entries' => 2]], $status['dictionaries']);

        // Nothing changed: nothing sent.
        DeeplGlossary::markPending();
        DeeplGlossary::syncIfPending();
        $this->assertCount(1, $this->deeplRequests);

        $this->term('cart', 'ostoskori');
        DeeplGlossary::sync();
        $this->assertSame(['POST', 'DELETE'], array_column(array_slice($this->deeplRequests, 1), 'method'));
        $this->assertStringEndsWith('/v3/glossaries/glossary-1', $this->deeplRequests[2]['url']);
        $this->assertSame('glossary-2', DeeplGlossary::status()['glossaryId']);
    }

    public function test_a_refused_glossary_keeps_the_old_one_and_says_why(): void
    {
        add_filter('gds_content_translation_deepl_api_key', static fn (): string => 'key');
        update_option(DeeplGlossary::optionKey, ['glossaryId' => 'old', 'dictionaries' => [['sourceLang' => 'EN', 'targetLang' => 'FI', 'entries' => 1]]]);
        $this->term('wall brace', 'seinätuki');

        $this->deeplRefuses = true;
        $result = DeeplGlossary::sync();

        $this->assertWPError($result);
        $status = DeeplGlossary::status();
        $this->assertSame('old', $status['glossaryId']);
        $this->assertTrue($status['pending']);
        $this->assertStringContainsString('Invalid entries', $status['error']);
    }

    public function test_deepl_translate_requests_get_the_glossary_of_their_language_pair(): void
    {
        update_option(DeeplGlossary::optionKey, ['glossaryId' => 'g1', 'dictionaries' => [['sourceLang' => 'EN', 'targetLang' => 'FI', 'entries' => 1], ['sourceLang' => 'FI', 'targetLang' => 'EN', 'entries' => 1]]]);
        $request = static fn (array $body, string $url = 'https://api.deepl.com/v2/translate'): array => json_decode(DeeplGlossary::addToRequest(['body' => wp_json_encode($body)], $url)['body'], true);

        $this->assertSame('g1', $request(['source_lang' => 'EN', 'target_lang' => 'FI', 'text' => ['x']])['glossary_id']);
        $this->assertSame('g1', $request(['source_lang' => 'FI', 'target_lang' => 'EN-GB', 'text' => ['x']], 'https://api-free.deepl.com/v2/translate')['glossary_id']);
        $this->assertArrayNotHasKey('glossary_id', $request(['source_lang' => 'EN', 'target_lang' => 'SV', 'text' => ['x']]));
        // DeepL takes a glossary only with the source language named.
        $this->assertArrayNotHasKey('glossary_id', $request(['target_lang' => 'FI', 'text' => ['x']]));
        $this->assertArrayNotHasKey('glossary_id', $request(['source_lang' => 'EN', 'target_lang' => 'FI'], 'https://api.deepl.com/v2/usage'));
    }

    public function test_the_review_gets_the_terms_of_the_page_and_suggests_new_ones(): void
    {
        if (! class_exists(PLL_Translation_Walker_Factory::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $this->term('wall brace', 'seinätuki');
        $this->term('beam puller', 'palkinvetäjä');
        $ids = $this->createTranslatedPost();
        wp_update_post(['ID' => $ids['en'], 'post_content' => "<!-- wp:paragraph -->\n<p>Fix the wall brace with a beam clamp.</p>\n<!-- /wp:paragraph -->"]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => "<!-- wp:paragraph -->\n<p>Kiinnitä seinäpönkkä palkkikiinnikkeellä.</p>\n<!-- /wp:paragraph -->"]);
        $this->claudeResult = ['summary' => 'Two terms.', 'remarks' => [
            ['segment' => 1, 'severity' => 'error', 'category' => 'terminology', 'issue' => 'Glossary.', 'suggestion' => 'Kiinnitä seinätuki palkkikiinnikkeellä.', 'term' => ['source' => '', 'target' => '']],
            ['segment' => 1, 'severity' => 'warning', 'category' => 'terminology', 'issue' => 'Beam clamp.', 'suggestion' => 'Kiinnitä seinäpönkkä palkkipuristimella.', 'term' => ['source' => 'beam clamp', 'target' => 'palkkipuristin']],
        ]];

        TranslationAudit::queue($ids['fi']);
        (new TranslationAudit)->runQueued($ids['fi']);

        $payload = json_decode($this->claudeRequests[0]['messages'][0]['content'], true);
        $this->assertSame([['source' => 'wall brace', 'target' => 'seinätuki']], $payload['glossary']);
        $this->assertStringContainsString('glossary', $this->claudeRequests[0]['system']);

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame(1, $review['glossary']);
        $this->assertArrayNotHasKey('term', $review['remarks'][0]);
        $this->assertSame(['source' => 'beam clamp', 'target' => 'palkkipuristin'], $review['remarks'][1]['term']);

        $suggestions = Glossary::query(['status' => ['suggested']])['items'];
        $this->assertSame([['beam clamp', 'palkkipuristin', 'review', 1, 'Fix the wall brace with a beam clamp.', $ids['fi']]], array_map(
            static fn (array $item): array => [$item['source'], $item['target'], $item['origin'], $item['hits'], $item['example'], $item['postId']],
            $suggestions
        ));

        // The same page again does not count; an editor using the suggestion does.
        GlossaryLearning::fromReview($ids['fi'], $review);
        $this->assertSame(1, Glossary::find($suggestions[0]['id'])['hits'] + 0);
        $this->assertTrue(AuditReview::changeRemark($ids['fi'], 2, 'use', ''));
        $this->assertSame(2, (int) Glossary::find($suggestions[0]['id'])['hits']);
    }

    public function test_a_proofread_translation_suggests_the_terms_an_editor_changed(): void
    {
        if (! class_exists(PLL_Translation_Walker_Factory::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $this->term('cart', 'ostoskori');
        $ids = $this->createTranslatedPost();
        wp_update_post(['ID' => $ids['en'], 'post_content' => "<!-- wp:paragraph -->\n<p>Every wall brace fits.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Add it to the cart.</p>\n<!-- /wp:paragraph -->"]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => "<!-- wp:paragraph -->\n<p>Jokainen seinätuki sopii.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Lisää se ostoskoriin.</p>\n<!-- /wp:paragraph -->"]);
        GlossaryLearning::rememberMachineText($ids['fi']);

        // Nothing changed: nothing to learn.
        $this->assertSame([], GlossaryLearning::changes($ids['fi']));

        wp_update_post(['ID' => $ids['fi'], 'post_content' => "<!-- wp:paragraph -->\n<p>Jokainen seinäpönkkä sopii.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Lisää se ostoskoriin.</p>\n<!-- /wp:paragraph -->"]);
        $this->assertSame([['source' => 'Every wall brace fits.', 'machine' => 'Jokainen seinätuki sopii.', 'edited' => 'Jokainen seinäpönkkä sopii.']], GlossaryLearning::changes($ids['fi']));

        TranslationActions::setProofread($ids['fi'], true);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_learn_glossary', [$ids['fi']]));

        $this->claudeResult = ['terms' => [['source' => 'wall brace', 'target' => 'seinäpönkkä'], ['source' => 'a whole sentence that is far too long to be a term', 'target' => 'x']]];
        $this->assertSame(1, GlossaryLearning::learn($ids['fi']));

        $payload = json_decode($this->claudeRequests[0]['messages'][0]['content'], true);
        $this->assertSame('Jokainen seinäpönkkä sopii.', $payload['changes'][0]['edited']);
        $this->assertSame([['wall brace', 'seinäpönkkä', 'proofread', 'Every wall brace fits.']], array_map(
            static fn (array $item): array => [$item['source'], $item['target'], $item['origin'], $item['example']],
            Glossary::query(['status' => ['suggested']])['items']
        ));

        // Once per translation.
        $this->assertSame(0, GlossaryLearning::learn($ids['fi']));
        $this->assertCount(1, $this->claudeRequests);
        wp_clear_scheduled_hook('gds_content_translation_learn_glossary', [$ids['fi']]);
    }

    public function test_only_changes_to_approved_terms_go_to_deepl(): void
    {
        $suggestion = $this->term('cart', 'kärry', 'suggested');
        Glossary::update($suggestion, ['note' => 'A note.']);
        $this->assertFalse(DeeplGlossary::status()['pending']);

        Glossary::bulk([$suggestion], 'approve');
        $this->assertTrue(DeeplGlossary::status()['pending']);
        $this->assertMatchesRegularExpression('/\\+00:00$/', Glossary::getItem($suggestion)['updatedAt']);
    }

    public function test_a_rejected_suggestion_is_not_suggested_again(): void
    {
        $id = Glossary::suggest('EN', 'FI', 'cart', 'kärry', 'review', '', 10);
        Glossary::bulk([$id], 'reject');

        $this->assertSame($id, Glossary::suggest('EN', 'FI', 'cart', 'kärry', 'review', '', 11));
        $this->assertSame('rejected', Glossary::find($id)['status']);
        $this->assertNull(Glossary::suggest('EN', 'FI', 'a b c d e f g', 'x', 'review'));
    }

    public function test_the_rest_api_lists_adds_changes_and_bulk_edits_terms(): void
    {
        $server = rest_get_server();
        do_action('rest_api_init', $server);

        $create = new WP_REST_Request('POST', '/gds-content-translation/v1/glossary');
        $create->set_body_params(['targetLang' => 'FI', 'source' => 'wall brace', 'target' => 'seinätuki', 'note' => 'Not "tuki".']);
        $created = $server->dispatch($create)->get_data();
        $this->assertSame(['EN', 'FI', 'wall brace', 'seinätuki', 'approved', 'manual', 'Not "tuki".'], [$created['sourceLang'], $created['targetLang'], $created['source'], $created['target'], $created['status'], $created['origin'], $created['note']]);

        $duplicate = $server->dispatch($create);
        $this->assertSame(400, $duplicate->get_status());

        $update = new WP_REST_Request('POST', '/gds-content-translation/v1/glossary/'.$created['id']);
        $update->set_body_params(['target' => 'seinäpönkkä', 'status' => 'draft']);
        $this->assertSame(['seinäpönkkä', 'draft'], array_values(array_intersect_key($server->dispatch($update)->get_data(), ['target' => 1, 'status' => 1])));

        $list = new WP_REST_Request('GET', '/gds-content-translation/v1/glossary');
        $list->set_query_params(['status' => ['draft'], 'target_lang' => 'FI']);
        $data = $server->dispatch($list)->get_data();
        $this->assertSame(1, $data['total']);
        $this->assertSame(['approved' => 0, 'draft' => 1, 'suggested' => 0, 'rejected' => 0], $data['counts']);
        $this->assertArrayHasKey('configured', $data['sync']);

        $bulk = new WP_REST_Request('POST', '/gds-content-translation/v1/glossary/bulk');
        $bulk->set_body_params(['ids' => [$created['id']], 'operation' => 'delete']);
        $this->assertSame(1, $server->dispatch($bulk)->get_data()['changed']);

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, $server->dispatch($list)->get_status());
    }
}
