<?php

namespace Tests\Feature\Security;

use App\Traits\FileManagerTrait;
use App\Utils\ImageManager;
use App\Utils\StoredFileExtension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What the two storage helpers (ImageManager and FileManagerTrait, which every upload of the platform goes through) write to the public disk.
 *
 * The audit of the upload surface reproduced stored scripts and stored pages through both: the trait stored the client's extension as it came
 * (`.pht`, `.phps`, `.inc`, `.shtml` all ran or rendered), the image branch of both stored an SVG as uploaded, and the document branch of the
 * helper kept `.svg`, `.xml` and `.shtml`. A name now comes from a short list of data types, and the content decides when the client's
 * extension is not on it.
 */
class StoredUploadPolicyTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temporaryFiles, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** A file the way a real request carries it: its type is what finfo says about the bytes, whatever the client called it. */
    private function uploadOf(string $clientName, string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function imageBytes(string $format): string
    {
        $canvas = imagecreatetruecolor(20, 20);
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($canvas),
            'gif' => imagegif($canvas),
            'png' => imagepng($canvas),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private function jpegWithCode(string $clientName): UploadedFile
    {
        return $this->uploadOf($clientName, $this->imageBytes('jpeg') . "\n<?php system(\$_GET['c']); ?>");
    }

    /** the stored files of a directory, by name */
    private function stored(string $dir): array
    {
        return array_map('basename', Storage::disk('public')->files($dir));
    }

    private function viaTheHelper(string $format, UploadedFile $file): string
    {
        return ImageManager::file_upload(dir: 'documents/', format: $format, file: $file);
    }

    private function viaTheTrait(string $format, UploadedFile $file): string
    {
        $writer = new class {
            use FileManagerTrait {
                fileUpload as public;
            }
        };

        return $writer->fileUpload(dir: 'documents/', format: $format, file: $file);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> the file name the client wrote, what it carries, and the extension it is stored under */
    public static function hostileFiles(): array
    {
        return [
            'a jpeg that carries code, named .pht' => ['shell.pht', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, named .phps' => ['shell.phps', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, named .inc' => ['shell.inc', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, named .php' => ['shell.php', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, named .shtml' => ['shell.shtml', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, with a double extension' => ['shell.jpg.pht', 'jpeg-with-code', 'jpg'],
            'a jpeg that carries code, named in capitals' => ['SHELL.PHT', 'jpeg-with-code', 'jpg'],
            'php source named .pht' => ['shell.pht', 'php-source', 'bin'],
            'php source named .txt' => ['shell.txt', 'php-source', 'txt'],
            'an svg page named .svg' => ['logo.svg', 'svg', 'bin'],
            'an svg page named .xml' => ['logo.xml', 'svg', 'bin'],
            'an svg page named .shtml' => ['logo.shtml', 'svg', 'bin'],
            'an svg that starts with a comment, which sniffs as text' => ['logo.svg', 'svg-with-comment', 'txt'],
            'an html page named .html' => ['page.html', 'html', 'bin'],
            'an html page named .shtml' => ['page.shtml', 'html', 'bin'],
            'an html page named .xhtml' => ['page.xhtml', 'html', 'bin'],
            'a name that is not an extension' => ['page.../../x', 'html', 'bin'],
        ];
    }

    private function contentOf(string $kind, string $clientName): UploadedFile
    {
        return match ($kind) {
            'jpeg-with-code' => $this->jpegWithCode($clientName),
            'php-source' => $this->uploadOf($clientName, "<?php system(\$_GET['c']); ?>"),
            'svg' => $this->uploadOf($clientName, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"><script>alert(1)</script></svg>'),
            'svg-with-comment' => $this->uploadOf($clientName, '<!-- x --><svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"></svg>'),
            'html' => $this->uploadOf($clientName, '<html><body><script>alert(document.domain)</script></body></html>'),
        };
    }

    #[DataProvider('hostileFiles')]
    public function test_the_document_helper_never_stores_a_name_a_server_would_run_or_render(string $clientName, string $kind, string $storedExtension): void
    {
        $file = $this->contentOf($kind, $clientName);

        $name = $this->viaTheHelper(format: $file->getClientOriginalExtension(), file: $file);

        $this->assertStringEndsWith('.' . $storedExtension, $name);
        $this->assertSame([$name], $this->stored('documents'));
    }

    #[DataProvider('hostileFiles')]
    public function test_the_trait_every_service_uses_follows_the_same_rule(string $clientName, string $kind, string $storedExtension): void
    {
        $file = $this->contentOf($kind, $clientName);

        $name = $this->viaTheTrait(format: $file->getClientOriginalExtension(), file: $file);

        $this->assertStringEndsWith('.' . $storedExtension, $name);
        $this->assertSame([$name], $this->stored('documents'));
    }

    public function test_the_client_extension_of_a_data_file_is_kept(): void
    {
        foreach (['pdf', 'PDF', 'docx', 'xlsx', 'zip', 'rar', 'csv', 'txt', 'mp4', 'mp3', 'jpg', 'png', 'webp'] as $extension) {
            $this->assertSame(strtolower($extension), StoredFileExtension::of($extension));
        }
    }

    public function test_an_extension_that_is_not_a_data_type_is_stored_as_bin_when_nothing_else_says_what_the_file_is(): void
    {
        foreach (['php', 'phtml', 'pht', 'phar', 'phps', 'inc', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'xml', 'js', 'sh', 'exe', 'htaccess', '', '../../x', str_repeat('a', 40)] as $extension) {
            $this->assertSame('bin', StoredFileExtension::of($extension), var_export($extension, true));
        }
    }

    // ---- images ---------------------------------------------------------------------------------------------------------------------

    public function test_an_svg_sent_as_an_image_is_refused_and_nothing_is_stored(): void
    {
        $svg = $this->contentOf('svg', 'logo.svg');

        foreach ([fn () => ImageManager::upload(dir: 'review/', format: 'webp', image: $svg), fn () => $this->imageViaTheTrait($svg)] as $upload) {
            try {
                $upload();
                $this->fail('an SVG was stored as an image');
            } catch (ValidationException $refusal) {
                $this->assertSame(translate('the_file_is_not_a_supported_image'), $refusal->errors()['image'][0]);
            }
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function imageViaTheTrait(UploadedFile $image): string
    {
        $writer = new class {
            use FileManagerTrait {
                upload as public;
            }
        };

        return $writer->upload(dir: 'review/', format: 'webp', image: $image);
    }

    public function test_a_script_sent_as_an_image_is_refused_and_nothing_is_stored(): void
    {
        $script = $this->contentOf('php-source', 'shell.php');

        try {
            ImageManager::upload(dir: 'review/', format: 'webp', image: $script);
            $this->fail('a script was stored as an image');
        } catch (ValidationException) {
            $this->assertSame([], Storage::disk('public')->allFiles());
        }
    }

    public function test_a_real_gif_is_stored_as_it_came_so_it_stays_animated(): void
    {
        $bytes = $this->imageBytes('gif');

        $name = ImageManager::upload(dir: 'banner/', format: 'webp', image: $this->uploadOf('spinner.gif', $bytes));

        $this->assertStringEndsWith('.gif', $name);
        $this->assertSame($bytes, Storage::disk('public')->get('banner/' . $name));
    }

    public function test_a_png_is_stored_as_an_encoded_image_under_the_format_the_caller_asked_for(): void
    {
        $png = $this->uploadOf('photo.png', $this->imageBytes('png'));

        $name = ImageManager::upload(dir: 'banner/', format: 'png', image: $png);
        $viaTheTrait = $this->imageViaTheTrait($png);

        $this->assertMatchesRegularExpression('/\.(png|webp)$/', $name);
        $this->assertTrue(Storage::disk('public')->exists('banner/' . $name));
        $this->assertTrue(Storage::disk('public')->exists('review/' . $viaTheTrait));
    }

    public function test_a_png_that_calls_itself_a_gif_is_encoded_again_and_not_kept_as_a_gif(): void
    {
        $name = ImageManager::upload(dir: 'banner/', format: 'png', image: $this->uploadOf('photo.gif', $this->imageBytes('png')));

        $this->assertStringEndsNotWith('.gif', $name);
    }

    // ---- deleting ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> a path in the disk that a name from a request would build */
    public static function pathsThatGoUp(): array
    {
        return [
            'one level up' => ['product/../shop/banner/victim.webp'],
            'a name that starts with the way up' => ['../shop/banner/victim.webp'],
            'a windows separator' => ['product\\..\\shop\\banner\\victim.webp'],
        ];
    }

    #[DataProvider('pathsThatGoUp')]
    public function test_a_stored_name_that_goes_up_does_not_delete_another_file(string $path): void
    {
        Storage::disk('public')->put('shop/banner/victim.webp', 'banner');

        ImageManager::delete(full_path: $path);
        $this->deleteViaTheTrait($path);

        $this->assertTrue(Storage::disk('public')->exists('shop/banner/victim.webp'));
    }

    public function test_replacing_a_file_does_not_delete_the_file_a_name_that_goes_up_points_to(): void
    {
        Storage::disk('public')->put('shop/banner/victim.webp', 'banner');

        ImageManager::update(dir: 'product/', old_image: '../shop/banner/victim.webp', format: 'png', image: $this->uploadOf('photo.png', $this->imageBytes('png')));
        $writer = new class {
            use FileManagerTrait {
                update as public;
            }
        };
        $writer->update(dir: 'product/', oldImage: '../shop/banner/victim.webp', format: 'png', image: $this->uploadOf('photo.png', $this->imageBytes('png')));

        $this->assertTrue(Storage::disk('public')->exists('shop/banner/victim.webp'));
    }

    public function test_a_file_that_is_where_its_name_says_is_still_deleted(): void
    {
        Storage::disk('public')->put('product/thumbnail/photo.webp', 'image');
        Storage::disk('public')->put('product/preview/old.pdf', 'preview');

        ImageManager::delete(full_path: 'product/thumbnail/photo.webp');
        $this->deleteViaTheTrait('product/preview/old.pdf');

        $this->assertFalse(Storage::disk('public')->exists('product/thumbnail/photo.webp'));
        $this->assertFalse(Storage::disk('public')->exists('product/preview/old.pdf'));
    }

    private function deleteViaTheTrait(string $path): void
    {
        $writer = new class {
            use FileManagerTrait {
                delete as public;
            }
        };
        $writer->delete(filePath: $path);
    }
}
