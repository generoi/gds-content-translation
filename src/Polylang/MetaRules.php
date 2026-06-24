<?php

namespace GeneroWP\ContentTranslation\Polylang;

/**
 * Bridges project-specific post meta rules into Polylang Pro export filters.
 */
class MetaRules
{
    public function __construct()
    {
        add_filter('pll_post_metas_to_export', [$this, 'mergePostMetasToExport'], 10, 3);
        add_filter('acf/load_field', [$this, 'applyAcfFieldTranslationModes']);
    }

    /**
     * @param  array<string, mixed>  $metas
     * @return array<string, mixed>
     */
    public function mergePostMetasToExport(array $metas, int $from, int $to): array
    {
        $postType = get_post_type($from);

        if (! is_string($postType) || $postType === '') {
            return $metas;
        }

        /** @var array<string, array<string, mixed>> $rulesByPostType */
        $rulesByPostType = apply_filters('gds_content_translation_pll_post_metas_to_export', []);

        if ($rulesByPostType === [] || ! isset($rulesByPostType[$postType])) {
            return $metas;
        }

        $typeRules = $rulesByPostType[$postType];

        if (! is_array($typeRules) || $typeRules === []) {
            return $metas;
        }

        return array_merge($metas, $typeRules);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public function applyAcfFieldTranslationModes(array $field): array
    {
        if (empty($field['name']) || ! is_string($field['name'])) {
            return $field;
        }

        /** @var array<string, string> $modesByFieldName */
        $modesByFieldName = apply_filters('gds_content_translation_acf_field_translations', []);

        if ($modesByFieldName === [] || ! isset($modesByFieldName[$field['name']])) {
            return $field;
        }

        $mode = $modesByFieldName[$field['name']];

        if (is_string($mode) && $mode !== '') {
            $field['translations'] = $mode;
        }

        return $field;
    }
}
