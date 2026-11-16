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
 * an SVG root, no processing instruction, no entity, no element that scripts, embeds or animates, no element of the page languages (XHTML,
 * MathML, XSLT), no event handler, and no link that leaves the file (a fragment of the same file, or a raster image given as data).
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

    public static function isInert(string $svg): bool
    {
        if (stripos($svg, '<!entity') !== false) {
            return false;
        }

        $document = self::parse(xml: $svg);
        if ($document === null || !self::hasOnlyAPlainDocumentType(document: $document)) {
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

    private static function parse(string $xml): ?DOMDocument
    {
        $reportErrorsAsExceptions = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $isWellFormed = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($reportErrorsAsExceptions);

        return $isWellFormed ? $document : null;
    }

    /** The document type that editors write (`<!DOCTYPE svg PUBLIC ... "http://www.w3.org/Graphics/SVG/...">`) is fine; one with an internal subset declares entities. */
    private static function hasOnlyAPlainDocumentType(DOMDocument $document): bool
    {
        $documentType = $document->doctype;

        return $documentType === null
            || (($documentType->internalSubset ?? '') === ''
                && ($documentType->systemId === '' || str_starts_with((string)$documentType->systemId, 'http://www.w3.org/Graphics/SVG/')));
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

    /** Every `url(...)` of a value (a paint, a filter, a clip path, a style) points into the file or is a raster image given as data. */
    private static function loadsOnlyFromTheFile(string $text): bool
    {
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

    /** CSS cannot run script in a current browser, but it can import another sheet and load addresses; keep it to what draws inside the file. */
    private static function isInertStyle(string $css): bool
    {
        return preg_match('/@import|expression\s*\(|behaviou?r\s*:|-moz-binding|javascript\s*:|vbscript\s*:/i', $css) !== 1
            && self::loadsOnlyFromTheFile(text: $css);
    }

    private static function isScriptUrl(string $text): bool
    {
        return preg_match('/^[\s\x00-\x20]*(?:javascript|vbscript|data\s*:\s*text\/html)/i', $text) === 1;
    }
}
