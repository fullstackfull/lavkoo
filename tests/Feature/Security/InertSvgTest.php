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

    private function upload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);
    }
}
