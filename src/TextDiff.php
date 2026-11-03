<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Word-level difference between two short texts, as HTML with the removed
 * words in <del> and the added ones in <ins>.
 *
 * Compares the visible text (tags removed), which is what a translator reads.
 * The words both texts start and end with are left out of the comparison,
 * and what remains goes into a plain longest-common-subsequence table, as
 * long as that table stays small: a long text rewritten throughout gets no
 * difference, and the review page shows the suggestion as it is.
 */
final class TextDiff
{
    /** Most cells of the comparison table, one per pair of changed words, spaces and punctuation. */
    public const maxCells = 250000;

    /**
     * @return string The HTML, or '' when the texts are too long and too different to compare.
     */
    public static function render(string $from, string $to): string
    {
        $old = self::tokens($from);
        $new = self::tokens($to);

        // The same start and end need no table.
        $prefix = 0;

        while ($prefix < count($old) && $prefix < count($new) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < count($old) - $prefix && $suffix < count($new) - $prefix && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
            $suffix++;
        }

        $oldMiddle = array_slice($old, $prefix, count($old) - $prefix - $suffix);
        $newMiddle = array_slice($new, $prefix, count($new) - $prefix - $suffix);

        if ((count($oldMiddle) + 1) * (count($newMiddle) + 1) > self::maxCells) {
            return '';
        }

        $operations = array_merge(
            array_map(static fn (string $token): array => ['same', $token], array_slice($old, 0, $prefix)),
            self::operations($oldMiddle, $newMiddle),
            array_map(static fn (string $token): array => ['same', $token], array_slice($old, count($old) - $suffix))
        );
        $html = '';

        foreach ($operations as [$type, $token]) {
            $escaped = esc_html($token);
            $html .= match ($type) {
                'del' => trim($token) === '' ? $escaped : '<del>'.$escaped.'</del>',
                'ins' => trim($token) === '' ? $escaped : '<ins>'.$escaped.'</ins>',
                default => $escaped,
            };
        }

        // Neighbouring changes read as one: "<del>a</del> <del>b</del>" → "<del>a b</del>".
        return (string) preg_replace(['#</del>(\s+)<del>#u', '#</ins>(\s+)<ins>#u'], ['$1', '$1'], $html);
    }

    /**
     * @return list<string> Words, spaces and punctuation, in order.
     */
    private static function tokens(string $text): array
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_split('/(\s+|[^\p{L}\p{N}\s]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: string, 1: string}> [type, token], type "same", "del" or "ins".
     */
    private static function operations(array $old, array $new): array
    {
        $rows = count($old);
        $columns = count($new);
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $operations = [];
        $i = 0;
        $j = 0;

        while ($i < $rows && $j < $columns) {
            if ($old[$i] === $new[$j]) {
                $operations[] = ['same', $old[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $operations[] = ['del', $old[$i++]];
            } else {
                $operations[] = ['ins', $new[$j++]];
            }
        }

        while ($i < $rows) {
            $operations[] = ['del', $old[$i++]];
        }

        while ($j < $columns) {
            $operations[] = ['ins', $new[$j++]];
        }

        return $operations;
    }
}
