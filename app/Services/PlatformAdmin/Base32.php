<?php

namespace App\Services\PlatformAdmin;

/**
 * Base32 as RFC 4648 writes it (the alphabet `A-Z2-7`), which is how an authenticator app is given a secret. Written here because the second factor
 * is the only place the application needs it, and a dependency for forty lines would be a larger thing to review than they are.
 *
 * It encodes without padding, and decodes what a person or an app may have changed: lower case, spaces, the padding of the RFC. Anything else is not
 * Base32 and decodes to null, never to a guess.
 *
 * The bits are collected in one integer that is never narrowed: what has been written out moves up and falls off the end of it, and the bits that are
 * still to be read are always the lowest ones.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const BITS_PER_CHARACTER = 5;

    private const BITS_PER_BYTE = 8;

    /** The characters a whole number of bytes can end in: no run of bytes encodes to a text of 1, 3 or 6 characters more than a multiple of 8. */
    private const IMPOSSIBLE_REMAINDERS = [1, 3, 6];

    public static function encode(string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $bitsInBuffer = 0;

        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << self::BITS_PER_BYTE) | $byte;
            $bitsInBuffer += self::BITS_PER_BYTE;

            while ($bitsInBuffer >= self::BITS_PER_CHARACTER) {
                $bitsInBuffer -= self::BITS_PER_CHARACTER;
                $encoded .= self::ALPHABET[($buffer >> $bitsInBuffer) & 0x1F];
            }
        }

        if ($bitsInBuffer > 0) {
            $encoded .= self::ALPHABET[($buffer << (self::BITS_PER_CHARACTER - $bitsInBuffer)) & 0x1F];
        }

        return $encoded;
    }

    /** The bytes the text stands for, or null when it is not Base32. */
    public static function decode(string $text): ?string
    {
        $characters = rtrim(strtoupper(str_replace(' ', '', trim($text))), '=');

        if (preg_match('/^[A-Z2-7]*$/', $characters) !== 1 || in_array(strlen($characters) % 8, self::IMPOSSIBLE_REMAINDERS, true)) {
            return null;
        }

        if ($characters === '') {
            return '';
        }

        $decoded = '';
        $buffer = 0;
        $bitsInBuffer = 0;

        foreach (str_split($characters) as $character) {
            $buffer = ($buffer << self::BITS_PER_CHARACTER) | strpos(self::ALPHABET, $character);
            $bitsInBuffer += self::BITS_PER_CHARACTER;

            if ($bitsInBuffer >= self::BITS_PER_BYTE) {
                $bitsInBuffer -= self::BITS_PER_BYTE;
                $decoded .= chr(($buffer >> $bitsInBuffer) & 0xFF);
            }
        }

        return $decoded;
    }
}
