<?php

namespace Tests\Feature\Security;

use App\Traits\SettingsTrait;
use App\Utils\EnvironmentFile;
use App\Utils\Helpers;
use Closure;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Four screens of the admin panel (the app mode, debug and HTTPS switches, and the licence form) write what they were sent into the .env file
 * between double quotes. A value that held a line break added whole lines to the file (`DB_PASSWORD=...`, `QUEUE_CONNECTION=...`), and a quote, a
 * dollar sign or a `${VAR}` ended the string or was expanded when the file is read.
 */
class EnvironmentWriterTest extends TestCase
{
    /** A process that writes no file larger than 1 KB (and leaves no core file behind when the system kills it). */
    private const LIMIT_THE_SIZE_OF_A_FILE = 'posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0); posix_setrlimit(POSIX_RLIMIT_FSIZE, 1024, 1024); ';

    /** The write that passes the limit fails with an error instead of killing the process. */
    private const SURVIVE_THE_LIMIT = 'pcntl_signal(SIGXFSZ, SIG_IGN); ';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/env-writer-' . uniqid();
        mkdir($this->directory);
        file_put_contents($this->directory . '/.env', "APP_NAME=Shop\nAPP_DEBUG=false\nDB_PASSWORD=secret\n");
        $this->app->useEnvironmentPath($this->directory);
        $this->app->loadEnvironmentFrom('.env');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function write(string $key, mixed $value): mixed
    {
        $writer = new class {
            use SettingsTrait;
        };

        return $writer->setEnvironmentValue($key, $value);
    }

    /** @return array<string, string> what the file holds once read the way the framework reads it */
    private function read(): array
    {
        return Dotenv::createArrayBacked($this->directory)->load();
    }

    /** @return array<string, array{0: string, 1: string}> the value written, and the value the file must give back */
    public static function values(): array
    {
        return [
            'a plain word' => ['live', 'live'],
            'a word with a space' => ['My Shop', 'My Shop'],
            'a quote' => ['say "hi"', 'say "hi"'],
            'a dollar sign' => ['costs $5', 'costs $5'],
            'a variable reference' => ['${APP_NAME}x', '${APP_NAME}x'],
            'a backslash' => ['back\\slash', 'back\\slash'],
            'a trailing backslash' => ['trailing\\', 'trailing\\'],
            'a hash sign' => ['a # b', 'a # b'],
            'a single quote' => ["it's", "it's"],
            'non-latin text' => ['متجر', 'متجر'],
            'an empty value' => ['', ''],
        ];
    }

    #[DataProvider('values')]
    public function test_a_value_is_read_back_exactly_and_the_other_lines_are_untouched(string $value, string $expected): void
    {
        $this->write('APP_MODE', $value);

        $read = $this->read();
        $this->assertSame($expected, $read['APP_MODE']);
        $this->assertSame(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD', 'APP_MODE'], array_keys($read));
        $this->assertSame('secret', $read['DB_PASSWORD']);
    }

    /** @return array<string, array{0: string, 1: string}> the value written, and what the file gives back: the same text without its control characters */
    public static function valuesThatCarryAnotherLine(): array
    {
        return [
            'a line feed' => ["true\nDB_PASSWORD=attacker", 'trueDB_PASSWORD=attacker'],
            'a carriage return' => ["true\rQUEUE_CONNECTION=sync", 'trueQUEUE_CONNECTION=sync'],
            'both' => ["true\r\nAPP_KEY=base64:AAAA", 'trueAPP_KEY=base64:AAAA'],
            'a null byte' => ["true\0\nMAIL_HOST=evil.test", 'trueMAIL_HOST=evil.test'],
            'a vertical tab and a form feed' => ["true\x0b\x0cLOG_CHANNEL=stack", 'trueLOG_CHANNEL=stack'],
            'a delete character' => ["tr\x7Fue x", 'true x'],
            'a tab' => ["a\tb", 'ab'],
            'the whole first block of control characters' => ["x" . implode('', array_map('chr', range(0, 31))) . "y", 'xy'],
        ];
    }

    #[DataProvider('valuesThatCarryAnotherLine')]
    public function test_a_value_never_adds_a_line_to_the_file(string $value, string $expected): void
    {
        $this->write('APP_DEBUG', $value);

        $content = file_get_contents($this->directory . '/.env');
        $this->assertCount(3, file($this->directory . '/.env', FILE_IGNORE_NEW_LINES));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x09\x0b-\x1f\x7f]/', $content);
        $this->assertSame(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD'], array_keys($this->read()));
        $this->assertSame($expected, $this->read()['APP_DEBUG']);
        $this->assertSame('secret', $this->read()['DB_PASSWORD']);
    }

    public function test_two_writers_that_overlap_leave_a_whole_file_with_every_key(): void
    {
        $script = "require " . var_export(base_path('vendor/autoload.php'), true) . ";"
            . "for (\$i = 0; \$i < 300; \$i++) { App\\Utils\\EnvironmentFile::set(\$argv[1], \$argv[2], (string) \$i); }";
        $processes = [];
        foreach (['APP_DEBUG', 'APP_MODE', 'FORCE_HTTPS'] as $key) {
            $processes[] = proc_open([PHP_BINARY, '-r', $script, '--', $this->directory . '/.env', $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }
        foreach ($processes as $process) {
            $this->assertSame(0, proc_close($process));
        }

        $read = $this->read();
        // the order in which the three writers added their keys is theirs to decide; every key must be there, once
        $this->assertEqualsCanonicalizing(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD', 'APP_MODE', 'FORCE_HTTPS'], array_keys($read), 'no line was lost or cut');
        $this->assertCount(5, file($this->directory . '/.env', FILE_IGNORE_NEW_LINES));
        $this->assertSame(['Shop', 'secret', '299', '299', '299'], [$read['APP_NAME'], $read['DB_PASSWORD'], $read['APP_DEBUG'], $read['APP_MODE'], $read['FORCE_HTTPS']]);
    }

    public function test_the_legacy_helper_writes_with_the_same_care(): void
    {
        \App\Utils\Helpers::setEnvironmentValue('ADMIN_NAME', "x\"\nDB_PASSWORD=attacker\nY=\"");

        $this->assertSame(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD', 'ADMIN_NAME'], array_keys($this->read()));
        $this->assertSame('secret', $this->read()['DB_PASSWORD']);
        $this->assertSame('x"DB_PASSWORD=attackerY="', $this->read()['ADMIN_NAME']);
    }

    public function test_a_key_that_is_not_in_the_file_is_added_once_and_a_known_key_is_replaced(): void
    {
        $this->write('APP_DEBUG', 'true');
        $this->write('FORCE_HTTPS', true);
        $this->write('FORCE_HTTPS', false);

        $this->assertSame("APP_NAME=Shop\nAPP_DEBUG=true\nDB_PASSWORD=secret\nFORCE_HTTPS=\n", file_get_contents($this->directory . '/.env'));
    }

    public function test_the_value_the_function_returns_is_the_text_that_was_written(): void
    {
        $this->assertSame('live', $this->write('APP_MODE', 'live'));
        $this->assertSame('"a b"', $this->write('APP_MODE', 'a b'));
        $this->assertSame('"say \"hi\""', $this->write('APP_MODE', 'say "hi"'));
    }

    // ---- what the writer does to the lines it does not own -----------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> the file, the key written, the value written, and the file after */
    public static function filesThatAreChangedLineByLine(): array
    {
        return [
            'lines that end in CRLF' => ["APP_NAME=Shop\r\nAPP_DEBUG=false\r\nDB_PASSWORD=secret\r\n", 'APP_DEBUG', 'true', "APP_NAME=Shop\nAPP_DEBUG=true\nDB_PASSWORD=secret\n"],
            'a key with a character that a pattern reads as its own' => ["AXB=keep\nA.B=old\n", 'A.B', 'new', "AXB=keep\nA.B=new\n"],
            'a key with a parenthesis' => ["A=keep\nA(B=old\n", 'A(B', 'new', "A=keep\nA(B=new\n"],
            'the key in lower case in the file' => ["app_debug=false\nAPP_NAME=Shop\n", 'APP_DEBUG', 'true', "APP_DEBUG=true\nAPP_NAME=Shop\n"],
            'a key that is the start of another key' => ["APP_NAME=Shop\n", 'APP', 'x', "APP_NAME=Shop\nAPP=x\n"],
            'a key that is the end of another key' => ["OTHER_APP_DEBUG=1\n", 'APP_DEBUG', 'true', "OTHER_APP_DEBUG=1\nAPP_DEBUG=true\n"],
            'spaces around the equals sign' => ["APP_DEBUG = false\n", 'APP_DEBUG', 'true', "APP_DEBUG=true\n"],
            'a comment and blank lines' => ["# the shop\n\nAPP_NAME=Shop\n\n", 'APP_DEBUG', 'true', "# the shop\nAPP_NAME=Shop\nAPP_DEBUG=true\n"],
            'a file without a final line break' => ['APP_NAME=Shop', 'APP_DEBUG', 'true', "APP_NAME=Shop\nAPP_DEBUG=true\n"],
            'an empty file' => ['', 'APP_DEBUG', 'true', "APP_DEBUG=true\n"],
        ];
    }

    #[DataProvider('filesThatAreChangedLineByLine')]
    public function test_the_lines_the_key_does_not_name_are_kept_and_the_line_it_names_is_replaced(string $before, string $key, string $value, string $after): void
    {
        file_put_contents($this->directory . '/.env', $before);

        $this->write($key, $value);

        $this->assertSame($after, file_get_contents($this->directory . '/.env'));
    }

    // ---- a file the writer cannot write is an error, never a success -------------------------------------------------------------

    /** @return array<string, array{0: Closure(string): string}> a path the writer cannot open for writing, made from the directory of the test */
    public static function pathsThatCannotBeOpened(): array
    {
        return [
            'a directory in place of the file' => [static function (string $directory): string {
                mkdir($directory . '/.env-directory');

                return $directory . '/.env-directory';
            }],
            'a file that does not exist' => [static fn (string $directory): string => $directory . '/.env-missing'],
            'a directory that does not exist' => [static fn (string $directory): string => $directory . '/nowhere/.env'],
        ];
    }

    #[DataProvider('pathsThatCannotBeOpened')]
    public function test_a_file_that_cannot_be_opened_is_an_error_and_is_not_created(Closure $makePath): void
    {
        $path = $makePath($this->directory);
        $existedBefore = file_exists($path);

        $failure = $this->failureOf(static fn () => EnvironmentFile::set(path: $path, key: 'APP_DEBUG', value: 'true'));

        $this->assertNotNull($failure, 'the writer answered as if it had written a file it could not open');
        $this->assertStringContainsString($path, $failure->getMessage());
        $this->assertSame($existedBefore, file_exists($path), 'a file that was not there is not created holding one line');
        $this->assertSame(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD'], array_keys($this->read()), 'the real file is untouched');
    }

    public function test_the_trait_and_the_legacy_helper_raise_when_the_environment_file_is_missing(): void
    {
        unlink($this->directory . '/.env');

        foreach (['the trait' => fn () => $this->write('APP_DEBUG', 'true'), 'the legacy helper' => fn () => Helpers::setEnvironmentValue('APP_DEBUG', 'true')] as $entryPoint => $attempt) {
            $this->assertNotNull($this->failureOf($attempt), $entryPoint . ' answered as if it had written a file that is not there');
            $this->assertFileDoesNotExist($this->directory . '/.env');
        }
    }

    /** What the writer raised, or nothing when it answered; PHPUnit's own failures are RuntimeExceptions too, so a test cannot catch them in place. */
    private function failureOf(Closure $write): ?RuntimeException
    {
        try {
            $write();
        } catch (RuntimeException $failure) {
            return $failure;
        }

        return null;
    }

    // ---- the file is replaced in one step, never rewritten in place --------------------------------------------------------------

    /** @return array{process: resource, pipes: array<int, resource>} a PHP process that knows the application's classes and runs $code with $arguments as $argv[1...] */
    private function startPhp(string $code, string ...$arguments): array
    {
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';' . $code;
        $process = proc_open([PHP_BINARY, '-r', $script, '--', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);

        return ['process' => $process, 'pipes' => $pipes];
    }

    public function test_a_reader_never_sees_an_empty_or_cut_file_while_the_file_is_written_over_and_over(): void
    {
        $path = $this->directory . '/.env';
        $writer = $this->startPhp('for ($i = 0; $i < 400; $i++) { App\\Utils\\EnvironmentFile::set($argv[1], "APP_DEBUG", $i % 2 === 0 ? "true" : "false"); }', $path);

        $brokenReads = [];
        $sawAWrite = false;
        do {
            $status = proc_get_status($writer['process']);
            $content = (string) @file_get_contents($path);
            $sawAWrite = $sawAWrite || str_contains($content, 'APP_DEBUG=true');
            if (preg_match('/\AAPP_NAME=Shop\nAPP_DEBUG=(true|false)\nDB_PASSWORD=secret\n\z/', $content) !== 1) {
                $brokenReads[] = $content;
            }
        } while ($status['running']);

        $this->assertSame(0, $status['exitcode'], (string) stream_get_contents($writer['pipes'][2]));
        $this->assertTrue($sawAWrite, 'the reader did not overlap with the writer');
        $this->assertSame([], array_slice($brokenReads, 0, 3), count($brokenReads) . ' reads saw a file that was empty or cut off');
    }

    public function test_a_writer_that_waited_for_the_lock_works_on_the_file_that_replaced_the_one_it_waited_on(): void
    {
        $path = $this->directory . '/.env';
        $held = fopen($path, 'r+');
        flock($held, LOCK_EX);
        $waiting = $this->startPhp('echo "ready\n"; App\\Utils\\EnvironmentFile::set($argv[1], "APP_MODE", "live");', $path);
        fgets($waiting['pipes'][1]);
        usleep(300_000);

        // the writer that held the lock finishes first, and its file is a new one
        file_put_contents($path . '.next', "APP_NAME=Shop\nAPP_DEBUG=true\nDB_PASSWORD=secret\n");
        rename($path . '.next', $path);
        flock($held, LOCK_UN);
        fclose($held);

        $this->assertSame(0, proc_close($waiting['process']));
        $this->assertSame("APP_NAME=Shop\nAPP_DEBUG=true\nDB_PASSWORD=secret\nAPP_MODE=live\n", file_get_contents($path), 'the change of the writer that went first was lost');
    }

    public function test_the_file_is_replaced_by_a_new_one_and_no_temporary_file_stays(): void
    {
        $path = $this->directory . '/.env';
        $inodeBefore = fileinode($path);

        $this->write('APP_MODE', 'live');

        clearstatcache();
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was rewritten in place');
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
        $this->assertSame('live', $this->read()['APP_MODE']);
    }

    /** @return array<string, array{0: int}> */
    public static function permissionsOfTheFile(): array
    {
        return ['read and write for the owner only' => [0600], 'read for the group' => [0640], 'write for the group' => [0664], 'the usual' => [0644]];
    }

    #[DataProvider('permissionsOfTheFile')]
    public function test_the_new_file_has_the_permissions_of_the_old_one(int $mode): void
    {
        $path = $this->directory . '/.env';
        chmod($path, $mode);

        $this->write('APP_MODE', 'live');

        clearstatcache();
        $this->assertSame($mode, fileperms($path) & 07777);
    }

    public function test_the_new_file_has_the_owner_of_the_old_one_when_the_writer_runs_as_root(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('only root may give a file to another user');
        }
        $path = $this->directory . '/.env';
        chown($path, 12345);
        chgrp($path, 23456);

        $this->write('APP_MODE', 'live');

        clearstatcache();
        $this->assertSame([12345, 23456], [fileowner($path), filegroup($path)]);
    }

    public function test_a_link_to_the_file_stays_a_link_and_the_file_it_names_is_the_one_that_is_written(): void
    {
        mkdir($this->directory . '/shared');
        rename($this->directory . '/.env', $this->directory . '/shared/real.env');
        symlink($this->directory . '/shared/real.env', $this->directory . '/.env');

        $this->write('APP_MODE', 'live');

        $this->assertTrue(is_link($this->directory . '/.env'), 'the link was replaced by a file');
        $this->assertSame($this->directory . '/shared/real.env', readlink($this->directory . '/.env'));
        $this->assertSame('live', $this->read()['APP_MODE']);
        $this->assertSame(['real.env'], array_values(array_diff(scandir($this->directory . '/shared'), ['.', '..'])));
    }

    public function test_a_file_that_cannot_be_replaced_is_written_in_place_under_the_lock(): void
    {
        $path = $this->directory . '/.env';
        mkdir($path . '.tmp'); // the place of the temporary file is taken: no replacement is possible, as for a file that a container mounts
        $inodeBefore = fileinode($path);

        $this->write('APP_MODE', 'live');

        clearstatcache();
        $this->assertSame($inodeBefore, fileinode($path), 'the file was replaced');
        $this->assertSame("APP_NAME=Shop\nAPP_DEBUG=false\nDB_PASSWORD=secret\nAPP_MODE=live\n", file_get_contents($path));
        $this->assertDirectoryExists($path . '.tmp');
    }

    public function test_a_file_that_a_container_mounts_is_written_in_place_when_the_rename_is_refused(): void
    {
        $path = $this->directory . '/.env';
        $mounted = $this->directory . '/mounted.env';
        rename($path, $mounted);
        touch($path);
        if (!$this->bindMount(source: $mounted, target: $path)) {
            $this->markTestSkipped('a file can only be mounted over another by root, on a system that allows it');
        }

        try {
            $this->write('APP_MODE', 'live');

            $this->assertSame("APP_NAME=Shop\nAPP_DEBUG=false\nDB_PASSWORD=secret\nAPP_MODE=live\n", file_get_contents($mounted), 'the file behind the mount was not written');
            $this->assertFileDoesNotExist($path . '.tmp');
        } finally {
            exec('umount ' . escapeshellarg($path));
        }
    }

    private function bindMount(string $source, string $target): bool
    {
        exec('mount --bind ' . escapeshellarg($source) . ' ' . escapeshellarg($target) . ' 2>&1', result_code: $status);

        return $status === 0;
    }

    public function test_the_web_server_hides_the_temporary_file_as_it_hides_the_environment_file(): void
    {
        $this->assertSame(1, preg_match('/<FilesMatch "([^"]+)">\s*Order allow,deny\s*Deny from all\s*<\/FilesMatch>/', (string) file_get_contents(base_path('.htaccess')), $rule), 'no rule hides a pattern of files');

        foreach (['.env', '.env.tmp'] as $hidden) {
            $this->assertSame(1, preg_match('/' . $rule[1] . '/', $hidden), $hidden);
        }
        foreach (['index.php', '.htaccess', 'sitemap.xml'] as $served) {
            $this->assertSame(0, preg_match('/' . $rule[1] . '/', $served), $served);
        }
    }

    public function test_a_temporary_file_that_a_killed_write_left_is_replaced_by_the_next_write(): void
    {
        $path = $this->directory . '/.env';
        file_put_contents($path . '.tmp', 'APP_NAME=cut o');

        $this->write('APP_MODE', 'live');

        $this->assertFileDoesNotExist($path . '.tmp');
        $this->assertSame("APP_NAME=Shop\nAPP_DEBUG=false\nDB_PASSWORD=secret\nAPP_MODE=live\n", file_get_contents($path));
    }

    public function test_a_write_that_dies_halfway_leaves_the_old_file_whole(): void
    {
        $this->skipUnlessTheSizeOfAFileCanBeLimited();
        $path = $this->directory . '/.env';
        $original = $this->fileLongerThanTheLimit();
        file_put_contents($path, $original);

        // the system kills the process when its write of the new content passes the limit
        $writer = $this->startPhp(self::LIMIT_THE_SIZE_OF_A_FILE . 'App\\Utils\\EnvironmentFile::set($argv[1], "APP_DEBUG", "true");', $path);
        proc_close($writer['process']);

        $this->assertSame($original, file_get_contents($path), 'a write that did not finish cut the file short');
    }

    public function test_a_write_that_fails_in_the_copy_and_in_place_raises_and_leaves_the_old_file_whole(): void
    {
        $this->skipUnlessTheSizeOfAFileCanBeLimited();
        $path = $this->directory . '/.env';
        $original = $this->fileJustUnderTheLimit();
        file_put_contents($path, $original);

        // the copy passes the limit and fails, the write in place that follows passes it too, and the old content is put back
        $writer = $this->startPhp(self::LIMIT_THE_SIZE_OF_A_FILE . self::SURVIVE_THE_LIMIT . 'App\\Utils\\EnvironmentFile::set($argv[1], "APP_DEBUG", str_repeat("x", 300));', $path);
        $error = stream_get_contents($writer['pipes'][2]);

        $this->assertNotSame(0, proc_close($writer['process']), 'the writer answered as if it had written');
        $this->assertStringContainsString('cannot be written', $error);
        $this->assertSame($original, file_get_contents($path));
        $this->assertSame(['.env'], array_values(array_diff(scandir($this->directory), ['.', '..'])), 'a temporary file stayed');
    }

    public function test_a_write_in_place_that_fails_halfway_raises_and_puts_the_old_content_back(): void
    {
        $this->skipUnlessTheSizeOfAFileCanBeLimited();
        $path = $this->directory . '/.env';
        $original = $this->fileJustUnderTheLimit();
        file_put_contents($path, $original);
        mkdir($path . '.tmp'); // no replacement is possible: the file is written in place, and the new content passes the limit

        $writer = $this->startPhp(self::LIMIT_THE_SIZE_OF_A_FILE . self::SURVIVE_THE_LIMIT . 'App\\Utils\\EnvironmentFile::set($argv[1], "APP_DEBUG", str_repeat("x", 300));', $path);
        $error = stream_get_contents($writer['pipes'][2]);

        $this->assertNotSame(0, proc_close($writer['process']), 'the writer answered as if it had written');
        $this->assertStringContainsString('cannot be written', $error);
        $this->assertSame($original, file_get_contents($path), 'the old content was not put back');
    }

    private function skipUnlessTheSizeOfAFileCanBeLimited(): void
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
            $this->markTestSkipped('the size limit of a process needs the posix and pcntl extensions');
        }
    }

    private function fileLongerThanTheLimit(): string
    {
        return str_repeat("# a comment that makes the file longer than the limit\n", 30) . "APP_KEY=base64:abc\nDB_PASSWORD=secret\n";
    }

    /** A file that fits under the limit, so that the old content can be put back after a write that passes the limit. */
    private function fileJustUnderTheLimit(): string
    {
        return str_repeat("# a comment that keeps the file just under the limit\n", 18) . "APP_KEY=base64:abc\nDB_PASSWORD=secret\n";
    }
}
