<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\Tests\AdminActionTestCase;
use WPDieException;

/**
 * Trash all, from the status screen's title cell (Admin::handleRowAction):
 * a source post and all its translations, and its Undo.
 */
class RowTrashTest extends AdminActionTestCase
{
    private const trashRowAction = 'gds_ct_trash_row';

    private const restoreRowAction = 'gds_ct_restore_row';

    /** @var array<string, int> */
    private array $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->post = $this->createTranslatedPost(['post_name' => 'row-target', 'post_title' => 'Row target']);
    }

    private function trashRow(int $sourceId): string
    {
        return $this->handleRequest(self::trashRowAction, [
            'post' => (string) $sourceId,
            '_wpnonce' => wp_create_nonce(self::trashRowAction.'_'.$sourceId),
        ]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function undo(array $query): string
    {
        return $this->handleRequest(self::restoreRowAction, $query);
    }

    public function test_it_trashes_the_source_and_its_translations(): void
    {
        $location = $this->trashRow($this->post['en']);

        $this->assertSame('trash', get_post_status($this->post['en']));
        $this->assertSame('trash', get_post_status($this->post['fi']));

        // The row has left the list: no row to come back to.
        $this->assertStringNotContainsString('#', $location);

        $notices = $this->renderNotices();
        $this->assertStringContainsString('notice-success', $notices);
        $this->assertStringContainsString('“Row target” and its 1 translation were moved to the trash.', $notices);
        // Focus goes to the notice, not to a row.
        $this->assertStringContainsString('data-focus-row=""', $notices);

        $query = $this->noticeActionQuery($notices);
        $this->assertSame(self::restoreRowAction, $query['action']);
        $this->assertSame(implode(',', [$this->post['en'], $this->post['fi']]), $query['ids']);
    }

    public function test_undo_restores_every_post_with_its_previous_status(): void
    {
        wp_update_post(['ID' => $this->post['fi'], 'post_status' => 'draft']);

        $this->trashRow($this->post['en']);
        $location = $this->undo($this->noticeActionQuery($this->renderNotices()));

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertSame('draft', get_post_status($this->post['fi']));
        $this->assertStringEndsWith('#gds-ct-row-'.$this->post['en'], $location);

        $notices = $this->renderNotices();
        $this->assertStringContainsString('“Row target” and its 1 translation were restored from the trash.', $notices);
        $this->assertStringContainsString('data-focus-row="gds-ct-row-'.$this->post['en'].'"', $notices);
    }

    /**
     * A translation trashed on its own before stays trashed after Undo.
     */
    public function test_it_leaves_an_already_trashed_translation_alone(): void
    {
        wp_trash_post($this->post['fi']);

        $this->trashRow($this->post['en']);
        $notices = $this->renderNotices();

        $this->assertStringContainsString('“Row target” was moved to the trash.', $notices);

        $query = $this->noticeActionQuery($notices);
        $this->assertSame((string) $this->post['en'], $query['ids']);

        $this->undo($query);

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertSame('trash', get_post_status($this->post['fi']));
    }

    public function test_it_changes_nothing_unless_every_post_may_be_deleted(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        wp_update_post(['ID' => $this->post['en'], 'post_author' => $author]);
        wp_set_current_user($author);

        // The author may delete the source, not the translation someone else
        // published.
        $this->assertTrue(current_user_can('delete_post', $this->post['en']));
        $this->assertFalse(current_user_can('delete_post', $this->post['fi']));

        $location = $this->trashRow($this->post['en']);

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertSame('publish', get_post_status($this->post['fi']));
        $this->assertStringEndsWith('#gds-ct-row-'.$this->post['en'], $location);

        $notices = $this->renderNotices();
        $this->assertStringContainsString('notice-error', $notices);
        $this->assertStringContainsString('nothing was changed', $notices);
    }

    public function test_it_only_takes_a_post_in_the_default_language(): void
    {
        $this->trashRow($this->post['fi']);

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertSame('publish', get_post_status($this->post['fi']));
        $this->assertStringContainsString('Only a post in the default language', $this->renderNotices());
    }

    public function test_it_needs_a_valid_nonce(): void
    {
        $this->expectException(WPDieException::class);

        try {
            $this->handleRequest(self::trashRowAction, [
                'post' => (string) $this->post['en'],
                '_wpnonce' => wp_create_nonce(self::trashRowAction.'_'.$this->post['fi']),
            ]);
        } finally {
            $this->assertSame('publish', get_post_status($this->post['en']));
        }
    }

    /**
     * Undo's nonce covers its list of posts: it cannot be stretched to
     * restore a post Trash all did not trash.
     */
    public function test_undo_cannot_be_widened_to_other_posts(): void
    {
        $other = $this->createPost('fi');
        wp_trash_post($other);

        $this->trashRow($this->post['en']);
        $query = $this->noticeActionQuery($this->renderNotices());
        $query['ids'] .= ','.$other;

        try {
            $this->undo($query);
            $this->fail('A widened Undo was accepted.');
        } catch (WPDieException $die) {
            // check_admin_referer() dies.
        }

        $this->assertSame('trash', get_post_status($other));
        $this->assertSame('trash', get_post_status($this->post['en']));
    }

    /**
     * Even with a valid nonce, Undo only restores posts of this source.
     */
    public function test_undo_ignores_posts_outside_the_translation_group(): void
    {
        $other = $this->createPost('fi');
        wp_trash_post($other);
        wp_trash_post($this->post['en']);

        $ids = [$this->post['en'], $other];
        sort($ids);

        $this->undo([
            'post' => (string) $this->post['en'],
            'ids' => implode(',', $ids),
            '_wpnonce' => wp_create_nonce(self::restoreRowAction.'_'.$this->post['en'].'_'.implode(',', $ids)),
        ]);

        $this->assertSame('publish', get_post_status($this->post['en']));
        $this->assertSame('trash', get_post_status($other));
    }
}
