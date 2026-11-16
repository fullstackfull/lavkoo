<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\FileManagerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The file manager is the gallery of the public disk. Its download route took the disk from the request (`storage=local` is the whole private
 * tree of storage/app: software update archives, add-on archives, exports) and the page lists whichever disk the request names, so an employee who
 * holds `system_settings` could read what the shop keeps out of the web. The page itself links files through their public address and never
 * calls the download route, so the route is limited to the gallery: the public disk (or S3 when the shop uses it).
 */
class FileManagerAccessTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
    }

    protected function tearDown(): void
    {
        array_map([File::class, 'deleteDirectory'], array_filter($this->created, 'is_dir'));
        array_map('unlink', array_filter($this->created, 'is_file'));
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function fileOnDisk(string $path, string $content): string
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);
        $this->created[] = dirname($path) === storage_path('app') || dirname($path) === storage_path('app/public') ? $path : dirname($path);

        return $path;
    }

    private function controller(): FileManagerController
    {
        return app(FileManagerController::class);
    }

    private function link(string $name, array $query = []): Request
    {
        return Request::create('/', 'GET', $query);
    }

    private function download(string $path, array $query = []): StreamedResponse
    {
        return $this->controller()->download($this->link($path, $query), base64_encode($path));
    }

    private function body(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string)ob_get_clean();
    }

    public function test_a_file_of_the_gallery_is_downloaded_with_or_without_the_public_prefix_the_old_links_carried(): void
    {
        $this->fileOnDisk(storage_path('app/public/probe-gallery/a.txt'), 'GALLERY');

        foreach (['probe-gallery/a.txt', 'public/probe-gallery/a.txt'] as $path) {
            $this->assertSame('GALLERY', $this->body($this->download($path, ['storage' => 'public'])), $path);
        }
    }

    public function test_a_private_file_of_storage_app_is_not_downloaded_whatever_disk_the_request_names(): void
    {
        $this->fileOnDisk(storage_path('app/probe-private/secret.txt'), 'APP_KEY=base64:canary');

        foreach ([['storage' => 'public'], ['storage' => 'local'], ['storage' => 'private'], ['storage' => ['local']], []] as $query) {
            try {
                $response = $this->download('probe-private/secret.txt', $query);
                $this->assertStringNotContainsString('canary', $this->body($response), json_encode($query));
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function pathsThatLeaveTheGallery(): array
    {
        return [
            'a way up' => ['../probe-private/secret.txt'],
            'a way up behind a folder' => ['probe-gallery/../../probe-private/secret.txt'],
            'the public prefix and a way up' => ['public/../probe-private/secret.txt'],
            'an absolute path' => ['/etc/hostname'],
            'the project environment file' => ['../../.env'],
            'a path with a null byte' => ["probe-gallery/a.txt\0../../probe-private/secret.txt"],
            'a folder' => ['probe-gallery'],
            'nothing' => [''],
        ];
    }

    #[DataProvider('pathsThatLeaveTheGallery')]
    public function test_a_path_that_leaves_the_gallery_downloads_nothing(string $path): void
    {
        $this->fileOnDisk(storage_path('app/probe-private/secret.txt'), 'APP_KEY=base64:canary');
        $this->fileOnDisk(storage_path('app/public/probe-gallery/a.txt'), 'GALLERY');

        try {
            $body = $this->body($this->download($path, ['storage' => 'public']));
            $this->assertStringNotContainsString('canary', $body);
            $this->assertStringNotContainsString('APP_KEY', $body);
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_the_page_lists_the_public_disk_whatever_disk_the_request_names(): void
    {
        $this->fileOnDisk(storage_path('app/probe-private/secret.txt'), 'APP_KEY=base64:canary');

        foreach (['local', 'private', 'public', null] as $disk) {
            $view = $this->controller()->index(Request::create('/', 'GET', $disk === null ? [] : ['storage' => $disk]));

            $this->assertSame('public', $view->getData()['storage'], (string)$disk);
            $names = array_column($view->getData()['allItemList']->items(), 'name');
            $this->assertNotContains('probe-private', $names);
            $this->assertNotContains('probe private', $names);
        }
    }
}
