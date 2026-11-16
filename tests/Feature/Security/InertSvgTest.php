<?php

namespace Tests\Feature\Security;

use App\Services\Theme\ThemeAssetService;
use App\Utils\InertSvg;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Theme assets may be SVG logos, and an SVG is a document: opened by its address it runs script in the shop's origin. The old check looked for
 * forbidden words in the bytes (`<script`, `javascript:`, `<use`, `on...=`), which a prefixed element, a character reference, an entity or
 * a `<set>` that writes a handler walk around. The file is now parsed and only a plain vector image passes.
 */
class InertSvgTest extends TestCase
{
    private const SVG = 'xmlns="http://www.w3.org/2000/svg"';

    private const XLINK = 'xmlns:xlink="http://www.w3.org/1999/xlink"';

    private const XHTML_ENTITY = '<!ENTITY h "http://www.w3.org/1999/xhtml">';

    private const SVG_ENTITY = '<!ENTITY s "http://www.w3.org/2000/svg">';

    /** @return array<string, array{0: string}> */
    public static function imagesThatAreFine(): array
    {
        $svg = self::SVG;
        $xlink = self::XLINK;

        return [
            'the smallest image' => ["<svg $svg viewBox=\"0 0 10 10\"><path d=\"M0 0h10v10z\"/></svg>"],
            'what an editor writes (declaration and the W3C document type)' => ['<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n" . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n<svg $svg width=\"1\" height=\"1\"><rect width=\"1\" height=\"1\"/></svg>"],
            'a gradient and a paint that points to it' => ["<svg $svg><defs><linearGradient id=\"a\"><stop offset=\"0\" stop-color=\"#fff\"/></linearGradient></defs><rect fill=\"url(#a)\" width=\"1\" height=\"1\"/></svg>"],
            'a filter, a clip path and a mask by fragment' => ["<svg $svg><defs><filter id=\"f\"><feGaussianBlur stdDeviation=\"1\"/></filter><clipPath id=\"c\"><rect width=\"1\" height=\"1\"/></clipPath></defs><g filter=\"url(#f)\" clip-path=\"url('#c')\" style=\"fill:url(#a)\"><rect width=\"2\" height=\"2\"/></g></svg>"],
            'a use of a fragment' => ["<svg $svg $xlink><defs><path id=\"a\" d=\"M0 0\"/></defs><use xlink:href=\"#a\"/><use href=\"#a\"/></svg>"],
            'a link to a fragment' => ["<svg $svg $xlink><a xlink:href=\"#top\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a style element with classes' => ["<svg $svg><style>.a{fill:#f00}.b{stroke:url(#g)}</style><rect class=\"a\" width=\"1\" height=\"1\"/></svg>"],
            'a raster image given as data' => ["<svg $svg><image width=\"1\" height=\"1\" href=\"data:image/png;base64,iVBORw0KGgo=\"/></svg>"],
            'a color matrix whose values are numbers' => ["<svg $svg><filter id=\"f\"><feColorMatrix type=\"matrix\" values=\"1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 1 0\"/></filter></svg>"],
            'the metadata of Inkscape' => ["<svg $svg xmlns:inkscape=\"http://www.inkscape.org/namespaces/inkscape\" xmlns:sodipodi=\"http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd\" xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" xmlns:cc=\"http://creativecommons.org/ns#\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" inkscape:version=\"1.2\"><sodipodi:namedview id=\"nv\" inkscape:zoom=\"1\"/><metadata><rdf:RDF><cc:Work rdf:about=\"\"><dc:format>image/svg+xml</dc:format></cc:Work></rdf:RDF></metadata><rect width=\"1\" height=\"1\"/></svg>"],
            'a prefixed root' => ['<svg:svg xmlns:svg="http://www.w3.org/2000/svg"><svg:rect width="1" height="1"/></svg:svg>'],
            'text, a title and a label' => ["<svg $svg aria-label=\"Behavior: normal\"><title>Logo</title><desc>The logo</desc><text x=\"0\" y=\"1\">Shop</text></svg>"],
            'a comment and a cdata section' => ["<svg $svg><!-- made by a tool --><style><![CDATA[.a{fill:#000}]]></style></svg>"],
            'a link with an empty address' => ["<svg $svg><a href=\"\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a fragment with spaces around it' => ["<svg $svg><defs><path id=\"a\" d=\"M0 0\"/></defs><use href=\" #a \"/></svg>"],
            'a jpeg given as data' => ["<svg $svg><image width=\"1\" height=\"1\" href=\"data:image/jpeg;base64,/9j/4AAQ\"/></svg>"],
            'a png given as data with a parameter' => ["<svg $svg><image width=\"1\" height=\"1\" href=\"data:image/png;charset=utf-8;base64,iVBORw0KGgo=\"/></svg>"],
            'colours and fonts in a style' => ["<svg $svg><rect style=\"font-family:'Open Sans';fill:rgb(1,2,3);transform:rotate(5deg)\" width=\"1\" height=\"1\"/></svg>"],
            'a media query for the dark mode' => ["<svg $svg><style>@media (prefers-color-scheme: dark) { .a { fill: #fff } }</style></svg>"],
            'the SVG 1.0 document type' => ['<?xml version="1.0" standalone="no"?>' . "\n" . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 20010904//EN" "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd">' . "\n<svg $svg width=\"1\" height=\"1\"><rect width=\"1\" height=\"1\"/></svg>"],
            'the SVG 1.1 Tiny document type' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1 Tiny//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11-tiny.dtd">' . "<svg $svg><rect width=\"1\" height=\"1\"/></svg>"],
            'what Illustrator writes with its editing capabilities (entities for namespaces)' => ['<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" [' . "\n\t" . '<!ENTITY ns_extend "http://ns.adobe.com/Extensibility/1.0/">' . "\n\t" . '<!ENTITY ns_ai "http://ns.adobe.com/AdobeIllustrator/10.0/">' . "\n\t" . '<!ENTITY ns_graphs "http://ns.adobe.com/Graphs/1.0/">' . "\n]>\n" . "<svg $svg xmlns:x=\"&ns_extend;\" xmlns:i=\"&ns_ai;\" xmlns:graph=\"&ns_graphs;\" viewBox=\"0 0 1 1\"><rect width=\"1\" height=\"1\"/></svg>"],
            'what older Illustrator writes (the namespaces of the document itself as entities)' => ['<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/2001/REC-SVG-20010904/DTD/svg10.dtd" [' . "\n\t" . '<!ENTITY ns_svg "http://www.w3.org/2000/svg">' . "\n\t" . '<!ENTITY ns_xlink "http://www.w3.org/1999/xlink">' . "\n]>\n" . '<svg xmlns="&ns_svg;" xmlns:xlink="&ns_xlink;" width="1" height="1"><defs><path id="a" d="M0 0"/></defs><use xlink:href="#a"/></svg>'],
            'an entity that holds a word, used as the text of a title' => [self::declaring(entities: '<!ENTITY shop "Lavkoo">', body: '<title>&shop;</title><rect width="1" height="1"/>')],
            'an entity that holds a colour, used as the value of an attribute' => [self::declaring(entities: '<!ENTITY red "#f00">', body: '<rect width="1" height="1" fill="&red;" style="stroke:&red;"/>')],
            'a UTF-8 byte order mark' => ["\xEF\xBB\xBF<svg $svg><rect width=\"1\" height=\"1\"/></svg>"],
            'letters outside ASCII, in UTF-8' => [self::declared(encoding: 'UTF-8', body: '<title>caf' . "\xC3\xA9" . '</title>')],
            'UTF-8 declared in small letters' => [self::declared(encoding: 'utf-8', body: '<rect width="1" height="1"/>')],
            'UTF-8 declared without the dash' => [self::declared(encoding: 'UTF8', body: '<rect width="1" height="1"/>')],
            'UTF-8 declared in mixed letters' => [self::declared(encoding: 'Utf-8', body: '<rect width="1" height="1"/>')],
            'US-ASCII declared' => [self::declared(encoding: 'US-ASCII', body: '<rect width="1" height="1"/>')],
            'ASCII declared in small letters' => [self::declared(encoding: 'ascii', body: '<rect width="1" height="1"/>')],
            // ordinary CSS, and ordinary words, that were taken for something else
            'a class written with an escape in a style' => [self::styleElement('.sm\\:fill-red{fill:red}')],
            'an id written with a hex escape in a style' => [self::styleElement('#\\31 23{fill:red}')],
            'a content written with a unicode escape in a style' => [self::styleElement('.q::before{content:"\\201C"}')],
            'a comment with a parenthesis that is not closed' => [self::styleElement('/* palette (brand */ .a{fill:red}')],
            'a comment with an emoticon' => [self::styleElement('/* made with love :) */ .a{fill:red}')],
            'a comment with a parenthesis in a style attribute' => [self::styleAttribute('fill:red/* ) */')],
            'a string with a parenthesis that is not closed' => [self::styleElement('.a{font-family:"Foo (Bold"}')],
            'a comment that holds an address' => [self::styleElement('/* background: url(https://evil.test/old.png) */ .a{fill:red}')],
            'a comment that holds an import' => [self::styleElement("/* @import 'https://evil.test/a.css'; */ .a{fill:red}")],
            'a font that is a fragment of the file' => [self::styleElement('@font-face{font-family:"A";src:url("#FontID0") format(svg)} .a{font-family:"A"}')],
            'a raster image given as data in a style' => [self::styleElement('.a{background-image:url(data:image/png;base64,iVBORw0KGgo=)}')],
            'a large raster image given as data in a style' => [self::styleElement('.a{background-image:url("data:image/png;base64,' . str_repeat('AAAA', 150000) . '")}')],
            'a name that ends in url, as a function' => [self::styleAttribute('--x:curl(http://example.com)')],
            'a title with a Windows path and the letters url' => [self::attribute(name: 'title', value: 'curl C:\\Temp\\export')],
            'a file name of a Windows path with a parenthesis and the letters url' => [self::attribute(name: 'data-name', value: 'C:\\Users\\Ali\\Purl (old\\logo.png')],
            'the export file name that Inkscape writes' => [self::attribute(name: 'data-export', value: 'C:\\Users\\Ali\\Curly\\logo.png')],
            'a label that mentions an import and a binding' => [self::attribute(name: 'aria-label', value: '@import and -moz-binding are old CSS')],
            'a paint with a quoted fragment, spaces and a colour' => [self::attribute(name: 'fill', value: 'url( "#a" ) #000')],
            'a paint in capitals' => [self::attribute(name: 'fill', value: 'URL(#a)')],
            'a paint with an empty address' => [self::attribute(name: 'fill', value: 'url()')],
            'a fragment whose mark is written with an escape' => [self::attribute(name: 'fill', value: 'url(\\23 a)')],
        ];
    }

    #[DataProvider('imagesThatAreFine')]
    public function test_a_plain_vector_image_is_accepted(string $svg): void
    {
        $this->assertTrue(InertSvg::isInert($svg));
    }

    /** @return array<string, array{0: string}> */
    public static function imagesThatRunOrLoadSomething(): array
    {
        $svg = self::SVG;
        $xlink = self::XLINK;

        return [
            'a handler on the root' => ["<svg $svg onload=\"alert(1)\"/>"],
            'a handler on a child in capitals' => ["<svg $svg><rect ONCLICK=\"alert(1)\" width=\"1\" height=\"1\"/></svg>"],
            'a handler of a foreign namespace' => ["<svg $svg xmlns:ev=\"http://www.w3.org/2001/xml-events\"><rect ev:onclick=\"alert(1)\" width=\"1\" height=\"1\"/></svg>"],
            'a script element' => ["<svg $svg><script>alert(1)</script></svg>"],
            'a script element behind a prefix' => ["<svg $svg xmlns:s=\"http://www.w3.org/2000/svg\"><s:script>alert(1)</s:script></svg>"],
            'a script element in a cdata section' => ["<svg $svg><script><![CDATA[alert(1)]]></script></svg>"],
            'an xhtml script element' => ["<svg $svg><script xmlns=\"http://www.w3.org/1999/xhtml\">alert(1)</script></svg>"],
            'a foreign object holding a page' => ["<svg $svg><foreignObject><body xmlns=\"http://www.w3.org/1999/xhtml\"><iframe src=\"//evil.test/\"/></body></foreignObject></svg>"],
            'a foreign object with nothing in it' => ["<svg $svg><foreignObject width=\"1\" height=\"1\"/></svg>"],
            'a mathml element' => ["<svg $svg><math xmlns=\"http://www.w3.org/1998/Math/MathML\"><mtext>x</mtext></math></svg>"],
            'an xslt element' => ["<svg $svg><xsl:value-of xmlns:xsl=\"http://www.w3.org/1999/XSL/Transform\" select=\"1\"/></svg>"],
            'a script url in a link' => ["<svg $svg $xlink><a xlink:href=\"javascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a script url written with a character reference' => ["<svg $svg $xlink><a xlink:href=\"java&#115;cript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a script url after a line break' => ["<svg $svg $xlink><a xlink:href=\"&#10;javascript:alert(1)\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a page given as data' => ["<svg $svg><a href=\"data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a link to another site' => ["<svg $svg><a href=\"https://evil.test/\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a link to another file of the shop' => ["<svg $svg><a href=\"/admin/dashboard\"><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a use of a file of another site' => ["<svg $svg><use href=\"https://evil.test/x.svg#a\"/></svg>"],
            'a use of an image given as data' => ["<svg $svg><use href=\"data:image/svg+xml;base64,PHN2Zy8+\"/></svg>"],
            'an image of another site' => ["<svg $svg><image href=\"https://evil.test/pixel.png\"/></svg>"],
            'an svg given as an image' => ["<svg $svg><image href=\"data:image/svg+xml;base64,PHN2Zy8+\"/></svg>"],
            'a set element that writes a handler' => ["<svg $svg><rect width=\"1\" height=\"1\"><set attributeName=\"onmouseover\" to=\"alert(1)\"/></rect></svg>"],
            'an animate element that writes a link' => ["<svg $svg><a><animate attributeName=\"href\" values=\"javascript:alert(1)\"/><rect width=\"1\" height=\"1\"/></a></svg>"],
            'a handler element' => ["<svg $svg><handler type=\"application/ecmascript\">alert(1)</handler></svg>"],
            'a style that imports a sheet' => ["<svg $svg><style>@import url(https://evil.test/a.css);</style></svg>"],
            'a style that loads an address' => ["<svg $svg><style>.a{fill:url(https://evil.test/a.svg#f)}</style></svg>"],
            'a style attribute that loads an address' => ["<svg $svg><rect style=\"fill:url('https://evil.test/#f')\" width=\"1\" height=\"1\"/></svg>"],
            'a style attribute with an expression' => ["<svg $svg><rect style=\"width:expression(alert(1))\"/></svg>"],
            'a filter that loads a file of another site' => ["<svg $svg><rect filter=\"url(https://evil.test/f.svg#a)\" width=\"1\" height=\"1\"/></svg>"],
            'a paint that loads a file of another site' => ["<svg $svg><rect fill=\"url(//evil.test/p.svg#a) #000\" width=\"1\" height=\"1\"/></svg>"],
            'a base address' => ["<svg $svg xml:base=\"https://evil.test/\"><rect width=\"1\" height=\"1\"/></svg>"],
            'a style sheet instruction' => ["<?xml version=\"1.0\"?><?xml-stylesheet type=\"text/xsl\" href=\"/storage/x.xml\"?><svg $svg><rect width=\"1\" height=\"1\"/></svg>"],
            'an entity that expands to a script' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY s \"&#60;script xmlns=&#34;http://www.w3.org/2000/svg&#34;>alert(1)&#60;/script>\">]><svg $svg>&s;</svg>"],
            'a document type with an internal subset' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ATTLIST svg onload CDATA \"alert(1)\">]><svg $svg/>"],
            'a document type read from another site' => ["<?xml version=\"1.0\"?><!DOCTYPE svg SYSTEM \"http://evil.test/x.dtd\"><svg $svg/>"],
            'a root that is not an svg' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><script>alert(1)</script></body></html>'],
            'an svg in no namespace' => ['<svg><rect width="1" height="1"/></svg>'],
            'an xslt style sheet' => ['<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0"><xsl:template match="/"><svg xmlns="http://www.w3.org/2000/svg"/></xsl:template></xsl:stylesheet>'],
            'markup that is not well formed' => ["<svg $svg><rect width=\"1\" height=\"1\"></svg>"],
            'text' => ['not an image'],
            'a script' => ['<?php system($_GET["c"]); ?>'],
            'an empty file' => [''],
            // each element and attribute the rule bans, on its own, so that no other rule refuses it by luck
            'an animate element that writes nothing dangerous' => ["<svg $svg><rect width=\"1\" height=\"1\"><animate attributeName=\"x\" from=\"0\" to=\"1\" dur=\"1s\"/></rect></svg>"],
            'an animateMotion element' => ["<svg $svg><rect width=\"1\" height=\"1\"><animateMotion dur=\"1s\" path=\"M0 0 L1 1\"/></rect></svg>"],
            'an animateTransform element' => ["<svg $svg><rect width=\"1\" height=\"1\"><animateTransform attributeName=\"transform\" type=\"rotate\" from=\"0\" to=\"1\" dur=\"1s\"/></rect></svg>"],
            'an animateColor element' => ["<svg $svg><rect width=\"1\" height=\"1\"><animateColor attributeName=\"fill\" from=\"#000\" to=\"#fff\" dur=\"1s\"/></rect></svg>"],
            'a set element that writes a harmless value' => ["<svg $svg><rect width=\"1\" height=\"1\"><set attributeName=\"x\" to=\"1\"/></rect></svg>"],
            'a listener element' => ["<svg $svg><listener event=\"click\" observer=\"a\"/></svg>"],
            'a root that is an svg element but not the svg root' => ["<rect $svg width=\"1\" height=\"1\"/>"],
            'a src attribute that leaves the file' => ["<svg $svg><rect src=\"https://evil.test/x\" width=\"1\" height=\"1\"/></svg>"],
            'an action attribute that leaves the file' => ["<svg $svg><rect action=\"https://evil.test/x\" width=\"1\" height=\"1\"/></svg>"],
            'a formaction attribute that leaves the file' => ["<svg $svg><rect formaction=\"https://evil.test/x\" width=\"1\" height=\"1\"/></svg>"],
            'a data attribute that leaves the file' => ["<svg $svg><rect data=\"https://evil.test/x\" width=\"1\" height=\"1\"/></svg>"],
            'a script url in an attribute of no known kind (vbscript)' => ["<svg $svg><rect title=\"vbscript:msgbox(1)\" width=\"1\" height=\"1\"/></svg>"],
            'a page as data in an attribute of no known kind' => ["<svg $svg><rect title=\"data:text/html,x\" width=\"1\" height=\"1\"/></svg>"],
            'a script url in an attribute of no known kind (javascript, after a tab)' => ["<svg $svg><rect title=\"&#9;javascript:alert(1)\" width=\"1\" height=\"1\"/></svg>"],
            'an address in capitals' => ["<svg $svg><rect fill=\"URL(https://evil.test/p.svg#a)\" width=\"1\" height=\"1\"/></svg>"],
            'an image given as data that is not base64' => ["<svg $svg><image href=\"data:image/png,AAAA\"/></svg>"],
            'an image given as data with a tail after the data' => ["<svg $svg><image href=\"data:image/png;base64,AAAA!!\"/></svg>"],
            'an html page given as an image type' => ["<svg $svg><image href=\"data:image/html;base64,AAAA\"/></svg>"],
            'a style with an import of a string' => ["<svg $svg><style>@import 'https://evil.test/a.css';</style></svg>"],
            'a style with a behavior' => ["<svg $svg><rect style=\"behavior:foo\" width=\"1\" height=\"1\"/></svg>"],
            'a style with a binding' => ["<svg $svg><rect style=\"-moz-binding:foo\" width=\"1\" height=\"1\"/></svg>"],
            'a style with a script url' => ["<svg $svg><rect style=\"x:javascript:alert(1)\" width=\"1\" height=\"1\"/></svg>"],
            'a style with a vbscript url' => ["<svg $svg><rect style=\"x:vbscript:msgbox(1)\" width=\"1\" height=\"1\"/></svg>"],
            // the browser loads an address that the rule for `url(...)` did not see
            'an address that is not closed, in a style attribute' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:url(http://evil.test/p.png\"><rect width=\"1\" height=\"1\"/></svg>"],
            'an address that is not closed, in a style element' => ["<svg $svg><style>svg{background-image:url(http://evil.test/p.png</style></svg>"],
            'an address that is not closed, in a quote' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:url('http://evil.test/p.png\"><rect width=\"1\" height=\"1\"/></svg>"],
            'an address that is not closed, in a paint' => ["<svg $svg><rect fill=\"url(http://evil.test/p.svg#a\" width=\"1\" height=\"1\"/></svg>"],
            'an import written with an escape' => ["<svg $svg><style>@\\69mport 'http://evil.test/a.css';</style></svg>"],
            'an address written with an escape' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:\\75rl(http://evil.test/p.png)\"><rect width=\"1\" height=\"1\"/></svg>"],
            'an address written with an escape in the middle' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:u\\72l(http://evil.test/p.png)\"><rect width=\"1\" height=\"1\"/></svg>"],
            'an image set' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:image-set('http://evil.test/p.png' 1x)\"><rect width=\"1\" height=\"1\"/></svg>"],
            'an image set with a prefix' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:-webkit-image-set('http://evil.test/p.png' 1x)\"><rect width=\"1\" height=\"1\"/></svg>"],
            'a source function' => ["<svg $svg width=\"1\" height=\"1\" style=\"background-image:src('http://evil.test/p.png')\"><rect width=\"1\" height=\"1\"/></svg>"],
            // document types
            'a document type that declares an entity with markup' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY e \"<g/>\">]><svg $svg/>"],
            'a document type that declares an entity with a reference' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY a \"x\"><!ENTITY b \"&a;&a;\">]><svg $svg/>"],
            'a document type that declares a parameter entity' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY % p \"x\">]><svg $svg/>"],
            'a document type that declares an entity read from a file' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY f SYSTEM \"file:///etc/passwd\">]><svg $svg>&f;</svg>"],
            'a document type of a system id outside the standard' => ["<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org.evil.test/Graphics/SVG/1.1/DTD/svg11.dtd\"><svg $svg/>"],
            // an entity that names a namespace: the browser reads the namespace the entity stands for, so the element is judged by that one
            'an xhtml script whose namespace is an entity' => [self::declaring(entities: self::XHTML_ENTITY, body: '<script xmlns="&h;">alert(1)</script>')],
            'an xhtml script behind a prefix that an entity names' => [self::declaring(entities: self::XHTML_ENTITY, body: '<g xmlns:h="&h;"><h:script>alert(1)</h:script></g>')],
            'an xhtml script in a nested default namespace written with an entity' => [self::declaring(entities: self::XHTML_ENTITY, body: '<g><g><script xmlns="&h;"><![CDATA[alert(1)]]></script></g></g>')],
            'an svg script whose namespace is an entity' => [self::declaring(entities: self::SVG_ENTITY, body: '<script xmlns="&s;">alert(1)</script>')],
            'an svg script behind a prefix that an entity names' => [self::declaring(entities: self::SVG_ENTITY, body: '<g xmlns:v="&s;"><v:script>alert(1)</v:script></g>')],
            'a style element whose namespace is an entity, with an import' => [self::declaring(entities: self::SVG_ENTITY, body: '<style xmlns="&s;">@import \'https://evil.test/a.css\';</style>')],
            'a handler element whose namespace is an entity' => [self::declaring(entities: self::SVG_ENTITY, body: '<handler xmlns="&s;" type="application/ecmascript">alert(1)</handler>')],
            'an animate element whose namespace is an entity' => [self::declaring(entities: self::SVG_ENTITY, body: '<rect width="1" height="1"><animate xmlns="&s;" attributeName="x" from="0" to="1" dur="1s"/></rect>')],
            'a set element whose namespace is an entity' => [self::declaring(entities: self::SVG_ENTITY, body: '<rect width="1" height="1"><set xmlns="&s;" attributeName="x" to="1"/></rect>')],
            'a foreign object whose namespace is an entity' => [self::declaring(entities: self::SVG_ENTITY, body: '<foreignObject xmlns="&s;" width="1" height="1"/>')],
            'an xhtml element in a foreign object, both namespaces entities' => [self::declaring(entities: self::SVG_ENTITY . self::XHTML_ENTITY, body: '<foreignObject xmlns="&s;" width="1" height="1"><body xmlns="&h;">text</body></foreignObject>')],
            'a mathml element whose namespace is an entity' => [self::declaring(entities: '<!ENTITY m "http://www.w3.org/1998/Math/MathML">', body: '<math xmlns="&m;"><mtext>x</mtext></math>')],
            'an xslt element whose namespace is an entity' => [self::declaring(entities: '<!ENTITY x "http://www.w3.org/1999/XSL/Transform">', body: '<xsl:value-of xmlns:xsl="&x;" select="1"/>')],
            // an entity that stands for the text of an attribute or of a style is read as that text
            'an entity that holds an address, used as a style attribute' => [self::declaring(entities: '<!ENTITY u "mask-image:url(https://evil.test/p.png)">', body: '<rect style="&u;" width="1" height="1"/>')],
            'an entity that holds an address, used as a paint' => [self::declaring(entities: '<!ENTITY u "url(https://evil.test/p.svg#a)">', body: '<rect fill="&u;" width="1" height="1"/>')],
            'an entity that holds an import, used as a style' => [self::declaring(entities: '<!ENTITY css "@import \'https://evil.test/a.css\';">', body: '<style>&css;</style>')],
            'an entity that holds a script url, used as a link' => [self::declaring(entities: '<!ENTITY js "javascript:alert(1)">', body: '<a href="&js;"><rect width="1" height="1"/></a>')],
            'an entity that holds half of a script url, next to the other half' => [self::declaring(entities: '<!ENTITY a "java"><!ENTITY b "script:alert(1)">', body: '<a href="&a;&b;"><rect width="1" height="1"/></a>')],
            // libxml stops an expansion that is far larger than the file, so a file cannot be made to fill the memory
            'an entity repeated until it expands to far more than the file holds' => [self::declaring(entities: '<!ENTITY a "' . str_repeat('a', 100000) . '">', body: '<title>' . str_repeat('&a;', 4000) . '</title>')],
            // only UTF-8 (and ASCII) is read: in another encoding a byte can stand for another character to libxml than to the browser, such as
            // the backslash of an escape or the at sign of an import, and the UTF-16 and UTF-32 files are not read at all
            'an import in a document declared as ISO646-DE' => [self::declared(encoding: 'ISO646-DE', body: "<style>@import 'https://evil.test/a.css';</style>")],
            'an escaped address in a document declared as Shift_JIS' => [self::declared(encoding: 'Shift_JIS', body: '<style>rect{mask-image:\\75rl(https://evil.test/p.png)}</style>')],
            'an escaped address in an attribute of a document declared as Shift_JIS' => [self::declared(encoding: 'Shift_JIS', body: '<rect mask="\\75rl(https://evil.test/p.png)" width="1" height="1"/>')],
            'a document declared as ISO-8859-1' => [self::declared(encoding: 'ISO-8859-1', body: '<title>caf' . "\xE9" . '</title>')],
            'a document declared as windows-1252' => [self::declared(encoding: 'windows-1252', body: '<rect width="1" height="1"/>')],
            'a document declared as Latin-1 in other letters' => [self::declared(encoding: 'latin1', body: '<rect width="1" height="1"/>')],
            'a UTF-16 document with a byte order mark (little endian)' => ["\xFF\xFE" . mb_convert_encoding("<svg $svg><rect width=\"1\" height=\"1\"/></svg>", 'UTF-16LE', 'UTF-8')],
            'a UTF-16 document with a byte order mark (big endian)' => ["\xFE\xFF" . mb_convert_encoding("<svg $svg><rect width=\"1\" height=\"1\"/></svg>", 'UTF-16BE', 'UTF-8')],
            'a UTF-16 document without a byte order mark that declares UTF-8' => [mb_convert_encoding(self::declared(encoding: 'UTF-8', body: '<rect width="1" height="1"/>'), 'UTF-16LE', 'UTF-8')],
            'a UTF-32 document without a byte order mark' => [mb_convert_encoding("<svg $svg><rect width=\"1\" height=\"1\"/></svg>", 'UTF-32BE', 'UTF-8')],
            'a UTF-32 document with a byte order mark' => ["\xFF\xFE\x00\x00" . mb_convert_encoding("<svg $svg><rect width=\"1\" height=\"1\"/></svg>", 'UTF-32LE', 'UTF-8')],
            'markup after a NUL byte, which libxml does not read' => ["<svg $svg><rect width=\"1\" height=\"1\"/></svg>\0<script>alert(1)</script>"],
            // a parenthesis that a comment, a string or an entity holds balances nothing: an address that is not closed is read where it stands
            'a comment with a parenthesis, then an address that is not closed' => [self::styleElement('/* ) */ rect{mask-image:url(https://evil.test/p.png')],
            'a comment with a parenthesis, then an address that is not closed, in a style attribute' => [self::styleAttribute('/* ) */mask-image:url(https://evil.test/p.png')],
            'a comment with a parenthesis, then an address that is not closed, in a paint' => [self::attribute(name: 'mask', value: '/* ) */url(https://evil.test/p.png')],
            'a comment with a parenthesis, then a quoted address that is not closed' => [self::styleElement('/* ) */ rect{mask-image:url("https://evil.test/p.png"')],
            'a string with a parenthesis, then an address that is not closed' => [self::styleElement('.x{content:")"} rect{mask-image:url(https://evil.test/p.png')],
            'an entity that holds a parenthesis in a string, then an address that is not closed' => [self::declaring(entities: '<!ENTITY c ")">', body: '<style>.x{content:"&c;"} rect{mask-image:url(https://evil.test/p.png</style>')],
            'a filter with an address that is not closed' => [self::attribute(name: 'filter', value: 'url(https://evil.test/p.svg#f')],
            // the rules for a style are the rules for every attribute that a browser reads as CSS
            'an image set in a mask' => [self::attribute(name: 'mask', value: "image-set('https://evil.test/p.png' 1x)")],
            'an image set in capitals in a mask' => [self::attribute(name: 'mask', value: "IMAGE-SET('https://evil.test/p.png' 1x)")],
            'an image set with a prefix in a cursor' => [self::attribute(name: 'cursor', value: "-webkit-image-set('https://evil.test/p.png' 1x), auto")],
            'an image set in a clip path' => [self::attribute(name: 'clip-path', value: "image-set('https://evil.test/p.png' 1x)")],
            'an image set in a marker' => [self::attribute(name: 'marker-start', value: "image-set('https://evil.test/p.png' 1x)")],
            'an image set in a filter' => [self::attribute(name: 'filter', value: "image-set('https://evil.test/p.png' 1x)")],
            'an image set in a paint' => [self::attribute(name: 'fill', value: "image-set('https://evil.test/p.png' 1x)")],
            'a cross fade in a cursor' => [self::attribute(name: 'cursor', value: 'cross-fade(url(#a), url(#b), 50%), auto')],
            'a cross fade with a prefix in a mask' => [self::attribute(name: 'mask', value: '-webkit-cross-fade(url(#a), url(#b), 50%)')],
            'a source function in a mask' => [self::attribute(name: 'mask', value: "src('https://evil.test/p.png')")],
            'an element function in a mask' => [self::attribute(name: 'mask', value: 'element(#a)')],
            'an element function with a prefix in a mask' => [self::attribute(name: 'mask', value: '-moz-element(#a)')],
            'an image function in a mask' => [self::attribute(name: 'mask', value: 'image("https://evil.test/p.png")')],
            'a function that reads an address from an attribute' => ['<svg ' . self::SVG . '><rect data-u="https://evil.test/p.png" style="mask-image:attr(data-u url)" width="1" height="1"/></svg>'],
            'an address written with an escape in a mask' => [self::attribute(name: 'mask', value: '\\75rl(https://evil.test/p.png)')],
            'an address written with an escape in the middle, in a mask' => [self::attribute(name: 'mask', value: 'u\\72l(https://evil.test/p.png)')],
            'an address written with capitals and an escape, in a mask' => [self::attribute(name: 'mask', value: '\\55RL(https://evil.test/p.png)')],
            'an address written with three escapes, in a mask' => [self::attribute(name: 'mask', value: '\\75\\72\\6c(https://evil.test/p.png)')],
            'an address written with an escape of six digits, in a mask' => [self::attribute(name: 'mask', value: '\\000075rl(https://evil.test/p.png)')],
            'an address written with an escape that a space ends, in a mask' => [self::attribute(name: 'mask', value: '\\75 rl(https://evil.test/p.png)')],
            'an address written with an escape that a line break ends, in a mask' => [self::attribute(name: 'mask', value: "\\75\nrl(https://evil.test/p.png)")],
            'an address written with an escape in a cursor' => [self::attribute(name: 'cursor', value: '\\75rl(https://evil.test/p.png), auto')],
            'an address written with an escape in a clip path' => [self::attribute(name: 'clip-path', value: '\\75rl(https://evil.test/p.svg#c)')],
            'an address written with an escape in a marker' => [self::attribute(name: 'marker-start', value: '\\75rl(https://evil.test/p.svg#m)')],
            'an address written with an escape in a filter' => [self::attribute(name: 'filter', value: '\\75rl(https://evil.test/p.svg#f)')],
            'an address written with an escape in a paint' => [self::attribute(name: 'fill', value: '\\75rl(https://evil.test/p.svg#a)')],
            'an address written with an escape in a stroke' => [self::attribute(name: 'stroke', value: '\\75rl(https://evil.test/p.svg#a)')],
            // what a backslash writes is part of an unquoted address and is never a parenthesis, a quote or a space of the syntax
            'an address that starts with an escaped parenthesis, in a paint' => [self::attribute(name: 'mask', value: 'url(\\29 ../../../admin/logout)')],
            'an address that starts with an escaped parenthesis, in a style attribute' => [self::styleAttribute('mask-image:url(\\29 ../../../admin/logout)')],
            'an address that starts with an escaped parenthesis, in a style element' => [self::styleElement('rect{mask-image:url(\\29 ../../../admin/logout)}')],
            'an address that is only an escaped parenthesis' => [self::attribute(name: 'fill', value: 'url(\\29 )')],
            'a fragment between escaped quotes' => [self::attribute(name: 'fill', value: 'url(\\22 #a\\22 )')],
            'a fragment between escaped single quotes' => [self::styleAttribute("fill:url(\\27 #a\\27 )")],
            'an address that starts with an escaped space and goes to another site' => [self::attribute(name: 'mask', value: 'url(\\20 https://evil.test/p.png)')],
            // where a comment, a string or an address ends is read as CSS reads it, so that nothing hides what a browser loads
            'a comment marker in a string does not hide an address' => [self::styleElement('a{b:"/*"} rect{mask-image:url(https://evil.test/p.png)} c{d:"*/"}')],
            'a comment marker in an address does not hide the next address' => [self::styleElement('rect{mask-image:url(#a/*)} rect{mask-image:url(https://evil.test/p.png)} /* */')],
            'an escape that spells comment markers does not hide an address' => [self::styleElement('x{y:\\2f\\2a } rect{mask-image:url(https://evil.test/p.png)} z{w:\\2a\\2f }')],
            'a string that ends at a line break does not hide an address' => [self::styleElement("a{b:\"\n} rect{mask-image:url(https://evil.test/p.png)}\"")],
            'an address in a string that goes over two lines' => [self::styleElement("rect{mask-image:url(\"https://evil.test/p.p\\\nng\")}")],
            'an address that a comment follows in a style attribute' => [self::styleAttribute('mask-image:url(https://evil.test/p.png)/* ) */')],
            'an address that a bad address does not swallow' => [self::styleElement('rect{mask-image:url(a b)} rect{mask-image:url(https://evil.test/p.png)}')],
            'an address that is a very long one' => [self::styleElement('rect{mask-image:url(https://evil.test/p.png?' . str_repeat('a', 600000) . ')}')],
            // the browser reads a style from the text nodes of the element, and not from the text of the elements inside it
            'a style whose address is split by an element' => ['<svg ' . self::SVG . '><style>rect{mask-image:url(<g>#a)</g>https://evil.test/p.png;}</style><rect width="1" height="1"/></svg>'],
            'a style that holds an element' => ['<svg ' . self::SVG . '><style>.a{fill:red}<g/></style></svg>'],
        ];
    }

    #[DataProvider('imagesThatRunOrLoadSomething')]
    public function test_an_image_that_runs_script_or_loads_from_elsewhere_is_refused(string $svg): void
    {
        $this->assertFalse(InertSvg::isInert($svg));
    }

    public function test_the_theme_asset_service_decides_with_the_same_rule(): void
    {
        $service = app(ThemeAssetService::class);
        $fine = $this->upload('<svg ' . self::SVG . '><rect width="1" height="1"/></svg>');
        $hostile = $this->upload('<svg ' . self::SVG . ' xmlns:s="http://www.w3.org/2000/svg"><s:script>alert(1)</s:script></svg>');

        $this->assertTrue($service->isSafeSvg($fine));
        $this->assertFalse($service->isSafeSvg($hostile));
    }

    private static function declaring(string $entities, string $body): string
    {
        return '<?xml version="1.0"?><!DOCTYPE svg [' . $entities . ']><svg ' . self::SVG . ' ' . self::XLINK . '>' . $body . '</svg>';
    }

    private static function declared(string $encoding, string $body): string
    {
        return '<?xml version="1.0" encoding="' . $encoding . '"?><svg ' . self::SVG . '>' . $body . '</svg>';
    }

    private static function styleElement(string $css): string
    {
        return '<svg ' . self::SVG . '><style>' . htmlspecialchars($css, ENT_QUOTES | ENT_XML1) . '</style><rect width="1" height="1"/></svg>';
    }

    private static function styleAttribute(string $css): string
    {
        return self::attribute(name: 'style', value: $css);
    }

    /** The value is kept as it is: a line break, a tab and a carriage return in an attribute are written as character references. */
    private static function attribute(string $name, string $value): string
    {
        $written = strtr(htmlspecialchars($value, ENT_QUOTES | ENT_XML1), ["\n" => '&#10;', "\r" => '&#13;', "\t" => '&#9;']);

        return '<svg ' . self::SVG . '><rect ' . $name . '="' . $written . '" width="1" height="1"/></svg>';
    }

    private function upload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);
    }
}
