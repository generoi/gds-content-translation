<?php

namespace GeneroWP\ContentTranslation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Word-level difference between two short texts, as HTML with the removed
 * words in <del> and the added ones in <ins>.
 *
 * Compares the visible text (tags removed), which is what a translator reads;
 * segments are a sentence or a paragraph, so a plain longest-common-
 * subsequence table is small enough.
 */
final class TextDiff
{
    public static function render(string $from, string $to): string
    {
        $old = self::tokens($from);
        $new = self::tokens($to);
        $html = '';

        foreach (self::operations($old, $new) as [$type, $token]) {
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
