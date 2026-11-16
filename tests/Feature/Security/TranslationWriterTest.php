<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * translate() writes a key it does not know into resources/lang/<locale>/new-messages.php, and every request that renders a page with a new
 * key does it at the same time. The writer read the file, added the key and wrote the whole file back with file_put_contents() (truncate, then
 * write), with no lock: three processes that added distinct keys left a file that did not parse (after which every translate() of that locale
 * fell back to the humanised key until the file was repaired) or one that held only some of the keys, and a reader saw the file cut off.
 */
class TranslationWriterTest extends TestCase
{
    private const LOCALE = 'probe-race';

    private const WRITERS = 3;

    private const KEYS_PER_WRITER = 120;

    private const SEEDED_KEYS = 2000;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = base_path('resources/lang/' . self::LOCALE);
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory . '/messages.php', "<?php\n\nreturn [\n];\n");
        $seeded = "<?php\n\nreturn [\n";
        for ($index = 0; $index < self::SEEDED_KEYS; $index++) {
            $seeded .= "\t'seeded_key_{$index}' => 'Seeded key {$index}',\n";
        }
        file_put_contents($this->directory . '/new-messages.php', $seeded . "];\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return array{process: resource, pipes: array<int, resource>} a process that boots the application and adds its own keys through the real translate() writer */
    private function startWriter(int $worker): array
    {
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';'
            . '$app = require ' . var_export(base_path('bootstrap/app.php'), true) . ';'
            . '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            . 'for ($i = 0; $i < ' . self::KEYS_PER_WRITER . '; $i++) { getOrPutTranslateMessageValueByKey(local: ' . var_export(self::LOCALE, true) . ', key: "worker{$argv[1]}_key{$i}"); }';
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $process = proc_open([PHP_BINARY, '-r', $script, '--', (string) $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);

        return ['process' => $process, 'pipes' => $pipes];
    }

    public function test_writers_that_add_distinct_keys_at_once_lose_none_and_a_reader_never_sees_a_cut_file(): void
    {
        $path = $this->directory . '/new-messages.php';
        $writers = array_map($this->startWriter(...), range(1, self::WRITERS));

        $cutReads = 0;
        $statuses = [];
        do {
            foreach ($writers as $index => $writer) {
                $statuses[$index] = proc_get_status($writer['process']);
            }
            $content = (string) @file_get_contents($path);
            if (!str_starts_with($content, "<?php\n") || !str_ends_with($content, "];\n")) {
                $cutReads++;
            }
        } while (in_array(true, array_column($statuses, 'running'), true));

        foreach ($writers as $index => $writer) {
            $this->assertSame(0, $statuses[$index]['exitcode'], (string) stream_get_contents($writer['pipes'][2]));
        }
        $messages = include $path;
        $this->assertIsArray($messages, 'the file does not parse');
        $this->assertCount(self::SEEDED_KEYS + self::WRITERS * self::KEYS_PER_WRITER, $messages, 'keys were lost');
        $this->assertSame('Worker2 key7', $messages['worker2_key7']);
        $this->assertSame('Seeded key 1999', $messages['seeded_key_1999']);
        $this->assertSame(0, $cutReads, 'a reader saw the file cut off');
        $this->assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..', 'messages.php', 'new-messages.php'])), 'a temporary file stayed');
    }

    public function test_a_key_that_another_request_added_meanwhile_keeps_its_text(): void
    {
        $path = $this->directory . '/new-messages.php';
        file_put_contents($path, "<?php\n\nreturn [\n\t'shared_key' => 'Text that another request stored',\n];\n");

        addNewTranslationMessage(path: $path, key: 'shared_key', message: 'Shared key');
        addNewTranslationMessage(path: $path, key: 'second_key', message: 'Second key');

        $this->assertSame(['shared_key' => 'Text that another request stored', 'second_key' => 'Second key'], include $path);
    }

    public function test_a_key_with_a_backslash_a_dollar_sign_and_an_interpolation_is_stored_as_a_string_that_is_never_run(): void
    {
        $path = $this->directory . '/new-messages.php';
        $keys = ['costs_$5_and_${file_put_contents(base_path(1),1)}', 'path\\to\\file', 'trailing_backslash\\'];

        foreach ($keys as $key) {
            getOrPutTranslateMessageValueByKey(local: self::LOCALE, key: $key);
        }

        $stored = include $path;
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $stored);
            $this->assertSame(ucfirst(str_replace('_', ' ', removeSpecialCharacters($key))), $stored[$key]);
        }
        $this->assertFileDoesNotExist(base_path('1'));
    }

    public function test_a_language_file_that_does_not_parse_raises_and_is_left_as_it_is(): void
    {
        $path = $this->directory . '/new-messages.php';
        file_put_contents($path, "<?php\n\nreturn [\n\t'cut_off' => 'The file was cut o");

        $this->expectException(\ParseError::class);
        try {
            addNewTranslationMessage(path: $path, key: 'new_key', message: 'New key');
        } finally {
            $this->assertSame("<?php\n\nreturn [\n\t'cut_off' => 'The file was cut o", file_get_contents($path));
        }
    }
}
