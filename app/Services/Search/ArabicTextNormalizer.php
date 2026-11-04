<?php

namespace App\Services\Search;

/**
 * Normalises Arabic text so that spellings a customer treats as identical compare as identical
 * (Phase 2.1).
 *
 * The problem, measured against this store's own catalogue rather than assumed: a product is stored
 * as "جوفيلاب حمايه من الشمس". A customer searching for the ordinary spelling "حماية" — with taa
 * marbuta, which is how the word is normally written — gets ZERO results, because a raw
 * LIKE '%…%' treats ه and ة as different letters. The product is there; the customer cannot find it.
 *
 * Arabic has several such pairs that writers use interchangeably, and none of them are typos:
 *
 *   ا أ إ آ ٱ    alef with and without hamza — "احمد" and "أحمد" are the same name
 *   ة ه          taa marbuta vs haa — "حماية" and "حمايه"
 *   ي ى          yaa vs alef maqsura — "علي" and "علیٰ" endings
 *   ؤ و  ئ ي     hamza on waw / yaa
 *   ـ            tatweel, a decorative stretch with no phonetic value
 *   َ ُ ِ ّ ْ ً ٌ ٍ   diacritics, usually omitted when typing
 *
 * Normalisation folds each group to one representative. It is applied to BOTH the stored value and
 * the query, so the comparison is symmetric: neither side has to be spelled "correctly".
 *
 * Deliberately not a stemmer. Arabic stemming (stripping ال, plural forms, verb patterns) changes
 * meaning often enough to lose results, and the failure mode is silent. Character folding is
 * conservative and reversible in intent: it never merges two words a reader would consider
 * different.
 *
 * Latin text passes through lowercased and otherwise untouched, so a mixed catalogue — this one is
 * "MEDEE RETINOL 30 ml" alongside "ميدي ريتينول ٣٠ مل" — works from one code path.
 */
class ArabicTextNormalizer
{
    /** Character folds, applied after diacritics are stripped. */
    private const FOLD = [
        // alef family -> bare alef
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا', 'ٳ' => 'ا',
        // taa marbuta -> haa
        'ة' => 'ه',
        // alef maqsura -> yaa
        'ى' => 'ي', 'ﻯ' => 'ي', 'ی' => 'ي',
        // hamza carriers
        'ؤ' => 'و', 'ئ' => 'ي', 'ء' => '',
        // arabic-indic digits -> latin, so "٣٠ مل" matches "30 مل"
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // eastern arabic-indic (persian/urdu keyboards reach Arabic users too)
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        // persian kaf/yeh, which Arabic readers type interchangeably with the Arabic forms
        'ك' => 'ك', 'ک' => 'ك',
    ];

    /**
     * Diacritics (harakat) and tatweel — decorative marks that carry no distinction for search.
     * Expressed as a character-class range so every combining mark in the block is covered.
     */
    private const STRIP_PATTERN = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u';

    /**
     * Fold a string to its searchable form.
     *
     * Returns '' for null/empty input so callers can store the result in a NOT NULL column without
     * special-casing.
     */
    public function normalize(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        // Strip diacritics and tatweel first: a mark sitting on an alef must not block the fold.
        $text = preg_replace(self::STRIP_PATTERN, '', $text) ?? $text;

        $text = strtr($text, self::FOLD);

        // Case-fold with mb_* — strtolower() would mangle multi-byte input.
        $text = mb_strtolower($text, 'UTF-8');

        // Collapse punctuation and whitespace to single spaces so "vitamin-c" matches "vitamin c".
        // \p{L}\p{N} keeps letters and digits of every script, so Latin and Arabic behave alike.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Normalise a query and return its terms.
     *
     * @return array<int, string> non-empty terms, de-duplicated, order preserved
     */
    public function terms(?string $query): array
    {
        $normalized = $this->normalize($query);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            static fn (string $term): bool => $term !== ''
        )));
    }

    /** Whether the text contains Arabic script — used to decide which fields are worth searching. */
    public function containsArabic(?string $text): bool
    {
        return $text !== null && preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $text) === 1;
    }
}
