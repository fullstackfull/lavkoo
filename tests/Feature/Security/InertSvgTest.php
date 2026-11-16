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

    private function upload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true);
    }
}
