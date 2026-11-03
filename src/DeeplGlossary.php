<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The glossary's copy at DeepL, which machine translation uses.
 *
 * DeepL keeps one multilingual glossary (its v3 API) with a dictionary per
 * language pair. A changed glossary is sent again as a whole, within a
 * minute of the change and before the next AI translation: a new glossary
 * is created, then the old one deleted, so a translation running meanwhile
 * always has one.
 *
 * Polylang Pro's DeepL client has no glossary setting and no filter for its
 * request, so the glossary's ID is added to its translate requests on their
 * way out ({@see addToRequest()}). DeepL uses a glossary only when the
 * request names the source language, which Polylang's does for posts.
 */
final class DeeplGlossary
{
    public const optionKey = 'gds_content_translation_deepl_glossary';

    private const cronHook = 'gds_content_translation_sync_glossary';

    /** Seconds after a change before it is sent, so an editing session sends once. */
    private const delay = 60;

    public static function init(): void
    {
        add_filter('http_request_args', [self::class, 'addToRequest'], 10, 2);
        add_action(self::cronHook, [self::class, 'sync']);
    }

    /**
     * @return array{glossaryId: string, hash: string, syncedAt: int, pending: bool, error: string, dictionaries: list<array{sourceLang: string, targetLang: string, entries: int}>}
     */
    public static function state(): array
    {
        $state = get_option(self::optionKey);

        return array_merge([
            'glossaryId' => '',
            'hash' => '',
            'syncedAt' => 0,
            'pending' => false,
            'error' => '',
            'dictionaries' => [],
        ], is_array($state) ? $state : []);
    }

    private static function save(array $state): void
    {
        update_option(self::optionKey, $state, false);
    }

    /**
     * What the glossary screen shows.
     *
     * @return array<string, mixed>
     */
    public static function status(): array
    {
        $state = self::state();

        return [
            'configured' => self::apiKey() !== '',
            'glossaryId' => $state['glossaryId'],
            'syncedAt' => $state['syncedAt'] ? gmdate('c', (int) $state['syncedAt']) : '',
            'pending' => (bool) $state['pending'],
            'error' => $state['error'],
            'dictionaries' => $state['dictionaries'],
        ];
    }

    /** Polylang Pro's DeepL key, when DeepL is its machine translation service. */
    public static function apiKey(): string
    {
        if (! function_exists('PLL') || ! isset(PLL()->model->options)) {
            return '';
        }

        $options = PLL()->model->options;
        $services = $options['machine_translation_services'] ?? [];

        return (string) apply_filters('gds_content_translation_deepl_api_key', (string) ($services['deepl']['api_key'] ?? ''));
    }

    private static function apiBase(string $key): string
    {
        // Free plan keys end in ":fx" and have their own host.
        return str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    }

    /** The glossary changed: send it within a minute. */
    public static function markPending(): void
    {
        $state = self::state();

        if (! $state['pending']) {
            $state['pending'] = true;
            self::save($state);
        }

        if (! wp_next_scheduled(self::cronHook)) {
            wp_schedule_single_event(time() + self::delay, self::cronHook);
        }
    }

    /** Before an AI translation: send a change that has not gone yet. */
    public static function syncIfPending(): void
    {
        if (self::state()['pending']) {
            self::sync();
        }
    }

    /**
     * Send the approved terms to DeepL, unless they are what it has.
     */
    public static function sync(): true|WP_Error
    {
        $key = self::apiKey();
        $state = self::state();

        if ($key === '') {
            // Nothing to send to; the AI review still uses the glossary.
            self::save(array_merge($state, ['pending' => false, 'error' => '']));

            return true;
        }

        $dictionaries = Glossary::dictionaries();
        $hash = md5((string) wp_json_encode($dictionaries));

        if ($hash === $state['hash'] && ($state['glossaryId'] !== '' || $dictionaries === [])) {
            self::save(array_merge($state, ['pending' => false, 'error' => '']));

            return true;
        }

        $newId = '';
        $summary = [];

        if ($dictionaries !== []) {
            $body = ['name' => self::name(), 'dictionaries' => []];

            foreach ($dictionaries as $pair => $terms) {
                [$sourceLang, $targetLang] = explode('-', $pair);
                $lines = [];

                foreach ($terms as $source => $target) {
                    $lines[] = $source."\t".$target;
                }

                $body['dictionaries'][] = [
                    'source_lang' => $sourceLang,
                    'target_lang' => $targetLang,
                    'entries' => implode("\n", $lines),
                    'entries_format' => 'tsv',
                ];
                $summary[] = ['sourceLang' => $sourceLang, 'targetLang' => $targetLang, 'entries' => count($lines)];
            }

            $response = self::request($key, 'POST', '/v3/glossaries', $body);

            if ($response instanceof WP_Error) {
                self::save(array_merge($state, ['pending' => true, 'error' => $response->get_error_message()]));

                return $response;
            }

            $newId = (string) ($response['glossary_id'] ?? '');
        }

        self::save([
            'glossaryId' => $newId,
            'hash' => $hash,
            'syncedAt' => time(),
            'pending' => false,
            'error' => '',
            'dictionaries' => $summary,
        ]);

        if ($state['glossaryId'] !== '' && $state['glossaryId'] !== $newId) {
            // An old copy left behind does no harm; its failure is not the sync's.
            self::request($key, 'DELETE', '/v3/glossaries/'.rawurlencode($state['glossaryId']));
        }

        return true;
    }

    /** One glossary per site, named so it is recognisable in the DeepL account. */
    private static function name(): string
    {
        return sprintf('%s (gds-content-translation)', wp_parse_url(home_url(), PHP_URL_HOST) ?: get_bloginfo('name'));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|WP_Error
     */
    private static function request(string $key, string $method, string $path, ?array $body = null): array|WP_Error
    {
        $response = wp_remote_request(self::apiBase($key).$path, array_filter([
            'method' => $method,
            'headers' => ['Authorization' => 'DeepL-Auth-Key '.$key, 'Content-Type' => 'application/json'],
            'body' => $body !== null ? (string) wp_json_encode($body) : null,
            'timeout' => 60,
        ], static fn ($value): bool => $value !== null));

        if (is_wp_error($response)) {
            return new WP_Error('gds_ct_deepl_connection', sprintf(
                /* translators: %s: error message */
                __('Could not reach DeepL: %s', 'gds-content-translation'),
                $response->get_error_message()
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($decoded) ? trim(($decoded['message'] ?? '').' '.($decoded['detail'] ?? '')) : '';

            return new WP_Error('gds_ct_deepl_api', sprintf(
                /* translators: 1: HTTP status code, 2: DeepL's error message */
                __('DeepL refused the glossary (%1$d): %2$s', 'gds-content-translation'),
                $code,
                $message
            ));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The glossary's ID when it has terms for a language pair (glossary
     * codes, "FI" and "EN"), else ''. Documents name it themselves.
     */
    public static function glossaryFor(string $sourceLang, string $targetLang): string
    {
        $state = self::state();

        foreach ($state['dictionaries'] as $dictionary) {
            if ($dictionary['sourceLang'] === $sourceLang && $dictionary['targetLang'] === $targetLang) {
                return $state['glossaryId'];
            }
        }

        return '';
    }

    /**
     * Add the glossary to Polylang's DeepL translate requests, for the
     * language pairs it has terms for.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function addToRequest(array $args, string $url): array
    {
        if (! preg_match('#^https://api(-free)?\.deepl\.com/v2/translate$#', $url) || ! is_string($args['body'] ?? null)) {
            return $args;
        }

        $state = self::state();

        if ($state['glossaryId'] === '') {
            return $args;
        }

        $body = json_decode($args['body'], true);

        if (! is_array($body) || isset($body['glossary_id']) || empty($body['source_lang']) || empty($body['target_lang'])) {
            return $args;
        }

        // DeepL's target "EN-GB" uses the glossary's "EN" dictionary.
        $sourceLang = Glossary::code((string) $body['source_lang']);
        $targetLang = Glossary::code((string) $body['target_lang']);

        foreach ($state['dictionaries'] as $dictionary) {
            if ($dictionary['sourceLang'] === $sourceLang && $dictionary['targetLang'] === $targetLang) {
                $body['glossary_id'] = $state['glossaryId'];
                $args['body'] = (string) wp_json_encode($body);

                break;
            }
        }

        return $args;
    }
}
