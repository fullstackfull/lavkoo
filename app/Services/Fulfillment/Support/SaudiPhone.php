<?php

namespace App\Services\Fulfillment\Support;

/**
 * Normalises a Saudi mobile number to the national format both providers' documentation shows in its
 * examples (05XXXXXXXX). Returns null for anything that is not a valid Saudi mobile, so the fulfillment
 * is held for correction rather than sent with a number the courier cannot call.
 */
final class SaudiPhone
{
    /** Kuwait, Saudi Arabia, Oman, the Emirates, Bahrain and Qatar: where a national number is written with a leading zero that the international form drops. */
    private const GULF_COUNTRY_CODES = ['965', '966', '968', '971', '973', '974'];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

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
     * nothing else agrees with them), any other number by its digits.
     */
    public static function isSameNumber(string $first, string $second): bool
    {
        $firstSaudi = self::normalize($first);
        $secondSaudi = self::normalize($second);
        if ($firstSaudi !== null || $secondSaudi !== null) {
            return $firstSaudi === $secondSaudi;
        }

        $internationalFirst = self::withoutTrunkZero($first);

        return $internationalFirst !== '' && $internationalFirst === self::withoutTrunkZero($second);
    }

    /** The digits of an international number without the trunk zero some people keep after the country code. */
    private static function withoutTrunkZero(string $raw): string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        foreach (self::GULF_COUNTRY_CODES as $countryCode) {
            if (str_starts_with($digits, $countryCode . '0')) {
                return $countryCode . substr($digits, strlen($countryCode) + 1);
            }
        }

        return $digits;
    }
}
