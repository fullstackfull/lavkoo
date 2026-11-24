<?php

namespace App\Services\Fulfillment\Support;

/**
 * A customer's mobile number in the national form the courier of their own country expects (C-15).
 *
 * Fulfilment knows one phone rule: a Saudi mobile, written 05XXXXXXXX, which is the form both providers' documentation shows
 * ({@see SaudiPhone}). A number that does not match is not sent at all — the shipment is held for correction rather than given to a
 * courier who cannot call the customer. That is right for Saudi Arabia and wrong for the other five markets, where it would hold **every**
 * order: a Kuwaiti mobile is eight digits and has no trunk zero, so it can never match a Saudi pattern.
 *
 * So the rule is per country, read from `gcc.storefronts.phone`, and the Saudi rule is the one that already shipped.
 *
 * **What is checked, and what deliberately is not.** The country's dialling code and the length of its national number are facts that do
 * not move: eight digits in Kuwait, Bahrain, Qatar and Oman, nine beginning with a five in Saudi Arabia and the Emirates. Which *prefixes*
 * inside that length are mobile does move — regulators allocate new ranges, and number portability means a prefix no longer tells you the
 * operator (Kuwait's CITRA plan and Bahrain's TRA blocks both say so) — so a prefix list is **configuration an operator can correct**, not
 * a fact compiled into the platform. Where no pattern is configured, a number of the right length for its country is accepted; where one
 * is, it must match. Saudi Arabia ships with its pattern, which is why nothing about a Saudi shipment changes.
 */
final class MarketPhone
{
    /** What the configuration ships as the default storefront, and the answer when it names none at all. */
    private const COUNTRY_OF_LAST_RESORT = 'SA';

    /**
     * The number as the courier of this country should receive it, or null when it is not a number of that country.
     *
     * Null is a refusal, and the caller holds the shipment on it. A country the configuration says nothing about is also a refusal: the
     * platform does not guess a dialling code.
     */
    public static function nationalNumberFor(?string $country, ?string $raw): ?string
    {
        $rule = self::ruleFor($country);

        if ($rule === null || $raw === null) {
            return null;
        }

        $digits = self::withoutTheCountryCode(digits: self::asciiDigits($raw), countryCode: $rule['country_code']);

        if (!self::answersTheRule($digits, $rule)) {
            return null;
        }

        return $rule['trunk_zero'] ? '0' . $digits : $digits;
    }

    /**
     * The same number in the international form a provider's documentation asks for: 00, the market's dialling code, then the national
     * number without the trunk zero of the markets that write one.
     *
     * For Saudi Arabia that is `00966` and the national number without its leading zero, which is exactly what the Torod builder did with
     * the two written into it. A market with no trunk zero keeps every digit it has: cutting the first one off a Kuwaiti number would give
     * the courier seven digits of an eight-digit number.
     */
    public static function internationalForm(?string $country, string $national): string
    {
        $rule = self::ruleFor($country);

        if ($rule === null || $national === '') {
            return $national;
        }

        $digits = $rule['trunk_zero'] && str_starts_with($national, '0') ? substr($national, 1) : $national;

        return '00' . $rule['country_code'] . $digits;
    }

    /** The dialling code of a market, without its plus, or null when the configuration names none. */
    public static function diallingCodeOf(?string $country): ?string
    {
        return self::ruleFor($country)['country_code'] ?? null;
    }

    /** @return array{country_code: string, national_length: int, trunk_zero: bool, pattern: ?string}|null */
    private static function ruleFor(?string $country): ?array
    {
        $code = strtoupper(trim((string) $country));

        if ($code === '') {
            $code = self::defaultCountry();
        }

        $rule = config('gcc.storefronts.phone')[$code] ?? null;

        if (!is_array($rule) || !isset($rule['country_code'], $rule['national_length'])) {
            return null;
        }

        return [
            'country_code' => (string) $rule['country_code'],
            'national_length' => (int) $rule['national_length'],
            'trunk_zero' => (bool) ($rule['trunk_zero'] ?? false),
            'pattern' => isset($rule['pattern']) && is_string($rule['pattern']) && $rule['pattern'] !== '' ? $rule['pattern'] : null,
        ];
    }

    /** The national part of a number written in any of the forms a customer types: +965..., 00965..., 0965..., 965... or the number itself. */
    private static function withoutTheCountryCode(string $digits, string $countryCode): string
    {
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, $countryCode)) {
            $digits = substr($digits, strlen($countryCode));
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    /** @param array{national_length: int, pattern: ?string} $rule */
    private static function answersTheRule(string $digits, array $rule): bool
    {
        if ($rule['pattern'] !== null) {
            return preg_match($rule['pattern'], $digits) === 1;
        }

        return strlen($digits) === $rule['national_length'] && !str_starts_with($digits, '0');
    }

    /**
     * The market a shipment that names none is read by: the default storefront.
     *
     * Read from the configuration rather than through `StorefrontResolver`, which refuses to be built on a default it cannot serve -- a
     * refusal that belongs where a storefront is resolved, not where a phone number is read. A shipment is never held because the default
     * storefront is misconfigured.
     */
    private static function defaultCountry(): string
    {
        $configured = strtoupper(trim((string) config('gcc.storefronts.default_country')));

        return $configured === '' ? self::COUNTRY_OF_LAST_RESORT : $configured;
    }

    /** The digits of a spelling, Arabic-Indic, Persian and full-width ones included, as ASCII digits. */
    private static function asciiDigits(string $raw): string
    {
        $ascii = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);

        return preg_replace('/\D+/', '', $ascii) ?? '';
    }
}
