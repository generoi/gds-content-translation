<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Counts for the summary above the status table: per language, how many
 * translations are missing, not proof read, have open AI review errors, or
 * are being reviewed. Each is a filter on the table. Pure: rows in, counts out.
 */
final class StatusSummary
{
    /**
     * @param  list<array{languages: array<string, array{trashed: bool, proofread: bool, audit?: array{status: string, errors: int}|null}>}>  $rows
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $languages
     * @return array{
     *     total: int,
     *     postTypeLabel: string,
     *     defaultLanguageName: string,
     *     missing: list<array{name: string, slug: string, count: int}>,
     *     notProofread: list<array{name: string, slug: string, count: int}>,
     *     aiErrors: list<array{name: string, slug: string, count: int}>,
     *     reviewRunning: list<array{name: string, slug: string, count: int}>
     * }
     */
    public static function build(array $rows, array $languages, string $postTypeLabel): array
    {
        $defaultLanguageName = '';
        $lists = ['missing' => [], 'notProofread' => [], 'aiErrors' => [], 'reviewRunning' => []];
        $kindToList = ['missing' => 'missing', 'unproofread' => 'notProofread', 'errors' => 'aiErrors', 'running' => 'reviewRunning'];

        foreach ($languages as $language) {
            if ($language['isDefault']) {
                $defaultLanguageName = $language['name'];

                continue;
            }

            $counts = array_fill_keys(array_keys($kindToList), 0);

            foreach ($rows as $row) {
                // A trashed translation is not one anyone can read: missing.
                foreach (StatusList::cellKinds($row['languages'][$language['slug']] ?? null) as $kind) {
                    $counts[$kind]++;
                }
            }

            foreach ($kindToList as $kind => $list) {
                // Missing is listed for every language, the rest only when there are some.
                if ($kind === 'missing' || $counts[$kind] > 0) {
                    $lists[$list][] = [
                        'name' => $language['name'],
                        'slug' => $language['slug'],
                        'count' => $counts[$kind],
                    ];
                }
            }
        }

        return [
            'total' => count($rows),
            'postTypeLabel' => $postTypeLabel,
            'defaultLanguageName' => $defaultLanguageName,
        ] + $lists;
    }
}
