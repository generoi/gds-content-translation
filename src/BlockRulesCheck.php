<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which texts of the theme's and plugins' blocks are not translated: string
 * attributes without a rule in `pll_blocks_rules_for_attributes` (which this
 * plugin fills from `gds_content_translation_pll_blocks_rules_for_attributes`)
 * whose value the block shows as text. Polylang translates a block's HTML,
 * not its attributes, so a dynamic block's button text stays the original's.
 *
 * Only what shows: a block on some page is rendered, and an attribute counts
 * when its value is in the rendered text and not already in the block's own
 * HTML (which is translated). Class names, slugs and URLs never show as text.
 * Done when someone asks for it, and kept a week.
 */
final class BlockRulesCheck
{
    private const transient = 'gds_content_translation_block_rules_check';

    /** Pages rendered per block type. */
    private const postsPerBlock = 30;

    private static ?object $rules = null;

    /** After the rules change (a filter added later in the request). */
    public static function flushRules(): void
    {
        self::$rules = null;
    }

    /**
     * The last check, if there is one.
     *
     * @return array{checkedAt: int, missing: list<array{block: string, title: string, attribute: string, pages: int, example: string}>}|null
     */
    public static function cached(): ?array
    {
        $result = get_transient(self::transient);

        return is_array($result) ? $result : null;
    }

    public static function forget(): void
    {
        delete_transient(self::transient);
    }

    /**
     * Check every block type of the theme and plugins that the site's content uses.
     *
     * @return array{checkedAt: int, missing: list<array{block: string, title: string, attribute: string, pages: int, example: string}>}
     */
    public static function run(): array
    {
        global $wpdb;

        $missing = [];

        foreach (\WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type) {
            if (str_starts_with($name, 'core/')) {
                continue;
            }

            $candidates = self::untranslatedStringAttributes($name, (array) $type->attributes);

            if ($candidates === []) {
                continue;
            }

            $postIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'draft', 'pending', 'private', 'future') AND post_type NOT IN ('revision', 'attachment') AND post_content LIKE %s ORDER BY ID DESC LIMIT %d",
                '%'.$wpdb->esc_like('<!-- wp:'.$name.' ').'%',
                self::postsPerBlock
            )));
            $found = [];

            foreach ($postIds as $postId) {
                foreach (self::instances(parse_blocks((string) get_post_field('post_content', $postId)), $name) as $block) {
                    foreach (self::shownAttributes($block, $candidates) as $attribute => $value) {
                        $found[$attribute]['pages'][$postId] = true;
                        $found[$attribute]['example'] ??= $value;
                    }
                }
            }

            foreach ($found as $attribute => $data) {
                $missing[] = [
                    'block' => $name,
                    'title' => (string) ($type->title ?: $name),
                    'attribute' => (string) $attribute,
                    'pages' => count($data['pages']),
                    'example' => mb_substr((string) $data['example'], 0, 80),
                ];
            }
        }

        $result = ['checkedAt' => time(), 'missing' => $missing];
        set_transient(self::transient, $result, WEEK_IN_SECONDS);

        return $result;
    }

    /**
     * A block type's string attributes that have no rule to translate them.
     *
     * @param  array<string, array<string, mixed>>  $attributes
     * @return list<string>
     */
    public static function untranslatedStringAttributes(string $name, array $attributes): array
    {
        $rules = self::rulesFor($name);
        $names = [];

        foreach ($attributes as $attribute => $schema) {
            $type = (array) ($schema['type'] ?? []);

            // Read from the HTML (`source`): translated with it.
            if (! in_array('string', $type, true) || isset($schema['source']) || isset($schema['enum'])) {
                continue;
            }

            $ruled = array_filter(array_keys($rules), static fn ($pattern): bool => self::matching([(string) $attribute => true], (string) $pattern) !== []);

            if ($ruled === []) {
                $names[] = (string) $attribute;
            }
        }

        return $names;
    }

    /**
     * The attributes of a block that it shows as text, rendered.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $candidates
     * @return array<string, string> Attribute => its value.
     */
    private static function shownAttributes(array $block, array $candidates): array
    {
        $values = array_filter(
            array_intersect_key((array) $block['attrs'], array_flip($candidates)),
            static fn ($value): bool => is_string($value) && mb_strlen(trim($value)) >= 3 && preg_match('/\p{L}{2,}/u', $value) === 1
        );

        if ($values === []) {
            return [];
        }

        $normalize = static fn (string $html): string => ' '.trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags(str_replace('<', ' <', $html)), ENT_QUOTES, 'UTF-8'))).' ';
        $ownText = $normalize((string) ($block['innerHTML'] ?? ''));

        try {
            $rendered = $normalize(render_block($block));
        } catch (\Throwable) {
            return [];
        }

        $shown = [];

        foreach ($values as $attribute => $value) {
            $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value)));

            if ($text !== '' && str_contains($rendered, $text) && ! str_contains($ownText, $text)) {
                $shown[(string) $attribute] = $text;
            }
        }

        return $shown;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function instances(array $blocks, string $name): array
    {
        $found = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === $name) {
                $found[] = $block;
            }

            array_push($found, ...self::instances((array) ($block['innerBlocks'] ?? []), $name));
        }

        return $found;
    }

    /**
     * The values of a block's attributes that Polylang translates as text.
     *
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    public static function translatedValues(array $block): array
    {
        $name = (string) ($block['blockName'] ?? '');

        if ($name === '' || empty($block['attrs'])) {
            return [];
        }

        return self::collect((array) $block['attrs'], self::rulesFor($name));
    }

    /**
     * @return list<string>
     */
    private static function collect(mixed $value, mixed $rules): array
    {
        if ($rules === true) {
            if (is_string($value)) {
                return [$value];
            }

            if (! is_array($value)) {
                return [];
            }

            $strings = [];
            array_walk_recursive($value, static function ($leaf) use (&$strings): void {
                if (is_string($leaf)) {
                    $strings[] = $leaf;
                }
            });

            return $strings;
        }

        if (! is_array($rules) || ! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($rules as $pattern => $subRules) {
            foreach (self::matching($value, (string) $pattern) as $key) {
                array_push($strings, ...self::collect($value[$key], $subRules));
            }
        }

        return $strings;
    }

    /**
     * The keys of an array a rule's name matches (it may have `*`).
     *
     * @param  array<array-key, mixed>  $array
     * @return list<array-key>
     */
    private static function matching(array $array, string $pattern): array
    {
        if (! str_contains($pattern, '*')) {
            return array_key_exists($pattern, $array) ? [$pattern] : [];
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return array_values(array_filter(array_keys($array), static fn ($key): bool => preg_match($regex, (string) $key) === 1));
    }

    /**
     * Polylang's rules to translate a block's attributes, this plugin's included.
     *
     * @return array<string, mixed>
     */
    private static function rulesFor(string $name): array
    {
        if (class_exists(\PLL_Translation_Block_Parsing_Rules::class)) {
            self::$rules ??= new \PLL_Translation_Block_Parsing_Rules;

            return (array) self::$rules->get_attributes_to_translate(['blockName' => $name, 'attrs' => ['_' => true]]);
        }

        $rules = (array) apply_filters('pll_blocks_rules_for_attributes', []);

        return (array) ($rules[$name] ?? []);
    }
}
