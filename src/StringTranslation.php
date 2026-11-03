<?php

namespace GeneroWP\ContentTranslation;

use PLL_Admin_Strings;
use PLL_Language;
use PLL_MO;
use PLL_Translate_Slugs_Model;
use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Polylang's string translations, missing ones first: the texts registered
 * with `pll_register_string()` (the site title, Yoast's titles, widgets,
 * ACF's labels, the theme's texts that Theme Translation for Polylang finds)
 * and Polylang Pro's translated URL slugs, on Polylang → String translations.
 *
 * Nothing of its own is stored: translations are read and written with
 * Polylang's `PLL_MO` per language (the language term's
 * `_pll_strings_translations`), through the same sanitizing filter and the
 * same `pll_save_strings_translations` action as Polylang's own Strings
 * translations screen, so both screens show the same translations and a
 * saved slug flushes the rewrite rules as it does there. Only the AI
 * review's remarks are kept, in an option.
 *
 * Polylang keys a string by its text: the same text in two groups is one
 * string, translated once.
 */
final class StringTranslation
{
    private const remarksOption = 'gds_content_translation_string_remarks';

    /** Texts per Claude call when the AI review checks the strings. */
    private const reviewChunk = 80;

    /** Placeholders DeepL and editors must keep: %s, %1$d, Yoast's %%title%%, {name}. */
    private const placeholder = '/%%[a-z0-9_]+%%|%(?:\d+\$)?[sdf]|\{[^{}\s]+\}/i';

    /**
     * The registered strings with the theme's, once a request: the scan
     * reads every file of the theme, and registers them over the others.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $withTheme = null;

    public static function isAvailable(): bool
    {
        return class_exists(PLL_Admin_Strings::class) && class_exists(PLL_MO::class) && function_exists('PLL');
    }

    /**
     * Every registered text but the URL slugs, by Polylang's key (the md5
     * of the text).
     *
     * A theme's texts are registered by Theme Translation for Polylang only
     * on Polylang's Strings translations screen, so its scanner is run here
     * too: for the themes and plugins chosen in its settings, not for
     * WordPress' own texts, which WordPress' language packs translate.
     *
     * @return array<string, array{id: string, source: string, name: string, group: string, multiline: bool, gettext: bool, fixed: bool, html: bool}>
     */
    public static function texts(): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $all = self::$withTheme ?? PLL_Admin_Strings::get_strings();

        if (self::$withTheme === null && apply_filters('gds_content_translation_strings_scan_theme', class_exists('Polylang_Theme_Translation'))) {
            $registered = $all;
            $scanner = new \Polylang_Theme_Translation;
            $scanner->run_theme_scanner();
            $scanner->run_plugin_scanner();
            // What was registered otherwise keeps its group: the site title is not a theme text.
            $all = self::$withTheme = $registered + PLL_Admin_Strings::get_strings();
        }

        /**
         * Groups of strings that are not shown: WordPress' own texts,
         * translated by its language packs.
         *
         * @param  list<string>  $groups
         */
        $excluded = (array) apply_filters('gds_content_translation_strings_excluded_groups', ['TTfP: WordPress: Admin', 'TTfP: WordPress: Core']);

        /**
         * Strings kept as they are in every language, by name: formats
         * DeepL would break.
         *
         * @param  list<string>  $names
         */
        $kept = (array) apply_filters('gds_content_translation_strings_kept', ['date_format', 'time_format', 'breadcrumbs-sep']);

        $texts = [];

        foreach ($all as $string) {
            $source = (string) ($string['string'] ?? '');
            $name = (string) ($string['name'] ?? '');
            $group = (string) ($string['context'] ?? '');

            // The URL slugs have their own list.
            if ($source === '' || str_starts_with($name, 'slug_') || in_array($group, $excluded, true)) {
                continue;
            }

            $id = md5($source);
            $texts[$id] = [
                'id' => $id,
                'source' => $source,
                'name' => $name,
                'group' => $group,
                'multiline' => ! empty($string['multiline']),
                // A theme's or plugin's text: written in its own language,
                // usually English, so the default language needs it too.
                'gettext' => (bool) apply_filters('gds_content_translation_string_is_gettext', str_starts_with($group, 'TTfP: '), $string),
                'fixed' => in_array($name, $kept, true) || ! self::hasWords($source),
                'html' => $source !== wp_strip_all_tags($source),
            ];
        }

        return $texts;
    }

    /** Whether there is anything to translate: letters besides placeholders, not an address. */
    private static function hasWords(string $text): bool
    {
        $plain = trim(wp_strip_all_tags((string) preg_replace(self::placeholder, ' ', $text)));

        return preg_match('/\p{L}/u', $plain) === 1 && ! filter_var($plain, FILTER_VALIDATE_URL) && ! is_email($plain);
    }

    /**
     * Polylang Pro's translatable URL slugs (post type and taxonomy bases,
     * archives, author, search, pages), when its Translate slugs module is
     * on; by Polylang's key (`tuote`, `category`, `paged`).
     *
     * @return array<string, array{id: string, source: string, name: string, what: string, builtin: bool}>
     */
    public static function slugs(): array
    {
        $model = self::slugsModel();

        if (! $model) {
            return [];
        }

        $slugs = [];

        foreach ($model->get_translatable_slugs() as $key => $type) {
            $source = trim((string) ($type['slug'] ?? ''), '/');

            if ($source === '' || ! empty($type['hide'])) {
                continue;
            }

            $slugs[(string) $key] = [
                'id' => (string) $key,
                'source' => $source,
                'name' => 'slug_'.$key,
                'what' => self::slugLabel((string) $key),
                // WordPress' own bases are English, whatever the site's language.
                'builtin' => in_array($key, ['category', 'post_tag', 'post_format', 'author', 'search', 'attachment', 'paged'], true) || str_starts_with((string) $key, 'post-format-'),
            ];
        }

        return $slugs;
    }

    private static function slugsModel(): ?PLL_Translate_Slugs_Model
    {
        $model = isset(PLL()->translate_slugs) ? (PLL()->translate_slugs->slugs_model ?? null) : null;

        return $model instanceof PLL_Translate_Slugs_Model ? $model : null;
    }

    private static function slugLabel(string $key): string
    {
        if (str_starts_with($key, 'archive_') && ($type = get_post_type_object(substr($key, 8)))) {
            /* translators: %s: post type name, e.g. Products */
            return sprintf(__('%s archive', 'gds-content-translation'), $type->labels->name);
        }

        if (($type = get_post_type_object($key)) && $key !== 'attachment') {
            /* translators: %s: post type name, e.g. Products */
            return sprintf(__('%s (post type)', 'gds-content-translation'), $type->labels->name);
        }

        if ($taxonomy = get_taxonomy($key)) {
            /* translators: %s: taxonomy name, e.g. Categories */
            return sprintf(__('%s (taxonomy)', 'gds-content-translation'), $taxonomy->labels->name);
        }

        if (str_starts_with($key, 'post-format-')) {
            /* translators: %s: post format, e.g. gallery */
            return sprintf(__('Post format: %s', 'gds-content-translation'), substr($key, 12));
        }

        return match ($key) {
            'author' => __('Author pages', 'gds-content-translation'),
            'search' => __('Search results', 'gds-content-translation'),
            'attachment' => __('Attachment pages', 'gds-content-translation'),
            'paged' => __('Page numbers of a list (/page/2/)', 'gds-content-translation'),
            'front' => __('Start of post addresses', 'gds-content-translation'),
            default => $key,
        };
    }

    private static function mo(PLL_Language $language): PLL_MO
    {
        $mo = new PLL_MO;
        $mo->import_from_db($language);

        return $mo;
    }

    /**
     * @return array<string, array<string, array{issue: string, suggestion: string}>>
     */
    private static function remarks(): array
    {
        $remarks = get_option(self::remarksOption, []);

        return is_array($remarks) ? $remarks : [];
    }

    /**
     * @param  array<string, array<string, array{issue: string, suggestion: string}>>  $remarks
     */
    private static function storeRemarks(array $remarks): void
    {
        update_option(self::remarksOption, array_filter($remarks), false);
    }

    /**
     * Whether a text needs a translation in a language: a theme's text in
     * every language, any other in all but the default one, whose text it is.
     *
     * @param  array{gettext: bool, fixed: bool}  $text
     */
    private static function needs(array $text, string $slug): bool
    {
        return ! $text['fixed'] && ($text['gettext'] || $slug !== pll_default_language('slug'));
    }

    /**
     * How many texts and slugs each language is missing.
     *
     * @return array<string, array{texts: int, slugs: int}>
     */
    public static function missingCounts(): array
    {
        $texts = self::texts();
        $slugs = self::slugs();
        $default = (string) pll_default_language('slug');
        $counts = [];

        foreach (PLL()->model->get_languages_list() as $language) {
            $mo = self::mo($language);
            $counts[$language->slug] = [
                'texts' => count(array_filter($texts, static fn (array $text): bool => self::needs($text, $language->slug) && $mo->translate_if_any($text['source']) === '')),
                // The default language's slugs are the originals.
                'slugs' => $language->slug === $default ? 0 : count(array_filter($slugs, static fn (array $type): bool => $mo->translate_if_any($type['source']) === '')),
            ];
        }

        return $counts;
    }

    /**
     * The strings in a language, for the screen.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function rows(string $slug): array|WP_Error
    {
        $language = self::isAvailable() ? PLL()->model->get_language($slug) : false;

        if (! $language) {
            return new WP_Error('gds_ct_strings_language', __('There is no such language.', 'gds-content-translation'), ['status' => 404]);
        }

        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $isDefault = $default && $default->slug === $language->slug;
        $mo = self::mo($language);
        $defaultMo = $default && ! $isDefault ? self::mo($default) : null;
        $remarks = self::remarks()[$slug] ?? [];
        $rows = [];

        foreach (self::texts() as $text) {
            // In the default language, only a theme's texts are translated.
            if ($isDefault && ! $text['gettext']) {
                continue;
            }

            $remark = $remarks[$text['source']] ?? null;
            $rows[] = [
                'id' => $text['id'],
                'source' => $text['source'],
                // A theme's text as the default language shows it, for context.
                'original' => $text['gettext'] && $defaultMo ? $defaultMo->translate_if_any($text['source']) : '',
                'name' => $text['name'],
                'group' => self::groupLabel($text['group']),
                'multiline' => $text['multiline'],
                'html' => $text['html'],
                'fixed' => $text['fixed'],
                'translation' => $mo->translate_if_any($text['source']),
                'remark' => is_array($remark) ? ['issue' => (string) $remark['issue'], 'suggestion' => (string) $remark['suggestion']] : null,
            ];
        }

        $slugs = [];

        foreach (self::slugs() as $type) {
            $slugs[] = [
                'id' => $type['id'],
                'source' => $type['source'],
                'what' => $type['what'],
                'translation' => $mo->translate_if_any($type['source']),
            ];
        }

        return [
            'language' => ['slug' => $language->slug, 'name' => StatusList::languageName($language), 'isDefault' => $isDefault],
            'rows' => $rows,
            'slugs' => $slugs,
            'slugsAvailable' => self::slugsModel() !== null,
            'missing' => self::missingCounts(),
            'canTranslate' => DeeplApi::isAvailable(),
            'canReview' => TranslationAudit::isEnabled(),
        ];
    }

    /** Theme Translation for Polylang's "TTfP: gds" as "Theme: gds". */
    private static function groupLabel(string $group): string
    {
        if (str_starts_with($group, 'TTfP: ')) {
            /* translators: %s: theme or plugin name */
            return sprintf(__('Theme or plugin: %s', 'gds-content-translation'), substr($group, 6));
        }

        return $group;
    }

    /**
     * Save translations as an editor wrote them, by text ID; an empty one is
     * removed. A remark on a text that changed is settled.
     *
     * @param  array<string, string>  $translations
     */
    public static function save(string $slug, array $translations): int|WP_Error
    {
        $texts = self::texts();
        $entries = [];

        foreach ($translations as $id => $translation) {
            if (isset($texts[$id])) {
                $entries[$texts[$id]['source']] = ['translation' => (string) $translation, 'name' => $texts[$id]['name'], 'group' => $texts[$id]['group']];
            }
        }

        $changed = self::store($slug, $entries);

        if ($changed instanceof WP_Error) {
            return $changed;
        }

        $remarks = self::remarks();

        foreach ($changed as $source) {
            unset($remarks[$slug][$source]);
        }

        self::storeRemarks($remarks);

        return count($changed);
    }

    /**
     * Save URL slugs, by slug key. Saving goes through Polylang Pro's
     * sanitizing of slugs and flushes the rewrite rules, as its own screen does.
     *
     * @param  array<string, string>  $translations
     */
    public static function saveSlugs(string $slug, array $translations): int|WP_Error
    {
        $slugs = self::slugs();
        $entries = [];

        foreach ($translations as $key => $translation) {
            if (isset($slugs[$key])) {
                $entries[$slugs[$key]['source']] = ['translation' => self::cleanSlug((string) $translation), 'name' => $slugs[$key]['name'], 'group' => __('URL slugs', 'polylang-pro')];
            }
        }

        $changed = self::store($slug, $entries);

        return $changed instanceof WP_Error ? $changed : count($changed);
    }

    /** A slug as an address can have it: each part sanitized, the slashes between kept. */
    public static function cleanSlug(string $slug): string
    {
        return implode('/', array_filter(array_map('sanitize_title', explode('/', trim($slug, '/ '))), static fn (string $part): bool => $part !== ''));
    }

    /**
     * Write translations into Polylang's strings of a language, as its
     * Strings translations screen does (PLL_Settings_Strings: import, sanitize
     * by filter, add, export, then the action).
     *
     * @param  array<string, array{translation: string, name: string, group: string}>  $entries  By original text.
     * @return list<string>|WP_Error The originals whose translation changed.
     */
    private static function store(string $slug, array $entries): array|WP_Error
    {
        $language = self::isAvailable() ? PLL()->model->get_language($slug) : false;

        if (! $language) {
            return new WP_Error('gds_ct_strings_language', __('There is no such language.', 'gds-content-translation'), ['status' => 404]);
        }

        if ($entries === []) {
            return [];
        }

        // Polylang hooks its own sanitizing only on its settings screens.
        if (! has_filter('pll_sanitize_string_translation', [PLL_Admin_Strings::class, 'sanitize_string_translation'])) {
            PLL_Admin_Strings::init();
        }

        $mo = self::mo($language);
        $changed = [];

        foreach ($entries as $source => $entry) {
            $source = (string) $source;
            $previous = $mo->translate_if_any($source);
            $translation = trim((string) apply_filters('pll_sanitize_string_translation', trim($entry['translation']), $entry['name'], $entry['group'], $source, $previous));

            if ($translation === $previous) {
                continue;
            }

            if ($translation === '') {
                $mo->delete_entry($source);
            } else {
                $mo->add_entry($mo->make_entry($source, $translation));
            }

            $changed[] = $source;
        }

        if ($changed !== []) {
            $mo->export_to_db($language);

            /** Polylang's own: Polylang Pro flushes the rewrite rules for translated slugs. */
            do_action('pll_save_strings_translations');
        }

        return $changed;
    }

    /**
     * Have DeepL translate the texts that have no translation in a language
     * (or those of `$ids`), with the glossary, and save them.
     *
     * A theme's text is translated from its translation in the default
     * language when it has one, which the glossary applies to; else DeepL
     * detects its language.
     *
     * @param  list<string>  $ids
     */
    public static function translateMissing(string $slug, array $ids = []): int|WP_Error
    {
        $language = PLL()->model->get_language($slug);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));

        if (! $language || ! $default) {
            return new WP_Error('gds_ct_strings_language', __('There is no such language.', 'gds-content-translation'));
        }

        $mo = self::mo($language);
        $defaultMo = self::mo($default);
        // Texts by the language they are translated from: the default one, or DeepL's guess.
        $groups = ['default' => [], 'detect' => []];

        foreach (self::texts() as $id => $text) {
            if (($ids !== [] && ! in_array($id, $ids, true)) || ! self::needs($text, $slug) || $mo->translate_if_any($text['source']) !== '') {
                continue;
            }

            $from = $text['gettext'] && $language->slug !== $default->slug ? $defaultMo->translate_if_any($text['source']) : '';

            if ($text['gettext'] && $from === '') {
                $groups['detect'][$id] = $text['source'];
            } else {
                $groups['default'][$id] = $from !== '' ? $from : $text['source'];
            }
        }

        $translations = [];

        foreach ($groups as $group => $sources) {
            if ($sources === []) {
                continue;
            }

            $translated = self::deepl(array_values($sources), $group === 'default' ? $default : null, $language);

            if ($translated instanceof WP_Error) {
                return $translated;
            }

            $translations += array_combine(array_keys($sources), $translated);
        }

        return $translations === [] ? 0 : self::save($slug, $translations);
    }

    /**
     * DeepL's translation of some slugs, made URL-safe, to look at before
     * saving: a changed slug changes the addresses. Nothing is saved.
     *
     * @param  list<string>  $keys  None: the ones without a translation.
     * @return array<string, string>|WP_Error Slug key => suggestion.
     */
    public static function suggestSlugs(string $slug, array $keys = []): array|WP_Error
    {
        $language = PLL()->model->get_language($slug);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));

        if (! $language || ! $default) {
            return new WP_Error('gds_ct_strings_language', __('There is no such language.', 'gds-content-translation'));
        }

        $mo = self::mo($language);
        $groups = ['default' => [], 'detect' => []];

        foreach (self::slugs() as $key => $type) {
            if ($keys !== [] ? ! in_array($key, $keys, true) : $mo->translate_if_any($type['source']) !== '') {
                continue;
            }

            // "tuotteet" or "kayttokohde" as words: DeepL translates words, not slugs.
            $groups[$type['builtin'] ? 'detect' : 'default'][$key] = str_replace(['-', '_', '/'], [' ', ' ', ' / '], $type['source']);
        }

        $suggestions = [];

        foreach ($groups as $group => $sources) {
            if ($sources === []) {
                continue;
            }

            $translated = DeeplApi::translate(array_values($sources), $group === 'default' ? $default : null, $language);

            if ($translated instanceof WP_Error) {
                return $translated;
            }

            foreach (array_combine(array_keys($sources), $translated) as $key => $text) {
                $suggestions[$key] = self::cleanSlug(str_replace(' / ', '/', (string) $text));
            }
        }

        return array_filter($suggestions, static fn (string $suggestion): bool => $suggestion !== '');
    }

    /**
     * DeepL, with the placeholders a text needs (%s, %%title%%) marked
     * untranslatable, and sent as HTML for that.
     *
     * @param  list<string>  $texts
     * @return list<string>|WP_Error
     */
    private static function deepl(array $texts, ?PLL_Language $source, PLL_Language $target): array|WP_Error
    {
        $marked = array_map(static fn (string $text): string => (string) preg_replace_callback(self::placeholder, static fn (array $match): string => '<span translate="no">'.esc_html($match[0]).'</span>', $text), $texts);
        $translated = DeeplApi::translate($marked, $source, $target, true);

        if ($translated instanceof WP_Error) {
            return $translated;
        }

        foreach ($translated as $index => $text) {
            $text = (string) preg_replace('#<span translate="no">(.*?)</span>#s', '$1', $text);
            // A plain text came back as HTML only because of its placeholders.
            $wasPlain = $texts[$index] === wp_strip_all_tags($texts[$index]);
            $translated[$index] = $wasPlain && $marked[$index] !== $texts[$index] ? html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $text;
        }

        return $translated;
    }

    /**
     * Have the AI review check a language's translations, as it checks a
     * form's. Remarks replace the language's earlier ones.
     */
    public static function review(string $slug): int|WP_Error
    {
        $language = PLL()->model->get_language($slug);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));

        if (! $language || ! $default || ! TranslationAudit::isEnabled()) {
            return new WP_Error('gds_ct_review_off', __('The AI review is not available.', 'gds-content-translation'));
        }

        $mo = self::mo($language);
        $pairs = [];

        foreach (self::texts() as $text) {
            $translation = self::needs($text, $slug) ? $mo->translate_if_any($text['source']) : '';

            if ($translation !== '') {
                $pairs[] = ['source' => $text['source'], 'translation' => $translation, 'what' => trim(self::groupLabel($text['group']).' – '.$text['name'], ' –')];
            }
        }

        $glossary = Glossary::termsIn(implode("\n", array_column($pairs, 'source')), Glossary::code($default), Glossary::code($language));
        $remarks = [];

        foreach (array_chunk($pairs, self::reviewChunk) as $chunk) {
            $answer = (new TranslationAudit)->ask(
                'You review the translations of a website\'s short texts: its title and tagline, search engine titles, menu, button and screen reader labels, and the texts of its theme. Most originals are in the source language; a theme\'s texts may be in English. Report only real problems a reader would notice: wrong meaning, omissions, wrong terms, unnatural or inconsistent wording, a label that does not fit what it is. Each remark comes with a suggestion: the whole corrected translation of that text, from its first word to its last, never only the part that changes, since it replaces the translation as it is. Keep HTML and placeholders such as %s, %1$d and %%title%% exactly as they are.',
                (string) wp_json_encode([
                    'sourceLanguage' => $default->name,
                    'targetLanguage' => $language->name,
                    'glossary' => $glossary,
                    'texts' => array_map(static fn (array $pair, int $index): array => ['id' => $index] + $pair, $chunk, array_keys($chunk)),
                ], JSON_UNESCAPED_UNICODE),
                [
                    'type' => 'object',
                    'properties' => [
                        'remarks' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'issue' => ['type' => 'string'],
                                    'suggestion' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'issue', 'suggestion'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['remarks'],
                    'additionalProperties' => false,
                ]
            );

            if ($answer instanceof WP_Error) {
                return $answer;
            }

            foreach ((array) ($answer['remarks'] ?? []) as $remark) {
                $pair = $chunk[(int) ($remark['id'] ?? -1)] ?? null;
                $suggestion = trim((string) ($remark['suggestion'] ?? ''));

                // A suggestion much shorter than the text is a part of it: using it would cut the text.
                $whole = $pair && mb_strlen($suggestion) >= 0.6 * mb_strlen((string) $pair['translation']);

                if ($pair && $whole && $suggestion !== '' && $suggestion !== $pair['translation']) {
                    $remarks[$pair['source']] = ['issue' => trim((string) $remark['issue']), 'suggestion' => $suggestion];
                }
            }
        }

        $stored = self::remarks();
        $stored[$slug] = $remarks;
        self::storeRemarks($stored);

        return count($remarks);
    }

    /**
     * Take back a remark: used (its suggestion saved) or not an issue.
     */
    public static function settleRemark(string $slug, string $id): true|WP_Error
    {
        $text = self::texts()[$id] ?? null;
        $stored = self::remarks();

        if (! $text || ! isset($stored[$slug][$text['source']])) {
            return new WP_Error('gds_ct_strings_remark', __('This remark is no longer there. Reload the page.', 'gds-content-translation'));
        }

        unset($stored[$slug][$text['source']]);
        self::storeRemarks($stored);

        return true;
    }

    /**
     * Use a remark's suggestion.
     */
    public static function useRemark(string $slug, string $id): int|WP_Error
    {
        $text = self::texts()[$id] ?? null;
        $remark = $text ? (self::remarks()[$slug][$text['source']] ?? null) : null;

        if (! is_array($remark)) {
            return new WP_Error('gds_ct_strings_remark', __('This remark is no longer there. Reload the page.', 'gds-content-translation'));
        }

        $saved = self::save($slug, [$id => (string) $remark['suggestion']]);
        self::settleRemark($slug, $id);

        return $saved;
    }

    public static function screenUrl(string $slug = '', string $section = ''): string
    {
        return add_query_arg(array_filter(['page' => Admin::stringsPageSlug, 'strings_lang' => $slug ?: null, 'section' => $section ?: null]), admin_url('admin.php'));
    }
}
