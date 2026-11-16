<?php

namespace Tests\Feature\Security;

use App\Traits\ActivationClass;
use App\Traits\InstallationTrail;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * The installer and the updater rewrite the whole .env file: the installer merges the database and licence settings into it, and the updater
 * removes the lines that a run of writers left twice. Both read the file, changed it and put it back with file_put_contents(), without the lock
 * the settings screens take, so a write of theirs that overlapped another writer cut the other's lines off (the updater calls the cleanup right
 * after nine locked writes), and the file was empty for any request that read it meanwhile. They write through the same locked, replacing writer
 * as the settings screens now.
 */
class InstallerEnvironmentWritersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/env-installer-' . uniqid();
        mkdir($this->directory);
        $this->app->useEnvironmentPath($this->directory);
        $this->app->loadEnvironmentFrom('.env');
        $this->flushSession();
        $this->app['session']->start();
        session()->put(['admin_name' => 'Admin', 'admin_email' => 'YWRtaW5Ac2hvcC50ZXN0', 'username' => 'buyer', 'purchase_key' => '11111111-2222-3333-4444-555555555555']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function cleaner(): object
    {
        return new class {
            use ActivationClass;
        };
    }

    private function installer(): object
    {
        return new class {
            use InstallationTrail;
        };
    }

    /** @return array<string, string> what the file holds once read the way the framework reads it */
    private function read(): array
    {
        return Dotenv::createArrayBacked($this->directory)->load();
    }

    private function plant(string $content): string
    {
        $path = $this->directory . '/.env';
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array<string, string> */
    private function databaseForm(): array
    {
        return ['DB_HOST' => 'db.shop.test', 'DB_DATABASE' => 'shop', 'DB_USERNAME' => 'shop_user', 'DB_PASSWORD' => 'p@ss "w" $d'];
    }

    // ---- the updater's cleanup of repeated lines --------------------------------------------------------------------------------

    public function test_the_cleanup_keeps_the_first_line_of_a_key_and_the_comments_and_drops_blank_lines(): void
    {
        $path = $this->plant("# the shop\nAPP_NAME = Shop \n\nAPP_DEBUG=false\n  APP_NAME=Other\nnot a variable\nDB_PASSWORD=secret\nAPP_DEBUG=true\n");

        $this->assertTrue($this->cleaner()->cleanupEnvFileContext($path));

        $this->assertSame("# the shop\nAPP_NAME=Shop\nAPP_DEBUG=false\nnot a variable\nDB_PASSWORD=secret\n", file_get_contents($path));
    }

    public function test_the_cleanup_of_a_file_that_is_not_there_answers_false_and_creates_nothing(): void
    {
        $this->assertFalse($this->cleaner()->cleanupEnvFileContext($this->directory . '/.env'));

        $this->assertFileDoesNotExist($this->directory . '/.env');
    }

    public function test_the_cleanup_replaces_the_file_in_one_step(): void
    {
        $path = $this->plant("APP_NAME=Shop\nAPP_NAME=Shop\n");
        $inodeBefore = fileinode($path);

        $this->cleaner()->cleanupEnvFileContext($path);

        clearstatcache();
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was rewritten in place');
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_the_cleanup_of_a_file_that_cannot_be_written_is_an_error(): void
    {
        mkdir($this->directory . '/.env');

        $this->expectException(RuntimeException::class);

        $this->cleaner()->cleanupEnvFileContext($this->directory . '/.env');
    }

    public function test_the_cleanup_waits_for_a_writer_that_holds_the_lock(): void
    {
        $path = $this->plant("APP_NAME=Shop\nAPP_NAME=Shop\n");
        $held = fopen($path, 'r+');
        flock($held, LOCK_EX);
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . '; echo "ready\n"; (new class { use App\\Traits\\ActivationClass; })->cleanupEnvFileContext($argv[1]);';
        $cleanup = proc_open([PHP_BINARY, '-r', $script, '--', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fgets($pipes[1]);
        usleep(400_000);

        $stillWaiting = proc_get_status($cleanup)['running'];
        $heldContent = file_get_contents($path);
        flock($held, LOCK_UN);
        fclose($held);

        $this->assertSame(0, proc_close($cleanup));
        $this->assertTrue($stillWaiting, 'the cleanup did not wait for the lock');
        $this->assertSame("APP_NAME=Shop\nAPP_NAME=Shop\n", $heldContent, 'the cleanup wrote while another writer held the lock');
        $this->assertSame("APP_NAME=Shop\n", file_get_contents($path));
    }

    // ---- the installer's merge of the database and licence settings -------------------------------------------------------------

    public function test_the_installer_makes_the_file_when_there_is_none(): void
    {
        $this->installer()::updateEnvironmentFile($this->databaseForm());

        $read = $this->read();
        $this->assertSame(['shop', 'shop_user', 'db.shop.test', 'p@ss "w" $d'], [$read['DB_DATABASE'], $read['DB_USERNAME'], $read['DB_HOST'], $read['DB_PASSWORD']]);
        $this->assertStringStartsWith('base64:', $read['APP_KEY']);
        $this->assertSame(['false', 'live', 'true', 'buyer'], [$read['APP_DEBUG'], $read['APP_MODE'], $read['APP_INSTALL'], $read['BUYER_USERNAME']]);
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_the_installer_replaces_its_own_lines_in_place_and_keeps_the_others(): void
    {
        $path = $this->plant("# set by the host\nAPP_NAME=Old\nMAIL_HOST=smtp.shop.test\n\nDB_HOST=old-host\n");

        $this->installer()::updateEnvironmentFile($this->databaseForm());

        $content = (string) file_get_contents($path);
        $this->assertStringStartsWith("# set by the host\nAPP_NAME=6valley", $content);
        $this->assertStringContainsString("\nMAIL_HOST=smtp.shop.test\n\nDB_HOST=db.shop.test\n", $content);
        $this->assertSame(1, substr_count($content, 'DB_HOST='));
        $this->assertSame('smtp.shop.test', $this->read()['MAIL_HOST']);
    }

    public function test_the_installer_replaces_the_file_in_one_step(): void
    {
        $path = $this->plant("APP_NAME=Old\n");
        $inodeBefore = fileinode($path);

        $this->installer()::updateEnvironmentFile($this->databaseForm());

        clearstatcache();
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was rewritten in place');
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function test_the_installer_cannot_go_on_when_the_file_cannot_be_written(): void
    {
        mkdir($this->directory . '/.env');

        $this->expectException(RuntimeException::class);

        $this->installer()::updateEnvironmentFile($this->databaseForm());
    }
}
