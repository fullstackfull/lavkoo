<?php

namespace App\Services\Address;

/**
 * The Saudi National Address short address (العنوان الوطني المختصر) issued by Saudi Post (SPL): four letters followed by four
 * digits, e.g. RAGI2929.
 *
 * Only that published shape is enforced. SPL does not publish which letters exist or any check digit, so nothing here claims a
 * code is real — only that it is written the way SPL writes them. Existence can be checked against SPL's own lookup service when
 * the store has a key for it; see docs/address/national-address.md.
 */
final class NationalAddress
{
    /**
     * Canonical spelling of whatever a person typed: ASCII digits, upper-case Latin letters, no spaces, dashes of any kind or invisible
     * format characters (direction marks, joiners, soft hyphens). Only ASCII letters are upper-cased, so a letter outside A-Z stays what it is and the code is refused
     * (full Unicode case mapping would turn "ß" into "SS" and "ſ" into "S"). The result may still be invalid; use isValid() on it.
     * Blank input gives null.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = mb_convert_kana($raw, 'a', 'UTF-8');
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $text = preg_replace('/[\s\p{Z}\p{Pd}\p{Cf}_.]+/u', '', $text) ?? $text;
        $text = strtoupper($text);

        return $text === '' ? null : $text;
    }

    public static function isValid(?string $raw): bool
    {
        $normalized = self::normalize($raw);

        return $normalized !== null && preg_match(self::pattern(), $normalized) === 1;
    }

    /** The canonical form of a valid short address, or null when the input is blank or not a short address. */
    public static function canonical(?string $raw): ?string
    {
        $normalized = self::normalize($raw);

        return $normalized !== null && preg_match(self::pattern(), $normalized) === 1 ? $normalized : null;
    }

    /** What the configuration says the shape is, as a pattern: the single place the format is written down. */
    public static function pattern(): string
    {
        return '/^' . self::shape(letters: 'A-Z') . '$/D';
    }

    /** The same shape for an HTML `pattern` attribute and the client-side check (they upper-case what is typed, so both cases are accepted). */
    public static function inputPattern(): string
    {
        return self::shape(letters: 'A-Za-z');
    }

    private static function shape(string $letters): string
    {
        return '[' . $letters . ']{' . (int) config('address.national_address.letters', 4) . '}[0-9]{' . (int) config('address.national_address.digits', 4) . '}';
    }
}
