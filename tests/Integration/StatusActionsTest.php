<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Tests\RestTestCase;
use GeneroWP\ContentTranslation\TranslationAudit;
use PLL_Translation_Walker_Factory;

/**
 * POST /actions/{action}: the status screen's cell, row and bulk actions,
 * with the same checks as the admin-post links.
 */
class StatusActionsTest extends RestTestCase
{
    /** @var list<string> */
    private array $backgroundUrls = [];

    protected function setUp(): void
    {
        parent::setUp();

        add_filter('pre_http_request', [$this, 'fakeHttp'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fakeHttp'], 10);
        putenv('ANTHROPIC_API_KEY');

        parent::tearDown();
    }

    /**
     * @param  false|array<string, mixed>  $pre
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function fakeHttp($pre, array $args, string $url): array
    {
        $this->backgroundUrls[] = $url;

        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    public function test_bulk_proof_read_marks_the_existing_translations_of_every_row(): void
    {
        $first = $this->createTranslatedPost(['post_title' => 'First']);
        $second = $this->createTranslatedPost(['post_title' => 'Second']);
        $untranslated = $this->createPost('en', ['post_title' => 'Untranslated']);

        $data = $this->action('proofread', [$first['en'], $second['en'], $untranslated], ['proofread' => true]);

        $this->assertTrue($data['ok']);
        $this->assertSame('2 translations marked as proof read.', $data['message']);
        $this->assertSame('1', get_post_meta($first['fi'], GDS_CONTENT_TRANSLATION_META_KEY, true));
        $this->assertSame('1', get_post_meta($second['fi'], GDS_CONTENT_TRANSLATION_META_KEY, true));
        // Never the original.
        $this->assertSame('', get_post_meta($first['en'], GDS_CONTENT_TRANSLATION_META_KEY, true));

        $this->action('proofread', [$first['en']], ['languages' => ['fi'], 'proofread' => false]);
        $this->assertSame('', get_post_meta($first['fi'], GDS_CONTENT_TRANSLATION_META_KEY, true));
    }

    public function test_it_takes_only_default_language_rows_and_known_languages(): void
    {
        $ids = $this->createTranslatedPost();

        $data = $this->action('proofread', [$ids['fi'], 999999]);
        $this->assertFalse($data['ok']);
        $this->assertSame(['gds_ct_not_default', 'gds_ct_not_found'], array_column($data['results'], 'code'));

        $response = $this->request('POST', '/actions/proofread', ['ids' => [$ids['en']], 'args' => ['languages' => ['xx']]]);
        $this->assertSame(400, $response->get_status());

        $this->assertSame(404, $this->request('POST', '/actions/nope', ['ids' => [$ids['en']]])->get_status());
    }

    public function test_trash_and_restore_one_translation_never_the_original(): void
    {
        $ids = $this->createTranslatedPost(['post_title' => 'Cell']);
        wp_update_post(['ID' => $ids['fi'], 'post_status' => 'publish']);

        $trashed = $this->action('trash-translation', [$ids['en']], ['languages' => ['fi']]);
        $this->assertSame('The Finnish translation of “Cell” was moved to the trash.', $trashed['message']);
        $this->assertSame('trash', get_post_status($ids['fi']));
        $this->assertSame('publish', get_post_status($ids['en']));

        $restored = $this->action('restore-translation', [$ids['en']], ['languages' => ['fi']]);
        $this->assertTrue($restored['ok']);
        $this->assertSame('publish', get_post_status($ids['fi']), 'Back with the status it had.');

        // Delete permanently only from the trash.
        $refused = $this->action('delete-translation', [$ids['en']], ['languages' => ['fi']]);
        $this->assertFalse($refused['ok']);
        $this->assertNotNull(get_post($ids['fi']));

        wp_trash_post($ids['fi']);
        $this->assertTrue($this->action('delete-translation', [$ids['en']], ['languages' => ['fi']])['ok']);
        $this->assertNull(get_post($ids['fi']));

        // A cell action names one language.
        $this->assertSame(400, $this->request('POST', '/actions/trash-translation', ['ids' => [$ids['en']]])->get_status());
    }

    public function test_bulk_trash_takes_whole_rows_and_its_undo_restores_exactly_them(): void
    {
        $first = $this->createTranslatedPost(['post_title' => 'One']);
        $second = $this->createTranslatedPost(['post_title' => 'Two']);
        $other = $this->createTranslatedPost(['post_title' => 'Other']);
        wp_trash_post($other['fi']);

        $data = $this->action('trash', [$first['en'], $second['en']]);

        $this->assertSame('2 posts and 2 translations were moved to the trash.', $data['message']);
        $posts = array_column($data['results'], 'changed', 'id');
        $this->assertSame([$first['en'], $first['fi']], $posts[$first['en']]);

        foreach (array_merge($first, $second) as $postId) {
            $this->assertSame('trash', get_post_status($postId));
        }

        // A post outside a row's translations cannot be slipped into its Undo.
        $posts[$first['en']][] = $other['fi'];
        $undo = $this->action('restore', [$first['en'], $second['en']], ['posts' => $posts]);

        $this->assertTrue($undo['ok']);
        $this->assertSame('2 posts and 2 translations were restored from the trash.', $undo['message']);

        foreach (array_merge($first, $second) as $postId) {
            $this->assertNotSame('trash', get_post_status($postId));
        }

        $this->assertSame('trash', get_post_status($other['fi']));
    }

    public function test_capabilities_are_checked_per_post(): void
    {
        $ids = $this->createTranslatedPost(['post_title' => 'Someone else’s']);
        $mine = $this->createTranslatedPost(['post_title' => 'Mine']);
        $admin = get_current_user_id();
        $author = self::factory()->user->create(['role' => 'author']);
        $author = get_user_by('id', $author);
        // The screen's capability, but only an author's rights to posts.
        $author->add_cap('manage_options');
        wp_set_current_user($author->ID);

        foreach ($mine as $postId) {
            wp_update_post(['ID' => $postId, 'post_author' => $author->ID]);
        }

        $data = $this->action('trash', [$ids['en'], $mine['en']]);

        $this->assertFalse($data['ok']);
        $this->assertSame('gds_ct_forbidden', $data['results'][0]['code']);
        $this->assertNotSame('trash', get_post_status($ids['en']));
        $this->assertNotSame('trash', get_post_status($ids['fi']));
        $this->assertTrue($data['results'][1]['ok']);
        $this->assertSame('trash', get_post_status($mine['fi']));

        wp_set_current_user($admin);
    }

    public function test_bulk_review_queues_the_ai_review_of_each_translation(): void
    {
        if (! class_exists(PLL_Translation_Walker_Factory::class)) {
            $this->markTestSkipped('Polylang Pro is not available.');
        }

        $first = $this->createTranslatedPost(['post_title' => 'First']);
        $second = $this->createTranslatedPost(['post_title' => 'Second']);

        // Off without a key: refused before anything runs.
        $this->assertSame(400, $this->request('POST', '/actions/review', ['ids' => [$first['en']]])->get_status());

        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        add_filter('gds_content_translation_audit_parallel', static fn (): int => 1);

        $data = $this->action('review', [$first['en'], $second['en']]);

        $this->assertSame('The AI review of 2 translations is queued.', $data['message']);
        $this->assertSame('pending', TranslationAudit::get($first['fi'])['status']);
        // One at a time: the second waits for the first, and still shows as running.
        $this->assertSame('queued', TranslationAudit::get($second['fi'])['status']);
        $this->assertSame('pending', TranslationAudit::statuses([$second['fi']])[$second['fi']]['status']);
        $this->assertCount(1, array_filter($this->backgroundUrls, static fn (string $url): bool => str_contains($url, 'admin-ajax.php')));

        // Asked again while it runs: not queued twice.
        $again = $this->action('review', [$first['en']]);
        $this->assertStringContainsString('1 is already being reviewed.', $again['message']);
    }
}
