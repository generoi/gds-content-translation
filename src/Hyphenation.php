<?php

namespace GeneroWP\ContentTranslation;

use Vanderlee\Syllable\Syllable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Soft hyphens in the long words of headings and buttons, where a narrow
 * screen has to break them: "terrass­brädorna", "pilari­perustukset". They
 * are written into the content as wp-gutenberg-shy writes them, so an editor
 * sees them in the block editor and can move or remove them; that plugin
 * shows each as a plain soft hyphen on the page.
 *
 * Where a word may break comes from TeX's hyphenation patterns for the
 * language (vanderlee/syllable). A word that already has a soft hyphen is
 * the editor's: left as it is.
 */
final class Hyphenation
{
    public const optionKey = 'gds_content_translation_hyphenation';

    /** wp-gutenberg-shy's markup for one soft hyphen. */
    public const marker = '<span class="is-shy-character">'."\u{00AD}".'</span>';

    /** @var array<string, Syllable|null> */
    private static array $syllables = [];

    public static function isAvailable(): bool
    {
        return class_exists(Syllable::class);
    }

    /**
     * On by default where the library is installed.
     */
    public static function isEnabled(): bool
    {
        return self::isAvailable() && (bool) apply_filters('gds_content_translation_hyphenation', (bool) get_option(self::optionKey, true));
    }

    /**
     * Words this long or longer get soft hyphens.
     */
    public static function minLength(): int
    {
        return max(6, (int) apply_filters('gds_content_translation_hyphenation_min_length', 12));
    }

    /**
     * TeX's patterns for a Polylang language: its locale (sv_SE → sv,
     * en_GB → en-gb), else its slug.
     */
    private static function syllable(string $locale): ?Syllable
    {
        $locale = strtolower(str_replace('_', '-', $locale));

        if (array_key_exists($locale, self::$syllables)) {
            return self::$syllables[$locale];
        }

        $dir = dirname((string) (new \ReflectionClass(Syllable::class))->getFileName(), 2).'/languages';
        [$base] = explode('-', $locale);
        $candidates = array_unique([$locale, $base, $base === 'en' ? 'en-us' : $base]);
        $syllable = null;

        foreach ($candidates as $code) {
            if (is_readable($dir.'/hyph-'.$code.'.tex')) {
                Syllable::setLanguageDir($dir);
                $syllable = new Syllable($code);
                // Parsed again when needed: hyphenating happens when content is
                // written, not on every page view, and vendor may not be writable.
                $syllable->setCache(null);
                break;
            }
        }

        return self::$syllables[$locale] = $syllable;
    }

    /**
     * A post's headings and buttons, hyphenated. The post is saved only when
     * something changed; returns whether it did.
     */
    public static function applyToPost(int $postId): bool
    {
        $post = get_post($postId);
        $language = PLL()->model->post->get_language($postId);

        if (! $post || ! $language || ! self::isEnabled()) {
            return false;
        }

        $content = self::applyToContent($post->post_content, (string) $language->locale);

        if ($content === $post->post_content) {
            return false;
        }

        // Unslashed, every JSON escape in block attributes would be stripped.
        $result = wp_update_post(['ID' => $postId, 'post_content' => wp_slash($content)], true);

        return ! is_wp_error($result);
    }

    /**
     * The headings (h1–h6) and button links of a post's content, hyphenated;
     * the rest as it was.
     */
    public static function applyToContent(string $content, string $locale): string
    {
        return self::walk($content, $locale, true);
    }

    /**
     * All the text of an HTML fragment, hyphenated: a heading's text the
     * review writes back, say.
     */
    public static function applyToText(string $html, string $locale): string
    {
        return self::walk($html, $locale, false);
    }

    /**
     * Whether a text has soft hyphens of an editor's or this class's.
     */
    public static function has(string $html): bool
    {
        return SoftHyphens::strip($html) !== $html;
    }

    private static function walk(string $html, string $locale, bool $onlyTargets): string
    {
        if ($html === '' || ! self::isEnabled() || ! preg_match('/\p{L}{'.self::minLength().',}/u', $html)) {
            return $html;
        }

        $syllable = self::syllable($locale);

        if (! $syllable) {
            return $html;
        }

        // Existing soft hyphens (an editor's markup, or a bare one) stand in as
        // one character while words are read, so a word with one is left alone.
        $kept = [];
        $html = (string) preg_replace_callback('~<span\b[^>]*\bis-shy-character\b[^>]*>[^<]*</span>|&shy;|&#173;|&#xad;~i', static function (array $match) use (&$kept): string {
            $kept[] = $match[0];

            return "\u{E000}";
        }, $html);

        $parts = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
        $skipped = ['code', 'pre', 'script', 'style', 'kbd', 'samp', 'textarea'];
        // The open elements: name, whether hyphenated (a heading, a button's
        // link) and whether never (code).
        $open = [];
        $output = '';

        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '<') {
                $output .= $part;

                if (preg_match('~^<(/?)([a-z][a-z0-9-]*)\b([^>]*)>~i', $part, $tag)) {
                    $name = strtolower($tag[2]);

                    if ($tag[1] === '/') {
                        // Back to the element it closes.
                        for ($i = count($open) - 1; $i >= 0; $i--) {
                            if ($open[$i][0] === $name) {
                                array_splice($open, $i);
                                break;
                            }
                        }
                    } elseif (! in_array($name, $void, true) && ! str_ends_with(rtrim($tag[3]), '/')) {
                        $open[] = [$name, (bool) preg_match('/^h[1-6]$/', $name) || ($name === 'a' && str_contains($tag[3], 'wp-block-button__link')), in_array($name, $skipped, true)];
                    }
                }

                continue;
            }

            $target = in_array(true, array_column($open, 1), true);
            $never = in_array(true, array_column($open, 2), true);
            $output .= ($onlyTargets && ! $target) || $never ? $part : self::hyphenateText($part, $syllable);
        }

        $index = 0;

        return (string) preg_replace_callback('~\x{E000}~u', static function () use (&$index, $kept): string {
            return $kept[$index++] ?? self::marker;
        }, $output);
    }

    private static function hyphenateText(string $text, Syllable $syllable): string
    {
        $min = self::minLength();

        // Words with their soft hyphens, so one that has any is passed over.
        return (string) preg_replace_callback('~[\p{L}\x{00AD}\x{E000}]+~u', static function (array $match) use ($syllable, $min): string {
            $word = $match[0];

            if (mb_strlen($word) < $min || preg_match('~[\x{00AD}\x{E000}]~u', $word) || preg_match('~^\p{Lu}+$~u', $word)) {
                return $word;
            }

            return self::fewBreaks($syllable->splitWord($word));
        }, $text);
    }

    /**
     * A word's syllables joined at a few of the places it may break: about
     * one every eight letters, at the allowed places closest to even parts,
     * none leaving fewer than four letters on a side. A marker at every
     * syllable would fill the editor with them.
     *
     * @param  list<string>  $syllables
     */
    private static function fewBreaks(array $syllables): string
    {
        $word = implode('', $syllables);
        $length = mb_strlen($word);
        $minPart = 4;
        $breaks = max(1, intdiv($length, 8));
        $places = [];
        $at = 0;

        foreach (array_slice($syllables, 0, -1) as $part) {
            $at += mb_strlen($part);
            $places[] = $at;
        }

        $chosen = [];

        for ($i = 1; $i <= $breaks; $i++) {
            $target = $length * $i / ($breaks + 1);
            $best = null;

            foreach ($places as $place) {
                $previous = $chosen === [] ? 0 : max($chosen);

                if ($place - $previous < $minPart || $length - $place < $minPart) {
                    continue;
                }

                if ($best === null || abs($place - $target) < abs($best - $target)) {
                    $best = $place;
                }
            }

            if ($best !== null) {
                $chosen[] = $best;
            }
        }

        $chosen = array_values(array_unique($chosen));
        sort($chosen);
        $result = '';
        $from = 0;

        foreach ($chosen as $place) {
            $result .= mb_substr($word, $from, $place - $from).self::marker;
            $from = $place;
        }

        return $result.mb_substr($word, $from);
    }
}
