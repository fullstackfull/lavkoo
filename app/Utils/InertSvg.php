<?php

namespace App\Utils;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * An SVG is served from the shop's own origin and opened as a page when someone navigates to it, and then it is a document that runs script
 * there. A list of forbidden words in the bytes does not hold: a prefixed element (`<s:script>`), an entity that expands to markup, a
 * character reference inside a link, a `<set>` that writes a handler. So the file is parsed as XML, and only a plain vector image passes:
 * an SVG root, no processing instruction, no entity that is more than text, no element that scripts, embeds or animates, no element of the
 * page languages (XHTML, MathML, XSLT), no event handler, and no link that leaves the file (a fragment of the same file, or a raster image
 * given as data).
 */
final class InertSvg
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /** The namespaces of the languages a browser acts on; every other namespace (Inkscape, RDF, Adobe) is metadata that nothing runs. */
    private const ACTIVE_NAMESPACES = [
        'http://www.w3.org/1999/xhtml',
        'http://www.w3.org/1998/Math/MathML',
        'http://www.w3.org/1999/XSL/Transform',
    ];

    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'handler', 'listener', 'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor'];

    private const LINK_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'data'];

    private const RASTER_DATA_URL = '#^data:image/(?:png|jpe?g|gif|webp)(?:;[a-z0-9=.-]+)*;base64,[A-Za-z0-9+/=\s]*$#i';

    private const STANDARD_DTD = '#^http://www\.w3\.org/(?:Graphics/SVG|TR)/#';

    private const TEXT_ENTITY = '/<!ENTITY\s+[A-Za-z_][A-Za-z0-9._-]*\s+(?:"[^"<&%]*"|\'[^\'<&%]*\')\s*>/';

    public static function isInert(string $svg): bool
    {
        $document = self::parse(xml: $svg);
        if ($document === null) {
            return false;
        }

        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== self::SVG_NAMESPACE || $root->localName !== 'svg') {
            return false;
        }

        if ((new DOMXPath($document))->query('//processing-instruction()')->length > 0) {
            return false;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!self::isInertElement(element: $element)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The document with its entities expanded, so that every decision is made on the text and the namespaces the browser sees: an entity can
     * name a namespace (`<script xmlns="&h;">`), and without the expansion the element carries the name `&h;` and passes for a stranger. The
     * expansion comes second, once the document type is known to declare nothing but text, so that no entity is ever read from a file.
     */
    private static function parse(string $xml): ?DOMDocument
    {
        $document = self::load(xml: $xml, options: LIBXML_NONET);
        if ($document === null || !self::hasOnlyAPlainDocumentType(document: $document)) {
            return null;
        }

        return $document->doctype === null ? $document : self::load(xml: $xml, options: LIBXML_NONET | LIBXML_NOENT);
    }

    private static function load(string $xml, int $options): ?DOMDocument
    {
        $reportErrorsAsExceptions = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $isWellFormed = $xml !== '' && $document->loadXML($xml, $options | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($reportErrorsAsExceptions);

        return $isWellFormed ? $document : null;
    }

    /**
     * The document type that editors write (`<!DOCTYPE svg PUBLIC ... "http://www.w3.org/Graphics/SVG/...">`, the SVG 1.0 one under
     * `http://www.w3.org/TR/`) is fine. An internal subset is fine when it only names text, as Illustrator's does for its namespaces
     * (`<!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">`): a literal with no markup, no reference and no parameter entity expands
     * to text and to nothing else, and libxml refuses an expansion far larger than the file.
     */
    private static function hasOnlyAPlainDocumentType(DOMDocument $document): bool
    {
        $documentType = $document->doctype;

        return $documentType === null
            || (self::declaresOnlyTextEntities(internalSubset: (string)($documentType->internalSubset ?? ''))
                && ($documentType->systemId === '' || preg_match(self::STANDARD_DTD, (string)$documentType->systemId) === 1));
    }

    private static function declaresOnlyTextEntities(string $internalSubset): bool
    {
        return trim((string)preg_replace(self::TEXT_ENTITY, '', $internalSubset)) === '';
    }

    private static function isInertElement(DOMElement $element): bool
    {
        if (in_array($element->namespaceURI, self::ACTIVE_NAMESPACES, true)) {
            return false;
        }

        if ($element->namespaceURI === self::SVG_NAMESPACE && in_array(strtolower($element->localName), self::FORBIDDEN_ELEMENTS, true)) {
            return false;
        }

        if ($element->namespaceURI === self::SVG_NAMESPACE && strtolower($element->localName) === 'style' && !self::isInertStyle(css: $element->textContent)) {
            return false;
        }

        foreach ($element->attributes as $attribute) {
            if (!self::isInertAttribute(attribute: $attribute)) {
                return false;
            }
        }

        return true;
    }

    private static function isInertAttribute(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName);
        $value = trim($attribute->value);

        if (str_starts_with($name, 'on') || ($name === 'base' && $attribute->namespaceURI === 'http://www.w3.org/XML/1998/namespace')) {
            return false;
        }

        if ($name === 'style') {
            return self::isInertStyle(css: $value);
        }

        if (in_array($name, self::LINK_ATTRIBUTES, true)) {
            return self::staysInTheFile(reference: $value);
        }

        return !self::isScriptUrl(text: $value) && self::loadsOnlyFromTheFile(text: $value);
    }

    private static function staysInTheFile(string $reference): bool
    {
        return $reference === '' || str_starts_with($reference, '#') || self::isRasterDataUrl(address: $reference);
    }

    /**
     * Every `url(...)` of a value (a paint, a filter, a clip path, a style) points into the file or is a raster image given as data. A value
     * that mentions `url` must be well formed for that to be read: a browser loads an address that is not closed (`url(http://x/p.png`) and one
     * written with a CSS escape (`\75rl(`), and neither is a `url(...)` to the pattern below.
     */
    private static function loadsOnlyFromTheFile(string $text): bool
    {
        if (stripos($text, 'url') !== false && (str_contains($text, '\\') || substr_count($text, '(') !== substr_count($text, ')'))) {
            return false;
        }

        preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', $text, $matches);
        foreach ($matches[2] as $address) {
            if (!self::staysInTheFile(reference: trim($address))) {
                return false;
            }
        }

        return true;
    }

    private static function isRasterDataUrl(string $address): bool
    {
        return preg_match(self::RASTER_DATA_URL, $address) === 1;
    }

    /**
     * CSS cannot run script in a current browser, but it can import another sheet and load addresses; keep it to what draws inside the file.
     * A CSS escape (`@\69mport`, `\75rl(`) writes a keyword the patterns do not see, `image-set()` and `src()` load an address from a string,
     * and parentheses that do not match leave a `url(` open: any of them refuses the style.
     */
    private static function isInertStyle(string $css): bool
    {
        return preg_match('/@import|\\\\|image-set\s*\(|(?<![\w-])src\s*\(|expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:/i', $css) !== 1
            && substr_count($css, '(') === substr_count($css, ')')
            && self::loadsOnlyFromTheFile(text: $css);
    }

    private static function isScriptUrl(string $text): bool
    {
        return preg_match('/^[\s\x00-\x20]*(?:javascript|vbscript|data\s*:\s*text\/html)/i', $text) === 1;
    }
}
