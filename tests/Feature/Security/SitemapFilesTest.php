<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\SiteMapController;
use App\Utils\SitemapXml;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The sitemap screens of the admin panel. The download and the delete took the file name from the address (base64 of whatever the request
 * sent) and joined it to the sitemap directory, so `../../../../.env` downloaded the shop's secrets and deleted any file the web user owns.
 * The manual upload checked the name the client gave the file, then copied it to /sitemap.xml, where the shop's own origin serves it: an
 * XHTML or SVG page, or a file that carries a script element, is a page that runs script there.
 */
class SitemapFilesTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const SITEMAP = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">'
        . '<url><loc>https://shop.test/product/a</loc><lastmod>2026-11-16T10:00:00+00:00</lastmod><changefreq>weekly</changefreq><priority>0.8</priority>'
        . '<xhtml:link rel="alternate" hreflang="en" href="https://shop.test/product/a?language=en"/>'
        . '<image:image><image:loc>https://shop.test/a.jpg</image:loc></image:image>'
        . '<video:video><video:title>T</video:title><video:player_loc allow_embed="yes">https://shop.test/p</video:player_loc></video:video>'
        . '</url></urlset>';

    private string $directory;

    /** @var array<string, string|null> */
    private array $publishedBefore = [];

    /** @var list<string> */
    private array $storedBefore = [];

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();

        $this->directory = storage_path('app/public/sitemap');
        File::ensureDirectoryExists($this->directory);
        $this->storedBefore = array_map('basename', File::files($this->directory));
        foreach ([public_path('sitemap.xml'), base_path('sitemap.xml')] as $path) {
            $this->publishedBefore[$path] = is_file($path) ? file_get_contents($path) : null;
        }
    }

    protected function tearDown(): void
    {
        foreach (File::files($this->directory) as $file) {
            if (!in_array($file->getFilename(), $this->storedBefore, true)) {
                File::delete($file->getPathname());
            }
        }
        array_map('unlink', array_filter($this->createdFiles, 'file_exists'));
        foreach ($this->publishedBefore as $path => $content) {
            $content === null ? File::delete($path) : file_put_contents($path, $content);
        }
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function fileOnDisk(string $path, string $content): string
    {
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    private function controller(): SiteMapController
    {
        return app(SiteMapController::class);
    }

    private function link(string $name): Request
    {
        return Request::create('/', 'GET', ['path' => base64_encode($name)]);
    }

    private function upload(string $content, string $clientName = 'sitemap.xml'): Request
    {
        $temporary = $this->fileOnDisk(tempnam(sys_get_temp_dir(), 'sm'), $content);

        return Request::create('/', 'POST', [], [], ['xml_file' => new UploadedFile($temporary, $clientName, 'application/xml', null, true)]);
    }

    // ---- download and delete ----------------------------------------------------------------------------------------------------

    public function test_a_stored_sitemap_is_downloaded_and_deleted_by_its_name(): void
    {
        $stored = $this->fileOnDisk($this->directory . '/sitemap-probe.xml', self::SITEMAP);

        $download = $this->controller()->getDownload($this->link('sitemap-probe.xml'));
        $this->assertInstanceOf(BinaryFileResponse::class, $download);
        $this->assertSame($stored, $download->getFile()->getPathname());

        $this->controller()->getDelete($this->link('sitemap-probe.xml'));
        $this->assertFileDoesNotExist($stored);
    }

    /** @return array<string, array{0: mixed}> */
    public static function linksThatLeaveTheSitemapDirectory(): array
    {
        return [
            'a way up to the storage directory' => [base64_encode('../../../probe-victim.txt')],
            'a way up written with backslashes' => [base64_encode('..\\..\\..\\probe-victim.txt')],
            'a way up behind the sitemap directory' => [base64_encode('../sitemap/../../../probe-victim.txt')],
            'the project root' => [base64_encode('../../../../probe-victim.txt')],
            'an absolute path' => [base64_encode('/etc/hostname')],
            'a directory' => [base64_encode('.')],
            'the sitemap directory itself' => [base64_encode('..')],
            'a name that ends early' => [base64_encode("sitemap-probe.xml\0../../../probe-victim.txt")],
            'text that is not base64' => ['../../../probe-victim.txt'],
            'a list' => [[base64_encode('../../../probe-victim.txt')]],
            'nothing' => [null],
            'an empty value' => [''],
        ];
    }

    #[DataProvider('linksThatLeaveTheSitemapDirectory')]
    public function test_a_link_that_leaves_the_sitemap_directory_downloads_and_deletes_nothing(mixed $path): void
    {
        $victims = [$this->fileOnDisk(storage_path('probe-victim.txt'), 'CANARY'), $this->fileOnDisk(base_path('probe-victim.txt'), 'CANARY')];
        $request = Request::create('/', 'GET', ['path' => $path]);

        $download = $this->controller()->getDownload($request);
        $this->controller()->getDelete($request);

        $this->assertNotInstanceOf(BinaryFileResponse::class, $download);
        array_map($this->assertFileExists(...), $victims);
        $this->assertDirectoryExists($this->directory);
    }

    // ---- the vocabulary of a sitemap --------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function sitemapsThatAreFine(): array
    {
        $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        return [
            'what the generator writes (hreflang, image, video)' => [self::SITEMAP],
            'a bare list' => ['<?xml version="1.0"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'an index of sitemaps' => ['<?xml version="1.0"?><sitemapindex ' . $ns . '><sitemap><loc>https://a.test/s1.xml</loc><lastmod>2026-11-16</lastmod></sitemap></sitemapindex>'],
            'the schema location attribute of the standard' => ['<urlset ' . $ns . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd"><url><loc>https://a.test/</loc></url></urlset>'],
            'a comment and a cdata section' => ['<urlset ' . $ns . '><!-- made by a tool --><url><loc><![CDATA[https://a.test/?a=1&b=2]]></loc></url></urlset>'],
            'a byte order mark' => ["\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-8"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a declaration in lower case with single quotes and spaces' => ["<?xml version='1.0' encoding = 'utf-8' ?><urlset " . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a file that declares ASCII' => ['<?xml version="1.0" encoding="US-ASCII"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a file that declares ISO-8859-1' => ['<?xml version="1.0" encoding="ISO-8859-1"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'an address in Arabic' => ['<?xml version="1.0" encoding="UTF-8"?><urlset ' . $ns . '><url><loc>https://a.test/منتج</loc></url></urlset>'],
        ];
    }

    #[DataProvider('sitemapsThatAreFine')]
    public function test_a_sitemap_is_accepted(string $xml): void
    {
        $this->assertTrue(SitemapXml::isSitemap($xml));
    }

    /** @return array<string, array{0: string}> */
    public static function filesThatAreNotSitemaps(): array
    {
        $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        return [
            'an xhtml page' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><script>alert(document.domain)</script></body></html>'],
            'an svg image with a handler' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>'],
            'a sitemap that holds an xhtml script element' => ['<urlset ' . $ns . '><url><loc>https://a.test/</loc></url><script xmlns="http://www.w3.org/1999/xhtml">alert(1)</script></urlset>'],
            'the same behind a prefix' => ['<urlset ' . $ns . ' xmlns:x="http://www.w3.org/1999/xhtml"><url><loc>https://a.test/</loc></url><x:script>alert(1)</x:script></urlset>'],
            'a sitemap that holds an svg element' => ['<urlset ' . $ns . '><url><loc>https://a.test/</loc></url><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/></urlset>'],
            'a sitemap that holds an xhtml iframe' => ['<urlset ' . $ns . ' xmlns:x="http://www.w3.org/1999/xhtml"><x:iframe src="https://evil.test/"/></urlset>'],
            'a style sheet instruction' => ['<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="/storage/sitemap/evil.xml"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'an instruction inside the tree' => ['<urlset ' . $ns . '><url><?php echo 1; ?><loc>https://a.test/</loc></url></urlset>'],
            'a document type with an entity' => ['<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY s "&#60;script xmlns=&#34;http://www.w3.org/1999/xhtml&#34;>alert(1)&#60;/script>">]><urlset ' . $ns . '>&s;</urlset>'],
            'a document type that reads a file' => ['<?xml version="1.0"?><!DOCTYPE urlset SYSTEM "file:///etc/passwd"><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'an xhtml link that loads a style sheet' => ['<urlset ' . $ns . ' xmlns:xhtml="http://www.w3.org/1999/xhtml"><url><loc>https://a.test/</loc><xhtml:link rel="stylesheet" href="https://evil.test/a.css"/></url></urlset>'],
            'an alternate link that carries a handler' => ['<urlset ' . $ns . ' xmlns:xhtml="http://www.w3.org/1999/xhtml"><url><loc>https://a.test/</loc><xhtml:link rel="alternate" hreflang="en" href="https://a.test/en" onclick="alert(1)"/></url></urlset>'],
            'a sitemap element that carries a handler' => ['<urlset ' . $ns . '><url onclick="alert(1)"><loc>https://a.test/</loc></url></urlset>'],
            'a sitemap element that carries a handler in capitals' => ['<urlset ' . $ns . '><url ONCLICK="alert(1)"><loc>https://a.test/</loc></url></urlset>'],
            'an attribute of a foreign namespace' => ['<urlset ' . $ns . ' xmlns:x="http://www.w3.org/1999/xlink"><url x:href="javascript:alert(1)"><loc>https://a.test/</loc></url></urlset>'],
            'a style sheet transformation' => ['<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0"><xsl:template match="/"><html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html></xsl:template></xsl:stylesheet>'],
            'a root in no namespace' => ['<urlset><url><loc>https://a.test/</loc></url></urlset>'],
            'another root of the right namespace' => ['<html ' . $ns . '><url/></html>'],
            'text' => ['https://a.test/'],
            'a script' => ['<?php system($_GET["c"]);'],
            'an unclosed tag' => ['<urlset ' . $ns . '><url>'],
            'an empty file' => [''],
            // The encoding is the one thing a parser reads before it reads the markup. A name that libxml2 knows through ICU and a browser does
            // not (HZ, ISO-2022-KR, SCSU, CESU-8, UTF-7) turns bytes that are text for the validator into elements for the browser.
            'a file in HZ, where the browser reads a script element the validator saw as three characters' => ['<?xml version="1.0" encoding="HZ"?><urlset ' . $ns . ' xmlns:x="http://www.w3.org/1999/xhtml"><url><loc>~{<x:script>alert(1);</x:script>~}</loc></url></urlset>'],
            'a file in ISO-2022-KR' => ['<?xml version="1.0" encoding="ISO-2022-KR"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a file in SCSU' => ['<?xml version="1.0" encoding="SCSU"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a file in CESU-8' => ['<?xml version="1.0" encoding="CESU-8"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>'],
            'a file that declares UTF-16 and is written in it' => ["\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>', 'UTF-16LE', 'UTF-8')],
            'a file in UTF-16 with a byte order mark and no declaration' => ["\xFF\xFE" . mb_convert_encoding('<urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>', 'UTF-16LE', 'UTF-8')],
            'a file in UTF-16 without a byte order mark' => [mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><urlset ' . $ns . '><url><loc>https://a.test/</loc></url></urlset>', 'UTF-16BE', 'UTF-8')],
        ];
    }

    #[DataProvider('filesThatAreNotSitemaps')]
    public function test_a_file_that_is_not_a_sitemap_is_refused_and_nothing_is_stored_or_published(string $xml): void
    {
        $this->assertFalse(SitemapXml::isSitemap($xml));

        $this->controller()->getUpload($this->upload($xml));

        $this->assertSame($this->storedBefore, array_map('basename', File::files($this->directory)));
        foreach ($this->publishedBefore as $path => $content) {
            $this->assertSame($content, is_file($path) ? file_get_contents($path) : null, $path);
        }
    }

    // ---- the manual upload ------------------------------------------------------------------------------------------------------

    public function test_a_real_sitemap_is_stored_and_published_as_it_was_uploaded(): void
    {
        $this->controller()->getUpload($this->upload(self::SITEMAP));

        $stored = array_values(array_diff(array_map('basename', File::files($this->directory)), $this->storedBefore));
        $this->assertCount(1, $stored);
        $this->assertSame(self::SITEMAP, file_get_contents($this->directory . '/' . $stored[0]));
        $this->assertSame(self::SITEMAP, file_get_contents(public_path('sitemap.xml')), 'the published file is the sitemap, not the path of the temporary file');
        $this->assertSame(self::SITEMAP, file_get_contents(base_path('sitemap.xml')));
    }

    public function test_a_sitemap_that_the_client_names_something_else_is_refused(): void
    {
        $this->controller()->getUpload($this->upload(self::SITEMAP, clientName: 'sitemap.html'));
        $this->controller()->getUpload($this->upload(self::SITEMAP, clientName: 'sitemap.php'));

        $this->assertSame($this->storedBefore, array_map('basename', File::files($this->directory)));
    }

    public function test_a_request_without_a_file_or_with_a_list_of_files_is_refused_and_is_no_server_error(): void
    {
        $this->controller()->getUpload(Request::create('/', 'POST'));
        $this->controller()->getUpload(Request::create('/', 'POST', [], [], ['xml_file' => [new UploadedFile($this->fileOnDisk(tempnam(sys_get_temp_dir(), 'sm'), self::SITEMAP), 'a.xml', 'application/xml', null, true)]]));

        $this->assertSame($this->storedBefore, array_map('basename', File::files($this->directory)));
    }
}
