<?php

namespace App\Services\Fulfillment\Support;

/**
 * Normalises a Saudi mobile number to the national format both providers' documentation shows in its
 * examples (05XXXXXXXX). Returns null for anything that is not a valid Saudi mobile, so the fulfillment
 * is held for correction rather than sent with a number the courier cannot call.
 */
final class SaudiPhone
{
    /**
     * Egypt, Turkey, Morocco, Algeria, Libya, Sudan, Lebanon, Jordan, Syria, Iraq, Kuwait, Saudi Arabia, Yemen, Oman, Palestine, the Emirates,
     * Bahrain and Qatar: where people keep a zero straight after the country code that the international form of the number leaves out.
     * After any other code a zero is part of the number (in Italy it is), so it is never ignored. No code of the list starts another, so
     * a number matches at most one.
     */
    private const COUNTRY_CODES_WITH_A_TRUNK_ZERO = ['20', '90', '212', '213', '218', '249', '961', '962', '963', '964', '965', '966', '967', '968', '970', '971', '973', '974'];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = self::asciiDigits($raw);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^5\d{8}$/', $digits) === 1 ? '0' . $digits : null;
    }

    /**
     * Whether two spellings are one phone number: a Saudi number by its national form (so +966 5..., 05... and 009665... agree, and
     * nothing else agrees with them), any other number by its digits. A spelling of another country's number that holds a digit this class
     * cannot read is no number, so it equals nothing, itself included.
     */
    public static function isSameNumber(string $first, string $second): bool
    {
        $firstSaudi = self::saudiNumberOf($first);
        $secondSaudi = self::saudiNumberOf($second);
        if ($firstSaudi !== null || $secondSaudi !== null) {
            return $firstSaudi === $secondSaudi;
        }

        $internationalFirst = self::withoutTrunkZero($first);

        return $internationalFirst !== '' && $internationalFirst === self::withoutTrunkZero($second);
    }

    /** The national form of a Saudi spelling. A number written with another country's international prefix is not Saudi, whatever its digits read as. */
    private static function saudiNumberOf(string $raw): ?string
    {
        $digits = self::asciiDigits($raw);
        $international = str_starts_with(ltrim($raw), '+') || str_starts_with($digits, '00');
        $afterPrefix = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

        return $international && !str_starts_with($afterPrefix, '966') ? null : self::normalize($raw);
    }

    /** The spelling with its Arabic-Indic, Persian and full-width digits written as ASCII digits. */
    private static function withAsciiDigits(string $raw): string
    {
        return strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);
    }

    /** Only the digits of a spelling, Arabic-Indic, Persian and full-width ones included, as ASCII digits. */
    private static function asciiDigits(string $raw): string
    {
        return preg_replace('/\D+/', '', self::withAsciiDigits($raw)) ?? '';
    }

    /**
     * Whether the spelling holds a decimal digit of a script that is not converted (Bengali, Devanagari, Thai...): reading the digits it
     * knows and dropping that one would leave a shorter number, which is another phone. Text that cannot be inspected counts as holding one.
     */
    private static function holdsDigitsItCannotRead(string $raw): bool
    {
        return preg_match('/(?![0-9])\p{Nd}/u', self::withAsciiDigits($raw)) !== 0;
    }

    /**
     * The digits of an international number without the one trunk zero some people keep straight after a country code of the list. A
     * spelling that holds a digit this class cannot read has no digits to compare.
     */
    private static function withoutTrunkZero(string $raw): string
    {
        if (self::holdsDigitsItCannotRead($raw)) {
            return '';
        }

        $digits = self::asciiDigits($raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        foreach (self::COUNTRY_CODES_WITH_A_TRUNK_ZERO as $countryCode) {
            if (str_starts_with($digits, $countryCode . '0')) {
                return $countryCode . substr($digits, strlen($countryCode) + 1);
            }
        }

        return $digits;
    }
}
