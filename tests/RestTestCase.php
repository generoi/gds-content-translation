<?php

namespace GeneroWP\ContentTranslation\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Runs requests against the status screen's REST API as an administrator.
 */
abstract class RestTestCase extends PolylangTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init', $wp_rest_server);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/gds-content-translation/v1'.$route);

        if ($method === 'GET' || $method === 'DELETE') {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }

        return rest_get_server()->dispatch($request);
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    protected function action(string $action, array $ids, array $args = []): array
    {
        $response = $this->request('POST', '/actions/'.$action, ['ids' => $ids, 'args' => $args]);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function rows(array $params = []): array
    {
        $response = $this->request('GET', '/rows', array_merge(['post_type' => 'post'], $params));
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        return $response->get_data();
    }
}
