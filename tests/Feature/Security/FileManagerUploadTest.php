<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Admin\FileManagerUploadRequest;
use App\Services\FileManager\ArchiveReport;
use App\Services\FileManagerService;
use App\Utils\StoredFileExtension;
use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use SplFileInfo;
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

    /** The most an archive may unpack to: 64 MiB, whatever its compressed padding pays for. */
    private const CEILING = 64 * 1024 * 1024;

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

    /**
     * @param array<string, string> $entries
     * @param callable(ZipArchive): void|null $beforeClose what is done to the archive after its entries are added (an encryption, an attribute)
     */
    private function zipUpload(array $entries, ?callable $beforeClose = null): UploadedFile
    {
        $path = $this->temporaryFile('');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($beforeClose !== null) {
            $beforeClose($zip);
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

    /**
     * What the admin is told after posting to the real route of the form (without the panel's middleware): the newest toast, because the toast
     * service keeps the ones of the posts before it for as long as the application lives.
     *
     * @param array<string, mixed> $files
     * @return array{type: string, message: string}
     */
    private function toastAfterPosting(array $files): array
    {
        $this->withoutMiddleware()->call('POST', route('admin.system-setup.file-manager.image-upload'), ['storage' => 'public', 'path' => self::FOLDER], [], $files);
        $toast = (array) last((array) session('laravel-toaster-magic::messages', []));

        return ['type' => $toast['type'], 'message' => $toast['message']];
    }

    /** A shop that keeps its files on S3 (a fake bucket), and the request that uploads into it. */
    private function bucketRequest(array $files): Request
    {
        Storage::fake('s3');
        Cache::put('storage_connection_type', 's3');

        return Request::create('/', 'POST', ['storage' => 's3', 'path' => self::FOLDER], [], $files);
    }

    /** @return list<string> */
    private function storedOnTheBucket(): array
    {
        return Storage::disk('s3')->allFiles(self::FOLDER);
    }

    /**
     * A shop whose bucket is no fake: `$storesObject` is asked about the path of every object the bucket is told to store, and the bucket stores it
     * when the answer is yes and refuses it when it is no (the write answers false, as the s3 disk of the shop does, or throws when `$throws` is set).
     *
     * @param array<string, mixed> $files
     * @return Request the request that uploads into it
     */
    private function bucketThatWatches(Closure $storesObject, array $files, bool $throws = false): Request
    {
        $root = storage_path('framework/testing/probe-bucket-' . uniqid());
        $this->directoriesToRemove[] = $root;
        $adapter = new class($root, $storesObject) extends LocalFilesystemAdapter {
            public function __construct(string $root, private readonly Closure $storesObject)
            {
                parent::__construct($root);
            }

            public function writeStream(string $path, $contents, Config $config): void
            {
                if (!($this->storesObject)($path)) {
                    throw UnableToWriteFile::atLocation($path, 'the bucket refuses the object');
                }
                parent::writeStream($path, $contents, $config);
            }
        };
        Storage::set('s3', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => $throws]));
        Cache::put('storage_connection_type', 's3');

        return Request::create('/', 'POST', ['storage' => 's3', 'path' => self::FOLDER], [], $files);
    }

    /** An archive that writes down a size it does not have: its headers say `$declaredSize` bytes, its data is what it is. */
    private function zipUploadThatUnderstatesItsSize(string $name, string $content, int $declaredSize): UploadedFile
    {
        $upload = $this->zipUpload([$name => $content]);
        $bytes = (string) file_get_contents($upload->getPathname());
        $centralDirectory = (int) strrpos($bytes, "PK\x01\x02");
        $bytes = substr_replace($bytes, pack('V', $declaredSize), 22, 4);
        $bytes = substr_replace($bytes, pack('V', $declaredSize), $centralDirectory + 24, 4);
        file_put_contents($upload->getPathname(), $bytes);

        $this->assertSame($declaredSize, $this->statOfTheFirstEntry($upload)['size'], 'the archive says what the test wants it to say');

        return $upload;
    }

    /** An archive whose directory lists one entry `$times` times: its data is there once, its headers promise it `$times` times. */
    private function zipUploadThatListsAnEntryAgain(string $name, string $content, int $times): UploadedFile
    {
        $upload = $this->zipUpload([$name => $content]);
        $bytes = (string) file_get_contents($upload->getPathname());
        $directoryStart = (int) strrpos($bytes, "PK\x01\x02");
        $endStart = (int) strrpos($bytes, "PK\x05\x06");
        $directory = str_repeat(substr($bytes, $directoryStart, $endStart - $directoryStart), $times);
        $end = substr($bytes, $endStart);
        $end = substr_replace($end, pack('v', $times), 8, 2);
        $end = substr_replace($end, pack('v', $times), 10, 2);
        $end = substr_replace($end, pack('V', strlen($directory)), 12, 4);
        file_put_contents($upload->getPathname(), substr($bytes, 0, $directoryStart) . $directory . $end);

        return $upload;
    }

    /** An archive in which two entries have one name, with `$first` as the data of the first and `$second` as the data of the second. */
    private function zipUploadWithARepeatedName(string $name, string $first, string $second): UploadedFile
    {
        $placeholder = str_repeat('~', strlen($name));
        $upload = $this->zipUpload([$name => $first, $placeholder => $second]);
        file_put_contents($upload->getPathname(), str_replace($placeholder, $name, (string) file_get_contents($upload->getPathname())));

        return $upload;
    }

    /** An archive with one entry that has no name: libzip opens it and names the entry with an empty text. */
    private function zipUploadWithAnUnnamedEntry(): UploadedFile
    {
        $upload = $this->zipUpload(['x' => $this->jpeg()]);
        $bytes = (string) file_get_contents($upload->getPathname());
        $bytes = substr_replace($bytes, "\0\0", 26, 2);
        $bytes = substr($bytes, 0, 30) . substr($bytes, 31);
        $directoryStart = (int) strrpos($bytes, "PK\x01\x02");
        $bytes = substr_replace($bytes, "\0\0", $directoryStart + 28, 2);
        $bytes = substr($bytes, 0, $directoryStart + 46) . substr($bytes, $directoryStart + 47);
        $endStart = (int) strrpos($bytes, "PK\x05\x06");
        $end = unpack('Vsize/Voffset', substr($bytes, $endStart + 12, 8));
        $bytes = substr_replace($bytes, pack('VV', $end['size'] - 1, $end['offset'] - 1), $endStart + 12, 8);
        file_put_contents($upload->getPathname(), $bytes);

        $this->assertSame('', $this->statOfTheFirstEntry($upload)['name'], 'the entry has no name');

        return $upload;
    }

    /**
     * An archive in which the data of one entry is damaged: its first bytes are inverted, so a stored entry no longer matches the checksum the
     * archive keeps for it and a deflated entry is no deflate stream. The entries around it are whole.
     *
     * @param array<string, string> $entries
     * @param int $compression ZipArchive::CM_STORE or ZipArchive::CM_DEFLATE, the method of the damaged entry
     */
    private function zipUploadWithADamagedEntry(string $damagedName, array $entries, int $compression): UploadedFile
    {
        $upload = $this->zipUpload($entries, static fn (ZipArchive $zip): bool => $zip->setCompressionName($damagedName, $compression));
        $bytes = (string) file_get_contents($upload->getPathname());
        $nameStart = (int) strpos($bytes, $damagedName);
        $dataStart = $nameStart + strlen($damagedName) + unpack('v', $bytes, $nameStart - 2)[1];
        file_put_contents($upload->getPathname(), substr_replace($bytes, ~substr($bytes, $dataStart, 4), $dataStart, 4));

        return $upload;
    }

    /** @return array<string, mixed> */
    private function statOfTheFirstEntry(UploadedFile $upload): array
    {
        $archive = new ZipArchive();
        $archive->open($upload->getPathname());
        $stat = $archive->statIndex(0);
        $archive->close();

        return $stat;
    }

    /** @return list<string> the files below the test folder of the public disk, by their path in it */
    private function copiedFiles(): array
    {
        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        sort($copied);

        return $copied;
    }

    /** Noise with a zero run after every byte: a zip stores it in about a twentieth of its size, so the ratio is far below the one an archive of zeros has. */
    private function noiseWithGaps(int $bytesOfNoise): string
    {
        return implode(str_repeat("\0", 31), str_split(random_bytes($bytesOfNoise)));
    }

    /** What the entries of an archive take compressed, as the archive's own directory says. */
    private function compressedSizeOfTheEntries(UploadedFile $upload): int
    {
        $archive = new ZipArchive();
        $archive->open($upload->getPathname());
        $compressed = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $compressed += $archive->statIndex($index)['comp_size'];
        }
        $archive->close();

        return $compressed;
    }

    /**
     * An archive of 560 KiB of noise, which a zip keeps as it is, and as many zeros as make the entries unpack to `$ratio` times what they take
     * compressed (the allowance is 100 times that, and at least 50 MB). The zeros are measured on a sample, and the archive is checked for the ratio.
     */
    private function zipUploadThatUnpacksToTimesItsCompressedSize(float $ratio): UploadedFile
    {
        $noiseBytes = 560 * 1024;
        $sampleBytes = 16 * 1024 * 1024;
        $zerosCompressTo = $this->statOfTheFirstEntry($this->zipUpload(['sample.txt' => str_repeat("\0", $sampleBytes)]))['comp_size'] / $sampleBytes;
        $zerosBytes = (int) round($noiseBytes * ($ratio - 1) / (1 - $ratio * $zerosCompressTo));

        $archive = $this->zipUpload(['noise.jpg' => random_bytes($noiseBytes), 'zeros.txt' => str_repeat("\0", $zerosBytes)], static fn (ZipArchive $zip): bool => $zip->setCompressionName('noise.jpg', ZipArchive::CM_STORE));

        $this->assertEqualsWithDelta($ratio, ($noiseBytes + $zerosBytes) / $this->compressedSizeOfTheEntries($archive), 0.3, 'the archive unpacks to the ratio the test wants');

        return $archive;
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
            'evil.cgi.png' => '#!/bin/sh',
            'photo.jpg' => $this->jpeg(), 'my.holiday.photo.jpg' => $this->jpeg(), 'v1.2.pdf' => '%PDF-1.4', 'archive.tar.zip' => 'zip', 'phpinfo-notes.txt' => 'notes', 'php.jpg' => $this->jpeg(),
        ];

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        sort($copied);
        $this->assertSame(['archive.tar.zip', 'my.holiday.photo.jpg', 'photo.jpg', 'php.jpg', 'phpinfo-notes.txt', 'v1.2.pdf'], $copied);
    }

    /** @return array<string, array{0: string, 1: bool}> a name, and whether it is stored */
    public static function namesWithAnExtensionBeforeTheLastOne(): array
    {
        $served = [];
        foreach (['php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'shtml', 'shtm', 'stm', 'cgi'] as $extension) {
            $served["a .{$extension} that a server runs"] = ["evil.{$extension}.jpg", false];
        }
        $ordinary = [
            'a company' => 'Acme.Inc.jpg', 'a polish document' => 'terms.pl.pdf', 'a price list' => 'price.sh.csv', 'a python photo' => 'photo.py.jpg',
            'a ruby logo' => 'logo.rb.png', 'an asp page' => 'page.asp.jpg', 'an aspx page' => 'page.aspx.jpg', 'a jsp menu' => 'menu.jsp.png',
            'a jspx menu' => 'menu.jspx.png', 'a coldfusion form' => 'form.cfm.png', 'an include' => 'old.inc.txt',
        ];

        return $served + array_map(static fn (string $name): array => [$name, true], $ordinary);
    }

    #[DataProvider('namesWithAnExtensionBeforeTheLastOne')]
    public function test_a_zip_entry_is_stored_unless_an_extension_before_its_last_one_is_one_a_server_runs(string $name, bool $isStored): void
    {
        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload([$name => $this->jpeg(), 'control.jpg' => $this->jpeg()])]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        $this->assertContains('control.jpg', $copied);
        $this->assertSame($isStored, in_array($name, $copied, true));
    }

    public function test_a_folder_whose_name_carries_a_handler_word_hides_no_handler_from_the_files_in_it_on_either_disk(): void
    {
        $entries = ['v1.php.backup/photo.jpg' => $this->jpeg(), 'a.php/b.jpg' => $this->jpeg(), 'control.jpg' => $this->jpeg()];

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));

        $this->assertTrue($onTheDisk);
        $this->assertTrue($onTheBucket);
        $this->assertSame(['a.php/b.jpg', 'control.jpg', 'v1.php.backup/photo.jpg'], $this->copiedFiles());
        $this->assertCount(3, $this->storedOnTheBucket());
    }

    public function test_the_last_extension_of_a_name_is_its_type_and_hides_no_handler(): void
    {
        // `photo.php` is refused because `php` is no data type, not because a handler hides in front of the type
        $this->assertFalse(StoredFileExtension::hidesHandler(fileName: 'photo.php'));
        $this->assertTrue(StoredFileExtension::hidesHandler(fileName: 'photo.php.jpg'));
    }

    public function test_a_zip_entry_that_the_disk_would_refuse_is_not_copied(): void
    {
        $entries = [
            "line\nbreak.png" => $this->jpeg(), "zero\u{200B}width.png" => $this->jpeg(), "right\u{202E}left.png" => $this->jpeg(), "delete\x7F.png" => $this->jpeg(),
            "private\u{E000}use.png" => $this->jpeg(), "unassigned\u{0378}.png" => $this->jpeg(), 'ok.jpg' => $this->jpeg(),
        ];

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        $this->assertSame(['ok.jpg'], $copied);
    }

    public function test_the_unpacking_directory_is_gone_after_a_copy(): void
    {
        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'shell.php' => '<?php'])]));

        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_the_unpacking_directory_is_gone_after_a_copy_that_throws_and_no_script_was_ever_unpacked(): void
    {
        $unpackedFiles = [];
        $request = $this->bucketThatWatches(
            storesObject: static function () use (&$unpackedFiles): bool {
                $unpackedFiles = array_map('basename', glob(storage_path('app/temp-zip-*/*')) ?: []);

                return false;
            },
            files: ['file' => $this->zipUpload(['shell.php' => '<?php echo "LEFTOVER";', 'a.jpg' => $this->jpeg()])],
            throws: true,
        );

        try {
            $this->service()->uploadFileAndImages($request);
            $thrown = false;
        } catch (UnableToWriteFile) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'the bucket refuses with an exception');
        $this->assertSame(['1.jpg'], $unpackedFiles, 'only the image was unpacked, under a name of its own');
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: [], 'the directory is removed although the copy failed');
    }

    public function test_two_uploads_in_a_row_unpack_into_directories_of_their_own(): void
    {
        $directories = [];
        $watch = static function () use (&$directories): bool {
            $directories[] = glob(storage_path('app/temp-zip-*')) ?: [];

            return true;
        };

        $this->service()->uploadFileAndImages($this->bucketThatWatches(storesObject: $watch, files: ['file' => $this->zipUpload(['a.jpg' => $this->jpeg()])]));
        $this->service()->uploadFileAndImages($this->bucketThatWatches(storesObject: $watch, files: ['file' => $this->zipUpload(['a.jpg' => $this->jpeg()])]));

        $this->assertCount(2, $directories);
        $this->assertCount(1, $directories[0]);
        $this->assertCount(1, $directories[1]);
        $this->assertNotSame($directories[0], $directories[1], 'two uploads at once would share one directory');
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

    public function test_an_archive_whose_headers_understate_its_size_is_refused_before_anything_is_written(): void
    {
        $archive = $this->zipUploadThatUnderstatesItsSize('big.txt', str_repeat("\0", 60 * 1024 * 1024), declaredSize: 100);

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_on_s3_an_archive_whose_headers_understate_its_size_is_refused_before_anything_is_written(): void
    {
        $archive = $this->zipUploadThatUnderstatesItsSize('big.txt', str_repeat("\0", 60 * 1024 * 1024), declaredSize: 100);

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $archive]));

        $this->assertFalse($stored);
        $this->assertSame([], $this->storedOnTheBucket());
    }

    public function test_on_s3_an_archive_that_is_mostly_empty_space_is_refused_before_anything_is_written(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload(['big.jpg' => str_repeat("\0", 60 * 1024 * 1024)])]));

        $this->assertFalse($stored);
        $this->assertSame([], $this->storedOnTheBucket());
    }

    public function test_the_ratio_is_taken_over_the_entries_the_gallery_stores_only(): void
    {
        // 60 MB of zeros, and 3 MB of noise that is no file of the gallery: the noise must not pay for the zeros
        $archive = $this->zipUpload(['zeros.txt' => str_repeat("\0", 60 * 1024 * 1024), 'padding.php' => random_bytes(3 * 1024 * 1024)]);

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
    }

    public function test_an_archive_that_unpacks_to_more_than_the_most_is_stored_when_what_it_stores_is_not_mostly_empty_space(): void
    {
        // the same zeros, paid for by 3 MB of noise that is stored as well: the ratio is 20, far from a way to fill a disk
        $archive = $this->zipUpload(['zeros.txt' => str_repeat("\0", 60 * 1024 * 1024), 'padding.jpg' => random_bytes(3 * 1024 * 1024)]);

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));

        $this->assertTrue($stored);
        $this->assertSame(60 * 1024 * 1024, filesize(storage_path('app/public/' . self::FOLDER . '/zeros.txt')));
        $this->assertSame(3 * 1024 * 1024, filesize(storage_path('app/public/' . self::FOLDER . '/padding.jpg')));
    }

    public function test_an_archive_that_unpacks_to_exactly_the_most_is_stored(): void
    {
        $most = 50 * 1024 * 1024;

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['most.txt' => str_repeat("\0", $most)])]));

        $this->assertTrue($stored);
        $this->assertSame($most, filesize(storage_path('app/public/' . self::FOLDER . '/most.txt')));
    }

    public function test_an_archive_that_unpacks_to_one_byte_more_than_the_most_is_refused(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['more.txt' => str_repeat("\0", 50 * 1024 * 1024 + 1)])]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
    }

    public function test_an_archive_that_unpacks_to_just_under_a_hundred_times_what_it_takes_compressed_is_stored(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUploadThatUnpacksToTimesItsCompressedSize(ratio: 99.5)]));

        $this->assertTrue($stored);
        $this->assertCount(2, File::allFiles(storage_path('app/public/' . self::FOLDER)));
    }

    public function test_an_archive_that_unpacks_to_just_over_a_hundred_times_what_it_takes_compressed_is_refused(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUploadThatUnpacksToTimesItsCompressedSize(ratio: 100.5)]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
    }

    /** @return array<string, string> */
    private function manyImages(int $count): array
    {
        $images = [];
        foreach (range(1, $count) as $number) {
            $images["image-{$number}.jpg"] = 'x';
        }

        return $images;
    }

    public function test_the_folders_and_the_metadata_of_an_archive_do_not_count_toward_the_most_files(): void
    {
        // what Finder makes of 2600 images: a folder, and a hidden twin of every image under __MACOSX (5203 entries for 2600 files)
        $entries = ['gallery/' => '', '__MACOSX/' => '', '__MACOSX/gallery/' => ''];
        foreach (range(1, 2600) as $number) {
            $entries["gallery/image-{$number}.jpg"] = 'x';
            $entries["__MACOSX/gallery/._image-{$number}.jpg"] = 'metadata';
        }

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $this->assertTrue($stored);
        $this->assertCount(2600, File::allFiles(storage_path('app/public/' . self::FOLDER)));
    }

    public function test_an_archive_with_exactly_the_most_files_to_store_is_stored_whatever_else_it_holds(): void
    {
        $entries = $this->manyImages(5000) + ['shell.php' => '<?php', 'page.html' => '<p>', 'notes/' => '', 'thumbs/Thumbs.db' => 'db', 'photos/.DS_Store' => 'ds'];

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $this->assertTrue($stored);
        $this->assertCount(5000, File::allFiles(storage_path('app/public/' . self::FOLDER)));
    }

    public function test_on_s3_an_archive_with_more_than_the_most_files_to_store_is_refused_before_anything_is_written(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($this->manyImages(5001))]));

        $this->assertFalse($stored);
        $this->assertSame([], $this->storedOnTheBucket());
    }

    public function test_the_admin_is_told_that_an_archive_holds_too_many_files_and_nothing_else(): void
    {
        // the script is listed first, so it is counted as left out before the scan stops at the 5001st file
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['shell.php' => '<?php'] + $this->manyImages(5001))]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive holds more than 5000 files that can be stored'], $toast);
    }

    public function test_the_admin_is_told_how_much_an_archive_may_unpack_to(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['big.txt' => str_repeat("\0", 60 * 1024 * 1024)])]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive unpacks to more than 50 MB'], $toast);
    }

    public function test_the_admin_is_told_the_whole_allowance_when_a_later_entry_is_the_one_that_crosses_it(): void
    {
        // the first entry leaves 20 MB of the 50, the second crosses them: the sentence names the 50, not what was left
        $archive = $this->zipUpload(['first.txt' => str_repeat("\0", 30 * 1024 * 1024), 'second.txt' => str_repeat("\0", 30 * 1024 * 1024)]);

        $toast = $this->toastAfterPosting(['file' => $archive]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive unpacks to more than 50 MB'], $toast);
    }

    public function test_the_admin_is_told_the_size_an_archive_may_unpack_to_when_what_it_stores_allows_more_than_50_MB(): void
    {
        // 0.5 MB that does not compress and 70 MB of zeros: a hundred times what they take compressed is about 57 MB, and the archive holds 70
        $archive = $this->zipUpload(['noise.jpg' => random_bytes(520 * 1024), 'zeros.txt' => str_repeat("\0", 70 * 1024 * 1024)]);

        $toast = $this->toastAfterPosting(['file' => $archive]);

        $this->assertSame('error', $toast['type']);
        $this->assertSame(1, preg_match('/^The archive unpacks to more than (\d+) MB$/', $toast['message'], $matches), $toast['message']);
        $this->assertGreaterThan(55, (int) $matches[1]);
        $this->assertLessThan(60, (int) $matches[1]);
    }

    /**
     * 3 MiB of noise that a zip keeps as it is, which pays at a hundred times for about 300 MiB, and zeros: the allowance of the ratio is far
     * above the ceiling, so only the ceiling decides what such an archive may unpack to.
     */
    private function zipUploadWithPaddingAndZeros(int $zerosBytes): UploadedFile
    {
        $archive = $this->zipUpload(
            ['padding.jpg' => random_bytes(3 * 1024 * 1024), 'zeros.txt' => str_repeat("\0", $zerosBytes)],
            static fn (ZipArchive $zip): bool => $zip->setCompressionName('padding.jpg', ZipArchive::CM_STORE),
        );
        $this->assertGreaterThan(self::CEILING, 100 * $this->compressedSizeOfTheEntries($archive), 'the padding pays for more than the ceiling');

        return $archive;
    }

    public function test_an_archive_that_unpacks_to_exactly_64_MiB_is_stored_however_much_its_padding_pays_for(): void
    {
        $archive = $this->zipUploadWithPaddingAndZeros(zerosBytes: self::CEILING - 3 * 1024 * 1024);

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));

        $this->assertTrue($stored);
        $this->assertSame(self::CEILING - 3 * 1024 * 1024, filesize(storage_path('app/public/' . self::FOLDER . '/zeros.txt')));
        $this->assertSame(3 * 1024 * 1024, filesize(storage_path('app/public/' . self::FOLDER . '/padding.jpg')));
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_an_archive_whose_padding_pays_for_more_is_refused_whole_at_one_byte_over_64_MiB(): void
    {
        $archive = $this->zipUploadWithPaddingAndZeros(zerosBytes: self::CEILING - 3 * 1024 * 1024 + 1);

        $toast = $this->toastAfterPosting(['file' => $archive]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive unpacks to more than 64 MB'], $toast);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_on_s3_an_archive_over_the_ceiling_puts_no_object_on_the_bucket_and_leaves_no_temporary_file(): void
    {
        $archive = $this->zipUploadWithPaddingAndZeros(zerosBytes: 70 * 1024 * 1024);

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $archive]));

        $this->assertFalse($stored);
        $this->assertSame([], $this->storedOnTheBucket());
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_the_unpacking_directory_never_holds_more_than_the_ceiling_and_one_byte_whatever_the_archive_holds(): void
    {
        $archive = $this->zipUploadWithPaddingAndZeros(zerosBytes: 90 * 1024 * 1024);
        $directory = storage_path('app/temp-zip-ceiling-' . uniqid());
        $this->directoriesToRemove[] = $directory;
        File::ensureDirectoryExists($directory);
        $zip = new ZipArchive();
        $zip->open($archive->getPathname());

        $unpacked = (new ReflectionMethod(FileManagerService::class, 'unpackEntries'))
            ->invoke($this->service(), $zip, [0 => 'padding.jpg', 1 => 'zeros.txt'], $directory, (int) $archive->getSize(), new ArchiveReport());
        $zip->close();

        $this->assertNull($unpacked, 'the archive is refused');
        $onTheDisk = array_sum(array_map(static fn (SplFileInfo $file): int => $file->getSize(), File::allFiles($directory, true)));
        $this->assertGreaterThan(self::CEILING - 1, $onTheDisk, 'the unpacking went on to the ceiling');
        $this->assertLessThanOrEqual(self::CEILING + 1, $onTheDisk, 'the unpacking stopped one byte past the ceiling');
    }

    #[DataProvider('arabicLanguagesOfTheShop')]
    public function test_the_admin_is_told_the_ceiling_in_arabic(string $language): void
    {
        session(['local' => $language]);

        $toast = $this->toastAfterPosting(['file' => $this->zipUploadWithPaddingAndZeros(zerosBytes: self::CEILING)]);

        $this->assertSame('يتجاوز حجم الأرشيف بعد فك الضغط 64 ميغابايت', $toast['message']);
    }

    public function test_the_admin_is_told_that_an_archive_holds_no_file_that_can_be_stored(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['photos/' => '', '__MACOSX/photos/' => ''])]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive holds no file that can be stored'], $toast);
    }

    /** @return array<string, array{0: string}> */
    public static function arabicLanguagesOfTheShop(): array
    {
        return ['Saudi Arabic' => ['sa'], 'Emirati Arabic' => ['ae']];
    }

    #[DataProvider('arabicLanguagesOfTheShop')]
    public function test_the_admin_is_told_the_limits_in_arabic_with_their_numbers(string $language): void
    {
        session(['local' => $language]);

        $tooMany = $this->toastAfterPosting(['file' => $this->zipUpload(['shell.php' => '<?php'] + $this->manyImages(5001))]);
        $tooLarge = $this->toastAfterPosting(['file' => $this->zipUpload(['big.txt' => str_repeat("\0", 60 * 1024 * 1024)])]);

        $this->assertSame('يحتوي الأرشيف على أكثر من 5000 ملف يمكن حفظه', $tooMany['message']);
        $this->assertSame('يتجاوز حجم الأرشيف بعد فك الضغط 50 ميغابايت', $tooLarge['message']);
    }

    #[DataProvider('arabicLanguagesOfTheShop')]
    public function test_the_admin_is_told_in_arabic_how_many_files_an_archive_left_out_and_which(string $language): void
    {
        session(['local' => $language]);

        $several = $this->toastAfterPosting(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), 'shell.php' => '<?php', 'icons/a.svg' => '<svg/>', 'page.html' => '<p>'])]);
        $one = $this->toastAfterPosting(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), 'shell.php' => '<?php'])]);

        $this->assertStringEndsWith(': لم يُحفظ 3 من ملفات الأرشيف (shell.php, icons/a.svg, page.html)', $several['message']);
        $this->assertStringEndsWith(': لم يُحفظ ملف واحد من الأرشيف (shell.php)', $one['message']);
    }

    public function test_the_admin_is_told_in_arabic_how_many_more_files_were_left_out_than_are_named(): void
    {
        session(['local' => 'sa']);
        $entries = ['ok.jpg' => $this->jpeg()];
        foreach (range(1, 8) as $number) {
            $entries["script-{$number}.php"] = '<?php';
        }

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertStringEndsWith(': لم يُحفظ 8 من ملفات الأرشيف (script-1.php, script-2.php, script-3.php, script-4.php, script-5.php, و3 أخرى)', $toast['message']);
    }

    public function test_the_admin_is_told_that_an_archive_was_stored(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['a.jpg' => $this->jpeg()])]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully'], $toast);
    }

    public function test_the_admin_is_told_that_images_were_stored(): void
    {
        $toast = $this->toastAfterPosting(['images' => [$this->imageUpload('photo.jpg')]]);

        $this->assertSame(['type' => 'success', 'message' => 'Image uploaded successfully'], $toast);
    }

    public function test_an_archive_that_lists_one_entry_many_times_is_judged_by_what_it_holds_and_not_by_what_it_lists(): void
    {
        // 40 entries of 2 MB that compress twentyfold: the directory promises 4 MB of compressed data for 80 MB, the file holds 0.1 MB
        $archive = $this->zipUploadThatListsAnEntryAgain('noise.txt', $this->noiseWithGaps(bytesOfNoise: 65536), times: 40);
        $listed = 0;
        $zip = new ZipArchive();
        $zip->open($archive->getPathname());
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $listed += $zip->statIndex($index)['comp_size'];
        }
        $zip->close();
        $this->assertGreaterThan(40 * 2 * 1024 * 1024, 100 * $listed, 'the listed sizes would allow the unpacking');
        $this->assertLessThan(40 * 2 * 1024 * 1024, 100 * filesize($archive->getPathname()), 'the file does not');

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $archive]));

        $this->assertFalse($stored);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
    }

    public function test_on_s3_an_entry_is_streamed_and_never_read_into_memory_whole(): void
    {
        $archive = $this->zipUpload(['big.jpg' => random_bytes(24 * 1024 * 1024)]);
        $request = $this->bucketRequest(['file' => $archive]);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->service()->uploadFileAndImages($request);
        $peak = memory_get_peak_usage() - $before;

        $this->assertSame(24 * 1024 * 1024, Storage::disk('s3')->size($this->storedOnTheBucket()[0]));
        $this->assertLessThan(8 * 1024 * 1024, $peak);
    }

    public function test_an_entry_that_cannot_be_read_does_not_stop_the_entries_after_it(): void
    {
        $encrypt = static fn (ZipArchive $zip): bool => $zip->setEncryptionName('locked.jpg', ZipArchive::EM_AES_256, 'a password nobody knows');
        $entries = ['locked.jpg' => $this->jpeg(), 'open.jpg' => $this->jpeg()];

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries, $encrypt)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries, $encrypt)]));

        $this->assertTrue($onTheDisk);
        $this->assertTrue($onTheBucket);
        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        $this->assertSame(['open.jpg'], $copied);
        $this->assertCount(1, $this->storedOnTheBucket());
    }

    /** @return array<string, array{0: int}> */
    public static function methodsOfADamagedEntry(): array
    {
        return ['a stored entry whose data fails its checksum' => [ZipArchive::CM_STORE], 'a deflated entry whose data is no deflate stream' => [ZipArchive::CM_DEFLATE]];
    }

    #[DataProvider('methodsOfADamagedEntry')]
    public function test_an_entry_whose_data_is_damaged_is_left_out_and_listed_and_the_entries_around_it_are_stored_on_either_disk(int $compression): void
    {
        $entries = ['first.jpg' => $this->jpeg(), 'damaged.jpg' => str_repeat('damaged ', 512), 'last.jpg' => $this->jpeg()];
        $reportOfTheDisk = new ArchiveReport();
        $reportOfTheBucket = new ArchiveReport();

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUploadWithADamagedEntry('damaged.jpg', $entries, $compression)]), $reportOfTheDisk);
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUploadWithADamagedEntry('damaged.jpg', $entries, $compression)]), $reportOfTheBucket);

        $this->assertTrue($onTheDisk);
        $this->assertTrue($onTheBucket);
        $this->assertSame(['first.jpg', 'last.jpg'], $this->copiedFiles());
        $this->assertCount(2, $this->storedOnTheBucket());
        $this->assertSame('One file of the archive was not stored (damaged.jpg)', $reportOfTheDisk->skippedMessage());
        $this->assertSame('One file of the archive was not stored (damaged.jpg)', $reportOfTheBucket->skippedMessage());
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_the_partial_file_of_a_damaged_entry_is_gone_before_the_next_entry_is_unpacked(): void
    {
        $unpackedFiles = null;
        $request = $this->bucketThatWatches(
            storesObject: static function () use (&$unpackedFiles): bool {
                $unpackedFiles ??= array_map('basename', glob(storage_path('app/temp-zip-*/*')) ?: []);

                return true;
            },
            files: ['file' => $this->zipUploadWithADamagedEntry('damaged.jpg', ['first.jpg' => $this->jpeg(), 'damaged.jpg' => str_repeat('damaged ', 512), 'last.jpg' => $this->jpeg()], ZipArchive::CM_STORE)],
        );

        $this->service()->uploadFileAndImages($request);

        $this->assertSame(['0.jpg', '2.jpg'], $unpackedFiles, 'what was unpacked when the bucket was first asked: the two whole entries, by the index of the entry');
    }

    public function test_the_admin_is_told_that_an_entry_with_damaged_data_was_left_out_and_not_given_a_server_error(): void
    {
        $archive = $this->zipUploadWithADamagedEntry('damaged.jpg', ['ok.jpg' => $this->jpeg(), 'damaged.jpg' => str_repeat('damaged ', 512)], ZipArchive::CM_STORE);

        $toast = $this->toastAfterPosting(['file' => $archive]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully: One file of the archive was not stored (damaged.jpg)'], $toast);
    }

    public function test_an_archive_whose_only_entry_is_damaged_is_reported_as_not_stored_on_either_disk(): void
    {
        $entries = ['damaged.jpg' => str_repeat('damaged ', 512)];

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUploadWithADamagedEntry('damaged.jpg', $entries, ZipArchive::CM_STORE)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUploadWithADamagedEntry('damaged.jpg', $entries, ZipArchive::CM_STORE)]));

        $this->assertFalse($onTheDisk);
        $this->assertFalse($onTheBucket);
        $this->assertDirectoryDoesNotExist(storage_path('app/public/' . self::FOLDER));
        $this->assertSame([], $this->storedOnTheBucket());
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
        $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'b.pdf' => '%PDF-1.4', 'shell.php' => '<?php', 'page.html' => '<script>alert(1)</script>', 'c.php.jpg' => '<?php'])]));

        // `c.php.jpg` is chosen out like on the local disk, whatever name the bucket would give it
        $stored = $this->storedOnTheBucket();
        $this->assertCount(2, $stored);
        foreach ($stored as $name) {
            $this->assertMatchesRegularExpression('#^' . self::FOLDER . '/\d{4}-\d{2}-\d{2}-[0-9a-f]+\.(jpg|pdf)$#', $name);
        }
        $extensions = array_map(static fn (string $name): string => pathinfo($name, PATHINFO_EXTENSION), $stored);
        sort($extensions);
        $this->assertSame(['jpg', 'pdf'], $extensions, 'a document keeps its extension on the bucket');
    }

    public function test_on_s3_the_entries_of_the_local_disk_are_stored_and_no_others(): void
    {
        $entries = [
            'ok.jpg' => $this->jpeg(), 'evil.php.jpg' => $this->jpeg(), "line\nbreak.png" => $this->jpeg(), "zero\u{200B}width.png" => $this->jpeg(),
            '.hidden/h.jpg' => $this->jpeg(), '../up.jpg' => $this->jpeg(), 'page.html' => '<script>alert(1)</script>', 'icon.svg' => '<svg/>',
        ];

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));

        $this->assertTrue($stored);
        $this->assertCount(1, $this->storedOnTheBucket());
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function entriesThatTheGalleryDoesNotStore(): array
    {
        return [
            'an svg' => [['icons/a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>']],
            'a script' => [['shell.php' => '<?php']],
            'a script behind a data type' => [['shell.php.jpg' => '<?php']],
            'folders' => [['album.jpg/' => '', 'photos/' => '']],
            'hidden paths' => [['.hidden/a.jpg' => 'x', '.hidden.jpg' => 'x', 'sub/.thumb.png' => 'x']],
        ];
    }

    /** @param array<string, string> $entries */
    #[DataProvider('entriesThatTheGalleryDoesNotStore')]
    public function test_an_archive_that_holds_nothing_the_gallery_stores_is_reported_as_not_stored_on_either_disk(array $entries): void
    {
        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));

        $this->assertFalse($onTheDisk);
        $this->assertFalse($onTheBucket);
        $this->assertSame([], $this->storedOnTheBucket());
    }

    public function test_a_file_that_is_not_an_archive_is_reported_as_not_stored_on_either_disk(): void
    {
        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->imageUpload('images.zip', 'plain text, no archive')]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->imageUpload('images.zip', 'plain text, no archive')]));

        $this->assertFalse($onTheDisk);
        $this->assertFalse($onTheBucket);
    }

    public function test_on_s3_a_folder_entry_that_is_named_like_an_image_is_not_stored_as_an_empty_object(): void
    {
        $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload(['album.jpg/' => '', 'album.jpg/inner.jpg' => $this->jpeg()])]));

        $stored = $this->storedOnTheBucket();
        $this->assertCount(1, $stored);
        $this->assertGreaterThan(0, Storage::disk('s3')->size($stored[0]));
    }

    /** @return array<string, string> entries under names that the local file system cannot hold: a component of 260 bytes, a folder of 300 bytes, a path of over 4000 bytes */
    private function entriesWithNamesTheLocalDiskCannotHold(): array
    {
        return [
            str_repeat("\u{0635}", 128) . '.jpg' => $this->jpeg(),
            str_repeat('d', 300) . '/inner.jpg' => $this->jpeg(),
            implode('/', array_fill(0, 17, str_repeat('p', 250))) . '/deep.jpg' => $this->jpeg(),
        ];
    }

    public function test_on_s3_an_entry_is_stored_whatever_the_local_disk_could_hold_under_its_name(): void
    {
        $entries = $this->entriesWithNamesTheLocalDiskCannotHold() + ['same.jpg' => $this->jpeg(), 'same.jpg/inner.jpg' => $this->jpeg(), 'control.jpg' => $this->jpeg()];

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));

        $this->assertTrue($stored);
        $this->assertCount(6, $this->storedOnTheBucket());
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_on_the_local_disk_an_entry_whose_name_the_disk_cannot_hold_is_left_out_and_listed_and_the_others_are_stored(): void
    {
        $entries = $this->entriesWithNamesTheLocalDiskCannotHold() + ['control.jpg' => $this->jpeg(), 'same.jpg' => $this->jpeg(), 'same.jpg/inner.jpg' => $this->jpeg()];
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]), $report);

        $this->assertTrue($stored);
        $this->assertSame(['control.jpg', 'same.jpg'], $this->copiedFiles());
        $this->assertStringStartsWith('4 files of the archive were not stored (', (string) $report->skippedMessage());
        $this->assertSame([], glob(storage_path('app/temp-zip-*')) ?: []);
    }

    public function test_on_the_local_disk_a_file_that_comes_after_a_folder_of_its_name_is_left_out_and_listed(): void
    {
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['dir.jpg/inner.jpg' => $this->jpeg(), 'dir.jpg' => $this->jpeg()])]), $report);

        $this->assertTrue($stored);
        $this->assertSame(['dir.jpg/inner.jpg'], $this->copiedFiles());
        $this->assertSame('One file of the archive was not stored (dir.jpg)', $report->skippedMessage());
    }

    public function test_an_archive_none_of_whose_names_the_local_disk_can_hold_is_reported_as_not_stored_there_and_stored_on_s3(): void
    {
        $entries = $this->entriesWithNamesTheLocalDiskCannotHold();

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));

        $this->assertFalse($onTheDisk);
        $this->assertTrue($onTheBucket);
        $this->assertCount(3, $this->storedOnTheBucket());
    }

    public function test_an_upload_does_not_need_the_app_directory_of_the_storage_to_exist(): void
    {
        $storage = sys_get_temp_dir() . '/fm-storage-' . uniqid();
        mkdir($storage);
        $this->directoriesToRemove[] = $storage;
        $this->app->useStoragePath($storage);
        $entries = ['a.jpg' => $this->jpeg(), 'nested/b.jpg' => $this->jpeg()];

        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload($entries)]));
        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $this->assertTrue($onTheBucket);
        $this->assertTrue($onTheDisk);
        $this->assertCount(2, $this->storedOnTheBucket());
        $this->assertFileExists($storage . '/app/public/' . self::FOLDER . '/nested/b.jpg');
        $this->assertSame([], glob($storage . '/app/temp-zip-*') ?: []);
    }

    public function test_on_s3_an_archive_that_the_bucket_refuses_entirely_is_reported_as_not_stored(): void
    {
        $request = $this->bucketThatWatches(storesObject: static fn (): bool => false, files: ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'b.jpg' => $this->jpeg()])]);
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($request, $report);

        $this->assertFalse($stored);
        $this->assertSame([], $this->storedOnTheBucket());
        $this->assertSame('2 files of the archive were not stored (a.jpg, b.jpg)', $report->skippedMessage());
    }

    public function test_on_s3_an_object_that_the_bucket_refuses_is_listed_and_the_others_are_stored(): void
    {
        $objects = 0;
        $request = $this->bucketThatWatches(
            storesObject: static function () use (&$objects): bool {
                return ++$objects !== 2;
            },
            files: ['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'b.jpg' => $this->jpeg(), 'c.jpg' => $this->jpeg()])],
        );
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($request, $report);

        $this->assertTrue($stored);
        $this->assertCount(2, $this->storedOnTheBucket());
        $this->assertSame('One file of the archive was not stored (b.jpg)', $report->skippedMessage());
    }

    public function test_an_archive_whose_only_entry_cannot_be_read_is_reported_as_not_stored_on_either_disk(): void
    {
        $encrypt = static fn (ZipArchive $zip): bool => $zip->setEncryptionName('locked.jpg', ZipArchive::EM_AES_256, 'a password nobody knows');

        $onTheDisk = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['locked.jpg' => $this->jpeg()], $encrypt)]));
        $onTheBucket = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload(['locked.jpg' => $this->jpeg()], $encrypt)]));

        $this->assertFalse($onTheDisk);
        $this->assertFalse($onTheBucket);
        $this->assertSame([], $this->storedOnTheBucket());
    }

    public function test_a_hidden_path_is_not_stored_and_the_visible_file_beside_it_is(): void
    {
        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['.hidden/a.jpg' => $this->jpeg(), 'sub/.thumb.png' => $this->jpeg(), 'visible.jpg' => $this->jpeg()])]));

        $this->assertTrue($stored);
        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        $this->assertSame(['visible.jpg'], $copied);
    }

    public function test_a_dot_in_the_middle_of_a_name_or_a_dot_slash_in_front_of_it_does_not_hide_a_path(): void
    {
        $entries = ['./dot-slash.jpg' => $this->jpeg(), 'v1.0/photo.name.jpg' => $this->jpeg(), 'a.b.c/d.e.jpg' => $this->jpeg()];

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload($entries)]));

        $copied = array_map(static fn ($file): string => $file->getRelativePathname(), File::allFiles(storage_path('app/public/' . self::FOLDER), true));
        sort($copied);
        $this->assertSame(['a.b.c/d.e.jpg', 'dot-slash.jpg', 'v1.0/photo.name.jpg'], $copied);
    }

    public function test_the_admin_is_told_what_an_archive_left_out_when_it_stored_the_rest(): void
    {
        $entries = ['ok.jpg' => $this->jpeg(), 'shell.php' => '<?php', 'icons/a.svg' => '<svg/>', "zero\u{200B}width.png" => $this->jpeg()];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully: 3 files of the archive were not stored (shell.php, icons/a.svg, zero?width.png)'], $toast);
    }

    public function test_the_admin_is_told_about_one_entry_that_was_left_out_in_the_singular(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), 'shell.php.jpg' => '<?php'])]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully: One file of the archive was not stored (shell.php.jpg)'], $toast);
    }

    public function test_the_admin_is_told_the_first_names_of_what_was_left_out_and_how_many_more_there_are(): void
    {
        $entries = ['ok.jpg' => $this->jpeg()];
        foreach (range(1, 8) as $number) {
            $entries["script-{$number}.php"] = '<?php';
        }

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame('File uploaded successfully: 8 files of the archive were not stored (script-1.php, script-2.php, script-3.php, script-4.php, script-5.php, and 3 more)', $toast['message']);
    }

    public function test_a_name_the_archive_left_out_is_shown_as_text_and_never_as_markup(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), '<img src=x onerror=alert(1)>.php' => '<?php'])]);

        $this->assertSame('File uploaded successfully: One file of the archive was not stored (&lt;img src=x onerror=alert(1)&gt;.php)', $toast['message']);
    }

    public function test_the_control_and_format_characters_of_a_name_are_not_shown(): void
    {
        $entries = ['ok.jpg' => $this->jpeg(), "line\nbreak.png" => 'x', "right\u{202E}left.png" => 'x', "\u{0645}\u{06CC}\u{200C}\u{062E}.jpg" => 'x', "a\u{E000}b.png" => 'x'];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame("File uploaded successfully: 4 files of the archive were not stored (line?break.png, right?left.png, \u{0645}\u{06CC}?\u{062E}.jpg, a?b.png)", $toast['message']);
    }

    public function test_a_very_long_name_is_cut_in_the_message(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), str_repeat('long-', 40) . '.php' => '<?php'])]);

        $this->assertSame('File uploaded successfully: One file of the archive was not stored (' . substr(str_repeat('long-', 40), 0, 60) . '...)', $toast['message']);
    }

    public function test_the_admin_is_told_what_an_archive_left_out_when_it_stored_nothing(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['icons/a.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>'])]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive holds no file that can be stored: One file of the archive was not stored (icons/a.svg)'], $toast);
    }

    public function test_an_entry_without_a_name_is_neither_stored_nor_reported_as_left_out(): void
    {
        $toast = $this->toastAfterPosting(['file' => $this->zipUploadWithAnUnnamedEntry()]);

        $this->assertSame(['type' => 'error', 'message' => 'The archive holds no file that can be stored'], $toast);
    }

    public function test_the_metadata_of_a_folder_at_any_depth_is_not_reported_as_left_out(): void
    {
        $entries = ['photos/2026/.DS_Store' => 'metadata', 'photos/2026/Thumbs.db' => 'metadata', 'photos/2026/a.jpg' => $this->jpeg()];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully'], $toast);
    }

    public function test_the_system_files_of_an_archive_are_not_reported_as_left_out_whatever_their_case(): void
    {
        $entries = [
            'desktop.ini' => '[.ShellClassInfo]', 'photos/Desktop.INI' => '[.ShellClassInfo]', 'META-INF/MANIFEST.MF' => 'Manifest-Version: 1.0', '._a.jpg' => 'apple double',
            'photos/._b.jpg' => 'apple double', 'THUMBS.DB' => 'metadata', 'photos/.ds_store' => 'metadata', '__MACOSX/photos/a.jpg' => 'metadata', 'photos/a.jpg' => $this->jpeg(),
        ];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully'], $toast);
        $this->assertSame(['photos/a.jpg'], $this->copiedFiles());
    }

    public function test_a_name_that_only_starts_like_a_system_file_is_an_ordinary_name_and_a_hidden_one_is_listed(): void
    {
        $entries = ['Thumbs.db.jpg' => $this->jpeg(), 'photos/Thumbs.db.png' => $this->jpeg(), 'Thumbs.dbx/a.jpg' => $this->jpeg(), 'desktop.ini.jpg' => $this->jpeg(), 'photos/.DS_Store.jpg' => $this->jpeg()];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully: One file of the archive was not stored (photos/.DS_Store.jpg)'], $toast);
        $this->assertSame(['Thumbs.db.jpg', 'Thumbs.dbx/a.jpg', 'desktop.ini.jpg', 'photos/Thumbs.db.png'], $this->copiedFiles());
    }

    public function test_on_the_local_disk_the_first_of_two_entries_of_one_name_is_stored_and_the_repeat_is_listed(): void
    {
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUploadWithARepeatedName('a.jpg', first: 'FIRST', second: 'SECOND')]), $report);

        $this->assertTrue($stored);
        $this->assertSame('FIRST', file_get_contents(storage_path('app/public/' . self::FOLDER . '/a.jpg')));
        $this->assertSame('One file of the archive was not stored (a.jpg)', $report->skippedMessage());
    }

    public function test_on_s3_two_entries_of_one_name_are_two_objects_each_with_the_data_of_its_own_entry(): void
    {
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUploadWithARepeatedName('a.jpg', first: 'FIRST', second: 'SECOND')]), $report);

        $this->assertTrue($stored);
        $contents = array_map(static fn (string $path): string => Storage::disk('s3')->get($path), $this->storedOnTheBucket());
        sort($contents);
        $this->assertSame(['FIRST', 'SECOND'], $contents);
        $this->assertNull($report->skippedMessage());
    }

    public function test_the_recent_files_the_gallery_remembers_are_forgotten_after_an_archive_is_stored(): void
    {
        foreach (['cache_for_recent_file_list_public', 'cache_for_recent_file_list_s3'] as $key) {
            Cache::put($key, ['an old list']);
        }

        $this->service()->uploadFileAndImages($this->request(self::FOLDER, ['file' => $this->zipUpload(['a.jpg' => $this->jpeg()])]));

        $this->assertFalse(Cache::has('cache_for_recent_file_list_public'));
        $this->assertFalse(Cache::has('cache_for_recent_file_list_s3'));
    }

    public function test_the_recent_files_the_gallery_remembers_are_forgotten_after_an_archive_is_stored_on_s3(): void
    {
        $request = $this->bucketRequest(['file' => $this->zipUpload(['a.jpg' => $this->jpeg()])]);
        Cache::put('cache_for_recent_file_list_s3', ['an old list']);

        $this->service()->uploadFileAndImages($request);

        $this->assertFalse(Cache::has('cache_for_recent_file_list_s3'));
    }

    public function test_the_admin_is_told_what_an_archive_left_out_when_images_came_with_it(): void
    {
        $toast = $this->toastAfterPosting(['images' => [$this->imageUpload('photo.jpg')], 'file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), 'shell.php' => '<?php'])]);

        $this->assertSame(['type' => 'success', 'message' => 'Image uploaded successfully: One file of the archive was not stored (shell.php)'], $toast);
    }

    public function test_a_run_of_control_and_format_characters_in_a_name_is_shown_as_one_mark(): void
    {
        $report = new ArchiveReport();
        $report->skip("a\u{200B}\u{200B}\u{202E}\u{200F}b.php");

        $this->assertSame('One file of the archive was not stored (a?b.php)', $report->skippedMessage());
    }

    public function test_a_name_that_is_no_text_is_shown_as_text(): void
    {
        $report = new ArchiveReport();
        $report->skip("bad\xFF\xFEname.php");

        $message = (string) $report->skippedMessage();

        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        $this->assertStringContainsString('name.php', $message);
    }

    public function test_on_s3_the_stored_files_are_public(): void
    {
        $request = $this->bucketRequest(['file' => $this->zipUpload(['a.jpg' => $this->jpeg(), 'docs/b.pdf' => '%PDF-1.4'])]);
        Storage::fake('s3', ['visibility' => 'private']);

        $this->service()->uploadFileAndImages($request);

        $visibilities = array_map(static fn (string $path): string => Storage::disk('s3')->getVisibility($path), $this->storedOnTheBucket());
        $this->assertSame(['public', 'public'], $visibilities);
    }

    public function test_the_admin_is_told_that_an_entry_nobody_can_read_was_left_out(): void
    {
        $encrypt = static fn (ZipArchive $zip): bool => $zip->setEncryptionName('locked.jpg', ZipArchive::EM_AES_256, 'a password nobody knows');

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload(['locked.jpg' => $this->jpeg(), 'open.jpg' => $this->jpeg()], $encrypt)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully: One file of the archive was not stored (locked.jpg)'], $toast);
    }

    public function test_the_folders_and_the_metadata_of_an_archive_are_not_reported_as_left_out(): void
    {
        $entries = ['gallery/' => '', '__MACOSX/gallery/._a.jpg' => 'metadata', 'gallery/.DS_Store' => 'metadata', 'gallery/Thumbs.db' => 'metadata', 'gallery/a.jpg' => $this->jpeg()];

        $toast = $this->toastAfterPosting(['file' => $this->zipUpload($entries)]);

        $this->assertSame(['type' => 'success', 'message' => 'File uploaded successfully'], $toast);
    }

    public function test_on_s3_what_an_archive_left_out_is_reported_like_on_the_disk(): void
    {
        $report = new ArchiveReport();

        $stored = $this->service()->uploadFileAndImages($this->bucketRequest(['file' => $this->zipUpload(['ok.jpg' => $this->jpeg(), 'shell.php' => '<?php', 'evil.php.jpg' => '<?php', '.hidden/a.jpg' => 'x'])]), $report);

        $this->assertTrue($stored);
        $this->assertSame('3 files of the archive were not stored (shell.php, evil.php.jpg, .hidden/a.jpg)', $report->skippedMessage());
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
