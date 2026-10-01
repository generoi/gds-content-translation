<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Tests\AdminActionTestCase;
use WPDieException;

/**
 * Delete permanently, from the status screen (Admin::handleTranslationAction),
 * and that the per-translation actions still never touch a source post.
 */
class TranslationActionTest extends AdminActionTestCase
{
    private const deleteAction = 'gds_ct_delete_translation';

    private const trashAction = 'gds_ct_trash_translation';

    /** @var array<string, int> */
    private array $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = $this->createTranslatedPost(['post_name' => 'action-target', 'post_title' => 'Action target']);
    }

    private function handle(string $action, int $postId, ?string $nonce = null): string
    {
        return $this->handleRequest($action, [
            'post' => (string) $postId,
            '_wpnonce' => $nonce ?? wp_create_nonce($action.'_'.$postId),
        ]);
    }

    public function test_it_deletes_a_trashed_translation_permanently(): void
    {
        wp_trash_post($this->post['fi']);

        $location = $this->handle(self::deleteAction, $this->post['fi']);

        $this->assertNull(get_post($this->post['fi']));
        $this->assertSame($this->post['en'], get_post($this->post['en'])->ID);

        // Back to the row it was deleted from.
        $this->assertStringContainsString('page=mlang_content_translation_status', $location);
        $this->assertStringEndsWith('#gds-ct-row-'.$this->post['en'], $location);

        $notice = $this->renderNotices();
        $this->assertStringContainsString('notice-success', $notice);
        $this->assertStringContainsString('was deleted permanently', $notice);
        $this->assertStringContainsString('Action target', $notice);
        $this->assertStringContainsString('data-focus-row="gds-ct-row-'.$this->post['en'].'"', $notice);
        $this->assertStringContainsString('data-focus-lang="fi"', $notice);
        // Nothing to undo.
        $this->assertStringNotContainsString('notice-action', $notice);
    }

    public function test_it_does_not_delete_a_translation_that_is_not_in_the_trash(): void
    {
        $this->handle(self::deleteAction, $this->post['fi']);

        $this->assertSame('publish', get_post_status($this->post['fi']));

        $notice = $this->renderNotices();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('is not in the trash', $notice);
    }

    public function test_it_never_deletes_the_source_language_post(): void
    {
        wp_trash_post($this->post['en']);

        $this->handle(self::deleteAction, $this->post['en']);

        $this->assertSame('trash', get_post_status($this->post['en']));

        $notice = $this->renderNotices();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('Only translations', $notice);
    }

    public function test_it_reports_a_post_that_no_longer_exists(): void
    {
        wp_delete_post($this->post['fi'], true);

        $location = $this->handle(self::deleteAction, $this->post['fi']);

        $this->assertStringNotContainsString('#', $location);
        $this->assertStringContainsString('no longer exists', $this->renderNotices());
    }

    public function test_it_needs_a_valid_nonce(): void
    {
        wp_trash_post($this->post['fi']);

        try {
            $this->handle(self::deleteAction, $this->post['fi'], wp_create_nonce(self::deleteAction.'_'.$this->post['en']));
            $this->fail('An invalid nonce was accepted.');
        } catch (WPDieException $die) {
            // check_admin_referer() dies.
        }

        $this->assertSame('trash', get_post_status($this->post['fi']));
    }

    public function test_it_needs_permission_to_delete_the_post(): void
    {
        wp_trash_post($this->post['fi']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        try {
            $this->handle(self::deleteAction, $this->post['fi']);
            $this->fail('A subscriber deleted a translation.');
        } catch (WPDieException $die) {
            $this->assertStringContainsString('not allowed', $die->getMessage());
        }

        $this->assertSame('trash', get_post_status($this->post['fi']));
    }

    public function test_trash_never_trashes_the_source_language_post(): void
    {
        $this->handle(self::trashAction, $this->post['en']);

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertStringContainsString('Only translations', $this->renderNotices());
    }
}
