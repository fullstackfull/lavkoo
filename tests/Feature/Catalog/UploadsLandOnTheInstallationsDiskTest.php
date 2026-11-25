<?php

namespace Tests\Feature\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HostsTheFileManager;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Where an upload, and the copy a product copied into another country gets, actually LAND.
 *
 * `FileManagerTrait` asked `config('filesystems.disks.default')` for the installation's disk in five places. `config/filesystems.php` has no
 * such key, nothing writes one, and `setStorageConnectionEnvironment()` writes `filesystems.default` instead -- so all five reads were
 * `null ?? 'public'`. On a shop configured for s3 that made the local public disk the DESTINATION of every upload and of every copied file, and
 * made `public` the disk recorded against the row, so the bytes went where nothing serves them from and the record named the wrong disk too.
 *
 * The three assertions here are the three things that were wrong, and each is written against the disk the installation CHOSE rather than
 * against the name of a driver, so the same test covers any disk a shop configures.
 */
class UploadsLandOnTheInstallationsDiskTest extends TestCase
{
    use RestoresLanguageFiles;

    /** The name of the disk standing in for a shop's remote storage. Any disk the configuration defines and that is not `local` behaves the same way. */
    private const REMOTE = 's3';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Storage::fake('public');
        Storage::fake(self::REMOTE);
        $this->files = new HostsTheFileManager();
    }

    private HostsTheFileManager $files;

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_a_shop_that_has_not_chosen_a_disk_still_uploads_to_the_public_disk(): void
    {
        Config::set('filesystems.default', 'local');

        $name = $this->files->uploadFile('catalogue/', 'txt', UploadedFile::fake()->createWithContent('sheet.txt', 'one'));

        Storage::disk('public')->assertExists('catalogue/' . $name);
        Storage::disk(self::REMOTE)->assertMissing('catalogue/' . $name);
    }

    public function test_an_upload_lands_on_the_disk_the_installation_stores_on(): void
    {
        Config::set('filesystems.default', self::REMOTE);

        $name = $this->files->uploadFile('catalogue/', 'txt', UploadedFile::fake()->createWithContent('sheet.txt', 'one'));

        Storage::disk(self::REMOTE)->assertExists('catalogue/' . $name);
        Storage::disk('public')->assertMissing('catalogue/' . $name);
    }

    public function test_a_copied_file_lands_on_that_disk_and_the_record_names_it(): void
    {
        Config::set('filesystems.default', self::REMOTE);
        Storage::disk(self::REMOTE)->put('product/source.webp', 'bytes-of-the-source');

        $copy = $this->files->copyFile('product/', 'source.webp', self::REMOTE);

        self::assertIsArray($copy);
        self::assertSame(self::REMOTE, $copy['storage'], 'the copy records the disk it was written to');
        Storage::disk(self::REMOTE)->assertExists('product/' . $copy['name']);
        Storage::disk('public')->assertMissing('product/' . $copy['name']);
        self::assertSame('bytes-of-the-source', Storage::disk(self::REMOTE)->get('product/' . $copy['name']));
    }

    public function test_a_file_on_that_disk_is_found_rather_than_read_as_missing(): void
    {
        Config::set('filesystems.default', self::REMOTE);
        Storage::disk(self::REMOTE)->put('product/only-remote.webp', 'bytes');

        // The probe is what every delete, every overwrite and every "does this directory exist" question goes through. While it answered
        // `public` only, a file on the shop's own disk read as absent: the directory was re-made on every upload and a delete deleted nothing.
        $found = $this->files->copyFile('product/', 'only-remote.webp', storageType: null);

        self::assertIsArray($found, 'a file on the installation\'s own disk must be reachable without the record naming the disk');
    }
}
