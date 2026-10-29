<?php

namespace GeneroWP\ContentTranslation\Actions;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One action of the status screen behind
 * POST /gds-content-translation/v1/actions/{id}, run on rows: the
 * default-language posts, with the languages the action is for in the args.
 */
interface Action
{
    public function id(): string;

    /**
     * Once per request, before any post runs.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|WP_Error
     */
    public function sanitizeArgs(array $args): array|WP_Error;

    /**
     * Whether the current user may run it on this post.
     *
     * @param  array<string, mixed>  $args
     */
    public function can(int $sourceId, array $args): bool;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|WP_Error What happened, for the result of this post.
     */
    public function run(int $sourceId, array $args): array|WP_Error;

    /**
     * The notice for the whole request.
     *
     * @param  list<array<string, mixed>>  $results  Per post: `ok`, and what run() returned or `message`.
     * @param  array<string, mixed>  $args
     */
    public function summarize(array $results, array $args): string;
}
