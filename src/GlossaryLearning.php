<?php

namespace GeneroWP\ContentTranslation;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * How the glossary grows: terms the AI review says should be translated one
 * way across the site, and the term changes editors make in an AI
 * translation before marking it proofread. Both become suggestions on the
 * glossary screen; nothing is used before an editor accepts it.
 *
 * For the second, the machine translation's texts are kept when it is
 * created. Once the translation is marked proofread, the texts an editor
 * changed are compared with them, in the background, and Claude names the
 * terms that changed ("stöd" became "väggstag").
 */
final class GlossaryLearning
{
    /** The machine translation's texts, as created: list of [source, translation]. */
    public const machineTextMetaKey = '_gds_content_translation_machine_text';

    /** When the proofread translation was compared, so it is compared once. */
    public const learnedMetaKey = '_gds_content_translation_glossary_learned';

    private const cronHook = 'gds_content_translation_learn_glossary';

    /** Changed texts sent to Claude at most. */
    private const changesMax = 80;

    public static function init(): void
    {
        add_action(self::cronHook, [self::class, 'learn']);
    }

    public static function isEnabled(): bool
    {
        return (bool) apply_filters('gds_content_translation_glossary_learn', TranslationAudit::isConfigured());
    }

    /** A new machine translation: keep its texts to compare with later. */
    public static function rememberMachineText(int $translationId): void
    {
        $translation = get_post($translationId);
        $sourceId = (int) pll_get_post($translationId, (string) pll_default_language('slug'));
        $source = $sourceId > 0 ? get_post($sourceId) : null;

        if (! $translation instanceof WP_Post || ! $source instanceof WP_Post) {
            return;
        }

        $pairs = array_map(
            static fn (array $segment): array => [$segment['source'], $segment['translation']],
            TranslationAudit::currentSegments($source, $translation)['segments']
        );

        update_post_meta($translationId, self::machineTextMetaKey, wp_slash($pairs));
        delete_post_meta($translationId, self::learnedMetaKey);
    }

    /** Marked proofread: compare it in the background. */
    public static function proofread(int $translationId): void
    {
        if (! self::isEnabled() || ! get_post_meta($translationId, self::machineTextMetaKey, true) || get_post_meta($translationId, self::learnedMetaKey, true)) {
            return;
        }

        if (! wp_next_scheduled(self::cronHook, [$translationId])) {
            wp_schedule_single_event(time(), self::cronHook, [$translationId]);
            spawn_cron();
        }
    }

    /**
     * The terms of the review's remarks, as suggestions.
     *
     * @param  array<string, mixed>  $review
     */
    public static function fromReview(int $translationId, array $review): int
    {
        $sourceLang = Glossary::code((string) self::locale($review['sourceLanguage'] ?? ''));
        $targetLang = Glossary::code((string) self::locale($review['targetLanguage'] ?? ''));
        $segments = array_column((array) ($review['segments'] ?? []), null, 'id');
        $count = 0;

        if ($sourceLang === '' || $targetLang === '' || $sourceLang === $targetLang) {
            return 0;
        }

        foreach ((array) ($review['remarks'] ?? []) as $remark) {
            $term = $remark['term'] ?? null;

            if (! is_array($term)) {
                continue;
            }

            $example = (string) ($segments[$remark['segment']]['source'] ?? '');

            if (Glossary::suggest($sourceLang, $targetLang, (string) $term['source'], (string) $term['target'], 'review', $example, $translationId) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private static function locale(string $slug): string
    {
        $language = $slug !== '' ? PLL()->model->get_language($slug) : false;

        return $language ? $language->locale : '';
    }

    /**
     * The changes an editor made to a proofread machine translation, by
     * original text.
     *
     * @return list<array{source: string, machine: string, edited: string}>
     */
    public static function changes(int $translationId): array
    {
        $translation = get_post($translationId);
        $sourceId = (int) pll_get_post($translationId, (string) pll_default_language('slug'));
        $source = $sourceId > 0 ? get_post($sourceId) : null;
        $machine = get_post_meta($translationId, self::machineTextMetaKey, true);

        if (! $translation instanceof WP_Post || ! $source instanceof WP_Post || ! is_array($machine)) {
            return [];
        }

        // By original text: blocks added or moved since do not shift the pairs.
        $machineBySource = [];

        foreach ($machine as [$sourceText, $translationText]) {
            $machineBySource[$sourceText] ??= $translationText;
        }

        $changes = [];

        foreach (TranslationAudit::currentSegments($source, $translation)['segments'] as $segment) {
            $before = $machineBySource[$segment['source']] ?? null;

            if ($before === null || trim(wp_strip_all_tags($before)) === trim(wp_strip_all_tags($segment['translation']))) {
                continue;
            }

            $changes[$segment['source']] = ['source' => $segment['source'], 'machine' => $before, 'edited' => $segment['translation']];
        }

        return array_slice(array_values($changes), 0, self::changesMax);
    }

    /** Ask Claude which terms the editor's changes settle, and suggest them. */
    public static function learn(int $translationId): int
    {
        if (get_post_meta($translationId, self::learnedMetaKey, true)) {
            return 0;
        }

        update_post_meta($translationId, self::learnedMetaKey, time());

        $changes = self::changes($translationId);
        $sourceSlug = (string) pll_default_language('slug');
        $targetSlug = (string) pll_get_post_language($translationId);
        $sourceLanguage = PLL()->model->get_language($sourceSlug);
        $targetLanguage = $targetSlug !== '' ? PLL()->model->get_language($targetSlug) : false;

        if ($changes === [] || ! $sourceLanguage || ! $targetLanguage) {
            return 0;
        }

        $sourceLang = Glossary::code($sourceLanguage);
        $targetLang = Glossary::code($targetLanguage);
        $result = (new TranslationAudit)->ask(
            self::systemPrompt(),
            (string) wp_json_encode([
                'source_language' => sprintf('%s (%s)', $sourceLanguage->name, $sourceLanguage->locale),
                'target_language' => sprintf('%s (%s)', $targetLanguage->name, $targetLanguage->locale),
                'glossary' => Glossary::termsIn(implode("\n", array_column($changes, 'source')), $sourceLang, $targetLang) ?: null,
                'changes' => $changes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::schema()
        );

        if (is_wp_error($result)) {
            error_log(sprintf('[gds-content-translation] Learning glossary terms from post %d failed: %s', $translationId, $result->get_error_message()));

            return 0;
        }

        $count = 0;

        foreach ((array) ($result['terms'] ?? []) as $term) {
            if (! is_array($term)) {
                continue;
            }

            $example = '';

            foreach ($changes as $change) {
                if (stripos(wp_strip_all_tags($change['source']), (string) ($term['source'] ?? '')) !== false) {
                    $example = $change['source'];

                    break;
                }
            }

            if (Glossary::suggest($sourceLang, $targetLang, (string) ($term['source'] ?? ''), (string) ($term['target'] ?? ''), 'proofread', $example, $translationId) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You help keep a translation glossary for a website: terms of the original language with the one translation they must have everywhere on the site, such as product names, technical words and fixed phrases.

An editor proofread a machine translation. You get the texts they changed: the original, the machine translation, and the editor's version. Find the terms whose translation the editor changed on purpose, where the editor's choice should be used across the whole site.

Rules:
- Only terms: a word or a short phrase of up to a few words, not a sentence.
- Give the term in its base form in both languages (singular, nominative, no article), as a glossary lists it, even when the text uses an inflected form.
- Leave out changes of grammar, word order, style, punctuation and spelling mistakes, and rewording that is not about a term.
- Leave out a term the user message lists in the glossary with the same translation.
- When unsure whether a change is a terminology decision, leave it out. No terms is a fine answer.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'terms' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'source' => ['type' => 'string'],
                            'target' => ['type' => 'string'],
                        ],
                        'required' => ['source', 'target'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['terms'],
            'additionalProperties' => false,
        ];
    }
}
