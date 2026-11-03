<?php

namespace GeneroWP\ContentTranslation;

use PLL_Language;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The translation glossary: terms with the translation they must have in one
 * target language. Approved terms go to DeepL as a glossary
 * ({@see DeeplGlossary}) and to the AI review as required wording; the review
 * and proofread translations suggest new ones ({@see GlossaryLearning}),
 * which an editor accepts or rejects.
 *
 * Languages are uppercase language codes, as DeepL and its CSV files name
 * them ("EN", "SV", "NB"), not Polylang slugs: two markets in one language
 * (de and de_at, say) share their terms.
 *
 * One table per site. A term is unique by its language pair, source and
 * target, so a second translation of the same source is a conflict an editor
 * sees, not a silent overwrite.
 */
final class Glossary
{
    public const statuses = ['approved', 'draft', 'suggested', 'rejected'];

    public const origins = ['import', 'manual', 'review', 'proofread'];

    public const orderbys = ['source', 'target', 'hits', 'updated'];

    public const perPageMax = 200;

    /** Longest term, in characters. A glossary holds terms, not sentences. */
    public const termMax = 200;

    /** Most terms of one page given to the AI review. */
    private const reviewMax = 150;

    private const schemaVersion = '1';

    private const schemaOption = 'gds_content_translation_glossary_schema';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'gds_ct_glossary';
    }

    /** Create or update the table when the plugin's schema is newer than the site's. */
    public static function install(): void
    {
        global $wpdb;

        if (get_option(self::schemaOption) === self::schemaVersion) {
            return;
        }

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        // dbDelta's format: two spaces after PRIMARY KEY, one key per line.
        dbDelta(sprintf(
            'CREATE TABLE %s (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_lang varchar(8) NOT NULL,
  target_lang varchar(8) NOT NULL,
  source_term varchar(255) NOT NULL,
  target_term varchar(255) NOT NULL,
  status varchar(16) NOT NULL DEFAULT \'approved\',
  origin varchar(16) NOT NULL DEFAULT \'manual\',
  note text NOT NULL,
  example text NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  term_hash char(32) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY term_hash (term_hash),
  KEY pair_status (source_lang,target_lang,status),
  KEY pair_source (source_lang,target_lang,source_term(100))
) %s;',
            self::table(),
            $wpdb->get_charset_collate()
        ));

        update_option(self::schemaOption, self::schemaVersion);
    }

    public static function init(): void
    {
        // An autoloaded option read on each request; the table is created on
        // the first request of a site after an update, REST and cron included.
        self::install();
    }

    /** A language's code: the language part of its locale, "SV" for sv_SE. */
    public static function code(PLL_Language|string $language): string
    {
        $locale = $language instanceof PLL_Language ? $language->locale : $language;

        return strtoupper((string) preg_replace('/[_-].*$/', '', $locale));
    }

    public static function sourceCode(): string
    {
        $default = PLL()->model->get_language((string) pll_default_language('slug'));

        return $default ? self::code($default) : '';
    }

    /**
     * The target languages a term can have: every language's code but the
     * default's, with a name in the admin's language.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function targetLanguages(): array
    {
        $source = self::sourceCode();
        $targets = [];

        foreach (pll_languages_list() as $slug) {
            $language = PLL()->model->get_language($slug);
            $code = $language ? self::code($language) : '';

            if ($code !== '' && $code !== $source && ! isset($targets[$code])) {
                $targets[$code] = ['code' => $code, 'name' => StatusList::languageName($language)];
            }
        }

        // Languages the glossary has terms for, without a language on this site
        // (a CSV for several sites, say).
        foreach (self::pairs() as $pair) {
            $targets[$pair['targetLang']] ??= ['code' => $pair['targetLang'], 'name' => self::codeName($pair['targetLang'])];
        }

        return array_values($targets);
    }

    /** A language code's name in the admin's language: "Danish" for DA. */
    private static function codeName(string $code): string
    {
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayLanguage(strtolower($code), get_user_locale()) : '';

        return $name !== '' && strtolower($name) !== strtolower($code) ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1) : $code;
    }

    public static function cleanTerm(mixed $value): string
    {
        // One line: DeepL takes a glossary as tab-separated lines.
        $term = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value)));

        return mb_substr($term, 0, self::termMax);
    }

    private static function hash(string $sourceLang, string $targetLang, string $source, string $target): string
    {
        return md5($sourceLang."\t".$targetLang."\t".$source."\t".$target);
    }

    private static function now(): string
    {
        return current_time('mysql', true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findByTerms(string $sourceLang, string $targetLang, string $source, string $target): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.self::table().' WHERE term_hash = %s',
            self::hash($sourceLang, $targetLang, $source, $target)
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Add a term.
     *
     * @param  array{sourceLang?: string, targetLang: string, source: string, target: string, status?: string, origin?: string, note?: string, example?: string, postId?: int, hits?: int}  $term
     */
    public static function add(array $term): int|WP_Error
    {
        global $wpdb;

        $row = self::validate($term);

        if ($row instanceof WP_Error) {
            return $row;
        }

        if (self::findByTerms($row['source_lang'], $row['target_lang'], $row['source_term'], $row['target_term'])) {
            return new WP_Error('gds_ct_glossary_exists', __('This term is in the glossary already.', 'gds-content-translation'));
        }

        $now = self::now();
        $inserted = $wpdb->insert(self::table(), $row + [
            'origin' => in_array($term['origin'] ?? '', self::origins, true) ? $term['origin'] : 'manual',
            'example' => mb_substr(wp_strip_all_tags((string) ($term['example'] ?? '')), 0, 1000),
            'post_id' => max(0, (int) ($term['postId'] ?? 0)),
            'hits' => max(0, (int) ($term['hits'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (! $inserted) {
            return new WP_Error('gds_ct_glossary_db', __('The term could not be saved.', 'gds-content-translation'));
        }

        // Before changed(), whose option write is the next insert.
        $id = (int) $wpdb->insert_id;

        if ($row['status'] === 'approved') {
            self::changed();
        }

        return $id;
    }

    /**
     * Change a term's languages, wording, note or status.
     *
     * @param  array<string, mixed>  $changes  Any of sourceLang, targetLang, source, target, status, note.
     */
    public static function update(int $id, array $changes): true|WP_Error
    {
        global $wpdb;

        $current = self::find($id);

        if (! $current) {
            return new WP_Error('gds_ct_glossary_missing', __('This term could not be found.', 'gds-content-translation'), ['status' => 404]);
        }

        $row = self::validate([
            'sourceLang' => $changes['sourceLang'] ?? $current['source_lang'],
            'targetLang' => $changes['targetLang'] ?? $current['target_lang'],
            'source' => $changes['source'] ?? $current['source_term'],
            'target' => $changes['target'] ?? $current['target_term'],
            'status' => $changes['status'] ?? $current['status'],
            'note' => $changes['note'] ?? $current['note'],
        ]);

        if ($row instanceof WP_Error) {
            return $row;
        }

        $other = self::findByTerms($row['source_lang'], $row['target_lang'], $row['source_term'], $row['target_term']);

        if ($other && (int) $other['id'] !== $id) {
            return new WP_Error('gds_ct_glossary_exists', __('This term is in the glossary already.', 'gds-content-translation'));
        }

        $wpdb->update(self::table(), $row + ['updated_at' => self::now()], ['id' => $id]);

        if ($row['status'] === 'approved' && $current['status'] !== 'approved') {
            self::retireOthers($id);
        }

        // Only approved terms go to DeepL.
        if ($row['status'] === 'approved' || $current['status'] === 'approved') {
            self::changed();
        }

        return true;
    }

    /**
     * Set the status of several terms, or delete them.
     *
     * @param  list<int>  $ids
     */
    public static function bulk(array $ids, string $operation): int
    {
        global $wpdb;

        $changed = 0;
        $approved = false;

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $term = self::find($id);

            if (! $term) {
                continue;
            }

            if ($operation === 'delete') {
                $changed += (int) $wpdb->delete(self::table(), ['id' => $id]);
                $approved = $approved || $term['status'] === 'approved';

                continue;
            }

            $status = ['approve' => 'approved', 'reject' => 'rejected', 'draft' => 'draft'][$operation] ?? '';

            if ($status === '' || $term['status'] === $status) {
                continue;
            }

            $wpdb->update(self::table(), ['status' => $status, 'updated_at' => self::now()], ['id' => $id]);
            $changed++;
            $approved = $approved || $term['status'] === 'approved' || $status === 'approved';

            if ($status === 'approved') {
                self::retireOthers($id);
            }
        }

        if ($approved) {
            self::changed();
        }

        return $changed;
    }

    /**
     * An approved term replaces the other approved translations of its
     * source: accepting a suggestion that says "väggstag, not stöd" rejects
     * "stöd".
     */
    private static function retireOthers(int $id): void
    {
        global $wpdb;

        $term = self::find($id);

        if (! $term) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET status = %s, updated_at = %s WHERE source_lang = %s AND target_lang = %s AND source_term = %s AND status = %s AND id <> %d',
            'rejected',
            self::now(),
            $term['source_lang'],
            $term['target_lang'],
            $term['source_term'],
            'approved',
            $id
        ));
    }

    /**
     * @param  array<string, mixed>  $term
     * @return array{source_lang: string, target_lang: string, source_term: string, target_term: string, status: string, note: string}|WP_Error
     */
    private static function validate(array $term): array|WP_Error
    {
        $sourceLang = strtoupper(sanitize_key((string) ($term['sourceLang'] ?? '') ?: self::sourceCode()));
        $targetLang = strtoupper(sanitize_key((string) ($term['targetLang'] ?? '')));
        $source = self::cleanTerm($term['source'] ?? '');
        $target = self::cleanTerm($term['target'] ?? '');
        $status = (string) ($term['status'] ?? 'approved');

        if ($source === '' || $target === '') {
            return new WP_Error('gds_ct_glossary_empty', __('A term needs both the original and its translation.', 'gds-content-translation'), ['status' => 400]);
        }

        if ($sourceLang === '' || $targetLang === '' || $sourceLang === $targetLang) {
            return new WP_Error('gds_ct_glossary_language', __('Choose the language of the translation.', 'gds-content-translation'), ['status' => 400]);
        }

        if (! in_array($status, self::statuses, true)) {
            return new WP_Error('gds_ct_glossary_status', __('Unknown status.', 'gds-content-translation'), ['status' => 400]);
        }

        return [
            'source_lang' => $sourceLang,
            'target_lang' => $targetLang,
            'source_term' => $source,
            'target_term' => $target,
            'status' => $status,
            'note' => mb_substr(trim(sanitize_textarea_field((string) ($term['note'] ?? ''))), 0, 1000),
            'term_hash' => self::hash($sourceLang, $targetLang, $source, $target),
        ];
    }

    /**
     * One page of terms, searched, filtered and sorted.
     *
     * @param  array{page?: int, perPage?: int, search?: string, status?: list<string>, origin?: list<string>, targetLang?: string, conflicts?: bool, orderby?: string, order?: string}  $query
     * @return array{items: list<array<string, mixed>>, total: int, totalPages: int}
     */
    public static function query(array $query): array
    {
        global $wpdb;

        $table = self::table();
        $where = ['1=1'];
        $args = [];

        $statuses = array_values(array_intersect((array) ($query['status'] ?? []), self::statuses));

        if ($statuses !== []) {
            $where[] = 'g.status IN ('.implode(',', array_fill(0, count($statuses), '%s')).')';
            array_push($args, ...$statuses);
        }

        $origins = array_values(array_intersect((array) ($query['origin'] ?? []), self::origins));

        if ($origins !== []) {
            $where[] = 'g.origin IN ('.implode(',', array_fill(0, count($origins), '%s')).')';
            array_push($args, ...$origins);
        }

        if (($query['targetLang'] ?? '') !== '') {
            $where[] = 'g.target_lang = %s';
            $args[] = strtoupper((string) $query['targetLang']);
        }

        if (($query['search'] ?? '') !== '') {
            $like = '%'.$wpdb->esc_like((string) $query['search']).'%';
            $where[] = '(g.source_term LIKE %s OR g.target_term LIKE %s OR g.note LIKE %s)';
            array_push($args, $like, $like, $like);
        }

        if (! empty($query['conflicts'])) {
            $where[] = "g.status = 'approved' AND ".self::conflictSql();
        }

        $orderby = [
            'source' => 'g.source_term',
            'target' => 'g.target_term',
            'hits' => 'g.hits',
            'updated' => 'g.updated_at',
        ][$query['orderby'] ?? 'source'] ?? 'g.source_term';
        $order = strtolower((string) ($query['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, min(self::perPageMax, (int) ($query['perPage'] ?? 50)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $whereSql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} g WHERE {$whereSql}", $args) : "SELECT COUNT(*) FROM {$table} g WHERE {$whereSql}");

        $rows = $wpdb->get_results($wpdb->prepare(
            self::selectSql()." WHERE {$whereSql} ORDER BY {$orderby} {$order}, g.id {$order} LIMIT %d OFFSET %d",
            array_merge($args, [$perPage, ($page - 1) * $perPage])
        ), ARRAY_A);

        return [
            'items' => array_map([self::class, 'item'], (array) $rows),
            'total' => $total,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /** Another approved term with this source and a different translation. */
    private static function conflictSql(): string
    {
        $table = self::table();

        return "EXISTS (SELECT 1 FROM {$table} c WHERE c.source_lang = g.source_lang AND c.target_lang = g.target_lang AND c.source_term = g.source_term AND c.status = 'approved' AND c.id <> g.id)";
    }

    /** Terms with whether they conflict, and the approved translation a suggestion would replace. */
    private static function selectSql(): string
    {
        $table = self::table();

        return 'SELECT g.*, '.self::conflictSql()." AS conflict,
            (SELECT r.target_term FROM {$table} r WHERE r.source_lang = g.source_lang AND r.target_lang = g.target_lang AND r.source_term = g.source_term AND r.status = 'approved' AND r.id <> g.id ORDER BY r.hits DESC, r.updated_at DESC LIMIT 1) AS replaces
            FROM {$table} g";
    }

    /**
     * One term as the REST API gives it.
     *
     * @return array<string, mixed>|null
     */
    public static function getItem(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(self::selectSql().' WHERE g.id = %d', $id), ARRAY_A);

        return is_array($row) ? self::item($row) : null;
    }

    /**
     * A term as the REST API gives it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function item(array $row): array
    {
        $postId = (int) $row['post_id'];
        $post = $postId > 0 ? get_post($postId) : null;

        return [
            'id' => (int) $row['id'],
            'sourceLang' => $row['source_lang'],
            'targetLang' => $row['target_lang'],
            'source' => $row['source_term'],
            'target' => $row['target_term'],
            'status' => $row['status'],
            'origin' => $row['origin'],
            'note' => $row['note'],
            'example' => $row['example'],
            'postId' => $post ? $postId : 0,
            'postTitle' => $post ? get_the_title($post) : '',
            'postEditUrl' => $post ? (string) get_edit_post_link($post, 'raw') : '',
            'hits' => (int) $row['hits'],
            'conflict' => ! empty($row['conflict']) && $row['status'] === 'approved',
            // What accepting this suggestion would replace.
            'replaces' => $row['status'] !== 'approved' && ($row['replaces'] ?? '') !== $row['target_term'] ? (string) ($row['replaces'] ?? '') : '',
            'updatedAt' => gmdate('c', (int) strtotime($row['updated_at'].' UTC')),
        ];
    }

    /**
     * @return array{approved: int, draft: int, suggested: int, rejected: int}
     */
    public static function counts(): array
    {
        global $wpdb;

        $counts = array_fill_keys(self::statuses, 0);

        foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n FROM '.self::table().' GROUP BY status', ARRAY_A) as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * Approved terms per target language.
     *
     * @return list<array{targetLang: string, approved: int}>
     */
    public static function pairs(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT target_lang, COUNT(*) AS n FROM '.self::table().' WHERE status = %s GROUP BY target_lang ORDER BY target_lang', 'approved'), ARRAY_A);

        return array_map(static fn (array $row): array => ['targetLang' => $row['target_lang'], 'approved' => (int) $row['n']], (array) $rows);
    }

    /**
     * The approved terms of every language pair, one translation per source:
     * the most confirmed, then the newest, when there are two.
     *
     * @return array<string, array<string, string>> "EN-SV" => [source => target]
     */
    public static function dictionaries(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT source_lang, target_lang, source_term, target_term FROM '.self::table().' WHERE status = %s ORDER BY hits ASC, updated_at ASC, id ASC',
            'approved'
        ), ARRAY_A);
        $dictionaries = [];

        // Later rows win, so the order above leaves the preferred one.
        foreach ((array) $rows as $row) {
            $dictionaries[$row['source_lang'].'-'.$row['target_lang']][$row['source_term']] = $row['target_term'];
        }

        ksort($dictionaries);

        foreach (array_keys($dictionaries) as $pair) {
            ksort($dictionaries[$pair], SORT_STRING);
        }

        return $dictionaries;
    }

    /**
     * The approved terms of a language pair whose source term is in the text:
     * the glossary of one page for the AI review. Whole words, any case;
     * longer terms first, as "wall brace kit" says more than "wall brace".
     *
     * @return list<array{source: string, target: string, note?: string}>
     */
    public static function termsIn(string $text, string $sourceLang, string $targetLang): array
    {
        global $wpdb;

        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (trim($text) === '') {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT source_term, target_term, note FROM '.self::table().' WHERE source_lang = %s AND target_lang = %s AND status = %s ORDER BY CHAR_LENGTH(source_term) DESC, hits DESC',
            $sourceLang,
            $targetLang,
            'approved'
        ), ARRAY_A);
        $terms = [];

        foreach ((array) $rows as $row) {
            if (isset($terms[$row['source_term']])) {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($row['source_term'], '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text) === 1) {
                $terms[$row['source_term']] = array_filter([
                    'source' => $row['source_term'],
                    'target' => $row['target_term'],
                    'note' => $row['note'] !== '' ? $row['note'] : null,
                ]);
            }

            if (count($terms) >= self::reviewMax) {
                break;
            }
        }

        return array_values($terms);
    }

    /**
     * The approved translations of a language pair that appear in a text in
     * the target language (whole words, any case), longest first: terms the
     * site wants exactly so, which a check of the page leaves as they are.
     *
     * @return list<string>
     */
    public static function targetTermsIn(string $text, string $sourceLang, string $targetLang): array
    {
        global $wpdb;

        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (trim($text) === '') {
            return [];
        }

        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT target_term FROM '.self::table().' WHERE source_lang = %s AND target_lang = %s AND status = %s ORDER BY CHAR_LENGTH(target_term) DESC',
            $sourceLang,
            $targetLang,
            'approved'
        ));
        $terms = [];

        foreach ((array) $rows as $term) {
            $term = (string) $term;

            if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                $terms[] = $term;
            }

            if (count($terms) >= self::reviewMax) {
                break;
            }
        }

        return $terms;
    }

    /**
     * A term the review or a proofread translation suggests. Seen again on
     * another page, a suggestion counts one more hit; one an editor rejected,
     * or approved already, is left as it is.
     */
    public static function suggest(string $sourceLang, string $targetLang, string $source, string $target, string $origin, string $example = '', int $postId = 0): ?int
    {
        global $wpdb;

        $source = self::cleanTerm($source);
        $target = self::cleanTerm($target);

        // A term, not a sentence: a few words at most.
        if ($source === '' || $target === '' || mb_strlen($source) > 80 || count(preg_split('/\s+/u', $source) ?: []) > 6) {
            return null;
        }

        $existing = self::findByTerms($sourceLang, $targetLang, $source, $target);

        if ($existing) {
            // Another page saying the same counts; the same page reviewed again does not.
            if ($existing['status'] === 'suggested' && (int) $existing['post_id'] !== $postId) {
                $wpdb->query($wpdb->prepare('UPDATE '.self::table().' SET hits = hits + 1, updated_at = %s WHERE id = %d', self::now(), (int) $existing['id']));
            }

            return (int) $existing['id'];
        }

        $id = self::add([
            'sourceLang' => $sourceLang,
            'targetLang' => $targetLang,
            'source' => $source,
            'target' => $target,
            'status' => 'suggested',
            'origin' => $origin,
            'example' => $example,
            'postId' => $postId,
            'hits' => 1,
        ]);

        return is_int($id) ? $id : null;
    }

    /** An editor used a suggestion that carried this term: one more hit. */
    public static function confirm(string $sourceLang, string $targetLang, string $source, string $target): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET hits = hits + 1 WHERE term_hash = %s',
            self::hash($sourceLang, $targetLang, self::cleanTerm($source), self::cleanTerm($target))
        ));
    }

    /**
     * Import DeepL's CSV format: one term per line, `source,target` and
     * optionally the two language codes. Two-column lines take the target
     * language given.
     *
     * Merge adds new terms and gives an existing approved term with the same
     * source the file's translation. Replace first removes the approved and
     * draft terms of the language pairs in the file; suggestions and
     * rejections stay, so the review does not suggest them again.
     *
     * @return array{added: int, updated: int, unchanged: int, skipped: int, pairs: list<string>}|WP_Error
     */
    public static function import(string $path, string $mode = 'merge', string $targetLang = ''): array|WP_Error
    {
        global $wpdb;

        $handle = @fopen($path, 'r');

        if (! $handle) {
            return new WP_Error('gds_ct_glossary_file', __('The file could not be read.', 'gds-content-translation'), ['status' => 400]);
        }

        // A byte order mark before the first quote would make it part of the term.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $sourceDefault = self::sourceCode();
        $targetDefault = strtoupper(sanitize_key($targetLang));
        $rows = [];
        $skipped = 0;
        $line = 0;

        while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            // A header line.
            if ($line === 1 && in_array(strtolower(trim((string) ($fields[0] ?? ''))), ['source', 'source term', 'term'], true)) {
                continue;
            }

            if ($fields === [null] || count($fields) < 2) {
                continue;
            }

            $source = self::cleanTerm($fields[0]);
            $target = self::cleanTerm($fields[1]);
            $from = strtoupper(trim((string) ($fields[2] ?? ''))) ?: $sourceDefault;
            $to = strtoupper(trim((string) ($fields[3] ?? ''))) ?: $targetDefault;

            if ($source === '' || $target === '' || ! preg_match('/^[A-Z]{2,3}$/', $from) || ! preg_match('/^[A-Z]{2,3}$/', $to) || $from === $to) {
                $skipped++;

                continue;
            }

            $rows[] = [$from, $to, $source, $target];
        }

        fclose($handle);

        if ($rows === []) {
            return new WP_Error('gds_ct_glossary_no_terms', __('No terms found in the file. Each line needs the term and its translation, and the two language codes unless you choose the language.', 'gds-content-translation'), ['status' => 400]);
        }

        $pairs = array_values(array_unique(array_map(static fn (array $row): string => $row[0].'-'.$row[1], $rows)));

        if ($mode === 'replace') {
            foreach ($pairs as $pair) {
                [$from, $to] = explode('-', $pair);
                $wpdb->query($wpdb->prepare(
                    'DELETE FROM '.self::table().' WHERE source_lang = %s AND target_lang = %s AND status IN (%s, %s)',
                    $from,
                    $to,
                    'approved',
                    'draft'
                ));
            }
        }

        $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => $skipped];
        $added = [];
        $now = self::now();

        foreach ($rows as [$from, $to, $source, $target]) {
            $same = self::findByTerms($from, $to, $source, $target);

            if ($same) {
                if ($same['status'] === 'approved') {
                    $counts['unchanged']++;
                } else {
                    $wpdb->update(self::table(), ['status' => 'approved', 'updated_at' => $now], ['id' => (int) $same['id']]);
                    $counts['updated']++;
                }

                continue;
            }

            // The file's translation of a source replaces the approved one.
            $other = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM '.self::table().' WHERE source_lang = %s AND target_lang = %s AND source_term = %s AND status = %s ORDER BY id LIMIT 1',
                $from,
                $to,
                $source,
                'approved'
            ));

            // A later line of the same file with the same source is a second
            // translation of it, not a replacement of the first.
            $fromThisFile = $other && in_array($source, $added[$from.'-'.$to] ?? [], true);

            if ($other && ! $fromThisFile) {
                $wpdb->update(self::table(), [
                    'target_term' => $target,
                    'term_hash' => self::hash($from, $to, $source, $target),
                    'origin' => 'import',
                    'updated_at' => $now,
                ], ['id' => (int) $other]);
                $counts['updated']++;
                $added[$from.'-'.$to][] = $source;

                continue;
            }

            $wpdb->insert(self::table(), [
                'source_lang' => $from,
                'target_lang' => $to,
                'source_term' => $source,
                'target_term' => $target,
                'status' => 'approved',
                'origin' => 'import',
                'note' => '',
                'example' => '',
                'post_id' => 0,
                'hits' => 0,
                'term_hash' => self::hash($from, $to, $source, $target),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $counts['added']++;
            $added[$from.'-'.$to][] = $source;
        }

        self::changed();

        return $counts + ['pairs' => $pairs];
    }

    /**
     * Terms as DeepL's CSV: no header, `"source","target","EN","SV"`, with a
     * byte order mark so Excel reads it as UTF-8.
     *
     * @param  list<string>  $statuses
     */
    public static function export(string $targetLang = '', array $statuses = ['approved']): string
    {
        global $wpdb;

        $statuses = array_values(array_intersect($statuses, self::statuses)) ?: ['approved'];
        $sql = 'SELECT source_lang, target_lang, source_term, target_term FROM '.self::table().' WHERE status IN ('.implode(',', array_fill(0, count($statuses), '%s')).')';
        $args = $statuses;

        if ($targetLang !== '') {
            $sql .= ' AND target_lang = %s';
            $args[] = strtoupper($targetLang);
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql.' ORDER BY target_lang, source_term', $args), ARRAY_A);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ((array) $rows as $row) {
            fputcsv($handle, [$row['source_term'], $row['target_term'], $row['source_lang'], $row['target_lang']], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** The approved terms changed: DeepL's copy is out of date. */
    public static function changed(): void
    {
        DeeplGlossary::markPending();
    }
}
