<?php

namespace GeneroWP\ContentTranslation\Rest;

use GeneroWP\ContentTranslation\Actions\Action;
use GeneroWP\ContentTranslation\Actions\Proofread;
use GeneroWP\ContentTranslation\Actions\Review;
use GeneroWP\ContentTranslation\Actions\Translate;
use GeneroWP\ContentTranslation\Actions\TrashRow;
use GeneroWP\ContentTranslation\Actions\TrashTranslation;
use GeneroWP\ContentTranslation\Admin;
use GeneroWP\ContentTranslation\AuditReview;
use GeneroWP\ContentTranslation\BulkTranslation;
use GeneroWP\ContentTranslation\StatusList;
use GeneroWP\ContentTranslation\TranslationActions;
use GeneroWP\ContentTranslation\TranslationAudit;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The status screen's REST API, under gds-content-translation/v1:
 *
 * - GET  /rows: one page of rows of a post type, searched, filtered and
 *   sorted, with the summary of the whole type.
 * - POST /actions/{action} {ids, args}: an action on rows (default-language
 *   post IDs), with a result per row and a notice for the whole request.
 * - GET  /jobs, DELETE /jobs: the bulk AI translation queue.
 * - GET  /reviews/{id}: the AI review page's table of remarks.
 * - POST /reviews/{id}/remarks {operation, remarks, text}: use suggestions,
 *   save an edit, mark remarks fixed or not an issue, or reopen them.
 *
 * Every route needs the capability of the screen; each action also checks
 * the posts it changes.
 */
final class StatusController
{
    public const namespace = 'gds-content-translation/v1';

    /** Rows per action request; the screen sends a larger selection in chunks. */
    public const maxIds = 100;

    public static function init(): void
    {
        add_action('rest_api_init', [new self, 'register']);
    }

    public static function permission(): bool
    {
        return current_user_can('manage_options');
    }

    public function register(): void
    {
        register_rest_route(self::namespace, '/rows', [
            'methods' => 'GET',
            'callback' => [$this, 'rows'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'post_type' => ['type' => 'string', 'required' => true],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => StatusList::perPageMax],
                'search' => ['type' => 'string', 'default' => ''],
                'orderby' => ['type' => 'string', 'enum' => StatusList::orderbys, 'default' => 'title'],
                'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                'filters' => [
                    'type' => 'object',
                    'default' => [],
                    'description' => 'Language slug => list of: '.implode(', ', StatusList::filterKinds).'.',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => StatusList::filterKinds]],
                ],
            ],
        ]);

        register_rest_route(self::namespace, '/actions/(?P<action>[a-z-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'action'],
            'permission_callback' => [self::class, 'permission'],
            'args' => [
                'ids' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => self::maxIds],
                'args' => ['type' => 'object', 'default' => []],
            ],
        ]);

        $canEditPost = static fn (WP_REST_Request $request): bool => self::permission() && current_user_can('edit_post', (int) $request['id']);

        register_rest_route(self::namespace, '/reviews/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'review'],
            'permission_callback' => $canEditPost,
        ]);

        register_rest_route(self::namespace, '/reviews/(?P<id>\\d+)/remarks', [
            'methods' => 'POST',
            'callback' => [$this, 'changeRemarks'],
            'permission_callback' => $canEditPost,
            'args' => [
                'operation' => ['type' => 'string', 'required' => true, 'enum' => ['use', 'edit', 'fixed', 'dismiss', 'reopen']],
                'remarks' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => self::maxIds],
                'text' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::namespace, '/jobs', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'jobs'],
                'permission_callback' => [self::class, 'permission'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'clearJobs'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'which' => ['type' => 'string', 'enum' => ['queued', 'finished'], 'default' => 'finished'],
                ],
            ],
        ]);
    }

    public function rows(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postType = sanitize_key((string) $request['post_type']);

        if (! isset(StatusList::postTypes()[$postType])) {
            return new WP_Error('gds_ct_invalid_post_type', __('This post type is not shown on the status screen.', 'gds-content-translation'), ['status' => 400]);
        }

        // The admin bar link opens the type last looked at.
        Admin::savePostType($postType);

        $result = StatusList::query($postType, [
            'search' => (string) $request['search'],
            'filters' => (array) $request['filters'],
            'orderby' => (string) $request['orderby'],
            'order' => (string) $request['order'],
            'page' => (int) $request['page'],
            'perPage' => (int) $request['per_page'],
        ]);

        $response = rest_ensure_response($result);
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) $result['totalPages']);

        return $response;
    }

    public function review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request['id'];

        if (! TranslationAudit::get($postId)) {
            return new WP_Error('gds_ct_no_review', __('There is no AI review for this post.', 'gds-content-translation'), ['status' => 404]);
        }

        return rest_ensure_response(AuditReview::remarkRows($postId));
    }

    /**
     * One operation on some remarks, each on its own: a suggestion that can
     * no longer be used fails alone. Answers with a result per remark and the
     * table as it is now.
     */
    public function changeRemarks(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $postId = (int) $request['id'];

        if (! TranslationAudit::get($postId)) {
            return new WP_Error('gds_ct_no_review', __('There is no AI review for this post.', 'gds-content-translation'), ['status' => 404]);
        }

        $results = [];

        foreach (array_unique(array_map('intval', (array) $request['remarks'])) as $remarkId) {
            $result = AuditReview::changeRemark($postId, $remarkId, (string) $request['operation'], (string) $request['text']);
            $results[] = [
                'id' => $remarkId,
                'ok' => $result === true,
                'message' => $result instanceof WP_Error ? $result->get_error_message() : '',
            ];
        }

        return rest_ensure_response(['results' => $results] + AuditReview::remarkRows($postId));
    }

    /**
     * The actions by ID.
     *
     * @return array<string, Action>
     */
    public static function actions(): array
    {
        $actions = [new Proofread, new Review, new Translate, new TrashRow, new TrashRow(true)];

        foreach (TrashTranslation::operations as $operation) {
            $actions[] = new TrashTranslation($operation);
        }

        $byId = [];

        foreach ($actions as $action) {
            $byId[$action->id()] = $action;
        }

        return $byId;
    }

    public function action(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $action = self::actions()[(string) $request['action']] ?? null;

        if ($action === null) {
            return new WP_Error('gds_ct_unknown_action', __('Unknown action.', 'gds-content-translation'), ['status' => 404]);
        }

        $args = $action->sanitizeArgs(is_array($request['args']) ? $request['args'] : []);

        if ($args instanceof WP_Error) {
            $args->add_data(array_merge(['status' => 400], (array) $args->get_error_data()));

            return $args;
        }

        $results = [];

        foreach (array_values(array_unique(array_map('intval', (array) $request['ids']))) as $sourceId) {
            $results[] = $this->runOne($action, $sourceId, $args);
        }

        $failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));

        return rest_ensure_response([
            'ok' => $failed === [],
            'message' => $action->summarize($results, $args),
            'results' => $results,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function runOne(Action $action, int $sourceId, array $args): array
    {
        $fail = static fn (string $code, string $message, array $data = []): array => array_merge($data, ['id' => $sourceId, 'ok' => false, 'code' => $code, 'message' => $message]);
        $post = $sourceId > 0 ? get_post($sourceId) : null;

        if (! $post) {
            return $fail('gds_ct_not_found', __('That post no longer exists. It may have been deleted in another tab.', 'gds-content-translation'));
        }

        if (! pll_is_translated_post_type($post->post_type) || pll_get_post_language($sourceId) !== pll_default_language('slug')) {
            return $fail('gds_ct_not_default', __('Only a post in the default language can be changed here.', 'gds-content-translation'));
        }

        $title = TranslationActions::title($sourceId);

        if (! $action->can($sourceId, $args)) {
            /* translators: %s: post title. */
            return $fail('gds_ct_forbidden', sprintf(__('You are not allowed to change “%s”.', 'gds-content-translation'), $title));
        }

        try {
            $data = $action->run($sourceId, $args);
        } catch (Throwable $e) {
            error_log(sprintf('[gds-content-translation] The %s action on post %d failed: %s', $action->id(), $sourceId, $e));
            $data = new WP_Error('gds_ct_exception', $e->getMessage());
        }

        if ($data instanceof WP_Error) {
            $errorData = $data->get_error_data();

            return $fail((string) $data->get_error_code(), $data->get_error_message(), is_array($errorData) ? $errorData : []);
        }

        return array_merge(['title' => $title], $data, ['id' => $sourceId, 'ok' => true]);
    }

    /**
     * The bulk AI translation queue, with each job's post title and, once
     * done, links to the translation and its review. Starts a worker when the
     * queue has stood still: the background request did not get through.
     */
    public function jobs(): WP_REST_Response
    {
        if (BulkTranslation::isStalled()) {
            BulkTranslation::spawn(true);
        }

        $jobs = [];

        foreach (BulkTranslation::all() as $job) {
            $jobs[] = [
                'sourceId' => $job['sourceId'],
                'language' => $job['language'],
                'status' => $job['status'],
                'error' => $job['error'],
                'queuedAt' => $job['queuedAt'],
                'finishedAt' => $job['finishedAt'],
                'mine' => $job['userId'] === get_current_user_id(),
                'title' => TranslationActions::title($job['sourceId']),
                'translationId' => $job['translationId'],
                'editUrl' => $job['translationId'] > 0 ? (string) get_edit_post_link($job['translationId'], 'raw') : '',
                'reviewUrl' => $job['translationId'] > 0 ? AuditReview::getUrl($job['translationId']) : '',
            ];
        }

        return rest_ensure_response(['jobs' => $jobs]);
    }

    public function clearJobs(WP_REST_Request $request): WP_REST_Response
    {
        $count = $request['which'] === 'queued' ? BulkTranslation::cancel() : BulkTranslation::clearFinished();

        return rest_ensure_response(['cleared' => $count]);
    }
}
