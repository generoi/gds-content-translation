<?php

namespace GeneroWP\ContentTranslation\Tests\Integration;

use GeneroWP\ContentTranslation\StatusList;
use GeneroWP\ContentTranslation\Tests\RestTestCase;
use GeneroWP\ContentTranslation\TranslationAudit;

/**
 * GET /rows: the status screen's list, built, searched, filtered, sorted and
 * paged on the server.
 */
class StatusRowsTest extends RestTestCase
{
    /**
     * @return list<string>
     */
    private function titles(array $data): array
    {
        return array_column($data['rows'], 'title');
    }

    public function test_one_row_per_default_language_post_with_a_cell_per_translation_language(): void
    {
        $translated = $this->createTranslatedPost(['post_title' => 'Translated']);
        $missing = $this->createPost('en', ['post_title' => 'Missing']);
        update_post_meta($translated['fi'], GDS_CONTENT_TRANSLATION_META_KEY, '1');

        $data = $this->rows();

        $this->assertSame(['Missing', 'Translated'], $this->titles($data));
        $this->assertSame(2, $data['total']);

        [$missingRow, $translatedRow] = $data['rows'];
        $this->assertSame($missing, $missingRow['sourceId']);
        $this->assertSame('missing', $missingRow['languages']['fi']['state']);
        $this->assertArrayNotHasKey('en', $missingRow['languages']);

        $cell = $translatedRow['languages']['fi'];
        $this->assertSame('translated', $cell['state']);
        $this->assertSame($translated['fi'], $cell['postId']);
        $this->assertTrue($cell['proofread']);
        $this->assertTrue($cell['canDelete']);
        $this->assertSame('none', $cell['audit']['status']);
        $this->assertStringContainsString('post='.$translated['fi'], $cell['editUrl']);
        $this->assertTrue($translatedRow['canTrashAll']);
        $this->assertSame(1, $translatedRow['translationCount']);

        $this->assertSame([['name' => 'Finnish', 'slug' => 'fi', 'count' => 1]], $data['summary']['missing']);
        $this->assertSame([], $data['summary']['notProofread']);
    }

    public function test_a_trashed_translation_is_shown_as_in_trash_and_counted_as_missing(): void
    {
        $ids = $this->createTranslatedPost(['post_title' => 'Trashed']);
        wp_trash_post($ids['fi']);

        $data = $this->rows();

        $this->assertSame('trashed', $data['rows'][0]['languages']['fi']['state']);
        $this->assertSame(0, $data['rows'][0]['translationCount']);
        $this->assertSame(1, $data['summary']['missing'][0]['count']);
        $this->assertSame(['Trashed'], $this->titles($this->rows(['filters' => ['fi' => ['missing']]])));
    }

    public function test_it_pages_and_sorts_on_the_server(): void
    {
        foreach (['Delta', 'Alpha', 'Charlie', 'Bravo', 'Echo'] as $index => $title) {
            $this->createPost('en', ['post_title' => $title, 'post_date' => sprintf('2026-01-0%d 10:00:00', $index + 1)]);
        }

        $page = $this->rows(['per_page' => 2, 'page' => 2]);
        $this->assertSame(['Charlie', 'Delta'], $this->titles($page));
        $this->assertSame(5, $page['total']);
        $this->assertSame(3, $page['totalPages']);

        $this->assertSame(['Echo', 'Delta'], $this->titles($this->rows(['orderby' => 'title', 'order' => 'desc', 'per_page' => 2])));
        $this->assertSame(['Echo', 'Bravo', 'Charlie'], $this->titles($this->rows(['orderby' => 'date', 'order' => 'desc', 'per_page' => 3])));
    }

    public function test_the_search_tolerates_a_missing_letter_only_when_nothing_matches(): void
    {
        $this->createPost('en', ['post_title' => 'Pillar foundation']);
        $this->createPost('en', ['post_title' => 'Pilar']);
        $this->createPost('en', ['post_title' => 'Contact']);

        // "pilar" is in one title as typed, so the typo tolerance stays out of it.
        $this->assertSame(['Pilar'], $this->titles($this->rows(['search' => 'pilar'])));
        $this->assertSame(['Pillar foundation'], $this->titles($this->rows(['search' => 'pillar FOUND'])));
        // "fondation" is in no title: one letter short of "foundation".
        $this->assertSame(['Pillar foundation'], $this->titles($this->rows(['search' => 'fondation'])));
        $this->assertSame([], $this->titles($this->rows(['search' => 'xyz'])));
    }

    public function test_filters_by_proof_reading_and_ai_review(): void
    {
        $unread = $this->createTranslatedPost(['post_title' => 'Unread']);
        $read = $this->createTranslatedPost(['post_title' => 'Read']);
        $errors = $this->createTranslatedPost(['post_title' => 'Errors']);
        update_post_meta($read['fi'], GDS_CONTENT_TRANSLATION_META_KEY, '1');
        update_post_meta($errors['fi'], GDS_CONTENT_TRANSLATION_META_KEY, '1');
        TranslationAudit::store($errors['fi'], ['status' => 'done', 'contentHash' => md5(''), 'remarks' => [
            ['id' => 1, 'segment' => 1, 'severity' => 'error', 'category' => 'meaning', 'issue' => '', 'suggestion' => '', 'state' => 'open'],
        ]]);
        TranslationAudit::store($unread['fi'], ['status' => 'pending', 'queuedAt' => time()]);

        $this->assertSame(['Unread'], $this->titles($this->rows(['filters' => ['fi' => ['unproofread']]])));
        $this->assertSame(['Errors'], $this->titles($this->rows(['filters' => ['fi' => ['errors']]])));
        $this->assertSame(['Unread'], $this->titles($this->rows(['filters' => ['fi' => ['running']]])));
        $this->assertSame(['Errors', 'Unread'], $this->titles($this->rows(['filters' => ['fi' => ['errors', 'unproofread']]])));

        $data = $this->rows();
        $this->assertSame([['name' => 'Finnish', 'slug' => 'fi', 'count' => 1]], $data['summary']['aiErrors']);
        $this->assertSame([['name' => 'Finnish', 'slug' => 'fi', 'count' => 1]], $data['summary']['reviewRunning']);

        $errorsRow = array_values(array_filter($data['rows'], static fn (array $row): bool => $row['title'] === 'Errors'))[0];
        $this->assertSame('done', $errorsRow['languages']['fi']['audit']['status']);
        $this->assertSame(1, $errorsRow['languages']['fi']['audit']['errors']);
        $this->assertStringContainsString('gds_ct_review='.$errors['fi'], $errorsRow['languages']['fi']['audit']['url']);
    }

    public function test_it_needs_the_screen_capability_and_a_shown_post_type(): void
    {
        $this->assertSame(400, $this->request('GET', '/rows', ['post_type' => 'nav_menu_item'])->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame(403, $this->request('GET', '/rows', ['post_type' => 'post'])->get_status());
    }

    public function test_cell_kinds(): void
    {
        $this->assertSame(['missing'], StatusList::cellKinds(null));
        $this->assertSame(['missing'], StatusList::cellKinds(['trashed' => true, 'proofread' => false]));
        $this->assertSame([], StatusList::cellKinds(['trashed' => false, 'proofread' => true, 'audit' => ['status' => 'done', 'errors' => 0]]));
        $this->assertSame(['unproofread', 'errors'], StatusList::cellKinds(['trashed' => false, 'proofread' => false, 'audit' => ['status' => 'done', 'errors' => 2]]));
    }
}
