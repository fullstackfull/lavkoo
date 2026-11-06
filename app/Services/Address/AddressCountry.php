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

    private const SAUDI = ['sa', 'ksa', 'sau', 'saudi arabia', 'saudiarabia', 'saudi', 'kingdom of saudi arabia', 'السعوديه', 'المملكه العربيه السعوديه', 'السعودية', 'المملكة العربية السعودية'];

    public static function isSaudi(?string $country): bool
    {
        $text = mb_strtolower(trim((string) $country));
        if ($text === '') {
            return false;
        }

        return in_array($text, self::SAUDI, true) || in_array(ArabicText::normalize($text), array_map([ArabicText::class, 'normalize'], self::SAUDI), true);
    }
}
