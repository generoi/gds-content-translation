<?php

namespace GeneroWP\ContentTranslation;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a visitor of a rendered page sees or hears, as texts: the visible
 * text, the texts of attributes that are shown or read out (alt, title,
 * aria-label, aria-description, placeholder, the value of a button input),
 * the title, the description and sharing tags of the head, the string values
 * of JSON-LD, and the texts of inline SVGs.
 *
 * Each text says where on the page it is, by the outermost landmark around
 * it: the head, the header, a menu, the content, a sidebar or the footer.
 * The same text in the same kind of place is listed once.
 *
 * Pure: it reads HTML and nothing else, so it can be tested with a page.
 * Text that a script adds after the page loads is not in the HTML and so not
 * found.
 */
final class PageText
{
    public const regions = ['head', 'header', 'nav', 'main', 'aside', 'footer', 'body'];

    /** Elements whose content is never shown as text. */
    private const skipped = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'canvas', 'video', 'audio', 'head'];

    /**
     * Elements that run inside a line of text: their text belongs to the
     * sentence around them, so "Read <a>more</a>" is one text.
     */
    private const inline = ['a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'label', 'img', 'picture', 'source', 'br'];

    /** Shown or read out to a visitor. */
    private const attributes = ['alt', 'title', 'aria-label', 'aria-description', 'placeholder'];

    /** Head tags that are shown: in search results, in a shared link. */
    private const metaNames = ['description', 'og:title', 'og:description', 'og:site_name', 'og:image:alt', 'twitter:title', 'twitter:description', 'twitter:image:alt'];

    /** JSON-LD keys whose values are identifiers, addresses or codes, not text. */
    private const jsonLdSkipped = ['url', 'contenturl', 'thumbnailurl', 'embedurl', 'sameas', 'logo', 'image', 'inlanguage', 'datepublished', 'datemodified', 'uploaddate', 'encodingformat', 'telephone', 'email', 'target', 'query-input', 'urltemplate', 'width', 'height', 'duration', 'pricecurrency', 'price', 'isbn', 'sku', 'gtin', 'postalcode'];

    /** Role attribute values that make an element a landmark, as the tag would. */
    private const roles = ['banner' => 'header', 'navigation' => 'nav', 'main' => 'main', 'complementary' => 'aside', 'contentinfo' => 'footer'];

    /**
     * Elements that make a landmark of their own. A header or footer inside
     * the content (an article's header) is not the page's: the outermost
     * landmark is the one that counts.
     */
    private const landmarks = ['header' => 'header', 'nav' => 'nav', 'main' => 'main', 'aside' => 'aside', 'footer' => 'footer'];

    /** @var array<string, array{text: string, where: list<string>, kinds: list<string>}> */
    private array $items = [];

    private string $buffer = '';

    private string $bufferRegion = 'body';

    private string $bufferKind = 'text';

    /**
     * The texts of a page, in the order they appear, each once with every
     * kind of place it is in.
     *
     * @return list<array{text: string, where: list<string>, kinds: list<string>}>
     */
    public static function extract(string $html): array
    {
        $extractor = new self;
        $extractor->read($html);

        return array_values($extractor->items);
    }

    /**
     * How many texts are in each place, for a summary.
     *
     * @param  list<array{text: string, where: list<string>, kinds: list<string>}>  $items
     * @return array<string, int>
     */
    public static function countByRegion(array $items): array
    {
        $counts = array_fill_keys(self::regions, 0);

        foreach ($items as $item) {
            foreach ($item['where'] as $region) {
                $counts[$region]++;
            }
        }

        return array_filter($counts);
    }

    /**
     * Whitespace collapsed and entities decoded, for comparing texts.
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text);

        return trim($text);
    }

    private function read(string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // libxml reads HTML as Latin-1 unless it is told otherwise.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->readHead($document);

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body instanceof DOMElement) {
            $this->walk($body, 'body', false);
            $this->flush();
        }

        // JSON-LD can be anywhere; it describes the page, as the head does.
        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script instanceof DOMElement && strtolower(trim($script->getAttribute('type'))) === 'application/ld+json') {
                $this->readJsonLd(json_decode((string) $script->textContent, true), '');
            }
        }
    }

    private function readHead(DOMDocument $document): void
    {
        $head = $document->getElementsByTagName('head')->item(0);

        if (! $head instanceof DOMElement) {
            return;
        }

        foreach ($head->getElementsByTagName('title') as $title) {
            $this->add((string) $title->textContent, 'head', 'title');
        }

        foreach ($head->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }

            $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));

            if (in_array($name, self::metaNames, true)) {
                $this->add($meta->getAttribute('content'), 'head', 'meta');
            }
        }
    }

    /**
     * @param  bool  $inSvg  Inside an inline SVG: only its text, title and desc are shown.
     */
    private function walk(DOMNode $node, string $region, bool $inSvg): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (! $inSvg || $this->svgTextParent($child)) {
                    $this->append((string) $child->nodeValue, $region, $inSvg ? 'svg' : 'text');
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::skipped, true) || $child->getAttribute('id') === 'wpadminbar') {
                continue;
            }

            // The outermost landmark counts: a header inside the content is the content's.
            $childRegion = $region === 'body' ? $this->landmark($child) ?? 'body' : $region;
            $childInSvg = $inSvg || $tag === 'svg';
            $breaks = ! $childInSvg && ! in_array($tag, self::inline, true);

            if ($breaks || $tag === 'svg') {
                $this->flush();
            }

            if ($tag === 'br') {
                $this->buffer .= ' ';
            }

            $this->readAttributes($child, $tag, $childRegion, $childInSvg);
            $this->walk($child, $childRegion, $childInSvg);

            if ($breaks || in_array($tag, ['svg', 'text', 'title', 'desc'], true)) {
                $this->flush();
            }
        }
    }

    private function svgTextParent(DOMText $text): bool
    {
        for ($node = $text->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, ['text', 'title', 'desc'], true)) {
                return true;
            }

            if ($tag === 'svg') {
                return false;
            }
        }

        return false;
    }

    private function landmark(DOMElement $element): ?string
    {
        $role = strtolower(trim($element->getAttribute('role')));

        return self::roles[$role] ?? self::landmarks[strtolower($element->tagName)] ?? null;
    }

    private function readAttributes(DOMElement $element, string $tag, string $region, bool $inSvg): void
    {
        foreach (self::attributes as $attribute) {
            if ($element->hasAttribute($attribute)) {
                $this->add($element->getAttribute($attribute), $region, $inSvg ? 'svg' : $attribute);
            }
        }

        if ($tag === 'input' && in_array(strtolower($element->getAttribute('type')), ['submit', 'button', 'reset'], true)) {
            $this->add($element->getAttribute('value'), $region, 'value');
        }
    }

    private function append(string $text, string $region, string $kind): void
    {
        if ($this->buffer === '' || trim($this->buffer) === '') {
            $this->bufferRegion = $region;
            $this->bufferKind = $kind;
        }

        $this->buffer .= $text;
    }

    private function flush(): void
    {
        $this->add($this->buffer, $this->bufferRegion, $this->bufferKind);
        $this->buffer = '';
    }

    private function add(string $text, string $region, string $kind): void
    {
        $text = self::normalize($text);

        // Text without letters (a price, 28 × 120, an arrow) has nothing to translate.
        if (mb_strlen($text) < 2 || preg_match('/\p{L}/u', $text) !== 1) {
            return;
        }

        // An address is not text to translate either.
        if (preg_match('#^(https?:)?//\S+$|^\S+@\S+\.\S+$#i', $text) === 1) {
            return;
        }

        $key = mb_strtolower($text);

        if (! isset($this->items[$key])) {
            $this->items[$key] = ['text' => $text, 'where' => [], 'kinds' => []];
        }

        if (! in_array($region, $this->items[$key]['where'], true)) {
            $this->items[$key]['where'][] = $region;
        }

        if (! in_array($kind, $this->items[$key]['kinds'], true)) {
            $this->items[$key]['kinds'][] = $kind;
        }
    }

    /**
     * The string values of JSON-LD, not its types, identifiers, addresses
     * and dates.
     */
    private function readJsonLd(mixed $value, string $key): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $childKey = is_string($childKey) ? $childKey : $key;

                // @graph and @value hold text; @type, @id and @context do not.
                $skipped = str_starts_with($childKey, '@') && ! in_array($childKey, ['@graph', '@value'], true);

                if ($skipped || in_array(strtolower($childKey), self::jsonLdSkipped, true)) {
                    continue;
                }

                $this->readJsonLd($child, $childKey);
            }

            return;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            $this->add(strip_tags($value), 'head', 'jsonld');
        }
    }
}
