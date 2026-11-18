<?php

namespace Tests\Support;

use LogicException;

/**
 * A file on a file system that a test scripts, as `scripted://name`. Its content lives in memory, and it answers a lock, a sync and a stat the way
 * the test says, which no file on a disk can be made to do: a file system that refuses locks or fsync (NFS without a lock daemon, some FUSE
 * mounts), and a name that leads to another file each time it is opened (every writer before this one replaced it).
 */
final class ScriptedFileStream
{
    private const SCHEME = 'scripted';

    /** A writer that opens the file more often than this is out of control: the stream stops it, so a test that would never end fails. */
    private const MOST_OPENS = 10000;

    /** @var array<string, string> the content of each file by its name */
    public static array $contents = [];

    public static bool $locksAreRefused = false;

    public static bool $everyOpenIsAnotherFile = false;

    public static int $opens = 0;

    public $context;

    private string $name = '';

    private int $position = 0;

    private int $inode = 1;

    public static function register(): void
    {
        self::unregister();
        self::$contents = [];
        self::$locksAreRefused = false;
        self::$everyOpenIsAnotherFile = false;
        self::$opens = 0;
        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function unregister(): void
    {
        in_array(self::SCHEME, stream_get_wrappers(), true) && stream_wrapper_unregister(self::SCHEME);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (++self::$opens > self::MOST_OPENS) {
            throw new LogicException('The file was opened over and over again.');
        }
        if (!array_key_exists($path, self::$contents)) {
            if (!str_contains($mode, 'c')) {
                return false;
            }
            self::$contents[$path] = '';
        }
        $this->name = $path;
        $this->inode = self::$everyOpenIsAnotherFile ? 1000 + self::$opens : 1;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$contents[$this->name], $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        $content = self::$contents[$this->name];
        self::$contents[$this->name] = substr($content, 0, $this->position) . $data . substr($content, $this->position + strlen($data));
        $this->position += strlen($data);

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents[$this->name]);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            default => strlen(self::$contents[$this->name]) + $offset,
        };

        return true;
    }

    public function stream_truncate(int $size): bool
    {
        self::$contents[$this->name] = str_pad(substr(self::$contents[$this->name], 0, $size), $size, "\0");

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return !self::$locksAreRefused || ($operation & LOCK_UN) === LOCK_UN;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return $this->statistics(inode: $this->inode, size: strlen(self::$contents[$this->name]));
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return array_key_exists($path, self::$contents) ? $this->statistics(inode: 1, size: strlen(self::$contents[$path])) : false;
    }

    public function stream_close(): void
    {
    }

    /** @return array<string, int> */
    private function statistics(int $inode, int $size): array
    {
        return ['dev' => 1, 'ino' => $inode, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => $size, 'atime' => 0, 'mtime' => 0, 'ctime' => 0, 'blksize' => -1, 'blocks' => -1];
    }
}
