<?php

namespace App\Utils;

use Closure;
use RuntimeException;

/**
 * A file that requests change while other requests read it: the .env file, the language files that translate() extends. A change is one
 * critical section under an exclusive lock on the file, and what the change is based on is read once the lock is held, so writers that
 * overlap lose nothing of each other's (two of them used to truncate each other's file, or keep only one's keys).
 *
 * The lock only keeps writers apart; readers take none. The new content is therefore written beside the file and renamed over it, so a reader
 * sees the old file or the new one, and a write that fails or is killed leaves the old one whole.
 *
 * A file that cannot be opened, locked, read or written is an error, never a success.
 */
final class AtomicFile
{
    /**
     * Replaces what the file holds by what $change makes of it.
     *
     * @param Closure(string): string $change what the file is to hold, given what it holds
     * @param bool $createWhenMissing whether a file that is not there is made (the installer writes the first .env)
     * @throws RuntimeException when the file cannot be opened, locked, read or written
     */
    public static function rewrite(string $path, Closure $change, bool $createWhenMissing = false): void
    {
        $handle = self::openLocked(path: $path, createWhenMissing: $createWhenMissing);

        try {
            $current = stream_get_contents($handle);
            if ($current === false) {
                throw new RuntimeException("The file {$path} cannot be read.");
            }
            self::write(path: $path, handle: $handle, current: $current, updated: $change($current));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The file, locked, once it is the file the path names. A writer that waited for the lock may have waited on a file that the writer before
     * it replaced: what it would read there is out of date, so it locks the file the path names now.
     *
     * @return resource
     */
    private static function openLocked(string $path, bool $createWhenMissing)
    {
        while (true) {
            $handle = @fopen($path, $createWhenMissing ? 'c+' : 'r+');
            if ($handle === false) {
                throw new RuntimeException("The file {$path} cannot be opened for writing.");
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException("The file {$path} cannot be locked.");
            }
            if (self::isTheFileAt(path: $path, handle: $handle)) {
                return $handle;
            }
            fclose($handle);
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
     * Writes the content beside the file and renames it over the file the path leads to (a link stays a link), with the permissions and, for
     * root, the owner of the old one. False where that is impossible (a file a container mounts, a directory the web user cannot write to,
     * another file system): the caller writes in place. A write that fails after the temporary file was made is an error, and it leaves the
     * old file as it was. The temporary file has one fixed name, which the lock makes safe, so a killed write leaves nothing the next one does
     * not replace.
     *
     * @param resource $handle
     */
    private static function replaceThroughTemporaryFile(string $path, $handle, string $content): bool
    {
        $realPath = realpath($path);
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
            if (self::copyAttributes(from: $handle, temporaryPath: $temporaryPath)) {
                if (!self::writeAll(handle: $temporary, content: $content)) {
                    throw new RuntimeException("The file {$path} cannot be written.");
                }
                $replaced = @rename($temporaryPath, $realPath);
            }
        } finally {
            fclose($temporary);
            if (!$replaced) {
                @unlink($temporaryPath);
            }
        }

        return $replaced;
    }

    /**
     * The permissions and the owner of the old file, so that the new one is not more readable than it was. False where the permissions cannot
     * be set (a file system without them): the old file is then written in place.
     *
     * @param resource $from
     */
    private static function copyAttributes($from, string $temporaryPath): bool
    {
        $attributes = fstat($from);
        // only root may give a file to another user; for anyone else the new file stays with the user who made it
        @chown($temporaryPath, $attributes['uid']);
        @chgrp($temporaryPath, $attributes['gid']);

        return @chmod($temporaryPath, $attributes['mode'] & 07777);
    }

    /** @param resource $handle */
    private static function overwrite($handle, string $content): bool
    {
        return ftruncate($handle, 0) && rewind($handle) && self::writeAll(handle: $handle, content: $content);
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $content): bool
    {
        return fwrite($handle, $content) === strlen($content) && fflush($handle) && fsync($handle);
    }
}
