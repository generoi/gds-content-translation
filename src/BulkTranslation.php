<?php

namespace GeneroWP\ContentTranslation;

use Throwable;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * AI translation of many posts at once, in the background, one at a time.
 *
 * A machine translation takes seconds to minutes, so a request that created
 * fifty of them would time out. Each selected post and language becomes a
 * job instead, stored as a meta of the original (`_gds_ct_job_<language>`),
 * which is also how the status screen shows "Queued" and "Translating…" in
 * the cell. A worker works through the jobs oldest first: started by a
 * non-blocking request to admin-ajax.php as the AI review is, it runs for a
 * short while, then hands over to a fresh request until the queue is empty.
 * A WP-Cron event is the fallback when that request cannot reach the site,
 * and the status screen's progress poll starts a worker too when the queue
 * has stood still for a while.
 *
 * Each job runs as the user who queued it, through the same
 * {@see MachineTranslation::create()} as the status screen's AI translation
 * link: the same checks, the same per-post and language lock, and the AI
 * review queued after it when that is on.
 */
final class BulkTranslation
{
    private const metaPrefix = '_gds_ct_job_';

    private const ajaxAction = 'gds_ct_run_bulk';

    private const cronHook = 'gds_content_translation_run_bulk';

    /** Option row that says a worker is running, holding when it last moved on. */
    private const workerOption = 'gds_ct_bulk_worker';

    /** When a worker was last started, so a poll does not start one per request. */
    private const spawnedOption = 'gds_ct_bulk_spawned';

    /** Seconds without progress after which a worker or a job counts as dead. */
    private const staleAfter = 300;

    /** Seconds a queued job may wait before the progress poll starts a worker itself. */
    private const stallAfter = 45;

    /** Tries of a job that finds its translation being created elsewhere, or whose worker died. */
    private const maxAttempts = 3;

    /** Finished jobs are forgotten after this long. */
    private const keepFinished = DAY_IN_SECONDS;

    public static function init(): void
    {
        $bulk = new self;
        add_action('wp_ajax_'.self::ajaxAction, [$bulk, 'handleBackgroundRequest']);
        add_action('wp_ajax_nopriv_'.self::ajaxAction, [$bulk, 'handleBackgroundRequest']);
        add_action(self::cronHook, [self::class, 'work']);
    }

    /**
     * Queue AI translations and start the worker.
     *
     * A post that has the translation already (not in the trash), or a job
     * for it that is queued or running, is skipped.
     *
     * @param  list<int>  $sourceIds  Default-language posts.
     * @param  list<string>  $languages
     * @return array<int, array<string, string>> Per post and language: "queued" or why it was skipped.
     */
    public static function enqueue(array $sourceIds, array $languages, string $mode = MachineTranslation::modeAi): array
    {
        $batch = wp_generate_password(12, false);
        $results = [];
        $queued = 0;

        foreach ($sourceIds as $sourceId) {
            $translations = pll_get_post_translations($sourceId);

            foreach ($languages as $language) {
                $existing = (int) ($translations[$language] ?? 0);
                $job = self::job($sourceId, $language);

                if ($existing && ! in_array(get_post_status($existing), [false, 'trash'], true)) {
                    $results[$sourceId][$language] = 'exists';
                } elseif ($job && in_array($job['status'], ['queued', 'running'], true)) {
                    $results[$sourceId][$language] = $job['status'];
                } else {
                    self::save($sourceId, $language, [
                        'status' => 'queued',
                        'mode' => $mode,
                        'batch' => $batch,
                        'userId' => get_current_user_id(),
                        'queuedAt' => time(),
                        'attempts' => 0,
                    ]);
                    $results[$sourceId][$language] = 'queued';
                    $queued++;
                }
            }
        }

        if ($queued > 0) {
            self::schedule();
            self::spawn();
        }

        return $results;
    }

    /**
     * Forget the jobs that have not started yet.
     *
     * @return int How many were cancelled.
     */
    public static function cancel(): int
    {
        $cancelled = 0;

        foreach (self::all() as $job) {
            if ($job['status'] === 'queued') {
                delete_post_meta($job['sourceId'], self::metaPrefix.$job['language']);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Forget finished jobs (done, skipped, failed), so their results leave
     * the progress panel and the cells.
     *
     * @param  list<int>  $sourceIds  Only these posts' jobs; all when empty.
     */
    public static function clearFinished(array $sourceIds = []): int
    {
        $cleared = 0;

        foreach (self::all() as $job) {
            if (in_array($job['status'], ['queued', 'running'], true) || ($sourceIds !== [] && ! in_array($job['sourceId'], $sourceIds, true))) {
                continue;
            }

            delete_post_meta($job['sourceId'], self::metaPrefix.$job['language']);
            $cleared++;
        }

        return $cleared;
    }

    /**
     * Every job, oldest first, with the title of its post. Finished jobs
     * older than a day are forgotten on the way.
     *
     * @return list<array{sourceId: int, language: string, status: string, mode: string, batch: string, userId: int, queuedAt: int, startedAt: int, finishedAt: int, attempts: int, error: string, translationId: int}>
     */
    public static function all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            $wpdb->esc_like(self::metaPrefix).'%'
        ));
        $jobs = [];

        foreach ($rows as $row) {
            $job = self::normalize((int) $row->post_id, substr((string) $row->meta_key, strlen(self::metaPrefix)), maybe_unserialize($row->meta_value));

            if ($job === null) {
                continue;
            }

            if ($job['finishedAt'] > 0 && time() - $job['finishedAt'] > self::keepFinished) {
                delete_post_meta($job['sourceId'], (string) $row->meta_key);

                continue;
            }

            $jobs[] = $job;
        }

        usort($jobs, static fn (array $a, array $b): int => [$a['queuedAt'], $a['sourceId'], $a['language']] <=> [$b['queuedAt'], $b['sourceId'], $b['language']]);

        return $jobs;
    }

    /**
     * Jobs of some posts, for their cells.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, array<string, array{status: string, error: string, queuedAt: int}>>
     */
    public static function jobsBySource(array $sourceIds): array
    {
        $wanted = array_fill_keys($sourceIds, true);
        $bySource = [];

        foreach (self::all() as $job) {
            if (isset($wanted[$job['sourceId']])) {
                $bySource[$job['sourceId']][$job['language']] = [
                    'status' => $job['status'],
                    'error' => $job['error'],
                    'queuedAt' => $job['queuedAt'],
                ];
            }
        }

        return $bySource;
    }

    /**
     * @return array{sourceId: int, language: string, status: string, mode: string, batch: string, userId: int, queuedAt: int, startedAt: int, finishedAt: int, attempts: int, error: string, translationId: int}|null
     */
    public static function job(int $sourceId, string $language): ?array
    {
        return self::normalize($sourceId, $language, get_post_meta($sourceId, self::metaPrefix.$language, true));
    }

    /**
     * Whether jobs wait while no worker runs, for longer than a worker takes
     * to start: the loopback request did not get through.
     */
    public static function isStalled(): bool
    {
        if (self::workerAlive()) {
            return false;
        }

        foreach (self::all() as $job) {
            if ($job['status'] === 'queued' && time() - $job['queuedAt'] > self::stallAfter) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start a worker in the background, unless one is running or was
     * started a moment ago.
     *
     * @param  bool  $force  For the worker handing over to the next one.
     */
    public static function spawn(bool $force = false): void
    {
        if (! $force && (self::workerAlive() || time() - (int) get_option(self::spawnedOption, 0) < 10)) {
            return;
        }

        update_option(self::spawnedOption, time(), false);
        $time = (string) time();

        wp_remote_post(admin_url('admin-ajax.php'), [
            'timeout' => 0.01,
            'blocking' => false,
            // Same as WordPress' own cron spawn: the site may not trust its own certificate.
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => [
                'action' => self::ajaxAction,
                'time' => $time,
                'token' => self::token($time),
            ],
        ]);
    }

    /**
     * The background request spawn() sends. Needs no login: the token, made
     * with the site's secret salt and valid for ten minutes, is the
     * authorisation. All it can do is work through the queue sooner.
     */
    public function handleBackgroundRequest(): void
    {
        $time = isset($_POST['time']) && is_string($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
        $token = isset($_POST['token']) && is_string($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if ($time === '' || $token === '' || abs(time() - (int) $time) > 10 * MINUTE_IN_SECONDS || ! hash_equals(self::token($time), $token)) {
            wp_die('', '', 403);
        }

        ignore_user_abort(true);
        self::work();

        wp_die();
    }

    /**
     * Work through queued jobs for a while, one at a time. Hands over to a
     * fresh request when there are more, so no request runs into the PHP
     * time limit.
     *
     * @param  float|null  $budget  Seconds to keep taking new jobs; a job
     *                              started before then is always finished.
     * @param  callable|null  $create  Creates one translation; tests replace
     *                                 the machine translation with it.
     */
    public static function work(?float $budget = null, ?callable $create = null): void
    {
        if (! self::acquireWorker()) {
            return;
        }

        $budget ??= (float) apply_filters('gds_content_translation_bulk_budget', 20.0);
        $deadline = microtime(true) + $budget;
        $more = false;
        $ran = 0;

        try {
            while (true) {
                $job = self::next();

                if ($job === null) {
                    break;
                }

                // At least one job per worker, so the queue always moves.
                if ($ran > 0 && microtime(true) >= $deadline) {
                    $more = true;
                    break;
                }

                self::run($job, $create);
                self::touchWorker();
                $ran++;
            }
        } finally {
            self::releaseWorker();
        }

        if ($more) {
            self::schedule();
            self::spawn(true);
        }
    }

    /**
     * The oldest queued job. A job left "running" by a worker that died is
     * put back in the queue (creating it again is safe: a translation that
     * was made after all is found and the job skipped), or failed after a
     * few tries.
     *
     * @return array<string, mixed>|null
     */
    private static function next(): ?array
    {
        foreach (self::all() as $job) {
            if ($job['status'] === 'running' && time() - $job['startedAt'] > self::staleAfter) {
                $retry = $job['attempts'] < self::maxAttempts;
                self::save($job['sourceId'], $job['language'], array_merge($job, $retry
                    ? ['status' => 'queued']
                    : ['status' => 'failed', 'finishedAt' => time(), 'error' => __('The translation did not finish.', 'gds-content-translation')]));

                if ($retry) {
                    return array_merge($job, ['status' => 'queued']);
                }
            }

            if ($job['status'] === 'queued') {
                return $job;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private static function run(array $job, ?callable $create): void
    {
        $previousUser = get_current_user_id();
        $job['attempts']++;

        self::save($job['sourceId'], $job['language'], array_merge($job, ['status' => 'running', 'startedAt' => time()]));

        if (function_exists('set_time_limit')) {
            set_time_limit(5 * MINUTE_IN_SECONDS);
        }

        wp_set_current_user($job['userId']);
        $warnings = [];

        try {
            $result = $create
                ? $create($job['sourceId'], $job['language'], $job['mode'])
                : MachineTranslation::create($job['sourceId'], $job['language'], $job['mode'], $warnings);
        } catch (Throwable $e) {
            error_log(sprintf('[gds-content-translation] Bulk translation of post %d into %s failed: %s', $job['sourceId'], $job['language'], $e));
            $result = new WP_Error('gds_ct_exception', $e->getMessage());
        } finally {
            wp_set_current_user($previousUser);
        }

        if ($result instanceof WP_Error && $result->get_error_code() === 'gds_ct_locked' && $job['attempts'] < self::maxAttempts) {
            // Being created from the status screen right now: try again later.
            self::save($job['sourceId'], $job['language'], array_merge($job, ['status' => 'queued', 'queuedAt' => time()]));

            return;
        }

        if ($result instanceof WP_Error) {
            self::save($job['sourceId'], $job['language'], array_merge($job, [
                // Someone created it meanwhile: nothing left to do.
                'status' => $result->get_error_code() === 'gds_ct_exists' ? 'skipped' : 'failed',
                'finishedAt' => time(),
                'error' => $result->get_error_message(),
            ]));

            return;
        }

        self::save($job['sourceId'], $job['language'], array_merge($job, [
            'status' => 'done',
            'finishedAt' => time(),
            'translationId' => (int) $result,
            'error' => implode(' ', $warnings),
        ]));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private static function save(int $sourceId, string $language, array $job): void
    {
        unset($job['sourceId'], $job['language']);
        update_post_meta($sourceId, self::metaPrefix.$language, $job);
    }

    /**
     * @return array{sourceId: int, language: string, status: string, mode: string, batch: string, userId: int, queuedAt: int, startedAt: int, finishedAt: int, attempts: int, error: string, translationId: int}|null
     */
    private static function normalize(int $sourceId, string $language, mixed $stored): ?array
    {
        if (! is_array($stored) || ! in_array($stored['status'] ?? '', ['queued', 'running', 'done', 'skipped', 'failed'], true)) {
            return null;
        }

        return [
            'sourceId' => $sourceId,
            'language' => sanitize_key($language),
            'status' => $stored['status'],
            'mode' => ($stored['mode'] ?? '') === MachineTranslation::modeCopy ? MachineTranslation::modeCopy : MachineTranslation::modeAi,
            'batch' => (string) ($stored['batch'] ?? ''),
            'userId' => (int) ($stored['userId'] ?? 0),
            'queuedAt' => (int) ($stored['queuedAt'] ?? 0),
            'startedAt' => (int) ($stored['startedAt'] ?? 0),
            'finishedAt' => (int) ($stored['finishedAt'] ?? 0),
            'attempts' => (int) ($stored['attempts'] ?? 0),
            'error' => (string) ($stored['error'] ?? ''),
            'translationId' => (int) ($stored['translationId'] ?? 0),
        ];
    }

    private static function schedule(): void
    {
        if (! wp_next_scheduled(self::cronHook)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::cronHook);
        }
    }

    private static function token(string $time): string
    {
        return wp_hash(self::ajaxAction.'|'.$time);
    }

    /**
     * One worker at a time: the INSERT either creates the row or fails on
     * the unique option name. A worker that has not moved on for a while
     * died; its row is taken over.
     */
    private static function acquireWorker(): bool
    {
        global $wpdb;

        $insert = static fn () => (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::workerOption,
            (string) time()
        ));

        if ($insert() === 1) {
            return true;
        }

        $touchedAt = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::workerOption));

        if ($touchedAt > 0 && time() - $touchedAt < self::staleAfter) {
            return false;
        }

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::workerOption, (string) $touchedAt));

        return $insert() === 1;
    }

    private static function touchWorker(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) time(), self::workerOption));
    }

    private static function releaseWorker(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::workerOption));
    }

    private static function workerAlive(): bool
    {
        global $wpdb;

        $touchedAt = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::workerOption));

        return $touchedAt > 0 && time() - $touchedAt < self::staleAfter;
    }
}
