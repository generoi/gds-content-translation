<?php

namespace GeneroWP\ContentTranslation\Tests\Unit;

use GeneroWP\ContentTranslation\StatusSummary;
use WP_UnitTestCase;

class StatusSummaryTest extends WP_UnitTestCase
{
    /** @var list<array{slug: string, name: string, isDefault: bool}> */
    private array $languages = [
        ['slug' => 'fi', 'name' => 'Suomi', 'isDefault' => true],
        ['slug' => 'sv', 'name' => 'Svenska', 'isDefault' => false],
        ['slug' => 'en', 'name' => 'English', 'isDefault' => false],
    ];

    /**
     * @param  array<string, array{trashed: bool, proofread: bool}>  $languages
     * @return array{languages: array<string, array{trashed: bool, proofread: bool}>}
     */
    private function row(array $languages): array
    {
        return ['languages' => $languages];
    }

    public function test_it_counts_missing_and_not_proof_read_per_language(): void
    {
        $summary = StatusSummary::build([
            $this->row([
                'fi' => ['trashed' => false, 'proofread' => false],
                'sv' => ['trashed' => false, 'proofread' => true],
                'en' => ['trashed' => false, 'proofread' => false],
            ]),
            $this->row([
                'fi' => ['trashed' => false, 'proofread' => false],
            ]),
        ], $this->languages, 'Products');

        $this->assertSame(2, $summary['total']);
        $this->assertSame('Suomi', $summary['defaultLanguageName']);
        $this->assertSame('Products', $summary['postTypeLabel']);
        $this->assertSame(
            [['name' => 'Svenska', 'slug' => 'sv', 'count' => 1], ['name' => 'English', 'slug' => 'en', 'count' => 1]],
            $summary['missing']
        );
        // Svenska is proof read, so only English is listed.
        $this->assertSame([['name' => 'English', 'slug' => 'en', 'count' => 1]], $summary['notProofread']);
    }

    public function test_a_trashed_translation_counts_as_missing_not_as_unread(): void
    {
        $summary = StatusSummary::build([
            $this->row([
                'fi' => ['trashed' => false, 'proofread' => false],
                'sv' => ['trashed' => true, 'proofread' => false],
                'en' => ['trashed' => true, 'proofread' => true],
            ]),
        ], $this->languages, 'Pages');

        $this->assertSame([1, 1], array_column($summary['missing'], 'count'));
        $this->assertSame([], $summary['notProofread']);
    }

    public function test_the_default_language_is_never_counted(): void
    {
        $summary = StatusSummary::build([$this->row([])], $this->languages, 'Pages');

        $this->assertSame(['sv', 'en'], array_column($summary['missing'], 'slug'));
    }
}
