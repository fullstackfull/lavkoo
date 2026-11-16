<?php

namespace App\Utils;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A sitemap the admin uploads is stored and copied to /sitemap.xml, so the shop's own origin serves it as XML. A browser treats an XML
 * document as a page when its root is XHTML or SVG, applies an xml-stylesheet to it, loads an XHTML stylesheet link and runs an XHTML script
 * element wherever it stands in the tree, so "a file whose name ends in .xml" is a page that runs script in the shop's origin. This accepts
 * the sitemap vocabulary and nothing a browser acts on: no DOCTYPE, no processing instruction, a sitemap root, and elements of the sitemap
 * namespaces (plus the one XHTML element a sitemap uses, the alternate-language link).
 *
 * The encoding is read before the markup, so it decides what the checks above looked at. libxml2 knows encodings through ICU (HZ, UTF-7,
 * ISO-2022-KR, SCSU, UTF-16 ...) that a browser does not, and bytes the validator reads as text there are markup to the browser. The file
 * is therefore refused unless it is read as ASCII-compatible text everywhere: no null byte, and no declared encoding but UTF-8, ASCII or
 * ISO-8859-1.
 */
final class SitemapXml
{
    private const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    /** The extensions Google defines (image, video, news): no browser gives their elements a meaning. */
    private const EXTENSION_NAMESPACES = [
        'http://www.google.com/schemas/sitemap-image/1.1',
        'http://www.google.com/schemas/sitemap-video/1.1',
        'http://www.google.com/schemas/sitemap-news/0.9',
    ];

    private const ROOT_NAMES = ['urlset', 'sitemapindex'];

    private const PLAIN_ENCODINGS = ['utf-8', 'us-ascii', 'iso-8859-1'];

    private const ALTERNATE_LINK_ATTRIBUTES = ['rel', 'hreflang', 'href', 'media'];

    public static function isSitemap(string $xml): bool
    {
        if (str_contains($xml, "\0") || stripos($xml, '<!doctype') !== false || stripos($xml, '<!entity') !== false) {
            return false;
        }

        $document = self::parse(xml: $xml);
        if ($document === null || $document->doctype !== null || !self::declaresPlainEncoding(document: $document)) {
            return false;
        }

        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== self::SITEMAP_NAMESPACE || !in_array($root->localName, self::ROOT_NAMES, true)) {
            return false;
        }

        if ((new DOMXPath($document))->query('//processing-instruction()')->length > 0) {
            return false;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (!self::isAllowedElement(element: $element)) {
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

    private static function declaresPlainEncoding(DOMDocument $document): bool
    {
        return $document->xmlEncoding === null || in_array(strtolower($document->xmlEncoding), self::PLAIN_ENCODINGS, true);
    }

    private static function isAllowedElement(DOMElement $element): bool
    {
        $namespace = $element->namespaceURI;

        if ($namespace === self::XHTML_NAMESPACE) {
            return $element->localName === 'link'
                && $element->getAttribute('rel') === 'alternate'
                && self::hasOnlyAttributes(element: $element, names: self::ALTERNATE_LINK_ATTRIBUTES);
        }

        if ($namespace !== self::SITEMAP_NAMESPACE && !in_array($namespace, self::EXTENSION_NAMESPACES, true)) {
            return false;
        }

        foreach ($element->attributes as $attribute) {
            if (!self::isHarmlessAttribute(attribute: $attribute)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $names */
    private static function hasOnlyAttributes(DOMElement $element, array $names): bool
    {
        foreach ($element->attributes as $attribute) {
            if (self::isNamespaceDeclaration(attribute: $attribute)) {
                continue;
            }
            if ($attribute->namespaceURI !== null || !in_array($attribute->localName, $names, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isHarmlessAttribute(DOMAttr $attribute): bool
    {
        if (self::isNamespaceDeclaration(attribute: $attribute)) {
            return true;
        }
        if ($attribute->namespaceURI === self::XSI_NAMESPACE) {
            return $attribute->localName === 'schemaLocation';
        }

        return $attribute->namespaceURI === null && !str_starts_with(strtolower($attribute->localName), 'on');
    }

    private static function isNamespaceDeclaration(DOMAttr $attribute): bool
    {
        return $attribute->namespaceURI === self::XMLNS_NAMESPACE || $attribute->name === 'xmlns' || str_starts_with($attribute->name, 'xmlns:');
    }
}
