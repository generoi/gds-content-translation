<?php

namespace GeneroWP\ContentTranslation\Rest;

use GeneroWP\ContentTranslation\Admin;
use GeneroWP\ContentTranslation\DeeplGlossary;
use GeneroWP\ContentTranslation\Glossary;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The glossary screen's REST API, under gds-content-translation/v1:
 *
 * - GET  /glossary: one page of terms, searched, filtered and sorted, with
 *   the counts per status, the approved terms per language and DeepL's copy.
 * - POST /glossary {targetLang, source, target, note, status}: add a term.
 * - POST /glossary/{id} {…}: change one.
 * - POST /glossary/bulk {ids, operation}: approve, reject, draft or delete.
 * - POST /glossary/import (multipart: file, mode, target_lang): a CSV.
 * - GET|POST /glossary/sync: DeepL's copy; POST sends the glossary now.
 *
 * The CSV export is a download, so it is an admin-post link
 * ({@see Admin::exportGlossary()}).
 */
final class GlossaryController
{
    public static function init(): void
    {
        add_action('rest_api_init', [new self, 'register']);
    }

    public function register(): void
    {
        $permission = [StatusController::class, 'permission'];
        $termArgs = [
            'targetLang' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'target' => ['type' => 'string'],
            'note' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => Glossary::statuses],
        ];

        register_rest_route(StatusController::namespace, '/glossary', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'list'],
                'permission_callback' => $permission,
                'args' => [
                    'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => Glossary::perPageMax],
                    'search' => ['type' => 'string', 'default' => ''],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Glossary::statuses], 'default' => []],
                    'origin' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Glossary::origins], 'default' => []],
                    'target_lang' => ['type' => 'string', 'default' => ''],
                    'conflicts' => ['type' => 'boolean', 'default' => false],
                    'orderby' => ['type' => 'string', 'enum' => Glossary::orderbys, 'default' => 'source'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => $permission,
                'args' => $termArgs,
            ],
        ]);

        register_rest_route(StatusController::namespace, '/glossary/(?P<id>\\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update'],
            'permission_callback' => $permission,
            'args' => $termArgs,
        ]);

        register_rest_route(StatusController::namespace, '/glossary/bulk', [
            'methods' => 'POST',
            'callback' => [$this, 'bulk'],
            'permission_callback' => $permission,
            'args' => [
                'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'required' => true, 'maxItems' => Glossary::perPageMax * 10],
                'operation' => ['type' => 'string', 'enum' => ['approve', 'reject', 'draft', 'delete'], 'required' => true],
            ],
        ]);

        register_rest_route(StatusController::namespace, '/glossary/import', [
            'methods' => 'POST',
            'callback' => [$this, 'import'],
            'permission_callback' => $permission,
            'args' => [
                'mode' => ['type' => 'string', 'enum' => ['merge', 'replace'], 'default' => 'merge'],
                'target_lang' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(StatusController::namespace, '/glossary/sync', [
            [
                'methods' => 'GET',
                'callback' => static fn (): WP_REST_Response => rest_ensure_response(DeeplGlossary::status()),
                'permission_callback' => $permission,
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'sync'],
                'permission_callback' => $permission,
            ],
        ]);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $result = Glossary::query([
            'page' => (int) $request['page'],
            'perPage' => (int) $request['per_page'],
            'search' => (string) $request['search'],
            'status' => (array) $request['status'],
            'origin' => (array) $request['origin'],
            'targetLang' => (string) $request['target_lang'],
            'conflicts' => (bool) $request['conflicts'],
            'orderby' => (string) $request['orderby'],
            'order' => (string) $request['order'],
        ]);

        $response = rest_ensure_response($result + [
            'counts' => Glossary::counts(),
            'pairs' => Glossary::pairs(),
            'sync' => DeeplGlossary::status(),
        ]);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['totalPages']);

        return $response;
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = Glossary::add([
            'targetLang' => (string) $request['targetLang'],
            'source' => (string) $request['source'],
            'target' => (string) $request['target'],
            'note' => (string) $request['note'],
            'status' => (string) ($request['status'] ?: 'approved'),
            'origin' => 'manual',
        ]);

        return is_wp_error($id) ? self::withStatus($id) : rest_ensure_response(self::item($id));
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request['id'];
        $changes = array_filter([
            'targetLang' => $request['targetLang'],
            'source' => $request['source'],
            'target' => $request['target'],
            'note' => $request['note'],
            'status' => $request['status'],
        ], static fn ($value): bool => $value !== null);

        $result = Glossary::update($id, $changes);

        return is_wp_error($result) ? self::withStatus($result) : rest_ensure_response(self::item($id));
    }

    public function bulk(WP_REST_Request $request): WP_REST_Response
    {
        $operation = (string) $request['operation'];
        $changed = Glossary::bulk(array_map('intval', (array) $request['ids']), $operation);

        $message = match ($operation) {
            /* translators: %d: number of terms */
            'approve' => _n('%d term is now in use.', '%d terms are now in use.', $changed, 'gds-content-translation'),
            /* translators: %d: number of terms */
            'reject' => _n('%d term rejected.', '%d terms rejected.', $changed, 'gds-content-translation'),
            /* translators: %d: number of terms */
            'draft' => _n('%d term moved to drafts.', '%d terms moved to drafts.', $changed, 'gds-content-translation'),
            /* translators: %d: number of terms */
            default => _n('%d term deleted.', '%d terms deleted.', $changed, 'gds-content-translation'),
        };

        return rest_ensure_response(['changed' => $changed, 'message' => sprintf($message, $changed)]);
    }

    public function import(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $file = $request->get_file_params()['file'] ?? null;

        if (! is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name'])) {
            return new WP_Error('gds_ct_glossary_file', __('Choose a CSV file to import.', 'gds-content-translation'), ['status' => 400]);
        }

        $result = Glossary::import((string) $file['tmp_name'], (string) $request['mode'], (string) $request['target_lang']);

        if (is_wp_error($result)) {
            return self::withStatus($result);
        }

        $message = sprintf(
            /* translators: 1: terms added, 2: terms updated, 3: terms that were the same already */
            __('Imported: %1$s added, %2$s updated, %3$s the same already.', 'gds-content-translation'),
            number_format_i18n($result['added']),
            number_format_i18n($result['updated']),
            number_format_i18n($result['unchanged'])
        );

        if ($result['skipped'] > 0) {
            /* translators: %s: number of lines */
            $message .= ' '.sprintf(_n('%s line skipped: no term, translation or language.', '%s lines skipped: no term, translation or language.', $result['skipped'], 'gds-content-translation'), number_format_i18n($result['skipped']));
        }

        return rest_ensure_response($result + ['message' => $message]);
    }

    public function sync(): WP_REST_Response|WP_Error
    {
        $result = DeeplGlossary::sync();

        if (is_wp_error($result)) {
            return self::withStatus($result, 502);
        }

        return rest_ensure_response(DeeplGlossary::status());
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(int $id): array
    {
        return Glossary::getItem($id) ?? [];
    }

    private static function withStatus(WP_Error $error, int $status = 400): WP_Error
    {
        $data = $error->get_error_data();

        if (! is_array($data) || ! isset($data['status'])) {
            $error->add_data(['status' => $status]);
        }

        return $error;
    }
}
