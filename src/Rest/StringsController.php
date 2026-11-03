<?php

namespace GeneroWP\ContentTranslation\Rest;

use GeneroWP\ContentTranslation\ImageAlt;
use GeneroWP\ContentTranslation\StringTranslation;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The String translations screen's REST API, under gds-content-translation/v1:
 *
 * - GET  /strings/{lang}: Polylang's strings and URL slugs in a language,
 *   with how many each language is missing.
 * - POST /strings/{lang} {translations, slugs}: save texts (by ID) and slugs
 *   (by key) as Polylang's Strings translations screen does.
 * - POST /strings/{lang}/translate {ids}: DeepL translates and saves texts
 *   without a translation.
 * - POST /strings/{lang}/slugs {keys}: DeepL's suggestions for slugs, not saved.
 * - POST /strings/{lang}/review: the AI review checks the translations.
 * - POST /strings/{lang}/remark {id, use}: use a remark or dismiss it.
 * - GET  /alts/{lang}: images with their alt text in a language, a page at a
 *   time; POST {alts}: save some; POST /alts/{lang}/translate {ids}: DeepL.
 */
final class StringsController
{
    public static function init(): void
    {
        add_action('rest_api_init', [new self, 'register']);
    }

    public function register(): void
    {
        $permission = [StatusController::class, 'permission'];
        $route = '/strings/(?P<lang>[a-z0-9_-]+)';
        $lang = ['lang' => ['type' => 'string']];

        register_rest_route(StatusController::namespace, $route, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rows'],
                'permission_callback' => $permission,
                'args' => $lang,
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save'],
                'permission_callback' => $permission,
                'args' => $lang + [
                    'translations' => ['type' => 'object', 'default' => [], 'additionalProperties' => ['type' => 'string']],
                    'slugs' => ['type' => 'object', 'default' => [], 'additionalProperties' => ['type' => 'string']],
                ],
            ],
        ]);

        register_rest_route(StatusController::namespace, $route.'/translate', [
            'methods' => 'POST',
            'callback' => [$this, 'translate'],
            'permission_callback' => $permission,
            'args' => $lang + ['ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []]],
        ]);

        register_rest_route(StatusController::namespace, $route.'/slugs', [
            'methods' => 'POST',
            'callback' => [$this, 'suggestSlugs'],
            'permission_callback' => $permission,
            'args' => $lang + ['keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []]],
        ]);

        register_rest_route(StatusController::namespace, $route.'/review', [
            'methods' => 'POST',
            'callback' => [$this, 'review'],
            'permission_callback' => $permission,
            'args' => $lang,
        ]);

        register_rest_route(StatusController::namespace, $route.'/remark', [
            'methods' => 'POST',
            'callback' => [$this, 'remark'],
            'permission_callback' => $permission,
            'args' => $lang + ['id' => ['type' => 'string', 'required' => true], 'use' => ['type' => 'boolean', 'default' => false]],
        ]);

        $altsRoute = '/alts/(?P<lang>[a-z0-9_-]+)';

        register_rest_route(StatusController::namespace, $altsRoute, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'alts'],
                'permission_callback' => $permission,
                'args' => $lang + [
                    'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => ImageAlt::perPageMax],
                    'search' => ['type' => 'string', 'default' => ''],
                    'missing' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveAlts'],
                'permission_callback' => $permission,
                'args' => $lang + ['alts' => ['type' => 'object', 'required' => true, 'additionalProperties' => ['type' => 'string']]],
            ],
        ]);

        register_rest_route(StatusController::namespace, $altsRoute.'/translate', [
            'methods' => 'POST',
            'callback' => [$this, 'translateAlts'],
            'permission_callback' => $permission,
            'args' => $lang + ['ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []]],
        ]);
    }

    /**
     * @param  int|WP_Error  $changed  What the request changed, as the screen tells it.
     */
    private function respond(string $slug, int|WP_Error $changed = 0): WP_REST_Response|WP_Error
    {
        if ($changed instanceof WP_Error) {
            return new WP_Error($changed->get_error_code(), $changed->get_error_message(), ['status' => (int) ($changed->get_error_data()['status'] ?? 400)]);
        }

        $rows = StringTranslation::rows($slug);

        return $rows instanceof WP_Error ? $rows : rest_ensure_response($rows + ['changed' => $changed]);
    }

    public function rows(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respond((string) $request['lang']);
    }

    public function save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = (string) $request['lang'];
        $texts = StringTranslation::save($slug, array_map('strval', (array) $request['translations']));

        if ($texts instanceof WP_Error) {
            return $this->respond($slug, $texts);
        }

        $slugs = StringTranslation::saveSlugs($slug, array_map('strval', (array) $request['slugs']));

        return $this->respond($slug, $slugs instanceof WP_Error ? $slugs : $texts + $slugs);
    }

    public function translate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = (string) $request['lang'];

        return $this->respond($slug, StringTranslation::translateMissing($slug, array_map('strval', (array) $request['ids'])));
    }

    public function suggestSlugs(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $suggestions = StringTranslation::suggestSlugs((string) $request['lang'], array_map('strval', (array) $request['keys']));

        if ($suggestions instanceof WP_Error) {
            return new WP_Error($suggestions->get_error_code(), $suggestions->get_error_message(), ['status' => 400]);
        }

        // An object, also when empty.
        return rest_ensure_response(['suggestions' => (object) $suggestions]);
    }

    public function review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = (string) $request['lang'];

        return $this->respond($slug, StringTranslation::review($slug));
    }

    public function remark(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = (string) $request['lang'];
        $id = (string) $request['id'];
        $result = $request['use'] ? StringTranslation::useRemark($slug, $id) : StringTranslation::settleRemark($slug, $id);

        return $this->respond($slug, $result instanceof WP_Error ? $result : 1);
    }

    public function alts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $rows = ImageAlt::rows((string) $request['lang'], [
            'search' => (string) $request['search'],
            'missing' => (bool) $request['missing'],
            'page' => (int) $request['page'],
            'perPage' => (int) $request['per_page'],
        ]);

        return $rows instanceof WP_Error ? $rows : rest_ensure_response($rows);
    }

    public function saveAlts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->altsResponse(ImageAlt::save((string) $request['lang'], array_map('strval', (array) $request['alts'])));
    }

    public function translateAlts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->altsResponse(ImageAlt::translate((string) $request['lang'], array_map('intval', (array) $request['ids'])));
    }

    /**
     * @param  array<int, string>|WP_Error  $alts
     */
    private function altsResponse(array|WP_Error $alts): WP_REST_Response|WP_Error
    {
        if ($alts instanceof WP_Error) {
            return new WP_Error($alts->get_error_code(), $alts->get_error_message(), ['status' => (int) ($alts->get_error_data()['status'] ?? 400)]);
        }

        return rest_ensure_response(['alts' => (object) $alts, 'changed' => count($alts), 'missing' => ImageAlt::missingCounts()]);
    }
}
