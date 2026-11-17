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

    /**
     * @param array<string, string> $settings php.ini settings of the process
     * @return array{process: resource, pipes: array<int, resource>} a process that boots the application and adds its own keys through the real translate() writer; what it logs goes to its error output
     */
    private function startWriter(int $worker, array $settings = [], int $keys = self::KEYS_PER_WRITER): array
    {
        $options = [];
        foreach ($settings as $name => $value) {
            array_push($options, '-d', "{$name}={$value}");
        }
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';'
            . '$app = require ' . var_export(base_path('bootstrap/app.php'), true) . ';'
            . '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            . 'for ($i = 0; $i < ' . $keys . '; $i++) { getOrPutTranslateMessageValueByKey(local: ' . var_export(self::LOCALE, true) . ', key: "worker{$argv[1]}_key{$i}"); }';
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'];
        $process = proc_open([PHP_BINARY, ...$options, '-r', $script, '--', (string) $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);

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

    public function test_keys_are_stored_where_opcache_restricts_its_api(): void
    {
        // opcache.restrict_api names the paths that may call opcache_invalidate(): any other path raises a warning, which the framework turns into an exception
        $writer = $this->startWriter(worker: 1, settings: ['opcache.enable_cli' => '1', 'opcache.restrict_api' => '/nonexistent'], keys: 5);
        $error = stream_get_contents($writer['pipes'][2]);

        $this->assertSame(0, proc_close($writer['process']), $error);
        $stored = include $this->directory . '/new-messages.php';
        $this->assertCount(self::SEEDED_KEYS + 5, $stored, 'the keys were not stored');
        $this->assertSame('Worker1 key4', $stored['worker1_key4']);
        $this->assertStringNotContainsString('opcache_invalidate', $error, 'the refused call of opcache_invalidate() was logged as a failure');
    }

    public function test_keys_are_stored_without_the_lock_where_php_cannot_lock_a_file_and_the_lack_is_logged_once(): void
    {
        $writer = $this->startWriter(worker: 1, settings: ['disable_functions' => 'flock'], keys: 5);
        $error = stream_get_contents($writer['pipes'][2]);

        $this->assertSame(0, proc_close($writer['process']), $error);
        $stored = include $this->directory . '/new-messages.php';
        $this->assertCount(self::SEEDED_KEYS + 5, $stored, 'the keys were not stored');
        $this->assertSame(1, substr_count($error, 'cannot be locked'), 'the lack of a lock is logged once, not once for each key');
        $this->assertSame([], array_values(array_diff(scandir($this->directory), ['.', '..', 'messages.php', 'new-messages.php'])), 'a temporary file stayed');
    }

    public function test_a_key_that_another_worker_added_is_not_lost_where_opcache_does_not_check_timestamps(): void
    {
        if (!function_exists('opcache_invalidate')) {
            $this->markTestSkipped('OPcache is not installed');
        }
        $path = $this->directory . '/opcache-gap.php';
        file_put_contents($path, "<?php\n\nreturn [\n\t'a' => 'A',\n];\n");
        // the process includes the file (OPcache keeps it), another worker adds a key that this OPcache is not told about, and the process adds its own
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';' . <<<'PHP'
            $path = $argv[1];
            include $path;
            file_put_contents($path, "<?php\n\nreturn [\n\t'a' => 'A',\n\t'b' => 'B',\n];\n");
            addNewTranslationMessage(path: $path, key: 'c', message: 'C');
            PHP;
        $settings = ['opcache.enable_cli' => '1', 'opcache.validate_timestamps' => '0', 'opcache.file_update_protection' => '0'];
        $options = [];
        foreach ($settings as $name => $value) {
            array_push($options, '-d', "{$name}={$value}");
        }
        $process = proc_open([PHP_BINARY, ...$options, '-r', $script, '--', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $error = stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $error);
        $this->assertSame(['a', 'b', 'c'], array_keys(include $path), 'the key of the other worker was lost');
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
