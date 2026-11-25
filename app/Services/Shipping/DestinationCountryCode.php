<?php

namespace App\Services\Shipping;

use App\Services\Address\AddressCountry;

/**
 * The two-letter code a country value names, wherever the shipping engine reads one.
 *
 * It has to exist because the values do not agree, and nothing in the engine can work until they do. A product's market is stored as a
 * code (`SA`); the address book has always stored the English NAME ("Saudi Arabia"); a provider's configuration and a duty rule are
 * written by an operator as codes. Comparing a name with a code says "these are different countries", so an order a customer places in
 * Riyadh for delivery in Riyadh reads as crossing a border -- and is then refused for want of an export permission.
 *
 * That is not a hypothetical: it is what the engine did before this existed, and the scenario matrix of H1-13 is what showed it. Nothing
 * broke in production because the engine ships off, but it would have refused every order on the day it was switched on.
 *
 * `AddressCountry` already knows how to read those spellings, so this adds no table of its own: it passes a value that is already a code
 * straight through and asks `AddressCountry::anyCountryIsoCode()` about anything else. A value it cannot recognise answers null, which
 * every caller in the engine treats as "nobody said where this is going" -- the safe direction.
 */
final class DestinationCountryCode
{
    /** The code, or null when the value names no country the platform can recognise. */
    public static function of(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        // Already a code: the markets of products, the routes of providers and the duty rules are all written this way, and a two-letter
        // value is never a country's name.
        if (strlen($trimmed) === 2 && ctype_alpha($trimmed)) {
            return strtoupper($trimmed);
        }

        return AddressCountry::anyCountryIsoCode($trimmed);
    }
}
