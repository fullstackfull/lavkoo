<?php

namespace App\Utils;

/**
 * The name of a stored file is one path segment the platform generated: no directory, no way up, nothing the file system reads as anything
 * but a name. A seller's request used to hand back a string for a product's images, thumbnail or digital file, and the platform joined it to
 * a directory to read, download or delete, so a name like ../../../../.env reached files that are not uploads at all.
 */
final class PlainFileName
{
    private const LONGEST = 255;

    /** Whether a value is the name of a stored file, as the platform writes them: text with no separator, no control character, not "." or "..". */
    public static function isPlain(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && $name !== '.'
            && $name !== '..'
            && strlen($name) <= self::LONGEST
            && preg_match('/[\/\\\\\x00-\x1f]/', $name) !== 1;
    }

    /** The name when it is a plain one, otherwise nothing. */
    public static function of(mixed $name): ?string
    {
        return self::isPlain($name) ? $name : null;
    }

    /**
     * Whether a path on a storage disk stays below its starting directory: no segment goes up, and nothing is hidden behind a control or format
     * character (a null byte, a delete character, a zero width space, a right-to-left override). The disks refuse such a path with an exception,
     * so a request that carries one is answered as a missing file instead of a server error.
     */
    public static function staysInside(string $path): bool
    {
        return preg_match('/(^|[\/\\\\])\.\.([\/\\\\]|$)/', $path) !== 1 && preg_match('/[\x00-\x1f]/', $path) !== 1 && preg_match('/\p{C}/u', $path) !== 1;
    }
}
