<?php

namespace App\Services\PlatformAdmin;

/**
 * The time-based one-time password of RFC 6238 over the counter code of RFC 4226, with HMAC-SHA-1, six digits and thirty seconds: what every
 * authenticator app does, and so the only thing that works with all of them. Written here, and checked against the published vectors in the tests,
 * because the application has no library for it and the check is a dozen lines.
 *
 * Nothing in it keeps state. Whether a code may be used again is decided by whoever stores the step it was accepted at.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /** A secret of 160 bits, the size RFC 4226 recommends, which is also what the authenticator apps that show one expect. */
    public const SECRET_BYTES = 20;

    /** One step either side of the current one: a phone whose clock is up to thirty seconds out still shows a code that is accepted. */
    public const DEFAULT_WINDOW = 1;

    private const OFFSET_BYTE = 19;

    public static function stepAt(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD_SECONDS);
    }

    public static function newSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /** The code of one step: the dynamic truncation of the HMAC of the counter, as RFC 4226 section 5.3 defines it. */
    public static function codeAt(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac(algo: 'sha1', data: pack('J', $step), key: $secret, binary: true);
        $offset = ord($hash[self::OFFSET_BYTE]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad(string: (string) ($truncated % (10 ** $digits)), length: $digits, pad_string: '0', pad_type: STR_PAD_LEFT);
    }

    /**
     * The step the code belongs to, among the current one and those `$window` either side, or null when it belongs to none of them.
     *
     * Every step of the window is computed and compared whatever the code is, so that the time it takes says nothing about how close a guess was; and
     * when two steps of the window happen to show the same code the later one is the answer, so that a code cannot be used again as the earlier of the two.
     */
    public static function matchingStep(string $secret, string $code, int $timestamp, int $window = self::DEFAULT_WINDOW): ?int
    {
        $current = self::stepAt(timestamp: $timestamp);
        $matched = null;

        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if (hash_equals(self::codeAt(secret: $secret, step: $step), $code)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /** The address an authenticator app reads (the key URI format): the issuer and the account name, and the parameters this class implements. */
    public static function otpauthUri(string $base32Secret, string $account, string $issuer): string
    {
        $query = http_build_query(
            data: ['secret' => $base32Secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD_SECONDS],
            encoding_type: PHP_QUERY_RFC3986,
        );

        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account) . '?' . $query;
    }
}
