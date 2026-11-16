<?php

namespace Tests\Feature\Security;

use App\Utils\AtomicFile;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ScriptedFileStream;
use Tests\TestCase;
use Throwable;

/**
 * The .env file and the language files are written by AtomicFile: under a lock, beside the file and renamed over it, or in place where that is
 * impossible. The replacement is an improvement on writing in place and never a condition for it, so a host where the replacement cannot be done
 * (a PHP that has disabled chmod, chown or chgrp as shared hosts do, a disk with no room for a second copy of the file, a file system that refuses
 * fsync) must still be written, as it was before the replacement existed. A file that cannot be opened, locked, read or written is an error.
 */
class AtomicFileTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $mounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/atomic-file-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        ScriptedFileStream::unregister();
        foreach ($this->mounts as $mount) {
            exec('umount ' . escapeshellarg($mount) . ' 2>&1');
        }
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return array{status: int, output: string, error: string} a PHP process that knows the application's classes and runs $code with $arguments as $argv[1...] */
    private function runPhp(string $code, array $arguments = [], array $settings = []): array
    {
        $options = [];
        foreach ($settings as $name => $value) {
            array_push($options, '-d', "{$name}={$value}");
        }
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . ';' . $code;
        $process = proc_open([PHP_BINARY, ...$options, '-r', $script, '--', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        array_map('fclose', $pipes);

        return ['status' => proc_close($process), 'output' => $output, 'error' => $error];
    }

    /** A file system with the room for one small file only: the file that is there leaves none for a second copy of itself. */
    private function mountSmallFileSystem(): string
    {
        $mountPoint = $this->directory . '/small-disk';
        mkdir($mountPoint);
        exec('mount -t tmpfs -o size=4k tmpfs ' . escapeshellarg($mountPoint) . ' 2>&1', result_code: $status);
        if ($status !== 0) {
            $this->markTestSkipped('a file system can only be mounted by root, on a system that allows it');
        }

        return $this->mounts[] = $mountPoint;
    }

    private function failureOf(Closure $write): ?Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /** @return list<string> */
    private function filesIn(string $directory): array
    {
        return array_values(array_diff(scandir($directory), ['.', '..']));
    }

    // ---- a function that PHP has disabled ---------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> the disabled function, and whether the file is still replaced by a new one (it is not where the permissions cannot be set or the path cannot be resolved) */
    public static function functionsThatMayBeDisabled(): array
    {
        return [
            'chmod' => ['chmod', false],
            'chown' => ['chown', true],
            'chgrp' => ['chgrp', true],
            'fsync' => ['fsync', true],
            'realpath' => ['realpath', false],
        ];
    }

    #[DataProvider('functionsThatMayBeDisabled')]
    public function test_a_write_still_succeeds_where_php_has_disabled_a_function_the_replacement_uses(string $function, bool $isReplaced): void
    {
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");
        chmod($path, 0600);
        $inodeBefore = fileinode($path);

        // umask 0: a new file would be readable by everyone, so a file that keeps its mode shows that the mode was set (or the file never replaced)
        $run = $this->runPhp('umask(0); App\\Utils\\AtomicFile::rewrite($argv[1], fn (string $content): string => $content . "second\n");', [$path], ['disable_functions' => $function]);

        $this->assertSame(0, $run['status'], $run['output'] . $run['error']);
        $this->assertSame("first\nsecond\n", file_get_contents($path));
        clearstatcache();
        $this->assertSame(0600, fileperms($path) & 07777, 'the file became more readable than it was');
        $this->assertSame($isReplaced, fileinode($path) !== $inodeBefore, $isReplaced ? 'the file was not replaced although it could be' : 'the file was replaced although its permissions could not be set');
        $this->assertSame(['settings.txt'], $this->filesIn($this->directory));
    }

    public function test_a_lock_that_cannot_be_taken_because_flock_is_disabled_is_an_error_and_the_file_is_untouched(): void
    {
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");

        $run = $this->runPhp('try { App\\Utils\\AtomicFile::rewrite($argv[1], fn (string $content): string => $content . "second\n"); echo "written"; } catch (Throwable $failure) { echo get_class($failure), ": ", $failure->getMessage(); }', [$path], ['disable_functions' => 'flock']);

        $this->assertSame("RuntimeException: The file {$path} cannot be locked.", $run['output'], $run['error']);
        $this->assertSame("first\n", file_get_contents($path));
    }

    // ---- a disk without room for a second copy ----------------------------------------------------------------------------------

    public function test_a_file_system_without_room_for_a_second_copy_is_written_in_place(): void
    {
        $path = $this->mountSmallFileSystem() . '/settings.txt';
        file_put_contents($path, "first\n");
        $inodeBefore = fileinode($path);

        AtomicFile::rewrite(path: $path, change: fn (string $content): string => $content . "second\n");

        clearstatcache();
        $this->assertSame("first\nsecond\n", file_get_contents($path));
        $this->assertSame($inodeBefore, fileinode($path), 'the file was replaced although the copy had no room');
        $this->assertSame(['settings.txt'], $this->filesIn(dirname($path)));
    }

    public function test_a_write_that_fits_neither_as_a_copy_nor_in_place_raises_and_leaves_the_old_content(): void
    {
        $path = $this->mountSmallFileSystem() . '/settings.txt';
        file_put_contents($path, "first\n");

        $failure = $this->failureOf(static fn () => AtomicFile::rewrite(path: $path, change: static fn (string $content): string => str_repeat('x', 5000)));

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringContainsString('cannot be written', $failure->getMessage());
        $this->assertSame("first\n", file_get_contents($path));
        $this->assertSame(['settings.txt'], $this->filesIn(dirname($path)));
    }

    // ---- a file system that refuses what the writer asks of it ------------------------------------------------------------------

    public function test_a_file_system_that_refuses_fsync_still_holds_what_was_written(): void
    {
        ScriptedFileStream::register();
        ScriptedFileStream::$contents['scripted://settings'] = "first\n";

        AtomicFile::rewrite(path: 'scripted://settings', change: fn (string $content): string => $content . "second\n");

        $this->assertSame("first\nsecond\n", ScriptedFileStream::$contents['scripted://settings']);
    }

    public function test_a_lock_that_the_file_system_refuses_is_an_error_and_the_file_is_untouched(): void
    {
        ScriptedFileStream::register();
        ScriptedFileStream::$contents['scripted://settings'] = "first\n";
        ScriptedFileStream::$locksAreRefused = true;

        $failure = $this->failureOf(static fn () => AtomicFile::rewrite(path: 'scripted://settings', change: static fn (string $content): string => $content . "second\n"));

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringContainsString('cannot be locked', $failure->getMessage());
        $this->assertSame("first\n", ScriptedFileStream::$contents['scripted://settings']);
    }

    public function test_a_writer_that_finds_the_file_replaced_again_and_again_gives_up_with_an_error(): void
    {
        ScriptedFileStream::register();
        ScriptedFileStream::$contents['scripted://settings'] = "first\n";
        ScriptedFileStream::$everyOpenIsAnotherFile = true;

        $failure = $this->failureOf(static fn () => AtomicFile::rewrite(path: 'scripted://settings', change: static fn (string $content): string => $content . "second\n"));

        $this->assertInstanceOf(RuntimeException::class, $failure, 'the writer kept going');
        $this->assertStringContainsString('cannot be locked', $failure->getMessage());
        $this->assertGreaterThan(1, ScriptedFileStream::$opens, 'the writer did not try again');
        $this->assertSame("first\n", ScriptedFileStream::$contents['scripted://settings']);
    }

    // ---- a change that is better made without the lock than not made ---------------------------------------------------------------

    public function test_a_change_that_may_do_without_the_lock_is_made_where_the_file_system_refuses_it_and_the_lack_is_logged_once(): void
    {
        $name = 'scripted://unlockable-' . uniqid();
        ScriptedFileStream::register();
        ScriptedFileStream::$contents[$name] = "first\n";
        ScriptedFileStream::$locksAreRefused = true;
        Log::shouldReceive('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, $name) && str_contains($message, 'cannot be locked'));

        AtomicFile::rewrite(path: $name, change: static fn (string $content): string => $content . "second\n", lockIsRequired: false);
        AtomicFile::rewrite(path: $name, change: static fn (string $content): string => $content . "third\n", lockIsRequired: false);

        $this->assertSame("first\nsecond\nthird\n", ScriptedFileStream::$contents[$name]);
    }

    public function test_a_change_that_may_do_without_the_lock_is_made_where_the_file_is_replaced_again_and_again(): void
    {
        $name = 'scripted://replaced-' . uniqid();
        ScriptedFileStream::register();
        ScriptedFileStream::$contents[$name] = "first\n";
        ScriptedFileStream::$everyOpenIsAnotherFile = true;
        Log::shouldReceive('warning')->once();

        AtomicFile::rewrite(path: $name, change: static fn (string $content): string => $content . "second\n", lockIsRequired: false);

        $this->assertSame("first\nsecond\n", ScriptedFileStream::$contents[$name]);
    }

    public function test_a_change_that_needs_the_lock_is_not_made_without_it(): void
    {
        $name = 'scripted://needs-the-lock-' . uniqid();
        ScriptedFileStream::register();
        ScriptedFileStream::$contents[$name] = "first\n";
        ScriptedFileStream::$locksAreRefused = true;

        $failure = $this->failureOf(static fn () => AtomicFile::rewrite(path: $name, change: static fn (string $content): string => $content . "second\n", lockIsRequired: true));

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame("first\n", ScriptedFileStream::$contents[$name]);
    }
}
