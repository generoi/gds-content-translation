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
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
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

    /** @var callable|null Runs while Claude "answers": an editor saving meanwhile. */
    private $whileClaudeAnswers = null;

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

        if ($this->whileClaudeAnswers) {
            ($this->whileClaudeAnswers)();
        }

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
        // The factory gives each post an excerpt of its own, which would be reviewed too.
        $ids = $this->createTranslatedPost(['post_title' => 'Page title', 'post_excerpt' => '']);
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
                ['segment' => 9, 'severity' => 'error', 'category' => 'meaning', 'issue' => 'No such segment.', 'suggestion' => 'Jotain'],
                ['segment' => 1, 'severity' => 'error', 'category' => 'untranslated', 'issue' => 'Kept on purpose, so this is not an error.', 'suggestion' => ''],
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
        // The "no change" remark, the one without a suggestion and the one
        // pointing nowhere are dropped: every remark left is one click.
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
    /**
     * Two remarks with suggestions, one without (dropped): what a follow-up has to apply.
     *
     * @return array<string, mixed>
     */
    private function remarksToApply(): array
    {
        return $this->claudeAnswers(['summary' => 'Two.', 'remarks' => [
            ['segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Missing umlauts.', 'suggestion' => 'Lue lisää'],
            ['segment' => 1, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Stiff.', 'suggestion' => 'Terve <strong>maailma</strong>'],
            ['segment' => 2, 'severity' => 'warning', 'category' => 'tone', 'issue' => 'Hard to say how.', 'suggestion' => ''],
        ]]);
    }

    public function test_manual_follow_up_leaves_the_remarks_to_an_editor(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->remarksToApply();

        TranslationAudit::queue($ids['fi'], 'manual');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $this->assertSame(['open', 'open'], array_column(TranslationAudit::get($ids['fi'])['remarks'], 'state'));
        $this->assertStringContainsString('Lue lisaa', get_post($ids['fi'])->post_content);
    }

    public function test_apply_follow_up_writes_every_suggestion_into_the_translation(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->remarksToApply();
        $revisionsBefore = count(wp_get_post_revisions($ids['fi']));

        TranslationAudit::queue($ids['fi'], 'apply');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $content = get_post($ids['fi'])->post_content;
        $this->assertStringContainsString('Terve <strong>maailma</strong>', $content);
        $this->assertStringNotContainsString('Lue lisaa', $content);

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame('done', $review['status']);
        $this->assertSame(2, $review['autoApplied']);
        $this->assertSame(0, $review['autoSkipped']);
        $bySegment = array_column($review['remarks'], null, 'segment');
        ksort($bySegment);
        $this->assertSame([1 => 'resolved', 3 => 'resolved'], array_map(static fn (array $remark) => $remark['state'], $bySegment));
        $this->assertSame([1 => 'auto', 3 => 'auto'], array_map(static fn (array $remark) => $remark['resolution'] ?? '', $bySegment));
        // Still counts as reviewed: the content hash follows the applied text.
        $this->assertSame(TranslationAudit::contentHash($ids['fi']), $review['contentHash']);
        $this->assertCount($revisionsBefore, wp_get_post_revisions($ids['fi']));
        // No second review.
        $this->assertCount(1, $this->claudeRequests);
        $this->assertSame('done', TranslationAudit::statuses([$ids['fi']])[$ids['fi']]['status']);
    }

    public function test_a_second_remark_on_a_text_does_not_undo_the_first(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Two on one.', 'remarks' => [
            ['segment' => 1, 'severity' => 'error', 'category' => 'terminology', 'issue' => 'Term.', 'suggestion' => 'Terve <strong>maailma</strong>'],
            ['segment' => 1, 'severity' => 'warning', 'category' => 'tone', 'issue' => 'Warmer.', 'suggestion' => 'Hei <strong>maailma</strong>!'],
        ]]);

        TranslationAudit::queue($ids['fi'], 'apply');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame(1, $review['autoApplied']);
        $this->assertSame(1, $review['autoSkipped']);
        $this->assertSame(['resolved', 'open'], array_column($review['remarks'], 'state'));
        $this->assertStringContainsString('Terve <strong>maailma</strong>', get_post($ids['fi'])->post_content);

        // Used by hand, the second is refused too; the editor's own text is not.
        $second = (int) $review['remarks'][1]['id'];
        $this->assertInstanceOf(WP_Error::class, TranslationAudit::applyText($ids['fi'], $second, 'Hei <strong>maailma</strong>!', 'suggestion'));
        $this->assertTrue(TranslationAudit::applyText($ids['fi'], $second, 'Terve <strong>maailma</strong>!', 'edited'));
    }

    public function test_an_automatic_apply_keeps_html_that_only_an_editor_may_save(): void
    {
        $ids = $this->translation();
        $editor = self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($editor);
        $embed = '<!-- wp:html --><iframe src="https://example.com/map"></iframe><!-- /wp:html -->';
        wp_update_post(['ID' => $ids['fi'], 'post_content' => get_post($ids['fi'])->post_content.$embed]);
        $this->claudeResponse = $this->remarksToApply();

        wp_set_current_user($editor);
        TranslationAudit::queue($ids['fi'], 'apply');
        // In the background, as the review runs: no one signed in.
        wp_set_current_user(0);
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $this->assertSame(2, TranslationAudit::get($ids['fi'])['autoApplied']);
        $this->assertStringContainsString('<iframe src="https://example.com/map"></iframe>', get_post($ids['fi'])->post_content);
    }

    public function test_the_status_screen_sees_a_review_running_until_its_suggestions_are_applied(): void
    {
        foreach (['apply', 'apply_review'] as $followup) {
            $ids = $this->translation();
            $this->claudeResponse = $this->remarksToApply();
            $seen = [];
            $watch = static function (int $postId) use (&$seen, $ids): void {
                if ($postId === $ids['fi']) {
                    $status = TranslationAudit::statuses([$postId])[$postId];
                    $seen[] = $status['status'].':'.$status['errors'];
                }
            };
            add_action('save_post', $watch);

            TranslationAudit::queue($ids['fi'], $followup);
            do_action('gds_content_translation_run_audit', $ids['fi']);
            remove_action('save_post', $watch);

            // Each suggestion is written as a save; none shows the remarks it fixes.
            $this->assertNotEmpty($seen);
            $this->assertSame(['pending:0'], array_values(array_unique($seen)), $followup);
            $this->assertSame($followup === 'apply' ? 'done' : 'pending', TranslationAudit::statuses([$ids['fi']])[$ids['fi']]['status'], $followup);
        }
    }

    public function test_apply_and_review_follow_up_runs_one_more_review_left_to_an_editor(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->remarksToApply();

        TranslationAudit::queue($ids['fi'], 'apply_review');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        // Applied, then queued again.
        $this->assertSame('pending', TranslationAudit::get($ids['fi'])['status']);
        $this->assertSame('manual', TranslationAudit::get($ids['fi'])['followup']);
        $this->assertSame(2, TranslationAudit::get($ids['fi'])['appliedBefore']);

        // The second review sees the applied text, and its remarks stay open.
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'One left.', 'remarks' => [
            ['segment' => 2, 'severity' => 'suggestion', 'category' => 'tone', 'issue' => 'Could be warmer.', 'suggestion' => 'Lue vielä lisää'],
        ]]);
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $payload = json_decode($this->claudeRequests[1]['body']['messages'][0]['content'], true);
        $this->assertSame(['Terve <strong>maailma</strong>', 'Lue lisää', 'Lue lisää'], array_column($payload['segments'], 'translation'));

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame('done', $review['status']);
        $this->assertSame(2, $review['appliedBefore']);
        $this->assertSame(['open'], array_column($review['remarks'], 'state'));
        $this->assertCount(2, $this->claudeRequests);
    }

    public function test_site_and_page_instructions_go_to_claude_and_stay_with_the_review(): void
    {
        $ids = $this->translation();
        update_option(TranslationAudit::optionKey, ['instructions' => "  “Read more” is always “Lue lisää”.\n<b>No</b> HTML.  "]);
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Fine.', 'remarks' => []]);

        TranslationAudit::queue($ids['fi'], 'manual', 'This page is for distributors: formal tone.');
        do_action('gds_content_translation_run_audit', $ids['fi']);

        $system = $this->claudeRequests[0]['body']['system'];
        $payload = json_decode($this->claudeRequests[0]['body']['messages'][0]['content'], true);
        $this->assertStringContainsString("editor's instructions", $system);
        $this->assertSame([
            'site' => "“Read more” is always “Lue lisää”.\nNo HTML.",
            'this_page' => 'This page is for distributors: formal tone.',
        ], $payload['instructions']);

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame('This page is for distributors: formal tone.', $review['instructions']['page']);

        // Run again: the page's instructions are kept; the site's are read anew.
        update_option(TranslationAudit::optionKey, ['instructions' => '']);
        TranslationAudit::queue($ids['fi']);
        do_action('gds_content_translation_run_audit', $ids['fi']);
        $payload = json_decode($this->claudeRequests[1]['body']['messages'][0]['content'], true);
        $this->assertSame(['this_page' => 'This page is for distributors: formal tone.'], $payload['instructions']);

        delete_option(TranslationAudit::optionKey);
    }

    public function test_without_instructions_the_prompt_has_none(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Fine.', 'remarks' => []]);

        (new TranslationAudit)->run($ids['fi']);

        $payload = json_decode($this->claudeRequests[0]['body']['messages'][0]['content'], true);
        $this->assertNull($payload['instructions']);
        $this->assertSame(str_repeat('a', TranslationAudit::instructionsMax), TranslationAudit::cleanInstructions(str_repeat('a', TranslationAudit::instructionsMax + 50)));
    }

    public function test_the_follow_up_falls_back_to_the_site_default(): void
    {
        $this->assertSame('manual', TranslationAudit::followup(null));
        update_option(TranslationAudit::optionKey, ['followup' => 'apply_review']);
        $this->assertSame('apply_review', TranslationAudit::followup('nonsense'));
        $this->assertSame('apply', TranslationAudit::followup('apply'));
        delete_option(TranslationAudit::optionKey);
    }

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

        $this->remarks($ids['fi'], 'use', [2]);
        $response = $this->remarks($ids['fi'], 'edit', [1], 'Hei <strong>koko</strong> maailma');

        // A result per remark, and the table as it is now.
        $this->assertSame([['id' => 1, 'ok' => true, 'message' => '']], $response->get_data()['results']);
        $this->assertSame(['error' => 0, 'warning' => 0, 'suggestion' => 0], $response->get_data()['counts']);
        $this->assertSame(['Hei <strong>koko</strong> maailma', 'Lue lisää', 'Lue lisää'], TranslationAudit::strings(get_post($ids['fi'])->post_content));

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame(['resolved', 'resolved'], array_column($review['remarks'], 'state'));
        $this->assertSame(['edited', 'suggestion'], array_column($review['remarks'], 'resolution'));
        $this->assertSame(md5(get_post($ids['fi'])->post_content), $review['contentHash']);
        $this->assertSame('1', get_comment($review['remarks'][0]['noteId'])->comment_approved);
        $this->assertSame('done:0:0:'.$review['contentHash'], get_post_meta($ids['fi'], TranslationAudit::statusMetaKey, true));
    }

    public function test_a_review_stored_without_text_places_can_still_be_applied(): void
    {
        $ids = $this->reviewedTranslation();
        $review = TranslationAudit::get($ids['fi']);
        $review['segments'] = array_map(static fn (array $segment): array => array_diff_key($segment, ['block' => 0, 'index' => 0]), $review['segments']);
        TranslationAudit::store($ids['fi'], $review);

        $this->assertTrue(TranslationAudit::applyText($ids['fi'], 2, 'Lue lisää', 'suggestion'));
        $this->assertContains('Lue lisää', TranslationAudit::strings(get_post($ids['fi'])->post_content));
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

        // The review page says so, and its table flags the text.
        ob_start();
        AuditReview::renderPage($ids['fi']);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('was edited after this review', $html);
        $rows = array_column(AuditReview::remarkRows($ids['fi'])['rows'], null, 'id');
        $this->assertTrue($rows['r2']['changed']);
        $this->assertFalse($rows['r1']['changed']);

        // Several at once: each on its own.
        $results = $this->remarks($ids['fi'], 'use', [1, 2])->get_data()['results'];
        $this->assertSame([true, false], array_column($results, 'ok'));
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
        $ids = $this->createTranslatedPost(['post_title' => 'Long page', 'post_excerpt' => '']);
        $texts = array_map(static fn (int $n): string => "Text {$n}", range(1, 61));
        wp_update_post(['ID' => $ids['en'], 'post_content' => $this->blocks(...$texts)]);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => $this->blocks(...array_map(static fn (string $text): string => "Teksti {$text}", $texts))]);
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Part.', 'remarks' => [
            ['segment' => 61, 'severity' => 'error', 'category' => 'other', 'issue' => 'Last one.', 'suggestion' => 'Teksti 61.'],
        ]]);

        $review = (new TranslationAudit)->run($ids['fi']);

        $this->assertCount(2, $this->claudeRequests);
        // Even parts, not 60 and a lone 1.
        $this->assertCount(31, json_decode($this->claudeRequests[0]['body']['messages'][0]['content'], true)['segments']);
        $second = json_decode($this->claudeRequests[1]['body']['messages'][0]['content'], true);
        $this->assertSame(range(32, 61), array_column($second['segments'], 'id'));
        $this->assertStringContainsString('Part 2 of 2', $second['part']);
        $this->assertSame(['input' => 200, 'output' => 40], $review['usage']);
        // Both calls answer alike: only the part that sent text 61 may remark on it.
        $this->assertSame([61], array_column($review['remarks'], 'segment'));
    }

    public function test_a_nested_block_placeholder_is_kept_and_shown_as_a_marker(): void
    {
        $ids = $this->translation();
        $placeholder = TranslationAudit::blockPlaceholder;
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'One.', 'remarks' => [
            ['segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Lost the block.', 'suggestion' => "Lue lisää {$placeholder}"],
        ]]);

        // A suggestion that drops or adds a nested block is not offered.
        $this->assertSame([], (new TranslationAudit)->run($ids['fi'])['remarks']);

        $this->assertSame(
            'Valitse <span class="gds-ct-review__nested">[nested block]</span> pilarit',
            TranslationAudit::displayText('Valitse '.wp_kses_post($placeholder).' pilarit')
        );
    }

    public function test_the_suggestion_shows_what_it_changes(): void
    {
        $this->assertSame('<del>Färdig</del><ins>Färdigt</ins> betongfundament', TextDiff::render('Färdig betongfundament', 'Färdigt betongfundament'));
        $this->assertSame(
            'Inga <del>formar</del><ins>gjutformar</ins>, ingen väntan',
            TextDiff::render('Inga <strong>formar</strong>, ingen väntan', 'Inga gjutformar, ingen väntan')
        );
    }

    public function test_long_texts_get_a_difference_only_when_it_stays_small(): void
    {
        $words = static fn (string $word, int $count): string => implode(' ', array_map(static fn (int $n): string => $word.$n, range(1, $count)));

        // One word changed in a long text: only the middle is compared.
        $long = $words('sana', 2000);
        $this->assertSame(1, substr_count(TextDiff::render($long, str_replace('sana1000 ', 'SANA ', $long)), '<ins>'));

        // A long text rewritten throughout: no difference.
        $this->assertSame('', TextDiff::render($words('sana', 600), $words('ord', 600)));

        // The review page shows that suggestion as it is.
        $ids = $this->translation();
        $translation = TranslationAudit::strings(get_post($ids['fi'])->post_content)[2];
        TranslationAudit::store($ids['fi'], [
            'status' => 'done',
            'contentHash' => '',
            'sourceId' => $ids['en'],
            'segments' => [['id' => 1, 'source' => 'Read more', 'translation' => $translation.' '.$words('sana', 600), 'block' => 4, 'index' => 0]],
            'remarks' => [['id' => 1, 'segment' => 1, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Stiff.', 'suggestion' => '<em>'.$words('ord', 600).'</em>', 'state' => 'open']],
        ]);
        $row = AuditReview::remarkRows($ids['fi'])['rows'][0];
        $this->assertSame('', $row['suggestionDiff']);
        $this->assertStringStartsWith('<em>ord1 ord2', $row['suggestionText']);
    }

    public function test_an_edit_saved_while_the_review_runs_still_shows(): void
    {
        $ids = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'One.', 'remarks' => [
            ['segment' => 3, 'severity' => 'error', 'category' => 'grammar', 'issue' => 'Missing umlauts.', 'suggestion' => 'Lue lisää'],
        ]]);
        $this->whileClaudeAnswers = static function () use ($ids): void {
            $content = str_replace('Hei <strong>maailma</strong>', 'Hei <strong>koko maailma</strong>', get_post($ids['fi'])->post_content);
            wp_update_post(['ID' => $ids['fi'], 'post_content' => wp_slash($content)]);
        };

        TranslationAudit::queue($ids['fi']);
        do_action('gds_content_translation_run_audit', $ids['fi']);
        $this->whileClaudeAnswers = null;

        // The notes were added, and the edit still counts as one.
        $review = TranslationAudit::get($ids['fi']);
        $this->assertGreaterThan(0, $review['remarks'][0]['noteId']);
        $this->assertTrue(TranslationAudit::editedSince($ids['fi'], $review['contentHash']));

        // So does it after a suggestion is used.
        $this->assertTrue(TranslationAudit::applyText($ids['fi'], 1, 'Lue lisää', 'suggestion'));
        $this->assertTrue(TranslationAudit::editedSince($ids['fi'], TranslationAudit::get($ids['fi'])['contentHash']));

        // Without an edit, using a suggestion is not one.
        $ids = $this->reviewedTranslation();
        $this->assertTrue(TranslationAudit::applyText($ids['fi'], 2, 'Lue lisää', 'suggestion'));
        $this->assertFalse(TranslationAudit::editedSince($ids['fi'], TranslationAudit::get($ids['fi'])['contentHash']));
    }

    public function test_a_review_made_before_fields_were_reviewed_is_not_edited(): void
    {
        $ids = $this->translation();
        wp_update_post(['ID' => $ids['fi'], 'post_title' => 'Sivun otsikko']);

        $this->assertFalse(TranslationAudit::editedSince($ids['fi'], md5(get_post($ids['fi'])->post_content)), 'The hash without the title.');
        $this->assertFalse(TranslationAudit::editedSince($ids['fi'], TranslationAudit::contentHash($ids['fi'])));
        $this->assertTrue(TranslationAudit::editedSince($ids['fi'], md5('something else')));
    }

    public function test_a_review_is_not_queued_while_one_runs(): void
    {
        $ids = $this->translation();
        TranslationAudit::queue($ids['fi']);
        $run = TranslationAudit::get($ids['fi'])['run'];
        $this->assertNotSame('', $run);

        $this->handleRequest('gds_ct_run_review', ['post' => (string) $ids['fi'], '_wpnonce' => wp_create_nonce('gds_ct_run_review')]);

        $this->assertSame($run, TranslationAudit::get($ids['fi'])['run'], 'Not queued again.');
        $this->assertStringContainsString('already running', $this->renderNotices());

        // One that is stuck can be run again.
        $review = TranslationAudit::get($ids['fi']);
        TranslationAudit::store($ids['fi'], array_merge($review, ['queuedAt' => time() - HOUR_IN_SECONDS]));
        $this->handleRequest('gds_ct_run_review', ['post' => (string) $ids['fi'], '_wpnonce' => wp_create_nonce('gds_ct_run_review')]);
        $this->assertNotSame($run, TranslationAudit::get($ids['fi'])['run']);
    }

    public function test_a_note_of_another_run_does_not_close_a_remark(): void
    {
        $ids = $this->reviewedTranslation();
        $review = TranslationAudit::get($ids['fi']);
        [$first, $second] = array_map('intval', array_column($review['remarks'], 'noteId'));
        $this->assertSame($review['run'], get_comment_meta($first, '_gds_content_translation_audit_run', true));

        // Left by an earlier run that overlapped this one: its remark IDs are another review's.
        update_comment_meta($first, '_gds_content_translation_audit_run', 'other');
        wp_set_comment_status($first, 'approve');
        $this->assertSame('open', TranslationAudit::get($ids['fi'])['remarks'][0]['state']);

        // This run's note does close its remark.
        wp_delete_comment($second, true);
        $this->assertSame('dismissed', TranslationAudit::get($ids['fi'])['remarks'][1]['state']);
    }

    public function test_the_cron_fallback_waits_for_a_free_place(): void
    {
        $ids = $this->translation();
        $other = $this->translation();
        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Fine.', 'remarks' => []]);
        add_filter('gds_content_translation_audit_parallel', static fn (): int => 1);

        TranslationAudit::queue($other['fi']);
        TranslationAudit::queue($ids['fi']);
        $this->assertSame('queued', TranslationAudit::get($ids['fi'])['status']);
        wp_clear_scheduled_hook('gds_content_translation_run_audit', [$ids['fi']]);

        do_action('gds_content_translation_run_audit', $ids['fi']);
        $this->assertSame([], $this->claudeRequests, 'The place is taken.');
        $this->assertSame('queued', TranslationAudit::get($ids['fi'])['status']);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_run_audit', [$ids['fi']]), 'Looked at again later.');

        // Once the other is done, there is room. (Its end would start this one.)
        TranslationAudit::store($other['fi'], ['status' => 'done', 'contentHash' => '', 'remarks' => []]);
        do_action('gds_content_translation_run_audit', $ids['fi']);
        $this->assertSame('done', TranslationAudit::get($ids['fi'])['status']);

        remove_all_filters('gds_content_translation_audit_parallel');
    }

    public function test_the_title_excerpt_and_seo_texts_are_reviewed_and_fixed(): void
    {
        $ids = $this->translation();
        wp_update_post(['ID' => $ids['en'], 'post_title' => 'Wall braces', 'post_excerpt' => 'Strong braces.']);
        wp_update_post(['ID' => $ids['fi'], 'post_title' => 'Seinäpönkät', 'post_excerpt' => 'Vahvat tuet.']);
        update_post_meta($ids['en'], '_yoast_wpseo_metadesc', 'Buy wall braces.');
        update_post_meta($ids['fi'], '_yoast_wpseo_metadesc', 'Osta seinäpönkkiä.');
        update_post_meta($ids['en'], '_yoast_wpseo_title', '%%title%% %%sep%% Shop');
        update_post_meta($ids['fi'], '_yoast_wpseo_title', '%%title%% %%sep%% Kauppa');
        // Still the original's, or empty: nothing to review.
        update_post_meta($ids['en'], '_yoast_wpseo_opengraph-title', 'Stedox');
        update_post_meta($ids['fi'], '_yoast_wpseo_opengraph-title', 'Stedox');
        update_post_meta($ids['en'], '_yoast_wpseo_twitter-title', 'Braces');

        $this->claudeResponse = $this->claudeAnswers(['summary' => 'Fields.', 'remarks' => [
            ['segment' => 4, 'severity' => 'error', 'category' => 'terminology', 'issue' => 'Glossary.', 'suggestion' => 'Seinätuet'],
            ['segment' => 6, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Shop.', 'suggestion' => '%%title%% %%sep%% Verkkokauppa'],
            ['segment' => 6, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Lost a variable.', 'suggestion' => '%%title%% Verkkokauppa'],
            ['segment' => 7, 'severity' => 'warning', 'category' => 'fluency', 'issue' => 'Stiff.', 'suggestion' => 'Osta seinätukia.'],
        ]]);

        TranslationAudit::queue($ids['fi']);
        do_action('gds_content_translation_run_audit', $ids['fi']);

        // After the content, each with its field's name.
        $payload = json_decode($this->claudeRequests[0]['body']['messages'][0]['content'], true);
        $this->assertSame(
            [[4, 'Title', 'Seinäpönkät'], [5, 'Excerpt', 'Vahvat tuet.'], [6, 'SEO title', '%%title%% %%sep%% Kauppa'], [7, 'Meta description', 'Osta seinäpönkkiä.']],
            array_map(static fn (array $segment): array => [$segment['id'], $segment['field'], $segment['translation']], array_slice($payload['segments'], 3))
        );
        $this->assertStringContainsString('%%sitename%%', $this->claudeRequests[0]['body']['system']);

        $review = TranslationAudit::get($ids['fi']);
        $this->assertSame([4, 6, 7], array_column($review['remarks'], 'segment'), 'The suggestion without the variable is dropped.');
        $this->assertSame(['segment' => 4, 'label' => 'Title'], array_intersect_key(AuditReview::remarkRows($ids['fi'])['rows'][3], ['segment' => 0, 'label' => '']));
        $this->assertFalse(TranslationAudit::editedSince($ids['fi'], $review['contentHash']));

        $this->assertTrue(TranslationAudit::applyText($ids['fi'], 1, 'Seinätuet', 'suggestion'));
        $this->assertTrue(TranslationAudit::applyText($ids['fi'], 2, '%%title%% %%sep%% Verkkokauppa', 'suggestion'));
        $this->assertSame('Seinätuet', get_post($ids['fi'])->post_title);
        $this->assertSame('%%title%% %%sep%% Verkkokauppa', get_post_meta($ids['fi'], '_yoast_wpseo_title', true));
        $this->assertFalse(TranslationAudit::editedSince($ids['fi'], TranslationAudit::get($ids['fi'])['contentHash']), 'Using them is no edit.');

        // A field changed since is left alone, and an edit in it shows.
        update_post_meta($ids['fi'], '_yoast_wpseo_metadesc', 'Osta seinätukia nyt.');
        $this->assertSame('gds_ct_audit_changed', TranslationAudit::applyText($ids['fi'], 3, 'Osta seinätukia.', 'suggestion')->get_error_code());
        $this->assertTrue(TranslationAudit::editedSince($ids['fi'], TranslationAudit::get($ids['fi'])['contentHash']));
    }

    public function test_only_editors_of_the_translation_can_change_remarks(): void
    {
        $ids = $this->translation();
        TranslationAudit::store($ids['fi'], ['status' => 'done', 'contentHash' => '', 'remarks' => [
            ['id' => 1, 'segment' => 1, 'severity' => 'error', 'category' => 'other', 'issue' => '', 'suggestion' => '', 'state' => 'open'],
        ]]);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->remarks($ids['fi'], 'dismiss', [1])->get_status());

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
     * POST /reviews/{id}/remarks as the current user.
     *
     * @param  list<int>  $remarks
     */
    private function remarks(int $postId, string $operation, array $remarks, string $text = ''): WP_REST_Response
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init', $wp_rest_server);

        $request = new WP_REST_Request('POST', '/gds-content-translation/v1/reviews/'.$postId.'/remarks');
        $request->set_body_params(['operation' => $operation, 'remarks' => $remarks, 'text' => $text]);

        return rest_get_server()->dispatch($request);
    }
}
