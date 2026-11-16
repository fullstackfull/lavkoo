<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\AppCarouselController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
    public static function namesThatAreNotDataTypes(): array
    {
        return [
            'pht' => ['slide.pht'], 'phps' => ['slide.phps'], 'inc' => ['slide.inc'], 'html' => ['slide.html'], 'htm' => ['slide.htm'],
            'shtml' => ['slide.shtml'], 'svg' => ['slide.svg'], 'xml' => ['slide.xml'], 'php6' => ['slide.php6'], 'capitals' => ['SLIDE.PHT'],
            'a double extension' => ['slide.jpg.pht'], 'no extension' => ['slide'],
        ];
    }

    #[DataProvider('namesThatAreNotDataTypes')]
    public function test_an_image_whose_name_is_not_a_data_type_is_stored_under_the_extension_its_content_has(string $clientName): void
    {
        $this->upload($clientName);

        $stored = $this->newlyStored();
        $this->assertCount(1, $stored, $clientName);
        $this->assertSame('jpg', pathinfo($stored[0], PATHINFO_EXTENSION), $stored[0]);
    }

    public function test_an_image_with_a_real_image_name_keeps_it(): void
    {
        foreach (['slide.jpg', 'slide.JPEG', 'slide.png', 'slide.webp'] as $clientName) {
            $this->upload($clientName);
        }

        $extensions = array_map(static fn (string $name): string => pathinfo($name, PATHINFO_EXTENSION), $this->newlyStored());
        sort($extensions);
        $this->assertSame(['jpeg', 'jpg', 'png', 'webp'], $extensions);
    }
}
