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
}
