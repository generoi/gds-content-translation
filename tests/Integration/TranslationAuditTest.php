<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\AuditReview;
use GeneroWP\ContentTranslation\Polylang\BlockRules;
use GeneroWP\ContentTranslation\Tests\AdminActionTestCase;
use GeneroWP\ContentTranslation\Tests\RedirectException;
use GeneroWP\ContentTranslation\TextDiff;
use GeneroWP\ContentTranslation\TranslationAudit;
use PLL_Translation_Walker_Factory;
use WP_Error;
use WPDieException;

/**
 * The AI review of a translation (TranslationAudit, AuditReview): which texts
 * Claude gets, the request, the background run, and the remark actions.
 */
class TranslationAuditTest extends AdminActionTestCase
{
    /** @var list<array<string, mixed>> */
    private array $claudeRequests = [];

    /** @var list<array<string, mixed>> */
    private array $backgroundRequests = [];

    /** @var array<string, mixed>|WP_Error */
    private array|WP_Error $claudeResponse = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(PLL_Translation_Walker_Factory::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        $_POST = [];

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return false|array<string, mixed>|WP_Error
     */
    public function fakeHttp($pre, array $args, string $url)
    {
        if (str_contains($url, 'admin-ajax.php')) {
            $this->backgroundRequests[] = $args;

            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }

        if (! str_contains($url, 'api.anthropic.com')) {
            return $pre;
        }

        $this->claudeRequests[] = ['headers' => $args['headers'], 'body' => json_decode($args['body'], true)];

        return $this->claudeResponse;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function claudeAnswers(array $result, string $stopReason = 'end_turn', int $code = 200): array
    {
        return [
            'headers' => [],
            'body' => wp_json_encode([
                'stop_reason' => $stopReason,
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                'content' => [
                    ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                    ['type' => 'text', 'text' => wp_json_encode($result)],
                ],
            ]),
            'response' => ['code' => $code, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }

    private function blocks(string ...$paragraphs): string
    {
        return implode("\n\n", array_map(
            static fn (string $text): string => "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->",
            $paragraphs
        ));
    }

    /**
     * An English original and its Finnish translation.
     *
     * @return array<string, int>
     */
    private function translation(): array
    {
        $ids = $this->createTranslatedPost(['post_title' => 'Page title']);
        wp_update_post(['ID' => $ids['en'], 'post_content' => $this->blocks('Hello <strong>world</strong>', 'Read more', 'Read more')]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $this->blocks('Hei <strong>maailma</strong>', 'Lue lisää', 'Lue lisaa')]);

        return $ids;
    }

    public function test_it_pairs_texts_block_by_block_with_repeats(): void
    {
        $paired = TranslationAudit::pairSegments(
            $this->blocks('One', 'Two', 'Two'),
            $this->blocks('Yksi', 'Kaksi', 'Kaksi')
        );

        // Where each text is: top-level block in parse_blocks() (whitespace
        // between blocks counts) and position among its texts.
        $this->assertSame([
            ['id' => 1, 'source' => 'One', 'translation' => 'Yksi', 'block' => 0, 'index' => 0],
            ['id' => 2, 'source' => 'Two', 'translation' => 'Kaksi', 'block' => 2, 'index' => 0],
            ['id' => 3, 'source' => 'Two', 'translation' => 'Kaksi', 'block' => 4, 'index' => 0],
        ], $paired['segments']);
        $this->assertSame(0, $paired['unaligned']);
    }

    public function test_a_replaced_block_is_counted_as_unaligned_without_shifting_the_rest(): void
    {
        $source = $this->blocks('One', 'Two', 'Three');
        $translation = str_replace(
            "<!-- wp:paragraph -->\n<p>Kaksi</p>\n<!-- /wp:paragraph -->",
            "<!-- wp:heading -->\n<h2>Otsikko</h2>\n<!-- /wp:heading -->",
            $this->blocks('Yksi', 'Kaksi', 'Kolme')
        );

        $paired = TranslationAudit::pairSegments($source, $translation);

        $this->assertSame([['One', 'Yksi'], ['Three', 'Kolme']], array_map(
            static fn (array $segment): array => [$segment['source'], $segment['translation']],
            $paired['segments']
        ));
        $this->assertSame(1, $paired['unaligned']);
    }

    public function test_link_urls_are_not_translatable_text(): void
    {
        $button = "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/ohjeet/mitoitus/\">Lue ohje</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";

        $this->assertSame(['Lue ohje'], array_column(TranslationAudit::pairSegments($button, $button)['segments'], 'source'));

        $rules = (new BlockRules)->removeLinkUrlRules(['core/button' => ['//a', '//a/@href'], 'core/image' => ['//figure/figcaption', '//figure/a/@href']]);
        $this->assertSame(['core/button' => ['//a'], 'core/image' => ['//figure/figcaption']], $rules);

        add_filter('gds_content_translation_translate_link_urls', '__return_true');
        $this->assertSame(['core/button' => ['//a', '//a/@href']], (new BlockRules)->removeLinkUrlRules(['core/button' => ['//a', '//a/@href']]));
        remove_filter('gds_content_translation_translate_link_urls', '__return_true');
    }

    public function test_it_sends_the_pairs_to_claude_and_keeps_only_useful_remarks(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers([
            'summary' => 'One problem.',
            'remarks' => [
                ['segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Missing umlauts.', 'suggestion' => 'Lue lisää'],
                ['segment' => 2, 'severity' => 'suggestion', 'category' => 'fluency', 'issue' => 'No change needed.', 'suggestion' => 'Lue lisää'],
                ['segment' => 9, 'severity' => 'error', 'category' => 'meaning', 'issue' => 'No such segment.', 'suggestion' => ''],
            ],
        ]);

        $review = (new TranslationAudit)->run($ids['fi']);

        $request = $this->claudeRequests[0];
        $headers = array_change_key_case($request['headers']);
        $this->assertSame('sk-ant-test', $headers['x-api-key']);
        $this->assertSame('2026-10-21', $headers['anthropic-version']);
        $this->assertSame('server-side-fallback-2026-07-01', $headers['anthropic-beta']);
        $this->assertSame(TranslationAudit::defaultModel, $request['body']['model']);
        $this->assertSame('default', $request['body']['fallbacks']);
        $this->assertSame('json_schema', $request['body']['output_config']['format']['type']);

        $payload = json_decode($request['body']['messages'][0]['content'], true);
        $this->assertSame(['id', 'source', 'translation'], array_keys($payload['segments'][0]));
        $this->assertSame(['Hello <strong>world</strong>', 'Read more', 'Read more'], array_column($payload['segments'], 'source'));
        $this->assertSame(['Hei <strong>maailma</strong>', 'Lue lisää', 'Lue lisaa'], array_column($payload['segments'], 'translation'));
        // Remarks in the translation's language by default.
        $this->assertSame($payload['target_language'], $payload['remark_language']);

        $this->assertSame('done', $review['status']);
        $this->assertSame(md5(get_post($ids['fi'])->post_content), $review['contentHash']);
        // The "no change" remark and the one pointing nowhere are dropped.
        $this->assertSame([['id' => 1, 'segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Missing umlauts.', 'suggestion' => 'Lue lisää', 'state' => 'open']], $review['remarks']);
    }

    public function test_a_failed_call_comes_back_as_a_failed_review(): void
    {
        $ids = $this->translation();

        $this->claudeResponse = $this->claudeAnswers([], 'end_turn', 401);
        $this->claudeResponse['body'] = '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}';
        $review = (new TranslationAudit)->run($ids['fi']);
        $this->assertSame('failed', $review['status']);
        $this->assertSame('Claude API error 401: invalid x-api-key', $review['error']);

        $this->claudeResponse = $this->claudeAnswers(['summary' => '', 'remarks' => []], 'refusal');
        $this->assertStringContainsString('refusal', (new TranslationAudit)->run($ids['fi'])['error']);

        $this->claudeResponse = new WP_Error('http_request_failed', 'cURL error 28: timed out');
        $this->assertStringContainsString('timed out', (new TranslationAudit)->run($ids['fi'])['error']);
    }

    public function test_a_queued_review_runs_once_from_the_background_request(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Fine.', 'remarks' => []]);

        TranslationAudit::queue($ids['fi']);

        $this->assertSame('pending', TranslationAudit::statuses([$ids['fi']])[$ids['fi']]['status']);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_run_audit', [$ids['fi']]));
        $this->assertCount(1, $this->backgroundRequests);
        $this->assertFalse($this->backgroundRequests[0]['blocking']);
        $token = $this->backgroundRequests[0]['body']['token'];

        // A wrong token is refused and runs nothing.
        $this->backgroundRequest($ids['fi'], 'wrong');
        $this->assertSame([], $this->claudeRequests);

        $this->backgroundRequest($ids['fi'], $token);
        $this->assertCount(1, $this->claudeRequests);
        $this->assertSame('done', TranslationAudit::get($ids['fi'])['status']);

        // The token is single-use, and the cron fallback finds nothing queued.
        $this->backgroundRequest($ids['fi'], $token);
        do_action('gds_content_translation_run_audit', $ids['fi']);
        $this->assertCount(1, $this->claudeRequests);
    }

    private function backgroundRequest(int $postId, string $token): void
    {
        $_POST = ['action' => 'gds_ct_run_audit', 'post' => (string) $postId, 'token' => $token];

        try {
            do_action('wp_ajax_nopriv_gds_ct_run_audit');
            $this->fail('The background request did not end with wp_die().');
        } catch (WPDieException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_statuses_count_open_remarks_by_severity(): void
    {
        $postId = $this->createPost('fi');
        $remark = static fn (int $id, string $severity, string $state): array => ['id' => $id, 'segment' => 1, 'severity' => $severity, 'category' => 'other', 'issue' => 'x \\ y', 'suggestion' => '', 'state' => $state];

        TranslationAudit::store($postId, ['status' => 'done', 'contentHash' => 'abc', 'remarks' => [
            $remark(1, 'error', 'open'),
            $remark(2, 'error', 'resolved'),
            $remark(3, 'warning', 'open'),
            $remark(4, 'suggestion', 'dismissed'),
        ]]);

        $this->assertSame(['status' => 'done', 'errors' => 1, 'other' => 1, 'contentHash' => 'abc', 'queuedAt' => 0], TranslationAudit::statuses([$postId])[$postId]);
        // Stored as given, backslashes included.
        $this->assertSame('x \\ y', TranslationAudit::get($postId)['remarks'][0]['issue']);

        $this->assertTrue(TranslationAudit::setRemarkState($postId, 1, 'dismissed'));
        $this->assertSame(0, TranslationAudit::statuses([$postId])[$postId]['errors']);

        $this->assertSame('stuck', TranslationAudit::parseStatus('pending:'.(time() - TranslationAudit::stuckAfter - 1))['status']);
        $this->assertSame('pending', TranslationAudit::parseStatus('pending:'.time())['status']);
    }

    /**
     * @return array<string, int>
     */
    private function reviewedTranslation(): array
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Two.', 'remarks' => [
            ['segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Missing umlauts.', 'suggestion' => 'Lue lisää'],
            ['segment' => 1, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Stiff.', 'suggestion' => 'Terve <strong>maailma</strong>'],
        ]]);

        TranslationAudit::queue($ids['fi']);
        do_action('gds_content_translation_run_audit', $ids['fi']);

        return $ids;
    }

    public function test_remarks_become_notes_on_their_blocks(): void
    {
        $ids = $this->reviewedTranslation();
        $review = TranslationAudit::get($ids['fi']);
        $content = get_post($ids['fi'])->post_content;

        $this->assertSame('done', $review['status']);
        $this->assertSame(md5($content), $review['contentHash'], 'Adding notes is not an edit.');

        foreach ($review['remarks'] as $remark) {
            $note = get_comment($remark['noteId']);
            $this->assertSame('note', $note->comment_type);
            $this->assertSame('0', $note->comment_approved);
            $this->assertStringContainsString($remark['issue'], $note->comment_content);
        }

        // Each note is on the block holding its text.
        $blocks = parse_blocks($content);
        $this->assertSame([$review['remarks'][0]['noteId']], $blocks[0]['attrs']['metadata']['noteId']);
        $this->assertSame([$review['remarks'][1]['noteId']], $blocks[4]['attrs']['metadata']['noteId']);

        // Resolving the note in the editor closes the remark, and the other way round.
        wp_set_comment_status($review['remarks'][0]['noteId'], 'approve');
        $this->assertSame('resolved', TranslationAudit::get($ids['fi'])['remarks'][0]['state']);
        TranslationAudit::setRemarkState($ids['fi'], 2, 'dismissed', true, 'not-an-issue');
        $this->assertSame('1', get_comment($review['remarks'][1]['noteId'])->comment_approved);

        // Running the review again replaces the notes.
        TranslationAudit::queue($ids['fi']);
        do_action('gds_content_translation_run_audit', $ids['fi']);
        $this->assertNull(get_comment($review['remarks'][0]['noteId']));
        $this->assertCount(2, get_comments(['post_id' => $ids['fi'], 'type' => 'note', 'status' => 'all']));
    }

    public function test_a_suggestion_or_an_edit_is_written_into_the_translation(): void
    {
        $ids = $this->reviewedTranslation();

        $this->submit('gds_ct_audit_remark', ['post' => (string) $ids['fi'], 'remark' => '2', 'operation' => 'use']);
        $location = $this->submit('gds_ct_audit_remark', ['post' => (string) $ids['fi'], 'remark' => '1', 'operation' => 'edit', 'text' => 'Hei <strong>koko</strong> maailma', 'view' => 'remarks']);

        $this->assertStringEndsWith('&view=remarks#gds-ct-remark-1', $location);
        $this->assertSame(['Hei <strong>koko</strong> maailma', 'Lue lisää', 'Lue lisää'], TranslationAudit::strings(get_post($ids['fi'])->post_content));

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame(['resolved', 'resolved'], array_column($review['remarks'], 'state'));
        $this->assertSame(['edited', 'suggestion'], array_column($review['remarks'], 'resolution'));
        $this->assertSame(md5(get_post($ids['fi'])->post_content), $review['contentHash']);
        $this->assertSame('1', get_comment($review['remarks'][0]['noteId'])->comment_approved);
        $this->assertSame('done:0:0:'.$review['contentHash'], get_post_meta($ids['fi'], TranslationAudit::statusMetaKey, true));
    }

    public function test_a_text_changed_in_the_editor_is_not_overwritten(): void
    {
        $ids = $this->reviewedTranslation();
        $content = str_replace('Lue lisaa', 'Lue lisää nyt', get_post($ids['fi'])->post_content);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => wp_slash($content)]);

        // Remark 2 is the one on the third text, "Lue lisaa".
        $result = TranslationAudit::applyText($ids['fi'], 2, 'Lue lisää', 'suggestion');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gds_ct_audit_changed', $result->get_error_code());
        $this->assertStringContainsString('Lue lisää nyt', get_post($ids['fi'])->post_content);

        // The review page offers to mark it fixed instead.
        ob_start();
        AuditReview::renderPage($ids['fi']);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('was edited after this review', $html);
        $this->assertStringContainsString('Changed since the review', $html);
        $this->assertStringContainsString('value="fixed"', $html);
    }

    public function test_a_review_is_run_from_the_status_screen_link(): void
    {
        $ids = $this->translation();
        $_GET = ['action' => 'gds_ct_run_review', 'post' => (string) $ids['fi'], '_wpnonce' => wp_create_nonce('gds_ct_run_review')];
        $_REQUEST = $_GET;

        try {
            do_action('admin_post_gds_ct_run_review');
            $this->fail('The handler did not redirect.');
        } catch (RedirectException $redirect) {
            $this->assertSame(AuditReview::getUrl($ids['fi']), $redirect->location);
        }

        $this->assertSame('pending', TranslationAudit::get($ids['fi'])['status']);

        // The page waits for it.
        ob_start();
        AuditReview::renderPage($ids['fi']);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-status="pending"', $html);
        $this->assertStringContainsString('The AI review is running', $html);
    }

    public function test_a_long_page_is_reviewed_in_several_calls(): void
    {
        $ids = $this->createTranslatedPost();
        $texts = array_map(static fn (int $n): string => "Text {$n}", range(1, 61));
        wp_update_post(['ID' => $ids['en'], 'post_content' => $this->blocks(...$texts)]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $this->blocks(...array_map(static fn (string $text): string => "Teksti {$text}", $texts))]);
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Part.', 'remarks' => [
            ['segment' => 61, 'severity' => 'error', 'category' => 'other', 'issue' => 'Last one.', 'suggestion' => ''],
        ]]);

        $review = (new TranslationAudit)->run($ids['fi']);

        $this->assertCount(2, $this->claudeRequests);
        // Even parts, not 60 and a lone 1.
        $this->assertCount(31, json_decode($this->claudeRequests[0]['body']['messages'][0]['content'], true)['segments']);
        $second = json_decode($this->claudeRequests[1]['body']['messages'][0]['content'], true);
        $this->assertSame(range(32, 61), array_column($second['segments'], 'id'));
        $this->assertStringContainsString('Part 2 of 2', $second['part']);
        $this->assertSame(['input' => 200, 'output' => 40], $review['usage']);
        // The same remark from both calls (the fake answers alike) is kept per call.
        $this->assertSame([61, 61], array_column($review['remarks'], 'segment'));
    }

    public function test_the_suggestion_shows_what_it_changes(): void
    {
        $this->assertSame('<del>Färdig</del><ins>Färdigt</ins> betongfundament', TextDiff::render('Färdig betongfundament', 'Färdigt betongfundament'));
        $this->assertSame(
            'Inga <del>formar</del><ins>gjutformar</ins>, ingen väntan',
            TextDiff::render('Inga <strong>formar</strong>, ingen väntan', 'Inga gjutformar, ingen väntan')
        );
    }

    public function test_only_editors_of_the_translation_can_change_remarks(): void
    {
        $ids = $this->translation();
        TranslationAudit::store($ids['fi'], ['status' => 'done', 'contentHash' => '', 'remarks' => [
            ['id' => 1, 'segment' => 1, 'severity' => 'error', 'category' => 'other', 'issue' => '', 'suggestion' => '', 'state' => 'open'],
        ]]);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        try {
            $this->submit('gds_ct_audit_remark', ['post' => (string) $ids['fi'], 'remark' => '1', 'operation' => 'dismiss']);
        } catch (WPDieException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('open', TranslationAudit::get($ids['fi'])['remarks'][0]['state']);
    }

    public function test_it_is_off_without_a_key_or_when_switched_off(): void
    {
        $this->assertTrue(TranslationAudit::isEnabled());
        $this->assertSame('environment', TranslationAudit::keySource());

        update_option(TranslationAudit::optionKey, ['enabled' => false]);
        $this->assertFalse(TranslationAudit::isEnabled());

        delete_option(TranslationAudit::optionKey);
        putenv('ANTHROPIC_API_KEY');
        $this->assertFalse(TranslationAudit::isEnabled());
    }

    /**
     * Post a review page form to its admin-post handler.
     *
     * @param  array<string, string>  $fields
     */
    private function submit(string $action, array $fields): string
    {
        $_POST = array_merge(['action' => $action, '_wpnonce' => wp_create_nonce($action.'_'.$fields['post'])], $fields);
        $_REQUEST = $_POST;

        try {
            do_action('admin_post_'.$action);
        } catch (RedirectException $redirect) {
            return $redirect->location;
        }

        $this->fail('The handler did not redirect.');
    }
}
