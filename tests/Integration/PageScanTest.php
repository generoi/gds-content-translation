<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Glossary;
use GeneroWP\ContentTranslation\PageScan;
use GeneroWP\ContentTranslation\PageText;
use GeneroWP\ContentTranslation\Tests\RestTestCase;
use GeneroWP\ContentTranslation\TranslationAudit;
use WP_Error;
use WP_Http_Cookie;
use WP_Session_Tokens;

/**
 * The check of a translation's page for text in the original's language
 * (PageScan): the estimate, the queue, the Claude call, where each finding
 * comes from, the stored result and the bulk action. The page and Claude are
 * faked through pre_http_request.
 */
class PageScanTest extends RestTestCase
{
    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $pageRequests = [];

    /** @var list<array<string, mixed>> */
    private array $claudeRequests = [];

    /** @var list<array<string, mixed>> */
    private array $backgroundRequests = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    private string $html = '';

    private int $pageCode = 200;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $wpdb->query('DELETE FROM '.Glossary::table());
        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
        $this->html = (string) file_get_contents(dirname(__DIR__).'/fixtures/page-sv.html');
    }

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        wp_clear_scheduled_hook('gds_content_translation_run_page_scan');

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return false|array<string, mixed>|WP_Error
     */
    public function fakeHttp($pre, array $args, string $url)
    {
        $response = static fn (string $body, int $code = 200): array => ['headers' => [], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

        if (str_contains($url, 'admin-ajax.php')) {
            $this->backgroundRequests[] = $args;

            return $response('');
        }

        if (str_contains($url, 'api.anthropic.com')) {
            $this->claudeRequests[] = json_decode($args['body'], true);

            return $response((string) wp_json_encode([
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
                'content' => [['type' => 'text', 'text' => wp_json_encode(['findings' => $this->findings])]],
            ]));
        }

        if (str_contains($url, 'example.org')) {
            $this->pageRequests[] = ['url' => $url, 'args' => $args];

            return $response($this->pageCode === 200 ? $this->html : '', $this->pageCode);
        }

        return $pre;
    }

    /**
     * A published English original and its Finnish translation, whose
     * content has one of the page's texts.
     *
     * @return array<string, int>
     */
    private function translation(string $status = 'publish'): array
    {
        $ids = $this->createTranslatedPost(['post_type' => 'page', 'post_status' => $status, 'post_title' => 'Hem']);
        wp_update_post(['ID' => $ids['fi'], 'post_content' => '<!-- wp:paragraph --><p>Plintarna levereras <strong>färdiggjutna</strong> från fabriken, så det behövs ingen egen gjutning.</p><!-- /wp:paragraph -->']);

        return $ids;
    }

    /**
     * The number Claude gets for a text of the fixture page.
     */
    private function idOf(string $text): int
    {
        $index = array_search($text, array_column(PageText::extract($this->html), 'text'), true);
        $this->assertIsInt($index, "The page has no text “{$text}”.");

        return $index + 1;
    }

    private function finding(string $text, string $source = 'other', string $suggestion = 'Translated'): array
    {
        return ['id' => $this->idOf($text), 'language' => 'Finnish', 'source' => $source, 'suggestion' => $suggestion];
    }

    public function test_the_estimate_counts_what_would_be_sent_without_calling_claude(): void
    {
        $ids = $this->translation();

        $response = $this->request('GET', '/scans/'.$ids['fi'].'/estimate');
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $estimate = $response->get_data();

        $this->assertSame([], $this->claudeRequests);
        $this->assertSame(29, $estimate['texts']);
        $this->assertSame(1, $estimate['calls']);
        $this->assertGreaterThan(1000, $estimate['characters']);
        $this->assertSame((int) ceil($estimate['characters'] / 3), $estimate['tokens']);
        $this->assertSame(TranslationAudit::defaultModel, $estimate['model']);
        $this->assertEqualsWithDelta(TranslationAudit::price(TranslationAudit::defaultModel, $estimate['tokens'], $estimate['outputTokens']), $estimate['price'], 0.000001);
        $this->assertFalse($estimate['preview']);
        $this->assertFalse($estimate['unchanged']);
        // The published page as anyone sees it, not from a cache.
        $this->assertStringContainsString(PageScan::fetchParam.'=1', $this->pageRequests[0]['url']);
        $this->assertSame([], $this->pageRequests[0]['args']['cookies']);
    }

    public function test_prices_are_per_million_tokens_and_an_unknown_model_counts_at_the_dearest(): void
    {
        $this->assertEqualsWithDelta(2.0 + 10.0, TranslationAudit::price('claude-sonnet-5-5', 1_000_000, 1_000_000), 0.0001);
        $this->assertEqualsWithDelta(0.004 + 0.002, TranslationAudit::price('claude-haiku-5-5', 40_000, 4_000), 0.0001);
        $this->assertEqualsWithDelta(TranslationAudit::price('claude-opus-5-5', 1000, 1000), TranslationAudit::price('claude-unknown', 1000, 1000), 0.0001);
    }

    public function test_a_check_is_queued_and_run_in_the_background_and_stored(): void
    {
        $ids = $this->translation();
        Glossary::add(['targetLang' => 'FI', 'source' => 'Deck board', 'target' => 'Terrassbräda']);
        $this->findings = [
            $this->finding('Hae tuotteella, koodilla tai käyttökohteella', 'template', 'Sök på produkt, kod eller användning'),
            // Not a text that was sent: left out.
            ['id' => 999, 'language' => 'Finnish', 'source' => 'other', 'suggestion' => 'x'],
        ];

        $response = $this->request('POST', '/scans/'.$ids['fi']);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertSame('queued', $response->get_data()['status']);
        $this->assertCount(1, $this->backgroundRequests);
        $this->assertSame([], $this->claudeRequests, 'Queuing does not call Claude.');

        PageScan::work();

        $this->assertCount(1, $this->claudeRequests);
        $request = $this->claudeRequests[0];
        $user = json_decode($request['messages'][0]['content'], true);
        $this->assertSame(TranslationAudit::defaultModel, $request['model']);
        $this->assertSame('json_schema', $request['output_config']['format']['type']);
        $this->assertSame('Finnish (fi)', $user['page_language']);
        $this->assertSame('English (en_US)', $user['original_language']);
        $this->assertSame(['Terrassbräda'], $user['allowed_as_they_are']);
        $this->assertCount(29, $user['texts']);
        $this->assertSame(['id' => 1, 'text' => 'Hem - bcepilar', 'where' => 'head'], $user['texts'][0]);

        $data = $this->request('GET', '/scans/'.$ids['fi'])->get_data();
        $this->assertSame('done', $data['status']);
        $this->assertSame('done', $data['result']['status']);
        $this->assertFalse($data['result']['changed']);
        $this->assertSame(29, $data['result']['texts']);
        $this->assertCount(1, $data['result']['findings']);
        $finding = $data['result']['findings'][0];
        $this->assertSame('Hae tuotteella, koodilla tai käyttökohteella', $finding['text']);
        $this->assertSame(['header'], $finding['where']);
        $this->assertSame(['placeholder'], $finding['kinds']);
        $this->assertSame('Sök på produkt, kod eller användning', $finding['suggestion']);
        // Found nowhere on the site: Claude's guess.
        $this->assertSame('template', $finding['source']['type']);
        $this->assertSame('done:1', get_post_meta($ids['fi'], PageScan::statusMetaKey, true));

        // The status screen's cell.
        $rows = $this->rows(['post_type' => 'page'])['rows'];
        $this->assertSame(['status' => 'done', 'found' => 1], $rows[0]['languages']['fi']['scan']);

        // A later edit: the result says the page has changed since.
        wp_update_post(['ID' => $ids['fi'], 'post_content' => '<p>Uusi teksti</p>']);
        $this->assertTrue($this->request('GET', '/scans/'.$ids['fi'])->get_data()['result']['changed']);
    }

    public function test_each_finding_gets_its_likely_source_and_a_link_to_fix_it(): void
    {
        $ids = $this->translation();
        $attachmentId = self::factory()->attachment->create(['post_title' => 'why-b.jpg']);
        update_post_meta($attachmentId, '_wp_attachment_image_alt', 'Kaivinkone tasoittaa sorapohjan');
        $patternId = self::factory()->post->create(['post_type' => 'wp_block', 'post_title' => 'Header button', 'post_content' => '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link">Suunnittele perustus</a></div><!-- /wp:button -->']);
        $menuId = wp_create_nav_menu('Footer');
        wp_update_nav_menu_item($menuId, 0, ['menu-item-title' => 'Ohjeet', 'menu-item-url' => 'https://example.org/ohjeet/', 'menu-item-status' => 'publish']);
        pll_register_string('footer title', 'Sivusto', 'GDS theme');

        $this->findings = [
            $this->finding('Plintarna levereras färdiggjutna från fabriken, så det behövs ingen egen gjutning.'),
            $this->finding('Kaivinkone tasoittaa sorapohjan'),
            $this->finding('Suunnittele perustus'),
            $this->finding('Ohjeet'),
            $this->finding('Sivusto'),
            $this->finding('Avaa valikko', 'script'),
        ];

        $result = (new PageScan)->run($ids['fi'], get_current_user_id());

        $this->assertSame('done', $result['status'], (string) $result['error']);
        $sources = array_column(array_column($result['findings'], 'source'), null, 'type');
        $byText = array_column($result['findings'], 'source', 'text');

        $this->assertSame('content', $byText['Plintarna levereras färdiggjutna från fabriken, så det behövs ingen egen gjutning.']['type']);
        $this->assertSame(get_edit_post_link($ids['fi'], 'raw'), $byText['Plintarna levereras färdiggjutna från fabriken, så det behövs ingen egen gjutning.']['url']);
        $this->assertSame('media', $byText['Kaivinkone tasoittaa sorapohjan']['type']);
        $this->assertSame(admin_url('upload.php?item='.$attachmentId), $byText['Kaivinkone tasoittaa sorapohjan']['url']);
        $this->assertSame('pattern', $byText['Suunnittele perustus']['type']);
        $this->assertStringContainsString('Header button', $byText['Suunnittele perustus']['label']);
        $this->assertSame(get_edit_post_link($patternId, 'raw'), $byText['Suunnittele perustus']['url']);
        $this->assertSame('menu', $byText['Ohjeet']['type']);
        $this->assertSame(admin_url('nav-menus.php?action=edit&menu='.$menuId), $byText['Ohjeet']['url']);
        $this->assertSame('string', $byText['Sivusto']['type']);
        $this->assertStringContainsString('page=mlang_strings', $byText['Sivusto']['url']);
        $this->assertStringContainsString('GDS theme', $byText['Sivusto']['label']);
        // A script's text: Claude's guess, nothing to link.
        $this->assertSame('script', $byText['Avaa valikko']['type']);
        $this->assertSame('', $byText['Avaa valikko']['url']);
        $this->assertCount(6, $sources);
    }

    public function test_a_draft_is_checked_through_its_preview_as_the_editor_who_asked(): void
    {
        $ids = $this->translation('draft');
        $userId = get_current_user_id();
        $sessions = count(WP_Session_Tokens::get_instance($userId)->get_all());

        $estimate = PageScan::estimate($ids['fi'], $userId);

        $this->assertIsArray($estimate);
        $this->assertTrue($estimate['preview']);
        $this->assertStringContainsString('preview=true', $this->pageRequests[0]['url']);
        $cookie = $this->pageRequests[0]['args']['cookies'][0];
        $this->assertInstanceOf(WP_Http_Cookie::class, $cookie);
        $this->assertSame(LOGGED_IN_COOKIE, $cookie->name);
        $this->assertSame(get_userdata($userId)->user_login, wp_parse_auth_cookie($cookie->value, 'logged_in')['username']);
        $this->assertCount($sessions, WP_Session_Tokens::get_instance($userId)->get_all());
    }

    public function test_the_preview_session_ends_after_the_request(): void
    {
        $ids = $this->translation('draft');
        $userId = get_current_user_id();

        PageScan::estimate($ids['fi'], $userId);

        $cookie = $this->pageRequests[0]['args']['cookies'][0];
        $this->assertFalse(wp_validate_auth_cookie($cookie->value, 'logged_in'));
        $this->assertSame([], WP_Session_Tokens::get_instance($userId)->get_all());
    }

    public function test_a_draft_cannot_be_checked_for_someone_who_cannot_edit_it(): void
    {
        $ids = $this->translation('draft');
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);

        $estimate = PageScan::estimate($ids['fi'], $subscriber);

        $this->assertInstanceOf(WP_Error::class, $estimate);
        $this->assertSame([], $this->pageRequests);
    }

    public function test_a_page_that_does_not_load_fails_with_the_reason(): void
    {
        $ids = $this->translation();
        $this->pageCode = 404;

        $result = (new PageScan)->run($ids['fi'], get_current_user_id());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('404', $result['error']);
        $this->assertSame([], $this->claudeRequests);
    }

    public function test_the_original_is_not_checked_and_nothing_runs_on_save(): void
    {
        $ids = $this->translation();

        $this->assertSame(403, $this->request('GET', '/scans/'.$ids['en'].'/estimate')->get_status());
        wp_update_post(['ID' => $ids['fi'], 'post_content' => '<p>Muutettu</p>']);

        $this->assertSame('', get_post_meta($ids['fi'], PageScan::statusMetaKey, true));
        $this->assertSame([], $this->pageRequests);
        $this->assertSame([], $this->claudeRequests);
    }

    public function test_the_bulk_action_estimates_then_queues_one_at_a_time(): void
    {
        $first = $this->translation();
        $second = $this->translation();

        $estimate = $this->action('page-scan', [$first['en'], $second['en']], ['estimate' => true]);

        $this->assertTrue($estimate['ok']);
        $this->assertSame([], $this->claudeRequests);
        $this->assertSame(1, $estimate['results'][0]['pages']);
        $this->assertSame(29, $estimate['results'][0]['texts']);
        $this->assertGreaterThan(0, $estimate['results'][0]['price']);
        $this->assertSame('', get_post_meta($first['fi'], PageScan::statusMetaKey, true));

        $queued = $this->action('page-scan', [$first['en'], $second['en']]);

        $this->assertSame(2, array_sum(array_column($queued['results'], 'queued')));
        $this->assertStringContainsString('2 pages', $queued['message']);
        // One request starts the worker, not one per page.
        $this->assertCount(1, $this->backgroundRequests);

        $again = $this->action('page-scan', [$first['en']]);
        $this->assertSame(1, $again['results'][0]['running']);

        $this->findings = [];
        PageScan::work();

        $this->assertCount(2, $this->claudeRequests);
        $this->assertSame('done:0', get_post_meta($first['fi'], PageScan::statusMetaKey, true));
        $this->assertSame('done:0', get_post_meta($second['fi'], PageScan::statusMetaKey, true));
    }

    public function test_it_needs_the_claude_key(): void
    {
        $ids = $this->translation();
        putenv('ANTHROPIC_API_KEY');

        $this->assertSame(400, $this->request('POST', '/scans/'.$ids['fi'])->get_status());
        $this->assertSame(400, $this->request('POST', '/actions/page-scan', ['ids' => [$ids['en']], 'args' => []])->get_status());
    }
}
