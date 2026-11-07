<?php

namespace App\Services\Fulfillment\Support;

/**
 * Canonical form for comparing place names written by different people in Arabic or English, so
 * "الرياض", "الرِّيَاض", "الریاض" (typed on a Persian or Urdu keyboard) and "Riyadh" can be matched exactly after normalising — never fuzzily.
 */
final class ArabicText
{
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);
        // Diacritics (tashkeel) and tatweel.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
            // The Persian and Urdu forms of the same letters: yeh, kaf and the three hehs.
            'ی' => 'ي', 'ک' => 'ك', 'ھ' => 'ه', 'ہ' => 'ه', 'ە' => 'ه',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // Definite article on the first word: "الرياض" and "رياض" are the same city.
        if (preg_match('/^ال(?=\S{2,})/u', $text) === 1) {
            $text = mb_substr($text, 2);
        }

        return $text;
    }
}
