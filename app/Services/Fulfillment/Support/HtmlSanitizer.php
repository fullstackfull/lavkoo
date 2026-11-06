<?php

namespace App\Services\Fulfillment\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Makes HTML written by a THIRD PARTY safe to print on the storefront.
 *
 * A supplier's product description is shown to every customer unescaped (the platform renders product details as HTML so
 * administrators can format them), so whatever the supplier's payload contains runs in the customer's browser. This does
 * not try to repair HTML: it parses it, then writes a NEW document from the parts it recognises. Only the tags and
 * attributes below are ever written, every value is escaped, links may only use http(s), mailto and tel, images only https,
 * and anything that can run, load or restyle (scripts, styles, frames, forms, SVG/MathML, event handlers, inline CSS) is
 * dropped, together with the content of the elements that are dropped whole.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'sub', 'sup', 'span', 'div', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'a', 'img',
    ];

    /** Elements dropped WITH their content: unwrapping them would print their code as text, or let it run elsewhere. */
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'noscript', 'noembed', 'noframes',
        'template', 'form', 'input', 'button', 'select', 'option', 'textarea', 'link', 'meta', 'base', 'title', 'head', 'audio', 'video',
        'source', 'track', 'canvas', 'dialog', 'xmp', 'plaintext', 'listing', 'marquee', 'portal',
    ];

    private const VOID_TAGS = ['br', 'hr', 'img'];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);

        return $body === null ? '' : trim(self::renderChildren($body));
    }

    private static function renderChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= self::render($child);
        }

        return $output;
    }

    private static function render(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars((string) $node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
            return '';
        }

        $inner = self::renderChildren($node);
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            return $inner;
        }

        $attributes = self::attributes($node, $tag);
        if ($tag === 'img' && !str_contains($attributes, ' src=')) {
            return '';
        }

        return in_array($tag, self::VOID_TAGS, true) ? "<{$tag}{$attributes}>" : "<{$tag}{$attributes}>{$inner}</{$tag}>";
    }

    private static function attributes(DOMElement $element, string $tag): string
    {
        $written = '';
        foreach (self::ALLOWED_ATTRIBUTES[$tag] ?? [] as $name) {
            if (!$element->hasAttribute($name)) {
                continue;
            }

            $value = self::safeValue($tag, $name, $element->getAttribute($name));
            if ($value !== null) {
                $written .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
            }
        }

        return $tag === 'a' && str_contains($written, ' href=') ? $written . ' rel="noopener noreferrer nofollow"' : $written;
    }

    private static function safeValue(string $tag, string $name, string $value): ?string
    {
        return match ($name) {
            'href' => self::url($value, self::LINK_SCHEMES),
            'src' => self::url($value, ['https'], allowRelative: false),
            'target' => strtolower($value) === '_blank' ? '_blank' : null,
            'width', 'height' => preg_match('/^\d{1,4}%?$/', trim($value)) === 1 ? trim($value) : null,
            'colspan', 'rowspan' => preg_match('/^\d{1,2}$/', trim($value)) === 1 ? trim($value) : null,
            default => mb_substr(str_replace(['<', '>'], '', $value), 0, 255),
        };
    }

    /**
     * A URL whose scheme is on the list. Whitespace and control characters are stripped before the scheme is read, because
     * browsers ignore them ("java\tscript:" is "javascript:"); a value that still has a scheme not on the list is refused.
     *
     * @param list<string> $schemes
     */
    private static function url(string $value, array $schemes, bool $allowRelative = true): ?string
    {
        $value = trim($value);
        $compact = preg_replace('/[\x00-\x20\x7f-\x9f\x{200b}-\x{200f}\x{2028}\x{2029}\x{feff}]+/u', '', $value) ?? '';
        if ($compact === '') {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $compact, $matches) === 1) {
            return in_array(strtolower($matches[1]), $schemes, true) ? $compact : null;
        }

        return $allowRelative && !str_starts_with($compact, '//') ? $compact : null;
    }
}
