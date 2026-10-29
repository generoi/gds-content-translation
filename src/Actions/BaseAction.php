<?php

namespace GeneroWP\ContentTranslation\Actions;

use GeneroWP\ContentTranslation\StatusList;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What most actions share: the languages they are for, and finding the
 * translation of a row in one of them.
 */
abstract class BaseAction implements Action
{
    public function sanitizeArgs(array $args): array|WP_Error
    {
        $languages = $this->languages($args);

        if ($languages instanceof WP_Error) {
            return $languages;
        }

        return ['languages' => $languages];
    }

    public function can(int $sourceId, array $args): bool
    {
        return current_user_can('edit_post', $sourceId);
    }

    /**
     * The translation languages asked for: all of them when none are given.
     *
     * @param  array<string, mixed>  $args
     * @return list<string>|WP_Error
     */
    protected function languages(array $args): array|WP_Error
    {
        $all = StatusList::translationLanguageSlugs();
        $asked = array_values(array_unique(array_map('sanitize_key', array_filter((array) ($args['languages'] ?? []), 'is_string'))));

        if ($asked === []) {
            return $all;
        }

        if (array_diff($asked, $all) !== []) {
            return new WP_Error('gds_ct_invalid_language', __('Unknown language.', 'gds-content-translation'), ['status' => 400]);
        }

        return $asked;
    }

    /**
     * The translation of a row in a language, trashed or not. 0 when there is none.
     */
    protected function translationId(int $sourceId, string $language): int
    {
        $translationId = (int) (pll_get_post_translations($sourceId)[$language] ?? 0);

        return $translationId !== $sourceId ? $translationId : 0;
    }

    /**
     * Translations that exist and are not in the trash, by language.
     *
     * @param  list<string>  $languages
     * @return array<string, int>
     */
    protected function liveTranslations(int $sourceId, array $languages): array
    {
        $live = [];

        foreach ($languages as $language) {
            $translationId = $this->translationId($sourceId, $language);

            if ($translationId > 0 && ! in_array(get_post_status($translationId), [false, 'trash'], true)) {
                $live[$language] = $translationId;
            }
        }

        return $live;
    }

    /**
     * The sum of one number over the results that succeeded.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected static function total(array $results, string $key): int
    {
        return (int) array_sum(array_map(static fn (array $result): int => (int) ($result[$key] ?? 0), $results));
    }

    /**
     * The message of the only result, or of the first failure.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected static function singleMessage(array $results): ?string
    {
        if (count($results) === 1) {
            return (string) ($results[0]['message'] ?? '');
        }

        return null;
    }
}
