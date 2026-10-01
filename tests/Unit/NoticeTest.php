<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\Notice;
use WP_UnitTestCase;

class NoticeTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function render(): string
    {
        ob_start();
        Notice::render();

        return (string) ob_get_clean();
    }

    public function test_it_renders_a_notice_once(): void
    {
        Notice::flash('success', 'Moved to the trash.');

        $html = $this->render();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Moved to the trash.', $html);

        // A reload does not show it again.
        $this->assertSame('', $this->render());
    }

    public function test_it_renders_nothing_without_a_notice(): void
    {
        $this->assertSame('', $this->render());
    }

    /**
     * A second action, say in another tab, must not replace the first notice.
     */
    public function test_it_keeps_every_notice_in_order(): void
    {
        Notice::flash('success', 'First.');
        Notice::flash('error', 'Second.');

        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'data-gds-ct-notice'));
        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('notice-error', $html);
        $this->assertLessThan(strpos($html, 'Second.'), strpos($html, 'First.'));
    }

    public function test_it_keeps_only_the_latest_notices(): void
    {
        foreach (range(1, 7) as $number) {
            Notice::flash('info', "Notice {$number}.");
        }

        $html = $this->render();

        $this->assertSame(5, substr_count($html, 'data-gds-ct-notice'));
        $this->assertStringNotContainsString('Notice 2.', $html);
        $this->assertStringContainsString('Notice 3.', $html);
        $this->assertStringContainsString('Notice 7.', $html);
    }

    public function test_an_unknown_type_is_an_error(): void
    {
        Notice::flash('<script>', 'Something.');

        $html = $this->render();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_it_escapes_the_message_and_the_action(): void
    {
        Notice::flash('success', 'Moved “<b>Title</b>”.', 'javascript:alert(1)', '<i>Undo</i>');
        $html = $this->render();

        $this->assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;i&gt;Undo&lt;/i&gt;', $html);
    }

    public function test_it_renders_the_action_link(): void
    {
        Notice::flash('success', 'Moved.', 'https://example.org/wp-admin/admin-post.php?action=undo&post=1', 'Undo');

        $html = $this->render();

        $this->assertStringContainsString('class="gds-content-translation__notice-action"', $html);
        $this->assertStringContainsString('href="https://example.org/wp-admin/admin-post.php?action=undo&#038;post=1"', $html);
        $this->assertStringContainsString('>Undo</a>', $html);
    }

    public function test_it_omits_an_action_without_a_label(): void
    {
        Notice::flash('success', 'Moved.', 'https://example.org/');

        $this->assertStringNotContainsString('notice-action', $this->render());
    }

    /**
     * The status screen moves focus to the notice, or to the cell it names.
     */
    public function test_it_is_a_focusable_status_naming_its_cell(): void
    {
        Notice::flash('success', 'Restored.', '', '', 'gds-ct-row-12', 'fi');

        $html = $this->render();

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('data-focus-row="gds-ct-row-12"', $html);
        $this->assertStringContainsString('data-focus-lang="fi"', $html);
    }

    public function test_notices_belong_to_the_user_who_caused_them(): void
    {
        Notice::flash('success', 'Mine.');

        $owner = get_current_user_id();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->assertSame('', $this->render());

        wp_set_current_user($owner);

        $this->assertStringContainsString('Mine.', $this->render());
    }

    /**
     * A notice flashed by the previous version, one slot per user, still shows
     * after an update.
     */
    public function test_it_reads_a_notice_stored_as_a_single_slot(): void
    {
        set_transient('gds_content_translation_notice_'.get_current_user_id(), [
            'type' => 'success',
            'message' => 'From before the update.',
            'actionUrl' => '',
            'actionLabel' => '',
        ], MINUTE_IN_SECONDS);

        $html = $this->render();

        $this->assertSame(1, substr_count($html, 'data-gds-ct-notice'));
        $this->assertStringContainsString('From before the update.', $html);
    }
}
