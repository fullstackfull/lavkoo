<?php

namespace App\Services\PlatformAdmin;

use RuntimeException;

/**
 * The one-time codes that let an admin in when the phone is lost: made here, shown once by the console, kept only as an HMAC.
 *
 * A code is ten characters, written `xxxxx-xxxxx`, from an alphabet with no `i`, `l`, `o`, `0` or `1` (they are read as one another): about 50 bits, each
 * character drawn with random_int(). Typing it in any case, with or without the hyphen, with spaces, all mean the same code.
 * What is stored is the HMAC-SHA-256 of the typed-in form under a key of its own purpose derived from the application key, so that a copy of the table
 * is not a list of codes that could be guessed offline, and a key used for something else is not this one.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    public const LENGTH = 10;

    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const GROUP_LENGTH = 5;

    private const KEY_PURPOSE = 'gcc20.platform-admin.mfa.recovery-codes';

    private const KEY_PREFIX = 'base64:';

    /** @return list<string> the codes as they are shown, all different */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $codes[self::randomCode()] = true;
        }

        return array_keys($codes);
    }

    /** The code as it is compared and stored, or null when what was typed cannot be a code. */
    public static function normalized(string $typed): ?string
    {
        $candidate = strtolower(str_replace(['-', ' '], '', trim($typed)));

        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}\z/', $candidate) === 1 ? $candidate : null;
    }

    /** What is kept of a normalized code. */
    public static function hash(string $normalized): string
    {
        return hash_hmac(algo: 'sha256', data: $normalized, key: self::key());
    }

    private static function randomCode(): string
    {
        $characters = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($characters, 0, self::GROUP_LENGTH) . '-' . substr($characters, self::GROUP_LENGTH);
    }

    private static function key(): string
    {
        $configured = (string) config('app.key');
        $bytes = str_starts_with($configured, self::KEY_PREFIX) ? (string) base64_decode(substr($configured, strlen(self::KEY_PREFIX)), true) : $configured;

        if ($bytes === '') {
            throw new RuntimeException('The application key is not set, so recovery codes cannot be made or checked.');
        }

        return hash_hmac(algo: 'sha256', data: self::KEY_PURPOSE, key: $bytes, binary: true);
    }
}
