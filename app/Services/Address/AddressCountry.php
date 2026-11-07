<?php

namespace App\Services\Address;

use App\Services\Fulfillment\Support\ArabicText;

/**
 * Is a stored or submitted country value Saudi Arabia? The address book has always held the English name ("Saudi Arabia"), and
 * older clients may send a code or an Arabic spelling, so all of those are recognised; what is written back stays the legacy name.
 */
final class AddressCountry
{
    public const LEGACY_NAME = 'Saudi Arabia';

    private const SAUDI = ['sa', 'ksa', 'sau', 'saudi arabia', 'saudiarabia', 'saudi', 'saudia', 'kingdom of saudi arabia', 'السعوديه', 'المملكه العربيه السعوديه', 'السعودية', 'المملكة العربية السعودية'];

    public static function isSaudi(?string $country): bool
    {
        $key = self::compact($country);

        return $key !== '' && in_array($key, array_map(self::compact(...), self::SAUDI), true);
    }

    /** The country spelled without case, diacritics, punctuation or spaces: "K.S.A.", "Saudi-Arabia" and "ksa" are one value. */
    private static function compact(?string $country): string
    {
        return str_replace(' ', '', ArabicText::normalize($country));
    }
}
