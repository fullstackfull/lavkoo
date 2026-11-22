<?php

namespace App\Services\Address;

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
    public const GCC_SPELLINGS = [
        'AE' => ['ae', 'are', 'uae', 'u.a.e.', 'united arab emirates', 'emirates', 'الامارات', 'الإمارات', 'الامارات العربية المتحدة', 'الإمارات العربية المتحدة', 'دولة الإمارات العربية المتحدة'],
        'KW' => ['kw', 'kwt', 'kuwait', 'state of kuwait', 'الكويت', 'دولة الكويت'],
        'QA' => ['qa', 'qat', 'qatar', 'state of qatar', 'قطر', 'دولة قطر'],
        'BH' => ['bh', 'bhr', 'bahrain', 'kingdom of bahrain', 'البحرين', 'مملكة البحرين'],
        'OM' => ['om', 'omn', 'oman', 'sultanate of oman', 'عمان', 'سلطنة عمان', 'عُمان'],
    ];

    private const SAUDI = ['sa', 'ksa', 'sau', 'saudi arabia', 'saudiarabia', 'saudi', 'saudia', 'kingdom of saudi arabia', 'السعوديه', 'المملكه العربيه السعوديه', 'السعودية', 'المملكة العربية السعودية'];

    public static function isSaudi(?string $country): bool
    {
        $key = self::compact($country);

        return $key !== '' && in_array($key, array_map(self::compact(...), self::SAUDI), true);
    }

    /**
     * The ISO 3166-1 alpha-2 code of a stored or submitted country value, or null when it names none of the six markets.
     *
     * Spelled every way the address book and the older clients might hold it: the code, the English name, the Arabic name.
     * Saudi Arabia is answered from SAUDI, so the two methods can never disagree about what is Saudi.
     */
    public static function isoCode(?string $country): ?string
    {
        if (self::isSaudi($country)) {
            return 'SA';
        }

        $key = self::compact($country);
        if ($key === '') {
            return null;
        }

        foreach (self::GCC_SPELLINGS as $code => $spellings) {
            foreach ($spellings as $spelling) {
                if (self::compact($spelling) === $key) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** The country spelled without case, diacritics, punctuation or spaces: "K.S.A.", "Saudi-Arabia" and "ksa" are one value. */
    private static function compact(?string $country): string
    {
        return str_replace(' ', '', ArabicText::normalize($country));
    }
}
