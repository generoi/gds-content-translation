<?php

namespace GeneroWP\ContentTranslation;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Forms forms in every language: one form, its texts translated.
 *
 * A form's labels, descriptions, placeholders, choices, buttons, messages
 * and confirmations are kept per language, translated by DeepL with the
 * glossary, checked by the AI review and edited on Polylang → Form
 * translations. On a page in another language the form shows its texts in
 * that language; what it submits (choice values) and where its entries go
 * stay the same, and each entry records its language.
 *
 * Notifications sent to the visitor (to an email field of the form) are
 * translated too; the ones to the site's own people are left as written.
 *
 * Texts are stored by their original: a changed label is a new text without
 * a translation yet, and the same text twice is translated once.
 */
final class FormTranslation
{
    private const optionPrefix = 'gds_content_translation_form_';

    public const entryMetaKey = 'gds_ct_language';

    private const langField = 'gds_ct_lang';

    /** Texts per Claude call when the AI review checks a form. */
    private const reviewChunk = 80;

    public static function init(): void
    {
        if (! self::isAvailable()) {
            return;
        }

        foreach (['gform_pre_render', 'gform_pre_validation', 'gform_pre_submission_filter'] as $filter) {
            add_filter($filter, [self::class, 'translateForm'], 5);
        }

        add_filter('gform_form_tag', [self::class, 'addLanguageField'], 10, 2);
        add_filter('gform_notification', [self::class, 'translateNotification'], 10, 3);
        add_action('gform_entry_created', [self::class, 'recordLanguage'], 10, 2);
        add_filter('gform_entry_meta', [self::class, 'entryMeta'], 10, 2);
        // A new or changed text has no translation yet: the pages showing the form count it again.
        add_action('gform_after_save_form', static fn ($form) => is_array($form) && ! empty($form['id']) ? MediaCheck::recheckUsing('form:'.(int) $form['id']) : null);
    }

    public static function isAvailable(): bool
    {
        return class_exists('GFAPI') && function_exists('pll_current_language');
    }

    /**
     * The language a form is shown or submitted in, when it is not the
     * default one. A submission says it in a field of its own, since an
     * AJAX submission's request has no language of its own.
     */
    public static function currentLanguage(): string
    {
        $default = (string) pll_default_language('slug');
        $posted = isset($_POST[self::langField]) && is_string($_POST[self::langField]) ? sanitize_key(wp_unslash($_POST[self::langField])) : '';
        $slug = $posted !== '' && in_array($posted, pll_languages_list(), true) ? $posted : (string) pll_current_language('slug');

        return $slug !== $default ? $slug : '';
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    public static function translateForm($form)
    {
        $slug = self::currentLanguage();

        if (! is_array($form) || $slug === '' || empty($form['id']) || (is_admin() && ! wp_doing_ajax())) {
            return $form;
        }

        $translations = self::translations((int) $form['id'], $slug);

        if ($translations === []) {
            return $form;
        }

        return self::walk($form, static fn (string $text): string => $translations[$text] ?? $text);
    }

    public static function addLanguageField(string $tag, $form): string
    {
        $slug = self::currentLanguage();

        return $slug !== '' ? $tag.'<input type="hidden" name="'.self::langField.'" value="'.esc_attr($slug).'">' : $tag;
    }

    /**
     * @param  array<string, mixed>  $notification
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function translateNotification($notification, $form, $entry)
    {
        $slug = is_array($entry) ? (string) gform_get_meta((int) ($entry['id'] ?? 0), self::entryMetaKey) : '';

        if ($slug === '' || ! is_array($notification) || ! self::isToVisitor($notification)) {
            return $notification;
        }

        $translations = self::translations((int) ($form['id'] ?? 0), $slug);

        foreach (['subject', 'message', 'fromName'] as $key) {
            if (isset($notification[$key]) && is_string($notification[$key]) && isset($translations[$notification[$key]])) {
                $notification[$key] = $translations[$notification[$key]];
            }
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private static function isToVisitor(array $notification): bool
    {
        return ($notification['toType'] ?? '') === 'field' && ($notification['isActive'] ?? true);
    }

    public static function recordLanguage($entry, $form): void
    {
        $slug = self::currentLanguage() ?: (string) pll_default_language('slug');

        if (is_array($entry) && ! empty($entry['id'])) {
            gform_update_meta((int) $entry['id'], self::entryMetaKey, $slug, (int) ($form['id'] ?? 0));
        }
    }

    /**
     * A Language column in the form's entries.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function entryMeta($meta, $formId)
    {
        $meta[self::entryMetaKey] = [
            'label' => __('Language', 'gds-content-translation'),
            'is_numeric' => false,
            'is_default_column' => false,
        ];

        return $meta;
    }

    /**
     * Every text of a form, translated or not, and what each is.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, string> Text => what it is ("Email – label").
     */
    public static function texts(array $form): array
    {
        $texts = [];
        self::walk($form, static function (string $text, string $context) use (&$texts): string {
            $texts[$text] ??= $context;

            return $text;
        }, true);

        return $texts;
    }

    /**
     * Run a callback over a form's texts and put back what it returns.
     *
     * @param  array<string, mixed>  $form
     * @param  callable(string, string): string  $change  Text, what it is.
     * @return array<string, mixed>
     */
    public static function walk(array $form, callable $change, bool $withNotifications = false): array
    {
        // Only texts that are there: a missing key stays missing, Gravity
        // Forms reads a key set to null differently.
        $at = static function (array $array, array $path, string $context) use (&$at, $change): array {
            $key = array_shift($path);

            if (! array_key_exists($key, $array)) {
                return $array;
            }

            if ($path !== []) {
                if (is_array($array[$key])) {
                    $array[$key] = $at($array[$key], $path, $context);
                }

                return $array;
            }

            $value = $array[$key];

            if (is_string($value) && preg_match('/\p{L}/u', wp_strip_all_tags($value))) {
                $array[$key] = $change($value, $context);
            }

            return $array;
        };

        foreach ([
            [['title'], __('Form title', 'gds-content-translation')],
            [['description'], __('Form description', 'gds-content-translation')],
            [['button', 'text'], __('Submit button', 'gds-content-translation')],
            [['save', 'button', 'text'], __('Save and continue', 'gds-content-translation')],
            [['lastPageButton', 'text'], __('Previous button', 'gds-content-translation')],
            [['requireLoginMessage'], __('Login message', 'gds-content-translation')],
            [['limitEntriesMessage'], __('Entry limit message', 'gds-content-translation')],
            [['scheduleMessage'], __('Schedule message', 'gds-content-translation')],
            [['schedulePendingMessage'], __('Schedule message', 'gds-content-translation')],
        ] as [$path, $context]) {
            $form = $at($form, $path, $context);
        }

        foreach (array_keys((array) ($form['pagination']['pages'] ?? [])) as $index) {
            $form = $at($form, ['pagination', 'pages', $index], __('Page name', 'gds-content-translation'));
        }

        foreach ((array) ($form['confirmations'] ?? []) as $id => $confirmation) {
            if (is_array($confirmation) && ($confirmation['type'] ?? 'message') === 'message') {
                $form = $at($form, ['confirmations', $id, 'message'], __('Confirmation message', 'gds-content-translation'));
            }
        }

        if ($withNotifications) {
            foreach ((array) ($form['notifications'] ?? []) as $id => $notification) {
                if (is_array($notification) && self::isToVisitor($notification)) {
                    $name = (string) ($notification['name'] ?? '');
                    /* translators: %s: name of the notification */
                    $form = $at($form, ['notifications', $id, 'subject'], sprintf(__('Email to the visitor “%s” – subject', 'gds-content-translation'), $name));
                    /* translators: %s: name of the notification */
                    $form = $at($form, ['notifications', $id, 'message'], sprintf(__('Email to the visitor “%s” – message', 'gds-content-translation'), $name));
                    /* translators: %s: name of the notification */
                    $form = $at($form, ['notifications', $id, 'fromName'], sprintf(__('Email to the visitor “%s” – from name', 'gds-content-translation'), $name));
                }
            }
        }

        $apply = static function ($value, string $context) use ($change) {
            return is_string($value) && preg_match('/\p{L}/u', wp_strip_all_tags($value)) ? $change($value, $context) : $value;
        };

        foreach ((array) ($form['fields'] ?? []) as $index => $field) {
            if (! is_object($field) && ! is_array($field)) {
                continue;
            }

            // GF_Field objects are shared with Gravity Forms' cache: change a copy.
            $field = is_object($field) ? clone $field : (object) $field;
            $name = trim(wp_strip_all_tags((string) ($field->label ?? ''))) ?: (string) ($field->type ?? '');
            /* translators: 1: field label, 2: which text of it, e.g. description */
            $context = static fn (string $what): string => sprintf(__('“%1$s” – %2$s', 'gds-content-translation'), $name, $what);

            foreach ([
                'label' => __('label', 'gds-content-translation'),
                'description' => __('description', 'gds-content-translation'),
                'placeholder' => __('placeholder', 'gds-content-translation'),
                'errorMessage' => __('error message', 'gds-content-translation'),
                'content' => __('content', 'gds-content-translation'),
                'checkboxLabel' => __('checkbox', 'gds-content-translation'),
                'customLabel' => __('label', 'gds-content-translation'),
            ] as $property => $what) {
                if (isset($field->{$property})) {
                    $field->{$property} = $apply($field->{$property}, $context($what));
                }
            }

            foreach (['choices' => 'text', 'inputs' => 'label'] as $list => $key) {
                if (! is_array($field->{$list} ?? null)) {
                    continue;
                }

                $items = $field->{$list};

                foreach ($items as $itemIndex => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    foreach ([$key => $list === 'choices' ? __('choice', 'gds-content-translation') : __('sub-label', 'gds-content-translation'), 'placeholder' => __('placeholder', 'gds-content-translation'), 'customLabel' => __('sub-label', 'gds-content-translation')] as $itemKey => $what) {
                        if (isset($item[$itemKey])) {
                            $items[$itemIndex][$itemKey] = $apply($item[$itemKey], $context($what));
                        }
                    }
                }

                $field->{$list} = $items;
            }

            foreach (['nextButton', 'previousButton'] as $button) {
                if (is_array($field->{$button} ?? null) && isset($field->{$button}['text'])) {
                    $value = $field->{$button};
                    $value['text'] = $apply($value['text'], $context(__('button', 'gds-content-translation')));
                    $field->{$button} = $value;
                }
            }

            $form['fields'][$index] = $field;
        }

        return $form;
    }

    /**
     * @return array{strings: array<string, array<string, string>>, remarks: array<string, array<string, array{issue: string, suggestion: string}>>}
     */
    private static function stored(int $formId): array
    {
        $stored = get_option(self::optionPrefix.$formId, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'strings' => (array) ($stored['strings'] ?? []),
            'remarks' => (array) ($stored['remarks'] ?? []),
        ];
    }

    /**
     * @param  array{strings: array<string, array<string, string>>, remarks: array<string, array<string, mixed>>}  $stored
     */
    private static function store(int $formId, array $stored): void
    {
        update_option(self::optionPrefix.$formId, $stored, false);
        MediaCheck::recheckUsing('form:'.$formId);
    }

    /**
     * A form's translations in a language, original => translation.
     *
     * @return array<string, string>
     */
    public static function translations(int $formId, string $slug): array
    {
        $strings = self::stored($formId)['strings'][$slug] ?? [];

        return is_array($strings) ? array_filter($strings, static fn ($text): bool => is_string($text) && $text !== '') : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function form(int $formId): ?array
    {
        $form = self::isAvailable() ? \GFAPI::get_form($formId) : false;

        return is_array($form) ? $form : null;
    }

    /**
     * How much of a form is translated into a language.
     *
     * @return array{total: int, translated: int, remarks: int}
     */
    public static function progress(int $formId, string $slug): array
    {
        $form = self::form($formId);

        if (! $form) {
            return ['total' => 0, 'translated' => 0, 'remarks' => 0];
        }

        $texts = self::texts($form);
        $translations = self::translations($formId, $slug);
        $remarks = (array) (self::stored($formId)['remarks'][$slug] ?? []);

        return [
            'total' => count($texts),
            'translated' => count(array_intersect_key($translations, $texts)),
            'remarks' => count(array_intersect_key($remarks, $texts)),
        ];
    }

    public static function isComplete(int $formId, string $slug): bool
    {
        $progress = self::progress($formId, $slug);

        return $progress['total'] > 0 && $progress['translated'] >= $progress['total'];
    }

    /**
     * Every form with its progress per language, for the screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function overview(): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $languages = StatusList::translationLanguageSlugs();
        $forms = [];

        foreach ((array) \GFAPI::get_forms(true, false, 'title') as $form) {
            $progress = [];

            foreach ($languages as $slug) {
                $progress[$slug] = self::progress((int) $form['id'], $slug);
            }

            $forms[] = [
                'id' => (int) $form['id'],
                'title' => (string) $form['title'],
                'languages' => $progress,
                'editUrl' => admin_url('admin.php?page=gf_edit_forms&id='.(int) $form['id']),
            ];
        }

        return $forms;
    }

    /**
     * A form's texts in a language, for the screen.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function rows(int $formId, string $slug): array|WP_Error
    {
        $form = self::form($formId);
        $language = PLL()->model->get_language($slug);

        if (! $form || ! $language || $slug === pll_default_language('slug')) {
            return new WP_Error('gds_ct_form_missing', __('There is no such form or language.', 'gds-content-translation'), ['status' => 404]);
        }

        $translations = self::translations($formId, $slug);
        $remarks = (array) (self::stored($formId)['remarks'][$slug] ?? []);
        $rows = [];

        foreach (self::texts($form) as $text => $context) {
            $rows[] = [
                'source' => $text,
                'context' => $context,
                'translation' => $translations[$text] ?? '',
                'html' => $text !== wp_strip_all_tags($text),
                'remark' => isset($remarks[$text]) ? ['issue' => (string) $remarks[$text]['issue'], 'suggestion' => (string) $remarks[$text]['suggestion']] : null,
            ];
        }

        return [
            'form' => ['id' => $formId, 'title' => (string) $form['title'], 'editUrl' => admin_url('admin.php?page=gf_edit_forms&id='.$formId)],
            'language' => StatusList::languageName($language),
            'rows' => $rows,
            'canTranslate' => DeeplApi::isAvailable(),
            'canReview' => TranslationAudit::isEnabled(),
        ];
    }

    /**
     * Save translations as an editor wrote them; an empty one is removed.
     * A remark on a text that changed is settled.
     *
     * @param  array<string, string>  $translations
     */
    public static function save(int $formId, string $slug, array $translations): void
    {
        $stored = self::stored($formId);
        $current = (array) ($stored['strings'][$slug] ?? []);

        foreach ($translations as $source => $translation) {
            $translation = self::clean((string) $source, (string) $translation);

            if ($translation === '') {
                unset($current[$source]);
            } elseif (($current[$source] ?? '') !== $translation) {
                $current[$source] = $translation;
                unset($stored['remarks'][$slug][$source]);
            }
        }

        $stored['strings'][$slug] = $current;
        self::store($formId, $stored);
    }

    private static function clean(string $source, string $translation): string
    {
        $translation = trim($translation);

        // Texts with HTML (descriptions, messages) keep what a post may have.
        return $source !== wp_strip_all_tags($source) ? wp_kses_post($translation) : sanitize_textarea_field($translation);
    }

    /**
     * Have DeepL translate the texts that have no translation yet.
     */
    public static function translateMissing(int $formId, string $slug): int|WP_Error
    {
        $form = self::form($formId);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $language = PLL()->model->get_language($slug);

        if (! $form || ! $default || ! $language) {
            return new WP_Error('gds_ct_form_missing', __('There is no such form or language.', 'gds-content-translation'));
        }

        $missing = array_values(array_diff(array_keys(self::texts($form)), array_keys(self::translations($formId, $slug))));

        if ($missing === []) {
            return 0;
        }

        $translated = DeeplApi::translate($missing, $default, $language, true);

        if ($translated instanceof WP_Error) {
            return $translated;
        }

        self::save($formId, $slug, array_combine($missing, $translated));

        return count($missing);
    }

    /**
     * Have the AI review check a form's translations, as it checks a page's.
     */
    public static function review(int $formId, string $slug): int|WP_Error
    {
        $form = self::form($formId);
        $default = PLL()->model->get_language((string) pll_default_language('slug'));
        $language = PLL()->model->get_language($slug);

        if (! $form || ! $default || ! $language || ! TranslationAudit::isEnabled()) {
            return new WP_Error('gds_ct_review_off', __('The AI review is not available.', 'gds-content-translation'));
        }

        $texts = self::texts($form);
        $translations = array_intersect_key(self::translations($formId, $slug), $texts);
        $pairs = [];

        foreach ($translations as $source => $translation) {
            $pairs[] = ['source' => $source, 'translation' => $translation, 'what' => $texts[$source]];
        }

        $glossary = Glossary::termsIn(implode("\n", array_keys($translations)), Glossary::code($default), Glossary::code($language));
        $remarks = [];

        foreach (array_chunk($pairs, self::reviewChunk) as $chunk) {
            $answer = (new TranslationAudit)->ask(
                'You review the translation of a web form: its labels, choices, buttons and messages. Report only real problems a reader would notice: wrong meaning, omissions, wrong terms, unnatural or inconsistent wording, a label that does not fit its field. Each remark comes with a suggestion: the whole corrected translation of that text, from its first word to its last, never only the part that changes, since it replaces the translation as it is. Keep HTML and merge tags such as {Name:1} exactly as they are.',
                (string) wp_json_encode([
                    'form' => (string) $form['title'],
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

        $stored = self::stored($formId);
        $stored['remarks'][$slug] = $remarks;
        self::store($formId, $stored);

        return count($remarks);
    }

    /**
     * Take back a remark: used (its suggestion saved) or not an issue.
     */
    public static function settleRemark(int $formId, string $slug, string $source, bool $use): void
    {
        $stored = self::stored($formId);
        $remark = $stored['remarks'][$slug][$source] ?? null;
        unset($stored['remarks'][$slug][$source]);
        self::store($formId, $stored);

        if ($use && is_array($remark)) {
            self::save($formId, $slug, [$source => (string) $remark['suggestion']]);
        }
    }

    /**
     * After an AI translation of a page with a form: its texts that have no
     * translation in that language yet go to DeepL. Errors are logged.
     */
    public static function translateFormsOf(int $postId): void
    {
        $slug = (string) pll_get_post_language($postId);

        foreach ((array) (MediaCheck::get($postId)['items'] ?? []) as $item) {
            if ($item['kind'] === 'form' && $item['state'] === 'open') {
                $result = self::translateMissing((int) $item['formId'], $slug);

                if ($result instanceof WP_Error) {
                    error_log(sprintf('[gds-content-translation] Translating form %d into %s failed: %s', $item['formId'], $slug, $result->get_error_message()));
                }
            }
        }
    }

    /**
     * The forms of a translation's list and how far each is translated.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array{total: int, translated: int, remarks: int, url: string}>
     */
    public static function statusFor(int $postId, array $items): array
    {
        $slug = (string) pll_get_post_language($postId);
        $status = [];

        foreach ($items as $item) {
            if ($item['kind'] === 'form' && ! empty($item['formId'])) {
                $status[(int) $item['formId']] = self::progress((int) $item['formId'], $slug) + ['url' => self::screenUrl((int) $item['formId'], $slug)];
            }
        }

        return $status;
    }

    public static function screenUrl(int $formId = 0, string $slug = ''): string
    {
        return add_query_arg(array_filter(['page' => Admin::formsPageSlug, 'form' => $formId ?: null, 'form_lang' => $slug ?: null]), admin_url('admin.php'));
    }
}
