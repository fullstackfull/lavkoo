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
        // `banners` has no create migration in this repository, so absent IS its migrated state and dropping it is the restore -- the rule
        // `Tests\Support\RestoresCategoryShippingCostsTable` is the other half of.
        \Illuminate\Support\Facades\Schema::dropIfExists('banners');
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

    /**
     * How many places still ask `config('filesystems.disks.default')`, a key this application NEVER SETS.
     *
     * It is not five. Across `app/` and `Modules/` there are 64 reads left in about forty files -- every model hook that annotates which
     * disk a picture is on, `ImageManager`, `settings.php`, a dozen services -- and each one resolves to `null ?? 'public'`. On a shop
     * configured for s3 every one of them records the wrong disk, and the addresses built from those records point at a local path that
     * holds nothing. `App\Utils\InstallationDisk` is the answer they should be asking for; sweeping sixty-odd call sites is a change of its
     * own, with its own review, so this number is the ratchet that keeps the gap visible and makes it shrink rather than grow.
     *
     * Comments are stripped first, so the two docblocks that EXPLAIN the key are not counted as asking for it.
     */
    public function test_the_number_of_places_still_asking_for_the_unset_disk_key_has_not_grown(): void
    {
        $reads = 0;
        $files = [];

        foreach (['app', 'Modules'] as $tree) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($tree), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $found = preg_match_all(
                    '/config\(\s*[\x27"]filesystems\.disks\.default[\x27"]/',
                    HostsTheFileManager::withoutComments((string) file_get_contents($file->getPathname())),
                );

                if ($found > 0) {
                    $reads += $found;
                    $files[] = substr($file->getPathname(), strlen(base_path()) + 1);
                }
            }
        }

        sort($files);

        self::assertLessThanOrEqual(
            self::READS_OF_THE_UNSET_KEY,
            $reads,
            'another place asks for `filesystems.disks.default`, which this application never sets, so it reads as the local public disk '
            . 'whatever the shop configured. Ask `App\Utils\InstallationDisk::name()` instead. The files that still ask: ' . implode(', ', $files),
        );
    }

    /** Measured on 2026-11-25, after `FileManagerTrait` and `Banner`'s saved hook were moved off the key. It can only fall. */
    private const READS_OF_THE_UNSET_KEY = 64;

    /**
     * A banner with a picture is created on an installation whose `storages` table is absent.
     *
     * `storages` is a table of the installation dump that NO migration creates. `Banner`'s `saved` hook writes the disk of a picture into
     * it, and where the table is absent that `updateOrInsert` threw -- inside a `saved` hook, i.e. AFTER the insert. So `Banner::create()`
     * raised an exception having already written the row, and a caller with its own try/catch swallowed it and silently skipped everything
     * that should have followed. That is how an orphan banner row got past a test once: the row existed and nothing downstream had run.
     *
     * The fixture has a NON-EMPTY `photo` on purpose. The hook only fires when `photo` is dirty and not empty, so a fixture with it NULL
     * passes whether the guard is there or not -- which is the shape that hid the defect in the first place.
     */
    public function test_a_banner_with_a_picture_is_created_where_the_storages_table_is_absent(): void
    {
        $this->buildBannersTableWithoutStorages();

        $banner = \App\Models\Banner::query()->create(['photo' => 'banner/picture.webp', 'banner_type' => 'Main Banner', 'published' => 1]);

        self::assertTrue($banner->exists, 'the row was written');
        self::assertSame(1, \App\Models\Banner::query()->count(), 'and nothing after the create was skipped by an exception from the hook');
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasTable('storages'), 'the annotation had nowhere to go, which is the point');
    }

    private function buildBannersTableWithoutStorages(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('storages');
        \Illuminate\Support\Facades\Schema::dropIfExists('banners');
        \Illuminate\Support\Facades\Schema::create('banners', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('photo')->nullable();
            $table->string('mobile_photo')->nullable();
            $table->string('banner_type')->nullable();
            $table->string('theme')->nullable();
            $table->boolean('published')->default(true);
            $table->integer('priority')->default(0);
            $table->string('url')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('background_color')->nullable();
            $table->timestamps();
        });
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
