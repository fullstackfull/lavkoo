<?php

namespace App\Utils;

use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A file that requests change while other requests read it: the .env file, the language files that translate() extends. A change is one
 * critical section under an exclusive lock on the file, and what the change is based on is read once the lock is held, so writers that
 * overlap lose nothing of each other's (two of them used to truncate each other's file, or keep only one's keys).
 *
 * The lock only keeps writers apart; readers take none. The new content is therefore written beside the file and renamed over it, so a reader
 * sees the old file or the new one, and a write that fails or is killed leaves the old one whole.
 *
 * The replacement is an improvement on writing in place and never a condition for it. Where it is impossible (a PHP that has disabled chmod,
 * chown or chgrp, a disk with no room for a second copy of the file, a file a container mounts) the file is written in place under the lock, and
 * fsync, which only asks the disk to make the bytes durable, is asked for where PHP has it and never decides whether a write succeeded.
 *
 * A file that cannot be opened, locked, read or written is an error, never a success. Only a caller whose change is better made without the
 * lock than not made (translate() adds a key that the next request adds again if it is lost) may ask for it to go on where no lock can be had.
 */
final class AtomicFile
{
    /**
     * How often a writer that waited for the lock may find the file replaced under it before it gives up. Each time is a write that another writer
     * finished while it waited, and a lock is not granted in turn: three processes that write 300 times each in a tight loop made one of them lose
     * 432 rounds in a row. A file whose inode never matches its name (a file system that makes up an inode for each open) would otherwise keep
     * the request in this loop for good.
     */
    private const MOST_REPLACEMENTS_WAITED_OUT = 1000;

    /** @var array<string, true> the files that were changed without a lock, so that the log says it once for each and not for each change */
    private static array $pathsChangedWithoutLock = [];

    /**
     * Replaces what the file holds by what $change makes of it.
     *
     * @param Closure(string): string $change what the file is to hold, given what it holds
     * @param bool $createWhenMissing whether a file that is not there is made (the installer writes the first .env)
     * @param bool $lockIsRequired whether a file that cannot be locked (a file system without locks) is an error; if it is not, the file is
     *                             changed without the lock and the log says so once
     * @throws RuntimeException when the file cannot be opened, locked (if the lock is required), read or written
     */
    public static function rewrite(string $path, Closure $change, bool $createWhenMissing = false, bool $lockIsRequired = true): void
    {
        $handle = self::openLocked(path: $path, createWhenMissing: $createWhenMissing, lockIsRequired: $lockIsRequired);

        try {
            $current = stream_get_contents($handle);
            if ($current === false) {
                throw new RuntimeException("The file {$path} cannot be read.");
            }
            self::write(path: $path, handle: $handle, current: $current, updated: $change($current));
        } finally {
            self::attempt('flock', $handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The file, locked, once it is the file the path names. A writer that waited for the lock may have waited on a file that the writer before
     * it replaced: what it would read there is out of date, so it locks the file the path names now.
     *
     * @return resource
     * @throws RuntimeException when the file cannot be opened, or locked (if the lock is required)
     */
    private static function openLocked(string $path, bool $createWhenMissing, bool $lockIsRequired)
    {
        $handle = self::lockedHandle(path: $path, createWhenMissing: $createWhenMissing);
        if ($handle !== null) {
            return $handle;
        }
        if ($lockIsRequired) {
            throw new RuntimeException("The file {$path} cannot be locked.");
        }
        self::reportChangeWithoutLock(path: $path);

        return self::open(path: $path, createWhenMissing: $createWhenMissing);
    }

    /** @return resource|null the locked file, or nothing where no lock can be had (a file system that refuses it, a file replaced again and again) */
    private static function lockedHandle(string $path, bool $createWhenMissing)
    {
        for ($round = 0; $round < self::MOST_REPLACEMENTS_WAITED_OUT; $round++) {
            $handle = self::open(path: $path, createWhenMissing: $createWhenMissing);
            if (!self::attempt('flock', $handle, LOCK_EX)) {
                fclose($handle);

                return null;
            }
            if (self::isTheFileAt(path: $path, handle: $handle)) {
                return $handle;
            }
            fclose($handle);
        }

        return null;
    }

    /**
     * @return resource
     * @throws RuntimeException when the file cannot be opened
     */
    private static function open(string $path, bool $createWhenMissing)
    {
        $handle = @fopen($path, $createWhenMissing ? 'c+' : 'r+');
        if ($handle === false) {
            throw new RuntimeException("The file {$path} cannot be opened for writing.");
        }

        return $handle;
    }

    private static function reportChangeWithoutLock(string $path): void
    {
        if (!isset(self::$pathsChangedWithoutLock[$path])) {
            self::$pathsChangedWithoutLock[$path] = true;
            Log::warning("The file {$path} cannot be locked, so it is changed without a lock.");
        }
    }

    /** @param resource $handle */
    private static function isTheFileAt(string $path, $handle): bool
    {
        clearstatcache(clear_realpath_cache: true, filename: $path);
        $named = @stat($path);
        $opened = fstat($handle);

        return $named !== false && $opened !== false && $named['dev'] === $opened['dev'] && $named['ino'] === $opened['ino'];
    }

    /** @param resource $handle */
    private static function write(string $path, $handle, string $current, string $updated): void
    {
        if (self::replaceThroughTemporaryFile(path: $path, handle: $handle, content: $updated)) {
            return;
        }

        if (!self::overwrite(handle: $handle, content: $updated)) {
            self::overwrite(handle: $handle, content: $current);
            throw new RuntimeException("The file {$path} cannot be written.");
        }
    }

    /**
     * Writes the content beside the file and renames it over the file the path leads to (a link stays a link), with the owner, the group and the
     * permissions of the old one. False where that is impossible (a file a container mounts, a directory the web user cannot write to, another
     * file system, a disk with no room for a second copy, an owner that the writer cannot give to a file, a PHP that has disabled the functions
     * that set it): the caller writes in place, and the old file is as it was. The temporary file has one fixed name, which the lock makes safe,
     * so a killed write leaves nothing the next one does not replace.
     *
     * @param resource $handle
     */
    private static function replaceThroughTemporaryFile(string $path, $handle, string $content): bool
    {
        $realPath = function_exists('realpath') ? realpath($path) : false;
        if ($realPath === false) {
            return false;
        }
        $temporaryPath = $realPath . '.tmp';
        @unlink($temporaryPath);
        $temporary = @fopen($temporaryPath, 'xb');
        if ($temporary === false) {
            return false;
        }

        $replaced = false;
        try {
            $replaced = self::copyAttributes(from: $handle, to: $temporary, temporaryPath: $temporaryPath)
                && self::writeAll(handle: $temporary, content: $content)
                && @rename($temporaryPath, $realPath);
        } finally {
            fclose($temporary);
            if (!$replaced) {
                @unlink($temporaryPath);
            }
        }

        return $replaced;
    }

    /**
     * The owner, the group and the permissions of the old file on the new one, so that the new one is not more readable than the old one was and
     * the user who owned the old one (a deploy account that owns a .env which the web user may write) still owns it. What the new file has
     * already is left alone; the rest is set, and False where it cannot be (chown is root's, a user may only pass a file to a group they are
     * in, a PHP may have disabled the functions): the old file is then written in place, which keeps everything about it.
     *
     * @param resource $from
     * @param resource $to
     */
    private static function copyAttributes($from, $to, string $temporaryPath): bool
    {
        $wanted = self::attributesOf(handle: $from);

        // the permissions last: a change of owner or group takes the setuid and setgid bits off the file
        foreach (['chown' => 'uid', 'chgrp' => 'gid', 'chmod' => 'mode'] as $function => $attribute) {
            if (self::attributesOf(handle: $to)[$attribute] !== $wanted[$attribute]) {
                self::attempt($function, $temporaryPath, $wanted[$attribute]);
            }
        }

        return self::attributesOf(handle: $to) === $wanted;
    }

    /**
     * @param resource $handle
     * @return array{uid: int, gid: int, mode: int}
     */
    private static function attributesOf($handle): array
    {
        $statistics = fstat($handle);

        return ['uid' => $statistics['uid'], 'gid' => $statistics['gid'], 'mode' => $statistics['mode'] & 07777];
    }

    /** @param resource $handle */
    private static function overwrite($handle, string $content): bool
    {
        return @ftruncate($handle, 0) && @rewind($handle) && self::writeAll(handle: $handle, content: $content);
    }

    /**
     * What fwrite and fflush report is whether the bytes were written, and a write that failed (a full disk is reported as a notice, which the
     * framework raises as an exception) is a result here and not an exception, so the caller can fall back. fsync only asks the disk to make
     * them durable: a file system that refuses it (certain FUSE and network mounts) still holds what was written.
     *
     * @param resource $handle
     */
    private static function writeAll($handle, string $content): bool
    {
        if (@fwrite($handle, $content) !== strlen($content) || !@fflush($handle)) {
            return false;
        }
        self::attempt('fsync', $handle);

        return true;
    }

    /** A file function that a hardened PHP has disabled (disable_functions) is one that refuses: what a refusal means is for the caller to say. */
    private static function attempt(string $function, mixed ...$arguments): bool
    {
        return function_exists($function) && @$function(...$arguments);
    }
}
