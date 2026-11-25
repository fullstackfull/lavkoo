<?php

namespace App\Utils;

/**
 * The disk this installation stores an uploaded file ON -- the answer `config('filesystems.disks.default')` is asked for in 60-odd places
 * across this application and never gives.
 *
 * There is no `disks.default` entry in `config/filesystems.php` and nothing writes one, so every one of those reads is `null ?? 'public'`:
 * the local public disk, whatever the shop configured. For a shop on the public disk that is right by accident; for a shop on s3 it means
 * the DESTINATION of an upload is the local disk and the `disk` recorded against the row is `public`, so the bytes are written where nothing
 * serves them from and the record names the wrong disk as well.
 *
 * What the installation actually chose is `filesystems.default`: `FileManagerTrait::setStorageConnectionEnvironment()` copies the
 * `storage_connection_type` setting into it at boot, and `config/filesystems.php` seeds it from `FILESYSTEM_DRIVER`.
 *
 * `local` is not an answer. It is Laravel's shipped default for that env var, this application has never stored an upload there (every
 * record's `*_storage_type` is `public` or `s3`), and its root is outside the directory the `storage` symlink publishes -- so a file written
 * there has no address. A disk the configuration does not define is not an answer either. Both fall back to `public`, which is what those
 * reads effectively return, so a shop that has not chosen s3 behaves exactly as it does today.
 */
final class InstallationDisk
{
    /** Laravel's shipped default for FILESYSTEM_DRIVER, which this application has never stored an upload on. */
    private const NOT_AN_ANSWER = 'local';

    public const FALLBACK = 'public';

    public static function name(): string
    {
        $chosen = config('filesystems.default');
        $chosen = is_string($chosen) ? trim($chosen) : '';

        return $chosen !== '' && $chosen !== self::NOT_AN_ANSWER && config('filesystems.disks.' . $chosen) !== null ? $chosen : self::FALLBACK;
    }
}
