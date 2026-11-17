<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\AppCarouselController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The app carousel moves its images straight into public/uploads/app-carousel, the web root, under the extension the client gave them. The
 * validation reads the content, so a JPEG that carries code or markup passes under any name that Laravel's own deny list misses: `.pht`, `.phps`,
 * `.inc` are run by servers that map them, `.html`, `.htm`, `.shtml` and `.svg` are pages that run script in the shop's origin.
 */
class AppCarouselUploadTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    /** @var list<string> */
    private array $storedBefore = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        Schema::create('app_carousel_settings', function ($table) {
            $table->id();
            $table->text('images')->nullable();
            $table->text('texts')->nullable();
            $table->boolean('show_text')->default(false);
            $table->boolean('show_carousel')->default(false);
            $table->timestamps();
        });
        File::ensureDirectoryExists(public_path('uploads/app-carousel'));
        $this->storedBefore = array_map('basename', File::files(public_path('uploads/app-carousel')));
    }

    protected function tearDown(): void
    {
        foreach (File::files(public_path('uploads/app-carousel')) as $file) {
            if (!in_array($file->getFilename(), $this->storedBefore, true)) {
                File::delete($file->getPathname());
            }
        }
        array_map('unlink', array_filter($this->temporaryFiles, 'file_exists'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function jpegWithCode(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return ob_get_clean() . "\n<?php system(\$_GET['c']); ?>\n<script>alert(document.domain)</script>\n";
    }

    /** @return list<string> */
    private function newlyStored(): array
    {
        return array_values(array_diff(array_map('basename', File::files(public_path('uploads/app-carousel'))), $this->storedBefore));
    }

    private function upload(string $clientName): void
    {
        $path = tempnam(sys_get_temp_dir(), 'car');
        file_put_contents($path, $this->jpegWithCode());
        $this->temporaryFiles[] = $path;
        $file = new UploadedFile($path, $clientName, 'image/jpeg', null, true);

        app(AppCarouselController::class)->update(Request::create('/', 'POST', ['show_carousel' => 1], [], ['images' => [$file]]));
    }

    /** @return array<string, array{0: string}> */
    public static function namesTheClientMayWrite(): array
    {
        return [
            'pht' => ['slide.pht'], 'phps' => ['slide.phps'], 'inc' => ['slide.inc'], 'html' => ['slide.html'], 'htm' => ['slide.htm'],
            'shtml' => ['slide.shtml'], 'svg' => ['slide.svg'], 'xml' => ['slide.xml'], 'php6' => ['slide.php6'], 'capitals' => ['SLIDE.PHT'],
            'a double extension' => ['slide.jpg.pht'], 'no extension' => ['slide'],
            // names of data types that are not images: served as a document, a text or a video whatever the bytes are
            'pdf' => ['slide.pdf'], 'zip' => ['slide.zip'], 'txt' => ['slide.txt'], 'csv' => ['slide.csv'], 'mp4' => ['slide.mp4'], 'docx' => ['slide.docx'],
            // a real image name for the content: the stored extension is the content's, not the client's
            'png' => ['slide.png'], 'webp' => ['slide.webp'], 'jpeg in capitals' => ['slide.JPEG'], 'jpg' => ['slide.jpg'],
        ];
    }

    #[DataProvider('namesTheClientMayWrite')]
    public function test_an_image_is_stored_under_the_extension_its_content_has_whatever_the_client_named_it(string $clientName): void
    {
        $this->upload($clientName);

        $stored = $this->newlyStored();
        $this->assertCount(1, $stored, $clientName);
        $this->assertMatchesRegularExpression('/^carousel_[0-9a-f]+\.[0-9]+\.jpg$/', $stored[0], 'no part of the stored name comes from the client');
    }

    private function pngOfSize(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 1, 0, 0, 0, 0)) . $chunk('IEND', '');
    }

    private function uploadBytes(string $bytes, string $clientName): void
    {
        $path = tempnam(sys_get_temp_dir(), 'car');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        app(AppCarouselController::class)->update(Request::create('/', 'POST', ['show_carousel' => 1], [], ['images' => [new UploadedFile($path, $clientName, 'image/png', null, true)]]));
    }

    public function test_an_image_that_declares_more_pixels_than_a_slide_needs_is_refused_before_it_is_stored(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->uploadBytes($this->pngOfSize(width: 30000, height: 30000), 'bomb.png');
        } finally {
            $this->assertSame([], $this->newlyStored());
        }
    }

    public function test_a_large_but_reasonable_image_is_stored(): void
    {
        $this->uploadBytes($this->pngOfSize(width: 4096, height: 2048), 'wide.png');

        $this->assertCount(1, $this->newlyStored());
    }
}
