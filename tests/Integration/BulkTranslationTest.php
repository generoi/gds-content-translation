<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\BulkTranslation;
use GeneroWP\ContentTranslation\MachineTranslation;
use GeneroWP\ContentTranslation\Tests\RestTestCase;
use WP_Error;
use WPDieException;

/**
 * Bulk AI translation: the queue (BulkTranslation), the worker that works
 * through it in the background, and the translate action and /jobs routes
 * the status screen uses. The machine translation itself is replaced: the
 * worker is given a creator that links a new post as the translation.
 */
class BulkTranslationTest extends RestTestCase
{
    /** @var list<array<string, mixed>> */
    private array $backgroundRequests = [];

    /** @var list<array{0: int, 1: string, 2: int}> Source, language, user. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        $_POST = [];

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function fakeHttp($pre, array $args, string $url): array
    {
        if (str_contains($url, 'admin-ajax.php')) {
            $this->backgroundRequests[] = $args;
        }

        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * Creates the translation as a linked post, as MachineTranslation::create() would.
     */
    public function fakeCreate(int $sourceId, string $language): int|WP_Error
    {
        $this->created[] = [$sourceId, $language, get_current_user_id()];

        if (get_the_title($sourceId) === 'Fails') {
            return new WP_Error('gds_ct_failed', 'Translation failed: DeepL quota exceeded');
        }

        $translationId = $this->createPost($language, ['post_status' => 'draft']);
        pll_save_post_translations(array_merge(pll_get_post_translations($sourceId), [$language => $translationId]));

        return $translationId;
    }

    public function test_it_queues_the_missing_translations_and_skips_the_rest(): void
    {
        $missing = $this->createPost('en', ['post_title' => 'Missing']);
        $translated = $this->createTranslatedPost(['post_title' => 'Translated']);
        $trashed = $this->createTranslatedPost(['post_title' => 'Trashed']);
        wp_trash_post($trashed['fi']);

        $outcome = BulkTranslation::enqueue([$missing, $translated['en'], $trashed['en']], ['fi']);

        $this->assertSame('queued', $outcome[$missing]['fi']);
        $this->assertSame('exists', $outcome[$translated['en']]['fi']);
        // A translation in the trash is replaced, as the cell's own AI translation does.
        $this->assertSame('queued', $outcome[$trashed['en']]['fi']);

        // Queued once: asking again does not queue it twice.
        $this->assertSame('queued', BulkTranslation::enqueue([$missing], ['fi'])[$missing]['fi']);
        $this->assertCount(2, BulkTranslation::all());

        // The worker was started in the background, with a token, and the cron fallback scheduled.
        $this->assertNotEmpty($this->backgroundRequests);
        $this->assertFalse($this->backgroundRequests[0]['blocking']);
        $this->assertSame('gds_ct_run_bulk', $this->backgroundRequests[0]['body']['action']);
        $this->assertNotFalse(wp_next_scheduled('gds_content_translation_run_bulk'));
    }

    public function test_the_worker_translates_one_at_a_time_as_the_user_who_queued_them(): void
    {
        $first = $this->createPost('en', ['post_title' => 'First']);
        $second = $this->createPost('en', ['post_title' => 'Fails']);
        $user = get_current_user_id();

        BulkTranslation::enqueue([$first, $second], ['fi']);
        wp_set_current_user(0);

        BulkTranslation::work(60.0, [$this, 'fakeCreate']);

        $this->assertSame([[$first, 'fi', $user], [$second, 'fi', $user]], $this->created);
        $this->assertSame(0, get_current_user_id(), 'The worker puts the user back.');

        $done = BulkTranslation::job($first, 'fi');
        $this->assertSame('done', $done['status']);
        $this->assertSame((int) pll_get_post($first, 'fi'), $done['translationId']);

        $failed = BulkTranslation::job($second, 'fi');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('Translation failed: DeepL quota exceeded', $failed['error']);
    }

    public function test_a_worker_that_runs_out_of_time_hands_over_to_the_next(): void
    {
        $first = $this->createPost('en', ['post_title' => 'First']);
        $second = $this->createPost('en', ['post_title' => 'Second']);
        BulkTranslation::enqueue([$first, $second], ['fi']);
        $this->backgroundRequests = [];

        // No time to take a new job after the first.
        BulkTranslation::work(0.0, [$this, 'fakeCreate']);

        $this->assertCount(1, $this->created);
        $this->assertSame('queued', BulkTranslation::job($second, 'fi')['status']);
        $this->assertCount(1, $this->backgroundRequests, 'The next worker is started.');

        BulkTranslation::work(60.0, [$this, 'fakeCreate']);
        $this->assertSame('done', BulkTranslation::job($second, 'fi')['status']);
    }

    public function test_a_translation_being_created_elsewhere_is_tried_again_later(): void
    {
        $postId = $this->createPost('en');
        BulkTranslation::enqueue([$postId], ['fi']);

        BulkTranslation::work(0.0, static fn (): WP_Error => new WP_Error('gds_ct_locked', 'Locked'));
        $this->assertSame('queued', BulkTranslation::job($postId, 'fi')['status']);

        BulkTranslation::work(0.0, static fn (): WP_Error => new WP_Error('gds_ct_exists', 'Translation already exists.'));
        $this->assertSame('skipped', BulkTranslation::job($postId, 'fi')['status']);
    }

    public function test_only_one_worker_runs_at_a_time(): void
    {
        $postId = $this->createPost('en');
        BulkTranslation::enqueue([$postId], ['fi']);

        add_option('gds_ct_bulk_worker', (string) time(), '', 'no');
        BulkTranslation::work(60.0, [$this, 'fakeCreate']);
        $this->assertSame([], $this->created);

        // A worker that stopped moving on long ago died: its place is taken over.
        update_option('gds_ct_bulk_worker', (string) (time() - HOUR_IN_SECONDS));
        wp_cache_delete('alloptions', 'options');
        BulkTranslation::work(60.0, [$this, 'fakeCreate']);
        $this->assertCount(1, $this->created);
    }

    public function test_the_background_request_needs_its_token(): void
    {
        $postId = $this->createPost('en');
        BulkTranslation::enqueue([$postId], ['fi']);
        $body = $this->backgroundRequests[0]['body'];

        $_POST = ['action' => 'gds_ct_run_bulk', 'time' => $body['time'], 'token' => 'wrong'];

        try {
            do_action('wp_ajax_nopriv_gds_ct_run_bulk');
            $this->fail('A wrong token was accepted.');
        } catch (WPDieException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('queued', BulkTranslation::job($postId, 'fi')['status']);
    }

    public function test_the_translate_action_and_the_jobs_routes(): void
    {
        $postId = $this->createPost('en', ['post_title' => 'Queued']);
        $available = MachineTranslation::isAvailable();
        $response = $this->request('POST', '/actions/translate', ['ids' => [$postId], 'args' => ['languages' => ['fi']]]);

        if (! $available) {
            // No machine translation service in the test site: refused up front.
            $this->assertSame(400, $response->get_status());
            BulkTranslation::enqueue([$postId], ['fi']);
        } else {
            $this->assertSame(200, $response->get_status());
            $this->assertSame(1, $response->get_data()['results'][0]['queued']);
        }

        $jobs = $this->request('GET', '/jobs')->get_data()['jobs'];
        $this->assertSame([[$postId, 'fi', 'queued', 'Queued', true]], array_map(static fn (array $job): array => [$job['sourceId'], $job['language'], $job['status'], $job['title'], $job['mine']], $jobs));

        // The cell shows it.
        $this->assertSame('queued', $this->rows()['rows'][0]['languages']['fi']['job']['status']);

        $this->assertSame(1, $this->request('DELETE', '/jobs', ['which' => 'queued'])->get_data()['cleared']);
        $this->assertSame([], BulkTranslation::all());
    }
}
