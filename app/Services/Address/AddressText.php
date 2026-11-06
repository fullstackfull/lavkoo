<?php

namespace App\Services\Address;

use App\Services\Fulfillment\Support\ArabicText;

/**
 * The comparison key for place names written by different people in Arabic or English.
 *
 * It builds on ArabicText (letter variants, diacritics, digits, the Arabic article) and removes what only decorates a Saudi place
 * name: a leading "city / governorate / village" word, the transliterated article (Al, Ar, As, Ash, At, Ad, Adh, An, Az) and the
 * spaces between words. It is a SEARCH key and a matching key, never an identifier: two different places can share one key,
 * which is why every automatic match built on it first checks that the key points at exactly one place.
 */
final class AddressText
{
    /** Words that say what kind of place a name is, not which place. */
    private const KIND_WORDS = [
        'مدينه', 'محافظه', 'قريه', 'هجره', 'مركز', 'منطقه',
        'city', 'town', 'village', 'governorate', 'province', 'region', 'district', 'of',
    ];

    private const ENGLISH_ARTICLES = ['al', 'ar', 'as', 'ash', 'at', 'ath', 'ad', 'adh', 'an', 'az', 'el'];

    /** Longest first, so "ash" is tried before "as". */
    private const JOINED_ARTICLES = ['adh', 'ash', 'ath', 'al', 'ar', 'as', 'at', 'ad', 'an', 'az'];

    private const JOINED_ARTICLE_MIN_REST = 4;

    public static function key(?string $text): string
    {
        $normalized = ArabicText::normalize($text);
        if ($normalized === '') {
            return '';
        }

        $words = array_values(array_filter(explode(' ', $normalized), static fn (string $word): bool => $word !== ''));
        $words = self::withoutKindWords($words);
        $words = self::withoutLeadingArticle($words);

        return implode('', $words);
    }

    /**
     * What to look for when someone types a name into a search box: the key, plus the key without a transliterated article that
     * was typed without a space ("Alkhobar"). Only for finding candidates — never for deciding that two places are the same.
     *
     * @return list<string>
     */
    public static function searchKeys(?string $text): array
    {
        $key = self::key($text);
        if ($key === '') {
            return [];
        }

        foreach (self::JOINED_ARTICLES as $article) {
            if (str_starts_with($key, $article) && strlen($key) - strlen($article) >= self::JOINED_ARTICLE_MIN_REST && preg_match('/^[a-z]+$/', $key) === 1) {
                return [$key, substr($key, strlen($article))];
            }
        }

        return [$key];
    }

    /** @param list<string> $words */
    private static function withoutKindWords(array $words): array
    {
        $kept = array_values(array_filter($words, static fn (string $word): bool => !in_array($word, self::KIND_WORDS, true)));

        return $kept === [] ? $words : $kept;
    }

    /** @param list<string> $words */
    private static function withoutLeadingArticle(array $words): array
    {
        if (count($words) < 2) {
            return self::withoutArabicArticle($words);
        }

        if (in_array($words[0], self::ENGLISH_ARTICLES, true)) {
            array_shift($words);
        }

        return self::withoutArabicArticle($words);
    }

    /** @param list<string> $words */
    private static function withoutArabicArticle(array $words): array
    {
        if ($words !== [] && preg_match('/^ال(?=\S{2,})/u', $words[0]) === 1) {
            $words[0] = mb_substr($words[0], 2);
        }

        return $words;
    }
}
