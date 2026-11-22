<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\Admin\Settings\FileManagerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** @return array<string, array{0: string}> paths that Flysystem refuses (it throws on any control, format, private use or unassigned character), which the route answers as a missing file */
    public static function pathsThatHoldAControlOrFormatCharacter(): array
    {
        return [
            'a delete character' => ["probe-gallery/a.txt\x7F"],
            'a zero width space' => ["probe-gallery/a\u{200B}.txt"],
            'a soft hyphen' => ["probe-gallery/a\u{00AD}.txt"],
            'a byte order mark' => ["probe-gallery/\u{FEFF}a.txt"],
            'a next line character' => ["probe-gallery/a\u{0085}.txt"],
            'a right-to-left override' => ["probe-gallery/a\u{202E}txt.exe"],
            'a private use character' => ["probe-gallery/a\u{E000}.txt"],
            'an unassigned code point' => ["probe-gallery/a\u{0378}.txt"],
        ];
    }

    #[DataProvider('pathsThatHoldAControlOrFormatCharacter')]
    public function test_a_path_that_holds_a_control_or_format_character_is_a_missing_file_and_no_server_error(string $path): void
    {
        $this->fileOnDisk(storage_path('app/public/probe-gallery/a.txt'), 'GALLERY');

        try {
            $this->download($path, ['storage' => 'public']);
            $this->fail('a path with a control or format character was served');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    #[DataProvider('pathsThatHoldAControlOrFormatCharacter')]
    public function test_a_folder_that_holds_a_control_or_format_character_opens_the_main_folder(string $folder): void
    {
        $view = $this->controller()->index(Request::create('/', 'GET', ['targetFolder' => base64_encode($folder)]));

        $this->assertSame('cHVibGlj', $view->getData()['targetFolder']);
    }

    public function test_a_folder_with_a_control_character_beside_bytes_that_are_no_text_opens_the_main_folder(): void
    {
        // a rule that reads Unicode cannot read such a name, so the rule for the plain control characters has to refuse it
        $view = $this->controller()->index(Request::create('/', 'GET', ['targetFolder' => base64_encode("probe-gallery/a\xFF\n")]));

        $this->assertSame('cHVibGlj', $view->getData()['targetFolder']);
    }

    private function bucketHolding(string $path, string $content, string $shopStorage): void
    {
        $bucket = storage_path('framework/testing/probe-bucket-' . uniqid());
        File::ensureDirectoryExists(dirname($bucket . '/' . $path));
        file_put_contents($bucket . '/' . $path, $content);
        $this->created[] = $bucket;
        config(['filesystems.disks.s3' => ['driver' => 'local', 'root' => $bucket]]);
        Cache::put('storage_connection_type', $shopStorage);
    }

    public function test_the_bucket_is_not_read_when_the_shop_keeps_its_files_locally(): void
    {
        $this->bucketHolding('secret-backups/db.sql', 'DUMP', shopStorage: 'local');

        try {
            $this->download('secret-backups/db.sql', ['storage' => 's3']);
            $this->fail('a file of the bucket was served although the shop does not use it');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $view = $this->controller()->index(Request::create('/', 'GET', ['storage' => 's3', 'targetFolder' => base64_encode('secret-backups')]));
        $this->assertSame('public', $view->getData()['storage']);
        $this->assertNotContains('db.sql', array_column($view->getData()['allItemList']->items(), 'name'));
    }

    public function test_the_bucket_is_read_when_the_shop_keeps_its_files_there(): void
    {
        $this->bucketHolding('secret-backups/db.sql', 'DUMP', shopStorage: 's3');

        $this->assertSame('DUMP', $this->body($this->download('secret-backups/db.sql', ['storage' => 's3'])));
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
