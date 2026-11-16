<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\FileManagerUploadRequest;
use App\Services\FileManagerService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;
use ZipArchive;

/**
 * The file manager of the admin panel writes into the public disk: images one by one and a zip that it unpacks.
 *
 * The folder came from the request and was joined to the disk's directory with plain file functions (a zip is copied with File::copy, which no
 * disk guards), so `../../../public/x` unpacked a zip into the web root, and every file of the zip was copied whatever it was called: a `.php`
 * or `.htaccess` file in a zip became a file of the public disk. The images were stored under the extension the client gave them.
 */
class FileManagerUploadTest extends TestCase
{
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private const FOLDER = 'probe-upload';

    /** @var list<string> */
    private array $directoriesToRemove = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    /** @var list<UploadedFile> */
    private array $uploads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCoreSchema();
        $this->directoriesToRemove = [
            storage_path('app/public/' . self::FOLDER),
            public_path('probe-escape'),
            storage_path('app/probe-escape-sibling'),
            base_path('probe-escape-root'),
            storage_path('app/public/probe-hidden'),
        ];
    }

    protected function tearDown(): void
    {
        array_map([File::class, 'deleteDirectory'], $this->directoriesToRemove);
        array_map('unlink', array_filter($this->temporaryFiles, 'file_exists'));
        File::delete(glob(storage_path('app/public/probe-root-*')) ?: []);
        array_map([File::class, 'deleteDirectory'], glob(storage_path('app/temp-zip-*')) ?: []);
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fm');
        file_put_contents($path, $content);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** @param array<string, string> $entries */
    private function zipUpload(array $entries): UploadedFile
    {
        $path = $this->temporaryFile('');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $this->uploads[] = new UploadedFile($path, 'images.zip', 'application/zip', null, true);
    }

    private function imageUpload(string $clientName, ?string $content = null): UploadedFile
    {
        return $this->uploads[] = new UploadedFile($this->temporaryFile($content ?? $this->jpeg()), $clientName, null, null, true);
    }

    /** @param array<string, mixed> $files */
    private function request(string $folder, array $files): Request
    {
        return Request::create('/', 'POST', ['storage' => 'public', 'path' => $folder], [], $files);
    }

    private function service(): FileManagerService
    {
        return app(FileManagerService::class);
    }

    public function test_a_zip_is_unpacked_into_the_folder_and_only_files_of_a_data_type_are_copied(): void
    {
        $entries = [
            'a.jpg' => $this->jpeg(), 'UPPER.JPG' => $this->jpeg(), 'nested/b.png' => $this->jpeg(), 'doc.pdf' => '%PDF-1.4',
            'shell.php' => '<?php system($_GET["c"]);', 'x.phtml' => '<?php echo 1;', 'x.phar' => 'phar', 'page.html' => '<script>alert(1)</script>',
            'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'run.sh' => 'rm -rf /', '.htaccess' => 'AddHandler application/x-httpd-php .jpg',
            '.user.ini' => 'auto_prepend_file=x.php', 'noextension' => 'x', 'config.json' => '{}',
        ];

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $this->assertTrue($stored);
        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        sort($copied);
        $this->assertSame(['UPPER.JPG', 'a.jpg', 'doc.pdf', 'nested/b.png'], $copied);
    }

    /** @return array<string, array{0: string}> */
    public static function foldersThatLeaveThePublicDisk(): array
    {
        return [
            'the web root' => ['../../../public/probe-escape'],
            'the directory beside the disk' => ['../probe-escape-sibling'],
            'the project root' => ['../../../probe-escape-root'],
            'a way up behind a folder' => ['probe-hidden/../../../../probe-escape-root'],
            'a way up written with backslashes' => ['..\\..\\..\\public\\probe-escape'],
            'the way up alone' => ['..'],
            'a path with a null byte' => ["probe-upload\0../../../public/probe-escape"],
            'a line break before the way up' => ["probe-upload\n../../../public/probe-escape"],
        ];
    }

    #[DataProvider('foldersThatLeaveThePublicDisk')]
    public function test_a_folder_that_goes_up_unpacks_nothing_and_stores_nothing(string $folder): void
    {
        $upload = $this->request($folder, ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'shell.php' => '<?php']), 'images' => [$this->imageUpload('probe-root-escape.jpg')]]);

        $stored = $this->service()->uploadFileAndImages($upload);

        $this->assertFalse($stored);
        foreach ([public_path('probe-escape'), storage_path('app/probe-escape-sibling'), base_path('probe-escape-root'), storage_path('app/public/probe-hidden'), storage_path('app/public/probe-root-escape.jpg')] as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function imageNames(): array
    {
        return [
            'a jpeg that says it is php' => ['evil.php', 'evil.jpg'],
            'a jpeg that says it is pht' => ['shell.pht', 'shell.jpg'],
            'a jpeg that says it is phar' => ['shell.phar', 'shell.jpg'],
            'a jpeg that says it is svg' => ['logo.svg', 'logo.jpg'],
            'a jpeg that says it is html' => ['page.html', 'page.jpg'],
            'a jpeg with a double extension' => ['a.jpg.phtml', 'ajpg.jpg'],
            'a jpeg with no extension' => ['noextension', 'noextension.jpg'],
            'a jpeg that says it is png' => ['photo.png', 'photo.png'],
            'a jpeg in capitals' => ['PHOTO.JPG', 'photo.jpg'],
        ];
    }

    #[DataProvider('imageNames')]
    public function test_an_image_is_stored_under_an_extension_of_a_data_type(string $clientName, string $storedName): void
    {
        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['images' => [$this->imageUpload($clientName)]]));

        $names = array_map('basename', File::files(storage_path('app/public/' . self::FOLDER)));
        $this->assertSame([$storedName], $names);
    }

    public function test_the_main_folder_still_receives_what_is_uploaded(): void
    {
        $this->service()->uploadFileAndImages($this->request('public', ['images' => [$this->imageUpload('probe-root-photo.jpg')], 'file' => $this->zipUpload(['probe-root-zipped.png' => $this->jpeg(), 'probe-root-run.php' => '<?php'])]));

        $this->assertFileExists(storage_path('app/public/probe-root-photo.jpg'));
        $this->assertFileExists(storage_path('app/public/probe-root-zipped.png'));
        $this->assertFileDoesNotExist(storage_path('app/public/probe-root-run.php'));
    }

    public function test_a_request_that_names_s3_stores_on_the_public_disk_when_the_shop_keeps_its_files_locally(): void
    {
        $bucket = storage_path('framework/testing/probe-bucket-' . uniqid());
        $this->directoriesToRemove[] = $bucket;
        config(['filesystems.disks.s3' => ['driver' => 'local', 'root' => $bucket]]);
        Cache::put('storage_connection_type', 'local');

        $this->service()->uploadFileAndImages(Request::create('/', 'POST', ['storage' => 's3', 'path' => self::FOLDER], [], ['images' => [$this->imageUpload('photo.jpg')]]));

        $this->assertSame([], is_dir($bucket) ? File::allFiles($bucket) : []);
        $this->assertFileExists(storage_path('app/public/' . self::FOLDER . '/photo.jpg'));
    }

    // ---- the form ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function foldersTheFormSees(): array
    {
        return [
            'the main folder' => ['public', true],
            'a folder' => ['product', true],
            'a folder below a folder' => ['product/digital-product', true],
            'a folder with dots in its name' => ['v1.0/a..b', true],
            'the way up' => ['..', false],
            'a way up behind a folder' => ['product/../../x', false],
            'a way up with backslashes' => ['product\\..\\x', false],
            'a null byte' => ["product\0", false],
            'a line break' => ["product\n..", false],
            'a list' => [['product'], false],
            'nothing' => ['', false],
        ];
    }

    #[DataProvider('foldersTheFormSees')]
    public function test_the_form_accepts_a_folder_of_the_disk_and_refuses_a_path_that_leaves_it(mixed $folder, bool $accepted): void
    {
        $rules = ['path' => (new FileManagerUploadRequest())->rules()['path']];

        $this->assertSame($accepted, Validator::make(['path' => $folder], $rules)->passes(), var_export($folder, true));
    }
}
