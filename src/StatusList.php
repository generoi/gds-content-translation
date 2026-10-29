<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The rows of the status screen: one per default-language post of a type,
 * with the state of its translation in every other language.
 *
 * Search, filters and sorting need every row (a filter on "missing in
 * English" can match the last post of a thousand), so a light index of all
 * of them is built first: IDs, titles, dates and a handful of bulk queries
 * for the translations' status, proof reading, AI review and queued jobs.
 * Only the page that is shown gets the rest (links, notes, permissions).
 */
final class StatusList
{
    /** Filters per language: what the cell must be. */
    public const filterKinds = ['missing', 'unproofread', 'errors', 'running'];

    public const orderbys = ['title', 'date'];

    public const perPageMax = 200;

    /**
     * Post types the screen shows a tab for.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function postTypes(): array
    {
        $postTypes = Settings::getAllPostTypes();

        foreach (Settings::getHiddenPostTypes() as $slug) {
            unset($postTypes[$slug]);
        }

        return $postTypes;
    }

    /**
     * @return list<array{slug: string, name: string, code: string, isDefault: bool}>
     */
    /**
     * A language's name in the language of the admin reading it ("Swedish",
     * or "Ruotsi" for a Finnish admin), not Polylang's own name for it
     * ("Svenska"), which reads like content in an admin screen. Polylang's
     * name where PHP has no intl.
     */
    public static function languageName(\PLL_Language $language): string
    {
        if (! class_exists(\Locale::class)) {
            return $language->name;
        }

        $name = \Locale::getDisplayLanguage($language->locale, get_user_locale());

        return $name !== '' && $name !== $language->locale ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1) : $language->name;
    }

    public static function languages(): array
    {
        $defaultSlug = pll_default_language('slug');
        $languages = [];

        foreach (pll_languages_list() as $slug) {
            $language = PLL()->model->get_language($slug);

            if (! $language) {
                continue;
            }

            $languages[] = [
                'slug' => $language->slug,
                'name' => self::languageName($language),
                'code' => strtoupper($language->slug),
                'isDefault' => $language->slug === $defaultSlug,
            ];
        }

        return $languages;
    }

    /**
     * @return list<string>
     */
    public static function translationLanguageSlugs(): array
    {
        return array_values(array_map(
            static fn (array $language): string => $language['slug'],
            array_filter(self::languages(), static fn (array $language): bool => ! $language['isDefault'])
        ));
    }

    /**
     * One page of rows, with the totals and the summary of the whole type.
     *
     * @param  array{search?: string, filters?: array<string, list<string>>, orderby?: string, order?: string, page?: int, perPage?: int}  $args
     * @return array{rows: list<array<string, mixed>>, total: int, totalPages: int, summary: array<string, mixed>}
     */
    public static function query(string $postType, array $args = []): array
    {
        $orderby = in_array($args['orderby'] ?? '', self::orderbys, true) ? $args['orderby'] : 'title';
        $order = strtolower((string) ($args['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, min(self::perPageMax, (int) ($args['perPage'] ?? 50)));
        $page = max(1, (int) ($args['page'] ?? 1));

        $languages = self::languages();
        $entries = self::entries($postType, $orderby, $order);
        $postTypeObject = get_post_type_object($postType);
        $summary = StatusSummary::build($entries, $languages, $postTypeObject ? $postTypeObject->labels->name : $postType);

        $matching = self::search(self::filter($entries, (array) ($args['filters'] ?? [])), (string) ($args['search'] ?? ''));
        $total = count($matching);
        $totalPages = (int) max(1, ceil($total / $perPage));
        $pageEntries = array_slice($matching, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => self::rows($pageEntries, $postType, $languages),
            'total' => $total,
            'totalPages' => $totalPages,
            'summary' => $summary,
        ];
    }

    /**
     * Every default-language post of a type, light: no content, no links.
     *
     * @return list<array{sourceId: int, title: string, date: string, status: string, languages: array<string, array{postId: int, trashed: bool, proofread: bool, audit: array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}|null}>, jobs: array<string, array<string, mixed>>}>
     */
    public static function entries(string $postType, string $orderby = 'title', string $order = 'ASC'): array
    {
        $defaultSlug = pll_default_language('slug');

        if (! $defaultSlug) {
            return [];
        }

        // IDs only: on a large catalogue the posts' content was most of the
        // memory. suppress_filters stays false: sites narrow this list with
        // pre_get_posts (only header and footer of the template parts, say).
        $sourceIds = array_map('intval', get_posts([
            'post_type' => $postType,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'orderby' => [$orderby === 'date' ? 'date' : 'title' => $order, 'ID' => $order],
            'lang' => $defaultSlug,
            'suppress_filters' => false,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]));

        if ($sourceIds === []) {
            return [];
        }

        // Polylang reads the translation groups from the term cache.
        update_object_term_cache($sourceIds, $postType);

        $sources = self::postFields($sourceIds);
        $translationsBySource = [];
        $translationIds = [];

        foreach ($sourceIds as $sourceId) {
            $translationsBySource[$sourceId] = array_map('intval', pll_get_post_translations($sourceId));

            foreach ($translationsBySource[$sourceId] as $translationId) {
                if ($translationId !== $sourceId) {
                    $translationIds[] = $translationId;
                }
            }
        }

        $translationIds = array_values(array_unique($translationIds));
        $translations = self::postFields($translationIds);
        $proofread = self::proofreadPostIds($translationIds);
        $audits = TranslationAudit::statuses($translationIds);
        $jobs = BulkTranslation::jobsBySource($sourceIds);
        $entries = [];

        foreach ($sourceIds as $sourceId) {
            if (! isset($sources[$sourceId])) {
                continue;
            }

            $cells = [];

            foreach ($translationsBySource[$sourceId] as $slug => $translationId) {
                if ($slug === $defaultSlug || ! isset($translations[$translationId])) {
                    continue;
                }

                $cells[$slug] = [
                    'postId' => $translationId,
                    // Polylang keeps a trashed translation linked. It is not a
                    // translation anyone can read, so it counts as missing.
                    'trashed' => $translations[$translationId]['status'] === 'trash',
                    'postStatus' => $translations[$translationId]['status'],
                    'proofread' => isset($proofread[$translationId]),
                    'audit' => $audits[$translationId] ?? null,
                ];
            }

            $entries[] = [
                'sourceId' => $sourceId,
                'title' => $sources[$sourceId]['title'],
                'date' => $sources[$sourceId]['date'],
                'status' => $sources[$sourceId]['status'],
                'languages' => $cells,
                'jobs' => $jobs[$sourceId] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * Rows whose cells match the filters: per language, any of the kinds
     * given; across languages, all of them.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, list<string>>  $filters  Language slug => kinds.
     * @return list<array<string, mixed>>
     */
    public static function filter(array $entries, array $filters): array
    {
        $filters = array_filter(array_map(
            static fn ($kinds): array => array_values(array_intersect((array) $kinds, self::filterKinds)),
            $filters
        ));

        if ($filters === []) {
            return $entries;
        }

        return array_values(array_filter($entries, static function (array $entry) use ($filters): bool {
            foreach ($filters as $slug => $kinds) {
                if (! array_intersect($kinds, self::cellKinds($entry['languages'][$slug] ?? null))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * What a cell counts as for the filters and the summary.
     *
     * @param  array{trashed: bool, proofread: bool, audit?: array{status: string, errors: int}|null}|null  $cell
     * @return list<string>
     */
    public static function cellKinds(?array $cell): array
    {
        if ($cell === null || $cell['trashed']) {
            return ['missing'];
        }

        $kinds = [];

        if (! $cell['proofread']) {
            $kinds[] = 'unproofread';
        }

        $audit = $cell['audit'] ?? null;

        if ($audit && $audit['status'] === 'done' && $audit['errors'] > 0) {
            $kinds[] = 'errors';
        }

        if ($audit && $audit['status'] === 'pending') {
            $kinds[] = 'running';
        }

        return $kinds;
    }

    /**
     * Rows whose title has every word searched for. Only when none has, a
     * missing letter within a word is tolerated ("trnslation"), so a typo
     * still finds something but a correct word never finds noise.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public static function search(array $entries, string $query): array
    {
        $terms = array_values(array_filter(explode(' ', self::normalize($query))));

        if ($terms === []) {
            return $entries;
        }

        $texts = [];

        foreach ($entries as $index => $entry) {
            $texts[$index] = self::normalize($entry['title']);
        }

        $exact = array_filter($entries, static function (array $entry, int $index) use ($texts, $terms): bool {
            foreach ($terms as $term) {
                if (! str_contains($texts[$index], $term)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        if ($exact !== []) {
            return array_values($exact);
        }

        return array_values(array_filter($entries, static function (array $entry, int $index) use ($texts, $terms): bool {
            $words = explode(' ', $texts[$index]);

            foreach ($terms as $term) {
                if (str_contains($texts[$index], $term)) {
                    continue;
                }

                if (mb_strlen($term) < 3) {
                    return false;
                }

                $found = false;

                foreach ($words as $word) {
                    if (mb_strlen($word) >= mb_strlen($term) && self::isSubsequence($word, $term)) {
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Lower case, without accents, single spaces.
     */
    public static function normalize(string $text): string
    {
        $text = remove_accents(mb_strtolower(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function isSubsequence(string $haystack, string $needle): bool
    {
        $offset = 0;
        $length = mb_strlen($needle);

        for ($i = 0; $i < $length; $i++) {
            $position = mb_strpos($haystack, mb_substr($needle, $i, 1), $offset);

            if ($position === false) {
                return false;
            }

            $offset = $position + 1;
        }

        return true;
    }

    /**
     * The full rows of one page.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array{slug: string, name: string, isDefault: bool}>  $languages
     * @return list<array<string, mixed>>
     */
    public static function rows(array $entries, string $postType, array $languages): array
    {
        if ($entries === []) {
            return [];
        }

        $postIds = [];

        foreach ($entries as $entry) {
            $postIds[] = $entry['sourceId'];

            foreach ($entry['languages'] as $cell) {
                $postIds[] = $cell['postId'];
            }
        }

        _prime_post_caches($postIds, false, false);

        $openNotes = self::openNoteCounts($postIds);
        $viewable = is_post_type_viewable($postType);
        $canDeleteAll = self::canDeleteAll($postType);
        $canDelete = static fn (int $postId): bool => $canDeleteAll || current_user_can('delete_post', $postId);
        $rows = [];

        foreach ($entries as $entry) {
            $sourceId = $entry['sourceId'];
            $cells = [];
            $canTrashAll = $canDelete($sourceId);
            $translationCount = 0;

            foreach ($languages as $language) {
                if ($language['isDefault']) {
                    continue;
                }

                $slug = $language['slug'];
                $cell = $entry['languages'][$slug] ?? null;
                $job = $entry['jobs'][$slug] ?? null;

                if ($cell === null) {
                    $cells[$slug] = ['state' => 'missing', 'postId' => 0, 'job' => $job];

                    continue;
                }

                $postId = $cell['postId'];
                $deletable = $canDelete($postId);
                $canTrashAll = $canTrashAll && $deletable;
                $translationCount += $cell['trashed'] ? 0 : 1;

                $cells[$slug] = [
                    'state' => $cell['trashed'] ? 'trashed' : 'translated',
                    'postId' => $postId,
                    'status' => (string) $cell['postStatus'],
                    'editUrl' => (string) get_edit_post_link($postId, 'raw'),
                    'viewUrl' => $viewable && ! $cell['trashed'] ? self::viewUrl($postId) : '',
                    'proofread' => $cell['proofread'],
                    'openNotes' => $openNotes[$postId] ?? 0,
                    'canDelete' => $deletable,
                    'audit' => $cell['trashed'] ? null : self::audit($postId, $cell['audit']),
                    'job' => $job,
                ];
            }

            $rows[] = [
                'id' => (string) $sourceId,
                'sourceId' => $sourceId,
                'title' => html_entity_decode($entry['title'], ENT_QUOTES, 'UTF-8'),
                'date' => mysql2date('c', $entry['date'], false),
                'status' => $entry['status'],
                'editUrl' => (string) get_edit_post_link($sourceId, 'raw'),
                'viewUrl' => $viewable ? self::viewUrl($sourceId) : '',
                'openNotes' => $openNotes[$sourceId] ?? 0,
                'canTrashAll' => $canTrashAll,
                'translationCount' => $translationCount,
                'languages' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * The AI review marker of a translation, for the cell.
     *
     * @param  array{status: string, errors: int, other: int, contentHash: string, queuedAt: int}|null  $audit
     * @return array{status: string, errors: int, other: int, edited: bool, error: string, url: string}
     */
    private static function audit(int $postId, ?array $audit): array
    {
        if ($audit === null) {
            return ['status' => 'none', 'errors' => 0, 'other' => 0, 'edited' => false, 'error' => '', 'url' => ''];
        }

        $post = get_post($postId);

        return [
            'status' => $audit['status'],
            'errors' => $audit['errors'],
            'other' => $audit['other'],
            'edited' => $audit['status'] === 'done' && $post && $audit['contentHash'] !== '' && TranslationAudit::contentHash((int) $post->ID) !== $audit['contentHash'],
            'error' => $audit['status'] === 'failed' ? (string) (TranslationAudit::get($postId)['error'] ?? '') : '',
            'url' => AuditReview::getUrl($postId),
        ];
    }

    /**
     * One capability check for the screen instead of map_meta_cap per cell.
     * Anyone who cannot delete every post of the type is checked per post.
     */
    private static function canDeleteAll(string $postType): bool
    {
        $postTypeObject = get_post_type_object($postType);

        return $postTypeObject
            && $postTypeObject->map_meta_cap
            && current_user_can($postTypeObject->cap->delete_posts)
            && current_user_can($postTypeObject->cap->delete_others_posts)
            && current_user_can($postTypeObject->cap->delete_published_posts)
            && current_user_can($postTypeObject->cap->delete_private_posts);
    }

    /**
     * Where to see a post on the site: its permalink once published, its
     * preview before that.
     */
    public static function viewUrl(int $postId): string
    {
        $post = get_post($postId);

        if (! $post) {
            return '';
        }

        $url = $post->post_status === 'publish' ? get_permalink($post) : get_preview_post_link($post);

        return is_string($url) ? $url : '';
    }

    /**
     * Title, date and status of some posts, in one query and without their
     * content.
     *
     * @param  list<int>  $postIds
     * @return array<int, array{title: string, date: string, status: string}>
     */
    private static function postFields(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $fields = [];

        foreach (array_chunk($postIds, 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_date, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
                ...$chunk
            ));

            foreach ($results as $row) {
                $fields[(int) $row->ID] = [
                    'title' => $row->post_title !== '' ? $row->post_title : __('(no title)', 'gds-content-translation'),
                    'date' => (string) $row->post_date,
                    'status' => (string) $row->post_status,
                ];
            }
        }

        return $fields;
    }

    /**
     * @param  list<int>  $postIds
     * @return array<int, true>
     */
    private static function proofreadPostIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $ids = [];

        foreach (array_chunk($postIds, 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ('', '0') AND post_id IN ({$placeholders})",
                GDS_CONTENT_TRANSLATION_META_KEY,
                ...$chunk
            )));
        }

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Open block editor notes per post. The AI review's notes are counted by
     * its own marker, not twice.
     *
     * @param  list<int>  $postIds
     * @return array<int, int>
     */
    private static function openNoteCounts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if ($postIds === []) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($postIds), '%d'));
        $sql = "
            SELECT comment_post_ID, COUNT(*) AS note_count
            FROM {$wpdb->comments}
            WHERE comment_type = 'note'
              AND comment_approved = '0'
              AND comment_parent = 0
              AND comment_post_ID IN ({$placeholders})
              AND NOT EXISTS (
                  SELECT 1 FROM {$wpdb->commentmeta}
                  WHERE {$wpdb->commentmeta}.comment_id = {$wpdb->comments}.comment_ID
                    AND {$wpdb->commentmeta}.meta_key = %s
              )
            GROUP BY comment_post_ID
        ";

        $counts = [];

        foreach ($wpdb->get_results($wpdb->prepare($sql, ...array_merge($postIds, [AuditNotes::remarkMetaKey]))) as $row) {
            $counts[(int) $row->comment_post_ID] = (int) $row->note_count;
        }

        return $counts;
    }
}
