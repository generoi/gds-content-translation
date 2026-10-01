<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Counts for the summary box above the status table. Pure: rows in, counts out.
 */
final class StatusSummary
{
    /**
     * @param  list<array{languages: array<string, array{trashed: bool, proofread: bool}>}>  $rows
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $languages
     * @return array{
     *     total: int,
     *     postTypeLabel: string,
     *     defaultLanguageName: string,
     *     missing: list<array{name: string, slug: string, count: int}>,
     *     notProofread: list<array{name: string, slug: string, count: int}>
     * }
     */
    public static function build(array $rows, array $languages, string $postTypeLabel): array
    {
        $defaultLanguageName = '';
        $missing = [];
        $notProofread = [];

        foreach ($languages as $language) {
            if ($language['isDefault']) {
                $defaultLanguageName = $language['name'];

                continue;
            }

            $missingCount = 0;
            $notProofreadCount = 0;

            foreach ($rows as $row) {
                $cell = $row['languages'][$language['slug']] ?? null;

                // A trashed translation is not one anyone can read.
                if ($cell === null || $cell['trashed']) {
                    $missingCount++;

                    continue;
                }

                if (! $cell['proofread']) {
                    $notProofreadCount++;
                }
            }

            $missing[] = [
                'name' => $language['name'],
                'slug' => $language['slug'],
                'count' => $missingCount,
            ];

            if ($notProofreadCount > 0) {
                $notProofread[] = [
                    'name' => $language['name'],
                    'slug' => $language['slug'],
                    'count' => $notProofreadCount,
                ];
            }
        }

        return [
            'total' => count($rows),
            'postTypeLabel' => $postTypeLabel,
            'defaultLanguageName' => $defaultLanguageName,
            'missing' => $missing,
            'notProofread' => $notProofread,
        ];
    }
}
