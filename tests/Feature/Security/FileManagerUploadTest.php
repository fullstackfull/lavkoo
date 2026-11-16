<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\FileManagerUploadRequest;
use App\Services\FileManagerService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    public function test_a_zip_entry_that_holds_a_script_extension_before_its_data_type_is_not_copied(): void
    {
        // Apache's AddHandler reads every extension of a name, so `evil.php.jpg` runs as PHP on a host that maps the handler.
        $entries = [
            'evil.php.jpg' => '<?php', 'evil.phtml.txt' => '<?php', 'evil.pht.png' => '<?php', 'sub/dir/evil.php.pdf' => '<?php', 'evil.PHP.jpg' => '<?php',
            'evil.php;.jpg' => '<?php', 'evil.php .jpg' => '<?php', 'evil.php5.zip' => '<?php', 'evil.php.old.jpg' => '<?php', 'evil.shtml.csv' => '<!--#exec cmd="id"-->',
            'evil.cgi.png' => '#!/bin/sh', 'evil.pl.txt' => '#!/usr/bin/perl',
            'photo.jpg' => $this->jpeg(), 'my.holiday.photo.jpg' => $this->jpeg(), 'v1.2.pdf' => '%PDF-1.4', 'archive.tar.zip' => 'zip', 'phpinfo-notes.txt' => 'notes', 'php.jpg' => $this->jpeg(),
        ];

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        sort($copied);
        $this->assertSame(['archive.tar.zip', 'my.holiday.photo.jpg', 'photo.jpg', 'php.jpg', 'phpinfo-notes.txt', 'v1.2.pdf'], $copied);
    }

    public function test_a_zip_entry_that_the_disk_would_refuse_is_not_copied(): void
    {
        $entries = ["line\nbreak.png" => $this->jpeg(), "zero\u{200B}width.png" => $this->jpeg(), "right\u{202E}left.png" => $this->jpeg(), "delete\x7F.png" => $this->jpeg(), 'ok.jpg' => $this->jpeg()];

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        $this->assertSame(['ok.jpg'], $copied);
    }

    public function test_the_unpacking_directory_is_gone_after_a_copy(): void
    {
        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'shell.php' => '<?php'])]));

        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_the_unpacking_directory_is_gone_after_a_copy_that_fails_and_no_script_was_ever_unpacked(): void
    {
        File::ensureDirectoryExists(storage_path('app/public/' . self::FOLDER));
        file_put_contents(storage_path('app/public/' . self::FOLDER . '/d.jpg'), 'a file where the zip wants a folder');
        $archive = $this->zipUpload(['shell.php' => '<?php echo "LEFTOVER";', 'a.jpg' => $this->jpeg(), 'd.jpg/inner.jpg' => $this->jpeg()]);
        $unpackedScripts = [];
        $watch = static function () use (&$unpackedScripts): void {
            foreach (glob(storage_path('app/temp-zip-*/shell.php')) ?: [] as $script) {
                $unpackedScripts[] = $script;
            }
        };

        try {
            $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));
        } catch (\Throwable) {
            $watch();
        }

        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: [], 'the directory is removed although the copy failed');
        $this->assertSame([], $unpackedScripts);
    }

    public function test_an_archive_that_holds_nothing_the_gallery_stores_is_reported_as_not_stored(): void
    {
        $nothing = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['icons/a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'b.php' => '<?php', 'c.php.jpg' => '<?php'])]));
        $something = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'b.php' => '<?php'])]));

        $this->assertFalse($nothing);
        $this->assertTrue($something);
    }

    public function test_an_archive_with_more_entries_than_a_gallery_needs_is_refused_before_anything_is_written(): void
    {
        $entries = [];
        foreach (range(1, 5001) as $number) {
            $entries["image-{$number}.txt"] = 'x';
        }

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_an_archive_that_is_mostly_empty_space_is_refused_before_anything_is_written(): void
    {
        // about 60 MB of zeros that a zip stores in about 60 KB
        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['big.jpg' => str_repeat("\0", 60 * 1024 * 1024)])]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
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

    public function test_on_s3_an_image_keeps_its_name_but_gets_an_extension_of_a_data_type(): void
    {
        Storage::fake('s3');
        Cache::put('storage_connection_type', 's3');

        $this->service()->uploadFileAndImages(Request::create('/', 'POST', ['storage' => 's3', 'path' => self::FOLDER], [], ['images' => [$this->imageUpload('photo.php'), $this->imageUpload('holiday.html'), $this->imageUpload('fine.png')]]));

        $stored = Storage::disk('s3')->allFiles(self::FOLDER);
        sort($stored);
        $this->assertSame([self::FOLDER . '/fine.png', self::FOLDER . '/holiday.jpg', self::FOLDER . '/photo.jpg'], $stored);
    }

    public function test_on_s3_a_zip_stores_only_files_of_a_data_type_under_generated_names(): void
    {
        Storage::fake('s3');
        Cache::put('storage_connection_type', 's3');

        $this->service()->uploadFileAndImages(Request::create('/', 'POST', ['storage' => 's3', 'path' => self::FOLDER], [], ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'b.pdf' => '%PDF-1.4', 'shell.php' => '<?php', 'page.html' => '<script>alert(1)</script>', 'c.php.jpg' => '<?php'])]));

        // the name is generated, so the script extension inside `c.php.jpg` is not in what is stored
        $stored = Storage::disk('s3')->allFiles(self::FOLDER);
        $this->assertCount(3, $stored);
        foreach ($stored as $name) {
            $this->assertMatchesRegularExpression('#^' . self::FOLDER . '/\d{4}-\d{2}-\d{2}-[0-9a-f]+\.(jpg|pdf)$#', $name);
        }
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
