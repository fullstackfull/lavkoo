<?php

namespace App\Services\Address;

use App\Enums\GlobalConstant;
use App\Services\Fulfillment\Support\ArabicText;

/**
 * Is a stored or submitted country value Saudi Arabia? The address book has always held the English name ("Saudi Arabia"), and
 * older clients may send a code or an Arabic spelling, so all of those are recognised; what is written back stays the legacy name.
 *
 * `isoCode` answers the same question for the five other GCC markets, for code that needs to FILE something under a market
 * (a marketing consent, for one) rather than branch on Saudi or not. It reads; it never changes what is written back.
 */
final class AddressCountry
{
    public const LEGACY_NAME = 'Saudi Arabia';

    /**
     * The other five GCC markets, each spelled as a code, in English and in Arabic. Saudi Arabia is NOT here: it stays in
     * SAUDI, which isSaudi() has always read, so adding this list cannot change any existing answer.
     */
    private const SAUDI = ['sa', 'ksa', 'sau', 'saudi arabia', 'saudiarabia', 'saudi', 'saudia', 'kingdom of saudi arabia', 'السعوديه', 'المملكه العربيه السعوديه', 'السعودية', 'المملكة العربية السعودية'];

    public static function isSaudi(?string $country): bool
    {
        $key = self::compact($country);

        return $key !== '' && in_array($key, array_map(self::compact(...), self::SAUDI), true);
    }

    /**
     * The six GCC markets' own spellings, beyond the English names the ISO list already carries.
     *
     * A country reaches this code from three places that do not agree: the address book, which has
     * always held the English name; an older client, which may send a code; and an operator typing a
     * routing rule by hand, who may write either, or Arabic. Saudi Arabia keeps its own list above
     * because it is the live market and carries the most spellings, including "KSA".
     *
     * Oman's Arabic name normalises to the same form as Amman, Jordan's capital. That is a city and
     * this is a country field, so it is left as it is rather than guessed at -- said here so the next
     * reader does not have to find it.
     */
    public const GCC_SPELLINGS = [
        'AE' => ['ae', 'are', 'uae', 'u.a.e.', 'united arab emirates', 'the united arab emirates', 'emirates', 'الامارات', 'الإمارات', 'الامارات العربية المتحدة', 'الإمارات العربية المتحدة', 'دولة الإمارات العربية المتحدة'],
        'KW' => ['kw', 'kwt', 'kuwait', 'state of kuwait', 'الكويت', 'دولة الكويت'],
        'QA' => ['qa', 'qat', 'qatar', 'state of qatar', 'قطر', 'دولة قطر'],
        'BH' => ['bh', 'bhr', 'bahrain', 'kingdom of bahrain', 'البحرين', 'مملكة البحرين'],
        'OM' => ['om', 'omn', 'oman', 'sultanate of oman', 'عمان', 'عُمان', 'سلطنة عمان'],
    ];

    /**
     * The ISO alpha-2 code of one of the SIX MARKETS this platform sells in, or null for anything else.
     *
     * Narrow on purpose, because a market is not a country: a market decides which consent row is read and which
     * unsubscribe link is offered, and an address in Egypt is in none of them, so it has to answer null and let the
     * caller fall back to the home market. Pinned by
     * MarketingMarketTest::test_a_country_outside_the_six_markets_resolves_to_nothing.
     *
     * For the broader question -- "which country is this text, whatever country it is" -- use anyCountryIsoCode(),
     * which the payment routing rules need and which this method must not answer, the two having one name until the
     * two branches that wrote them were merged.
     */
    public static function isoCode(?string $country): ?string
    {
        $key = self::compact($country);
        if ($key === '') {
            return null;
        }

        if (self::isSaudi($country)) {
            return 'SA';
        }

        foreach (self::GCC_SPELLINGS as $code => $spellings) {
            if (in_array($key, array_map(self::compact(...), $spellings), true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The ISO alpha-2 code a stored or submitted country value names, whichever country in the world it is, or null
     * when it names none.
     *
     * Null is not "no country": it is "this text is not a country I can recognise", which a caller must treat
     * differently from a recognised one. A routing rule whose country does not resolve cannot be compared by code at
     * all, and falling back to comparing the text is the only honest thing left -- deciding it matches nothing would
     * silently switch off a rule an operator wrote. That is why this reads the whole ISO list and not just the six
     * markets: see isoCode() for the narrow question.
     */
    public static function anyCountryIsoCode(?string $country): ?string
    {
        $key = self::compact($country);
        if ($key === '') {
            return null;
        }

        if ($code = self::isoCode($country)) {
            return $code;
        }

        foreach (GlobalConstant::COUNTRIES as $row) {
            if ($key === self::compact($row['code'] ?? null) || $key === self::compact($row['name'] ?? null)) {
                return strtoupper((string) $row['code']);
            }
        }

        return null;
    }

    /** Do two country values name the same country? False when either names none that can be recognised. */
    public static function sameCountry(?string $one, ?string $other): bool
    {
        $a = self::anyCountryIsoCode($one);

        return $a !== null && $a === self::anyCountryIsoCode($other);
    }

    /** The country spelled without case, diacritics, punctuation or spaces: "K.S.A.", "Saudi-Arabia" and "ksa" are one value. */
    private static function compact(?string $country): string
    {
        return str_replace(' ', '', ArabicText::normalize($country));
    }
}
