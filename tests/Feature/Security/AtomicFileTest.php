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
    /** The user and the group `nobody`. */
    private const UNPRIVILEGED = 65534;

    private string $directory;

    /** @var list<string> */
    private array $mounts = [];

    /** @var list<string> */
    private array $directoriesOnAnotherFileSystem = [];

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
        array_map(File::deleteDirectory(...), $this->directoriesOnAnotherFileSystem);

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

    /**
     * Like runPhp(), as the user and the group `nobody` (a process that starts as root gives up its rights after it has loaded the classes it
     * needs, which `nobody` could not read from this checkout).
     *
     * @return array{status: int, output: string, error: string}
     */
    private function runPhpAsAnUnprivilegedUser(string $code, array $arguments): array
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('only root can run a process as another user');
        }

        return $this->runPhp('class_exists(App\\Utils\\AtomicFile::class); posix_setgid(' . self::UNPRIVILEGED . '); posix_setuid(' . self::UNPRIVILEGED . ');' . $code, $arguments);
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

    /** A directory on a file system other than the one of the test's directory (shared memory, which a user may write to), or the test is skipped. */
    private function directoryOnAnotherFileSystem(): string
    {
        $directory = '/dev/shm/atomic-file-' . uniqid();
        if (!is_dir('/dev/shm') || !@mkdir($directory)) {
            $this->markTestSkipped('there is no second file system to write to');
        }
        $this->directoriesOnAnotherFileSystem[] = $directory;
        if (stat($directory)['dev'] === stat($this->directory)['dev']) {
            $this->markTestSkipped('/dev/shm is not another file system here');
        }

        return $directory;
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

    public function test_a_file_is_still_replaced_where_chmod_is_disabled_and_the_new_file_has_the_mode_of_the_old_one_anyway(): void
    {
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");
        chmod($path, 0644);
        $inodeBefore = fileinode($path);

        $run = $this->runPhp('umask(022); App\\Utils\\AtomicFile::rewrite($argv[1], fn (string $content): string => $content . "second\n");', [$path], ['disable_functions' => 'chmod']);

        $this->assertSame(0, $run['status'], $run['output'] . $run['error']);
        $this->assertSame("first\nsecond\n", file_get_contents($path));
        clearstatcache();
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was written in place although there was no permission to set');
    }

    // ---- the permissions of the old file on the new one -------------------------------------------------------------------------

    /** @return array<string, array{0: int}> */
    public static function modesWithSpecialBits(): array
    {
        return ['setuid' => [04755], 'setgid' => [02775], 'sticky' => [01644]];
    }

    #[DataProvider('modesWithSpecialBits')]
    public function test_the_new_file_keeps_the_setuid_setgid_and_sticky_bits_of_the_old_one(int $mode): void
    {
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");
        chmod($path, $mode);
        clearstatcache();
        if ((fileperms($path) & 07777) !== $mode) {
            $this->markTestSkipped('the system does not keep this bit on a file of this user');
        }
        $inodeBefore = fileinode($path);

        AtomicFile::rewrite(path: $path, change: static fn (string $content): string => $content . "second\n");

        clearstatcache();
        $this->assertSame($mode, fileperms($path) & 07777);
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was written in place, so no copy of its permissions was made');
    }

    public function test_the_new_file_is_closed_to_other_users_before_any_content_is_written_to_it(): void
    {
        $path = $this->directory . '/secrets.txt';
        file_put_contents($path, "SECRET=old\n");
        chmod($path, 0600);
        // umask 0: a file that is made is open to everyone until its permissions are set
        $script = 'require ' . var_export(base_path('vendor/autoload.php'), true) . '; umask(0); App\\Utils\\AtomicFile::rewrite($argv[1], fn (string $content): string => str_repeat("SECRET=0123456789abcdef0123\n", 400000));';
        $writer = proc_open([PHP_BINARY, '-r', $script, '--', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        $looksWithContent = 0;
        $looksAtContentOthersCanRead = 0;
        do {
            $status = proc_get_status($writer);
            clearstatcache(true, $path . '.tmp');
            $statistics = @stat($path . '.tmp');
            if ($statistics !== false && $statistics['size'] > 0) {
                $looksWithContent++;
                $looksAtContentOthersCanRead += ($statistics['mode'] & 077) === 0 ? 0 : 1;
            }
        } while ($status['running']);

        $this->assertSame(0, $status['exitcode'], (string) stream_get_contents($pipes[2]));
        proc_close($writer);
        $this->assertGreaterThan(0, $looksWithContent, 'the reader never saw the temporary file with content in it');
        $this->assertSame(0, $looksAtContentOthersCanRead, "{$looksAtContentOthersCanRead} of {$looksWithContent} looks at the temporary file found content that other users can read");
    }

    public function test_a_link_to_a_file_on_another_file_system_is_replaced_where_the_file_is_and_stays_a_link(): void
    {
        $target = $this->directoryOnAnotherFileSystem() . '/settings.txt';
        file_put_contents($target, "first\n");
        $link = $this->directory . '/settings.txt';
        symlink($target, $link);
        $inodeBefore = fileinode($target);

        AtomicFile::rewrite(path: $link, change: static fn (string $content): string => $content . "second\n");

        clearstatcache();
        $this->assertTrue(is_link($link));
        $this->assertSame("first\nsecond\n", file_get_contents($target));
        $this->assertNotSame($inodeBefore, fileinode($target), 'the file behind the link was written in place: its copy was made beside the link, on another file system');
        $this->assertSame(['settings.txt'], $this->filesIn(dirname($target)));
        $this->assertSame(['settings.txt'], $this->filesIn($this->directory));
    }

    // ---- a file that belongs to someone else ------------------------------------------------------------------------------------

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int|null, 4: bool}> the owner, the group and the mode of the file, the group that the directory gives to the files made in it (it is a directory that does), and whether the file is replaced by a new one */
    public static function filesWithAnotherOwner(): array
    {
        return [
            'another user, in the group of the writer' => [12345, self::UNPRIVILEGED, 0664, null, false],
            'the writer, in a group that it is not in' => [self::UNPRIVILEGED, 23456, 0660, null, false],
            'the writer, in a group that the directory gives to every new file' => [self::UNPRIVILEGED, 23456, 0640, 23456, true],
            'the writer, in its own group' => [self::UNPRIVILEGED, self::UNPRIVILEGED, 0600, null, true],
        ];
    }

    #[DataProvider('filesWithAnotherOwner')]
    public function test_a_file_is_replaced_only_where_the_writer_can_give_the_new_file_to_the_owner_of_the_old_one(int $owner, int $group, int $mode, ?int $directoryGroup, bool $isReplaced): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('only root may give a file to another user, and run a process as one');
        }
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");
        chown($path, $owner);
        chgrp($path, $group);
        chmod($path, $mode);
        if ($directoryGroup !== null) {
            chgrp($this->directory, $directoryGroup);
        }
        chmod($this->directory, $directoryGroup === null ? 0777 : 02777);
        $inodeBefore = fileinode($path);

        $run = $this->runPhpAsAnUnprivilegedUser('App\\Utils\\AtomicFile::rewrite($argv[1], fn (string $content): string => $content . "second\n");', [$path]);

        $this->assertSame(0, $run['status'], $run['output'] . $run['error']);
        $this->assertSame("first\nsecond\n", file_get_contents($path));
        clearstatcache();
        $this->assertSame([$owner, $group, $mode], [fileowner($path), filegroup($path), fileperms($path) & 07777], 'the file changed hands or permissions');
        $this->assertSame($isReplaced, fileinode($path) !== $inodeBefore, $isReplaced ? 'the file was not replaced although its owner could be kept' : 'the file was replaced by one that its owner no longer owns');
        $this->assertSame(['settings.txt'], $this->filesIn($this->directory));
    }

    public function test_a_file_of_another_owner_keeps_its_setuid_and_setgid_bits_where_root_gives_the_new_file_to_that_owner(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('only root may give a file to another user');
        }
        $path = $this->directory . '/settings.txt';
        file_put_contents($path, "first\n");
        chown($path, 12345);
        chgrp($path, 23456);
        chmod($path, 06755); // after the owner: giving a file away takes these bits off it
        $inodeBefore = fileinode($path);

        AtomicFile::rewrite(path: $path, change: static fn (string $content): string => $content . "second\n");

        clearstatcache();
        $this->assertSame([12345, 23456, 06755], [fileowner($path), filegroup($path), fileperms($path) & 07777]);
        $this->assertNotSame($inodeBefore, fileinode($path), 'the file was written in place, so its owner and its bits were never copied');
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
