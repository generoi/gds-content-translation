<?php

namespace GeneroWP\ContentTranslation\Tests;

use GeneroWP\ContentTranslation\Notice;

/**
 * Runs the status screen's admin-post handlers as admin-post.php does, with
 * the redirect turned into an exception so the handler stops before `exit`.
 */
abstract class AdminActionTestCase extends PolylangTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wp_redirect', [$this, 'stopRedirect']);
    }

    protected function tearDown(): void
    {
        remove_filter('wp_redirect', [$this, 'stopRedirect']);
        $_GET = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    public function stopRedirect(string $location): string
    {
        throw new RedirectException($location);
    }

    /**
     * @param  array<string, string>  $query  Including `_wpnonce`.
     * @return string Where the handler redirected to.
     */
    protected function handleRequest(string $action, array $query): string
    {
        $_GET = array_merge(['action' => $action, 'post_type' => 'post'], $query);
        $_REQUEST = $_GET;

        try {
            do_action('admin_post_'.$action);
        } catch (RedirectException $redirect) {
            return $redirect->location;
        }

        $this->fail('The handler did not redirect.');
    }

    protected function renderNotices(): string
    {
        ob_start();
        Notice::render();

        return (string) ob_get_clean();
    }

    /**
     * The query arguments of the first notice's action link (Undo).
     *
     * @return array<string, string>
     */
    protected function noticeActionQuery(string $notices): array
    {
        $this->assertSame(1, preg_match('/class="gds-content-translation__notice-action" href="([^"]+)"/', $notices, $match), 'The notice has no action link.');

        parse_str((string) wp_parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $query);

        return $query;
    }
}
