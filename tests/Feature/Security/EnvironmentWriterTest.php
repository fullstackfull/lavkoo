<?php

namespace Tests\Feature\Security;

use App\Traits\SettingsTrait;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
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
        @unlink($this->directory . '/.env');
        @rmdir($this->directory);

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

    /** @return array<string, array{0: string}> */
    public static function valuesThatCarryAnotherLine(): array
    {
        return [
            'a line feed' => ["true\nDB_PASSWORD=attacker"],
            'a carriage return' => ["true\rQUEUE_CONNECTION=sync"],
            'both' => ["true\r\nAPP_KEY=base64:AAAA"],
            'a null byte' => ["true\0\nMAIL_HOST=evil.test"],
            'a vertical tab and a form feed' => ["true\x0b\x0cLOG_CHANNEL=stack"],
        ];
    }

    #[DataProvider('valuesThatCarryAnotherLine')]
    public function test_a_value_never_adds_a_line_to_the_file(string $value): void
    {
        $this->write('APP_DEBUG', $value);

        $lines = file($this->directory . '/.env', FILE_IGNORE_NEW_LINES);
        $this->assertCount(3, $lines);
        $this->assertSame(['APP_NAME', 'APP_DEBUG', 'DB_PASSWORD'], array_keys($this->read()));
        $this->assertSame('secret', $this->read()['DB_PASSWORD']);
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
}
