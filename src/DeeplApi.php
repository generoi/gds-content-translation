<?php

namespace GeneroWP\ContentTranslation;

use PLL_Language;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Services\Deepl as DeeplService;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * DeepL, with Polylang Pro's key, for what Polylang Pro does not send it:
 * an SVG's labels and documents. Translate requests go out as JSON to
 * `/v2/translate`, so the glossary is added to them like to Polylang's own
 * ({@see DeeplGlossary::addToRequest()}).
 */
final class DeeplApi
{
    /** DeepL bills each document as at least this many characters. */
    public const documentMinimumCharacters = 50_000;

    /** Euros per million characters on DeepL API Pro, for the estimate. */
    public const defaultPricePerMillion = 20.0;

    private const textsPerRequest = 50;

    public static function isAvailable(): bool
    {
        return DeeplGlossary::apiKey() !== '' && class_exists(DeeplService::class);
    }

    /**
     * Whether asking again later can work: no connection, too many
     * requests, or DeepL's own trouble.
     */
    public static function isTemporary(WP_Error $error): bool
    {
        $code = (int) ($error->get_error_data()['code'] ?? 0);

        return $error->get_error_code() === 'gds_ct_deepl_connection' || $code === 429 || $code >= 500;
    }

    public static function isFreePlan(): bool
    {
        return str_ends_with(DeeplGlossary::apiKey(), ':fx');
    }

    private static function base(): string
    {
        return self::isFreePlan() ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    }

    public static function targetCode(PLL_Language $language): string
    {
        return DeeplService::get_target_code($language);
    }

    public static function sourceCode(PLL_Language $language): string
    {
        return DeeplService::get_source_code($language);
    }

    /**
     * Polylang Pro's formality setting, or the locale's (`de_DE_formal`).
     */
    private static function formality(PLL_Language $language): string
    {
        if (str_ends_with($language->locale, '_formal')) {
            return 'prefer_more';
        }

        if (str_ends_with($language->locale, '_informal')) {
            return 'prefer_less';
        }

        return (string) (PLL()->model->options['machine_translation_services']['deepl']['formality'] ?? 'default');
    }

    /**
     * Translate texts, in order. With `$html`, the ones with tags are sent
     * as HTML, so DeepL keeps their tags.
     *
     * @param  list<string>  $texts
     * @return list<string>|WP_Error
     */
    public static function translate(array $texts, PLL_Language $source, PLL_Language $target, bool $html = false): array|WP_Error
    {
        if ($texts === []) {
            return [];
        }

        $texts = array_values($texts);

        if ($html) {
            $tagged = array_filter($texts, static fn (string $text): bool => $text !== wp_strip_all_tags($text));

            if ($tagged !== []) {
                $plain = array_diff_key($texts, $tagged);
                $results = [];

                foreach ([[$plain, false], [$tagged, true]] as [$group, $asHtml]) {
                    $translated = $group !== [] ? self::requestTranslation(array_values($group), $source, $target, $asHtml) : [];

                    if ($translated instanceof WP_Error) {
                        return $translated;
                    }

                    $results += array_combine(array_keys($group), $translated) ?: [];
                }

                ksort($results);

                return array_values($results);
            }
        }

        return self::requestTranslation($texts, $source, $target, false);
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>|WP_Error
     */
    private static function requestTranslation(array $texts, PLL_Language $source, PLL_Language $target, bool $html): array|WP_Error
    {
        // DeepL takes at most 50 texts a request (a form's long list of choices).
        if (count($texts) > self::textsPerRequest) {
            $translations = [];

            foreach (array_chunk($texts, self::textsPerRequest) as $chunk) {
                $translated = self::requestTranslation($chunk, $source, $target, $html);

                if ($translated instanceof WP_Error) {
                    return $translated;
                }

                $translations = array_merge($translations, $translated);
            }

            return $translations;
        }

        $targetCode = self::targetCode($target);
        $sourceCode = self::sourceCode($source);

        if ($targetCode === '' || $sourceCode === '') {
            return new WP_Error('gds_ct_deepl_language', __('DeepL does not translate between these languages.', 'gds-content-translation'));
        }

        $response = self::request('POST', '/v2/translate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode([
                'text' => array_values($texts),
                'source_lang' => $sourceCode,
                'target_lang' => $targetCode,
                'formality' => self::formality($target),
            ] + ($html ? ['tag_handling' => 'html'] : [])),
        ]);

        if ($response instanceof WP_Error) {
            return $response;
        }

        $translations = array_map(
            static fn ($translation): string => (string) ($translation['text'] ?? ''),
            (array) ($response['translations'] ?? [])
        );

        return count($translations) === count($texts) ? $translations : new WP_Error('gds_ct_deepl_incomplete', __('DeepL did not translate every text.', 'gds-content-translation'));
    }

    /**
     * Characters used and allowed in this billing period; a limit of 0 is
     * unlimited.
     *
     * @return array{count: int, limit: int}|WP_Error
     */
    public static function usage(): array|WP_Error
    {
        $response = self::request('GET', '/v2/usage');

        if ($response instanceof WP_Error) {
            return $response;
        }

        $limit = (int) ($response['character_limit'] ?? 0);

        return [
            'count' => (int) ($response['character_count'] ?? 0),
            'limit' => $limit >= 10 ** 12 ? 0 : $limit,
        ];
    }

    /**
     * Send a document to be translated.
     *
     * @return array{id: string, key: string}|WP_Error
     */
    public static function uploadDocument(string $file, PLL_Language $source, PLL_Language $target): array|WP_Error
    {
        $targetCode = self::targetCode($target);
        $sourceCode = self::sourceCode($source);

        if ($targetCode === '' || $sourceCode === '' || ! is_readable($file)) {
            return new WP_Error('gds_ct_deepl_language', __('DeepL cannot translate this file between these languages.', 'gds-content-translation'));
        }

        $fields = ['source_lang' => $sourceCode, 'target_lang' => $targetCode, 'formality' => self::formality($target)];
        $glossaryId = DeeplGlossary::glossaryFor(Glossary::code($sourceCode), Glossary::code($targetCode));

        if ($glossaryId !== '') {
            $fields['glossary_id'] = $glossaryId;
        }

        $boundary = wp_generate_password(24, false);
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"".str_replace('"', '', wp_basename($file))."\"\r\n";
        $body .= 'Content-Type: '.((string) (wp_check_filetype($file)['type'] ?? '') ?: 'application/octet-stream')."\r\n\r\n";
        $body .= (string) file_get_contents($file)."\r\n--{$boundary}--\r\n";

        $response = self::request('POST', '/v2/document', [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
            'body' => $body,
        ]);

        if ($response instanceof WP_Error) {
            return $response;
        }

        $id = (string) ($response['document_id'] ?? '');
        $key = (string) ($response['document_key'] ?? '');

        return $id !== '' && $key !== '' ? ['id' => $id, 'key' => $key] : new WP_Error('gds_ct_deepl_document', __('DeepL did not accept the file.', 'gds-content-translation'));
    }

    /**
     * @return array{status: string, secondsRemaining: int, billedCharacters: int, error: string}|WP_Error
     */
    public static function documentStatus(string $id, string $key): array|WP_Error
    {
        $response = self::request('POST', '/v2/document/'.rawurlencode($id), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['document_key' => $key]),
        ]);

        if ($response instanceof WP_Error) {
            return $response;
        }

        return [
            'status' => (string) ($response['status'] ?? ''),
            'secondsRemaining' => (int) ($response['seconds_remaining'] ?? 0),
            'billedCharacters' => (int) ($response['billed_characters'] ?? 0),
            'error' => (string) ($response['error_message'] ?? ''),
        ];
    }

    /**
     * The translated document's bytes; DeepL gives them once.
     */
    public static function documentResult(string $id, string $key): string|WP_Error
    {
        return self::request('POST', '/v2/document/'.rawurlencode($id).'/result', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode(['document_key' => $key]),
        ], true);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|string|WP_Error
     */
    private static function request(string $method, string $path, array $args = [], bool $raw = false): array|string|WP_Error
    {
        $key = DeeplGlossary::apiKey();

        if ($key === '') {
            return new WP_Error('gds_ct_deepl_key', __('DeepL is not set up in Polylang’s machine translation settings.', 'gds-content-translation'));
        }

        $response = wp_remote_request(self::base().$path, array_merge($args, [
            'method' => $method,
            'timeout' => 60,
            'headers' => array_merge((array) ($args['headers'] ?? []), ['Authorization' => 'DeepL-Auth-Key '.$key]),
        ]));

        if (is_wp_error($response)) {
            return new WP_Error('gds_ct_deepl_connection', sprintf(
                /* translators: %s: error message */
                __('Could not reach DeepL: %s', 'gds-content-translation'),
                $response->get_error_message()
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code === 456) {
            return new WP_Error('gds_ct_deepl_quota', __('The DeepL quota for this billing period is used up.', 'gds-content-translation'));
        }

        if ($code < 200 || $code >= 300) {
            $decoded = json_decode($body, true);

            return new WP_Error('gds_ct_deepl_api', sprintf(
                /* translators: 1: HTTP status code, 2: DeepL's error message */
                __('DeepL refused the request (%1$d): %2$s', 'gds-content-translation'),
                $code,
                is_array($decoded) ? trim(($decoded['message'] ?? '').' '.($decoded['detail'] ?? '')) : ''
            ), ['code' => $code]);
        }

        if ($raw) {
            return $body;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
