<?php

namespace Tests\Feature\Security;

use App\Services\DigitalProductFileService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Tests\Support\BuildsDigitalFileWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * `php artisan digital-files:privatize` copies the digital files that sit on the public disk to the private disk, so an owner can move the files before the switch.
 * It is a dry run unless it is told to --execute, it verifies the size and the hash of every copy, it removes a public copy only with --remove-public and only after
 * the private copy checked out, and it never touches a row or a file of a bucket. These tests run it on fixtures and on fake disks only.
 */
class DigitalFilesPrivatizeCommandTest extends TestCase
{
    use BuildsDigitalFileWorld;
    use RestoresLanguageFiles;

    private const COMMAND = 'digital-files:privatize';

    /** What each file holds, by name: the content tells a copy from its source. */
    private const FILES = [
        'p-guide.pdf' => 'the guide of a product',
        'shared.pdf' => 'a file that a product and a variation hold',
        'v-only.pdf' => 'the file of a variation',
        'a-line.pdf' => 'the file delivered after a sale',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildDigitalFileWorld();
        $this->useDigitalDisks(private: false);
        DB::table('products')->insert([
            ['id' => 5, 'name' => 'Guide', 'digital_file_ready' => 'p-guide.pdf', 'digital_file_ready_storage_type' => 'public'],
            ['id' => 6, 'name' => 'Shared', 'digital_file_ready' => 'shared.pdf', 'digital_file_ready_storage_type' => null],
            ['id' => 7, 'name' => 'Missing', 'digital_file_ready' => 'p-missing.pdf', 'digital_file_ready_storage_type' => 'public'],
            ['id' => 8, 'name' => 'On the bucket', 'digital_file_ready' => 'p-bucket.pdf', 'digital_file_ready_storage_type' => 's3'],
            ['id' => 9, 'name' => 'A path', 'digital_file_ready' => '../../.env', 'digital_file_ready_storage_type' => 'public'],
            ['id' => 10, 'name' => 'Physical', 'digital_file_ready' => null, 'digital_file_ready_storage_type' => null],
            ['id' => 11, 'name' => 'Physical too', 'digital_file_ready' => '', 'digital_file_ready_storage_type' => null],
        ]);
        DB::table('digital_product_variations')->insert([
            ['id' => 1, 'product_id' => 6, 'variant_key' => 'Format-PDF', 'file' => 'shared.pdf'],
            ['id' => 2, 'product_id' => 5, 'variant_key' => 'Format-ZIP', 'file' => 'v-only.pdf'],
            ['id' => 3, 'product_id' => 5, 'variant_key' => 'Format-EPUB', 'file' => ''],
        ]);
        DB::table('order_details')->insert([
            ['id' => 1, 'order_id' => 1, 'digital_file_after_sell' => 'a-line.pdf'],
            ['id' => 2, 'order_id' => 1, 'digital_file_after_sell' => 'already-private.pdf'],
            ['id' => 3, 'order_id' => 1, 'digital_file_after_sell' => 'different.pdf'],
            ['id' => 4, 'order_id' => 1, 'digital_file_after_sell' => null],
        ]);
        DB::table('storages')->insert([
            ['data_type' => 'App\\Models\\DigitalProductVariation', 'data_id' => 1, 'key' => 'file', 'value' => 'public'],
            ['data_type' => 'App\\Models\\OrderDetail', 'data_id' => 1, 'key' => 'digital_file_after_sell', 'value' => 'public'],
        ]);
        foreach (self::FILES as $name => $content) {
            $this->putOnPublicDisk($name, $content);
        }
        $this->putOnPrivateDisk('already-private.pdf', 'moved before');
    }

    protected function tearDown(): void
    {
        $this->removeDigitalDisks();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** @return array<string, string> the content of every file of a disk, by path: what a run may and may not change */
    private function contentsOf(string $disk): array
    {
        $contents = [];
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $contents[$path] = (string) Storage::disk($disk)->get($path);
        }
        ksort($contents);

        return $contents;
    }

    /** @return array<string, string> every row of the tables that name a digital file, as it is stored */
    private function rows(): array
    {
        $rows = [];
        foreach (['products', 'digital_product_variations', 'order_details', 'storages'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }

    private function conflictOnThePublicDisk(): void
    {
        $this->putOnPublicDisk('different.pdf', 'the public version');
        $this->putOnPrivateDisk('different.pdf', 'the private version');
    }

    // ---- the dry run -------------------------------------------------------------------------------------------------------------

    public function test_a_dry_run_reports_what_it_would_copy_and_changes_nothing(): void
    {
        $public = $this->contentsOf('public');
        $private = $this->contentsOf('digital_private');
        $rows = $this->rows();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Would be copied')
            ->expectsOutputToContain('Already private')
            ->expectsOutputToContain('Missing')
            ->expectsOutputToContain('Not a plain file name')
            ->assertExitCode(0);

        $this->assertSame($public, $this->contentsOf('public'));
        $this->assertSame($private, $this->contentsOf('digital_private'));
        $this->assertSame($rows, $this->rows());
    }

    public function test_a_dry_run_counts_files_rows_and_bytes_and_names_the_files_that_need_a_look(): void
    {
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('files: 4, rows: 5, size: ' . Number::fileSize(array_sum(array_map('strlen', self::FILES)), maxPrecision: 1))
            ->expectsOutputToContain('p-missing.pdf (products#7)')
            ->expectsOutputToContain('../../.env (products#9)')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_does_not_even_create_the_private_folder(): void
    {
        $root = sys_get_temp_dir() . '/digital-privatize-dry-' . getmypid();
        config(['filesystems.disks.digital_private.root' => $root]);
        Storage::forgetDisk('digital_private');

        $this->artisan(self::COMMAND)->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_the_command_never_writes_a_row(): void
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $this->conflictOnThePublicDisk();
        config(['filesystems.digital_files_private' => true]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true]);

        $writes = array_filter($statements, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql) === 1);
        $this->assertSame([], array_values($writes));
        $this->assertNotEmpty($statements, 'the command reads the rows');
    }

    // ---- --execute ---------------------------------------------------------------------------------------------------------------

    public function test_execute_copies_every_file_a_row_names_verifies_it_and_keeps_the_public_copies(): void
    {
        $public = $this->contentsOf('public');
        $rows = $this->rows();

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('Copied and verified')
            ->expectsOutputToContain('run this as the user your web server\'s PHP runs as')
            ->assertExitCode(0);

        foreach (self::FILES as $name => $content) {
            $this->assertSame($content, Storage::disk('digital_private')->get(self::DIRECTORY . $name), $name);
        }
        $this->assertSame($public, $this->contentsOf('public'), 'a public copy is removed only by --remove-public');
        $this->assertSame($rows, $this->rows(), 'no row is touched');
        $this->assertSame([], array_filter(Storage::disk('digital_private')->allFiles(), static fn (string $path): bool => str_contains($path, '.partial')), 'no half copy is left');
    }

    public function test_a_file_that_two_rows_hold_is_copied_once(): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('files: 4, rows: 5')->assertExitCode(0);

        $this->assertCount(1, array_filter(Storage::disk('digital_private')->allFiles(), static fn (string $path): bool => str_ends_with($path, 'shared.pdf')));
    }

    public function test_an_execute_that_leaves_the_public_copies_says_what_comes_next(): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('run this again with --execute --remove-public')->assertExitCode(0);
    }

    public function test_a_second_execute_has_nothing_left_to_copy(): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $private = $this->contentsOf('digital_private');

        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('Already copied')
            ->doesntExpectOutputToContain('Copied and verified')
            ->assertExitCode(0);

        $this->assertSame($private, $this->contentsOf('digital_private'));
    }

    public function test_a_run_that_stopped_halfway_goes_on_where_it_stopped_and_a_leftover_half_copy_is_no_file_of_a_row(): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        Storage::disk('digital_private')->delete(self::DIRECTORY . 'a-line.pdf');
        Storage::disk('digital_private')->put(self::DIRECTORY . '.a-line.pdf.partial', 'half');

        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('Copied and verified')->assertExitCode(0);

        $this->assertSame(self::FILES['a-line.pdf'], Storage::disk('digital_private')->get(self::DIRECTORY . 'a-line.pdf'));
        $this->assertSame(self::FILES['p-guide.pdf'], Storage::disk('digital_private')->get(self::DIRECTORY . 'p-guide.pdf'));
        Storage::disk('public')->assertMissing(self::DIRECTORY . '.a-line.pdf.partial');
    }

    public function test_a_row_whose_file_is_missing_is_reported_and_is_no_failure(): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true])
            ->expectsOutputToContain('p-missing.pdf')
            ->assertExitCode(0);
    }

    // ---- --remove-public ---------------------------------------------------------------------------------------------------------

    public function test_remove_public_without_execute_is_refused_and_removes_nothing(): void
    {
        $public = $this->contentsOf('public');
        config(['filesystems.digital_files_private' => true]);

        $this->artisan(self::COMMAND, ['--remove-public' => true])
            ->expectsOutputToContain('--remove-public only works together with --execute')
            ->assertExitCode(1);

        $this->assertSame($public, $this->contentsOf('public'));
        $this->assertSame(['already-private.pdf'], array_map('basename', Storage::disk('digital_private')->allFiles()));
    }

    public function test_remove_public_is_refused_while_the_private_mode_is_off_because_the_shop_would_lose_its_files(): void
    {
        $public = $this->contentsOf('public');

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])
            ->expectsOutputToContain('DIGITAL_FILES_PRIVATE is off')
            ->assertExitCode(1);

        $this->assertSame($public, $this->contentsOf('public'));
        $this->assertSame(['already-private.pdf'], array_map('basename', Storage::disk('digital_private')->allFiles()), 'nothing was copied either');
    }

    public function test_remove_public_deletes_a_public_copy_after_its_private_copy_checked_out_and_the_shop_still_finds_every_file(): void
    {
        config(['filesystems.digital_files_private' => true]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])
            ->expectsOutputToContain('Public copies removed')
            ->assertExitCode(0);

        foreach (self::FILES as $name => $content) {
            Storage::disk('public')->assertMissing(self::DIRECTORY . $name);
            $this->assertSame($content, Storage::disk('digital_private')->get(self::DIRECTORY . $name), $name);
            $this->assertSame('digital_private', app(DigitalProductFileService::class)->diskHolding(name: $name));
        }
    }

    public function test_remove_public_goes_on_for_files_copied_by_an_earlier_run_and_a_later_run_finds_nothing_to_do(): void
    {
        config(['filesystems.digital_files_private' => true]);
        $this->artisan(self::COMMAND, ['--execute' => true])->assertExitCode(0);
        $this->assertCount(4, array_filter(Storage::disk('public')->allFiles(), static fn (string $path): bool => str_ends_with($path, '.pdf')));

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])->expectsOutputToContain('Public copies removed: 4')->assertExitCode(0);
        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])->expectsOutputToContain('Public copies removed: 0')->assertExitCode(0);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---- what it leaves alone ----------------------------------------------------------------------------------------------------

    public function test_a_file_that_differs_between_the_disks_is_left_alone_and_makes_the_run_fail(): void
    {
        $this->conflictOnThePublicDisk();
        config(['filesystems.digital_files_private' => true]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])
            ->expectsOutputToContain('Different content on the two disks')
            ->expectsOutputToContain('different.pdf')
            ->assertExitCode(1);

        $this->assertSame('the public version', Storage::disk('public')->get(self::DIRECTORY . 'different.pdf'));
        $this->assertSame('the private version', Storage::disk('digital_private')->get(self::DIRECTORY . 'different.pdf'));
        Storage::disk('public')->assertMissing(self::DIRECTORY . 'p-guide.pdf');
    }

    public function test_a_copy_that_does_not_verify_is_not_kept_and_its_public_copy_is_not_removed(): void
    {
        config(['filesystems.digital_files_private' => true]);
        $real = Storage::disk('digital_private');
        Storage::set('digital_private', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter {
            public function writeStream($path, $resource, array $options = [])
            {
                return parent::writeStream($path, fopen('data://text/plain,cut', 'r'), $options);
            }
        });

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])
            ->expectsOutputToContain('Failed')
            ->assertExitCode(1);

        foreach (self::FILES as $name => $content) {
            Storage::disk('public')->assertExists(self::DIRECTORY . $name);
            Storage::disk('digital_private')->assertMissing(self::DIRECTORY . $name);
        }
        $this->assertSame([self::DIRECTORY . 'already-private.pdf'], Storage::disk('digital_private')->allFiles(), 'no half copy is left');
    }

    public function test_a_private_copy_that_no_longer_checks_out_when_the_public_copy_is_about_to_go_keeps_the_public_copy(): void
    {
        config(['filesystems.digital_files_private' => true]);
        $real = Storage::disk('digital_private');
        Storage::set('digital_private', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter {
            public function move($from, $to)
            {
                $moved = parent::move($from, $to);
                $this->put($to, 'changed after the move');

                return $moved;
            }
        });

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])->expectsOutputToContain('Failed')->assertExitCode(1);

        foreach (self::FILES as $name => $content) {
            $this->assertSame($content, Storage::disk('public')->get(self::DIRECTORY . $name), 'the public copy of ' . $name . ' stays');
        }
    }

    public function test_a_file_of_the_public_folder_that_no_row_names_is_listed_and_left_where_it_is(): void
    {
        $this->putOnPublicDisk('left-by-a-replaced-product.pdf', 'an old version of a paid file');
        config(['filesystems.digital_files_private' => true]);

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])
            ->expectsOutputToContain('named by no row')
            ->expectsOutputToContain('left-by-a-replaced-product.pdf')
            ->assertExitCode(0);

        $this->assertSame('an old version of a paid file', Storage::disk('public')->get(self::DIRECTORY . 'left-by-a-replaced-product.pdf'));
        Storage::disk('digital_private')->assertMissing(self::DIRECTORY . 'left-by-a-replaced-product.pdf');
    }

    public function test_a_name_that_is_not_a_plain_file_name_is_never_read_or_copied(): void
    {
        Storage::disk('public')->put('.env', 'APP_KEY=secret');

        $this->artisan(self::COMMAND, ['--execute' => true])->expectsOutputToContain('Not a plain file name')->assertExitCode(0);

        $this->assertNotContains('APP_KEY=secret', $this->contentsOf('digital_private'));
        Storage::disk('digital_private')->assertMissing('.env');
    }

    public function test_a_row_that_keeps_its_file_on_a_bucket_is_never_touched_even_when_the_public_disk_has_a_file_of_that_name(): void
    {
        config(['filesystems.disks.default' => 's3', 'filesystems.digital_files_private' => true]);
        $this->putOnPublicDisk('p-bucket.pdf', 'a file of the public disk that carries the name');

        $this->artisan(self::COMMAND, ['--execute' => true, '--remove-public' => true])->expectsOutputToContain('On a bucket')->assertExitCode(0);

        Storage::disk('public')->assertExists(self::DIRECTORY . 'p-bucket.pdf');
        Storage::disk('digital_private')->assertMissing(self::DIRECTORY . 'p-bucket.pdf');
    }
}
