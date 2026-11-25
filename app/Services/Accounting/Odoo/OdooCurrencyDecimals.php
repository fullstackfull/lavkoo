<?php

namespace App\Services\Accounting\Odoo;

use App\Services\Storefront\StorefrontCurrency;

/**
 * How many decimal places an amount is written at on its way into Odoo, and when it is refused instead (H2-11).
 *
 * `OdooMoney` has always taken a `decimals` argument and `OdooDraftBuilder` never passed one, so every figure was written at TWO places.
 * For the riyal that is right. For the Kuwaiti dinar, the Bahraini dinar and the Omani rial it is wrong: ISO 4217 gives all three THREE,
 * so a posting of 12.345 KWD went into somebody's books as 12.35 and the fils disappeared at the one step nobody is watching.
 *
 * Two sources have to agree before a figure is written:
 *
 *  - **this platform**, which already knows the answer in one place: `gcc.storefronts.currency.decimals` through
 *    {@see StorefrontCurrency::decimalsOf()}, the same reader the storefronts, the channels and the gateways use. A second spelling of
 *    "the dinar has three" is how two subsystems come to round differently;
 *  - **the instance**, which reports `decimal_places` per currency where it reports anything at all. Odoo's reference has no
 *    `res.currency` page, so the field is read and not assumed, and "it said nothing" is its own case.
 *
 * The rule, and each branch is a different kind of safety:
 *
 *  1. the instance reports FEWER places than the currency has ⇒ **refused by name**. Odoo would round the figure by its own rule at a
 *     moment nobody is watching, which is precisely what this integration refuses to let happen to a foreign figure it cannot carry;
 *  2. the instance says nothing AND the currency has more than two places ⇒ **refused by name**. A three-decimal figure written into a
 *     field nobody has proved holds three is the same silent rounding, arrived at by omission;
 *  3. otherwise ⇒ the PLATFORM's number. Never the instance's, even when it reports more: a currency has the places it has, and writing
 *     a riyal at four would invite a figure with no meaning.
 *
 * **The Saudi platform is untouched by all of it.** SAR has two places on both sides, so branch 3 answers 2 whether or not the instance
 * reports anything — which is exactly what was written before this class existed.
 */
final class OdooCurrencyDecimals
{
    /** What a monetary field holds for a currency with two decimal places, and what every amount was written at before this. */
    public const TWO = 2;

    /** @return int|string the decimal places to write at, or the refusal code when the two sources cannot be reconciled */
    public static function for(OdooPreflight $preflight, ?string $code): int|string
    {
        $currency = mb_strtoupper(trim((string) $code));

        if ($currency === '') {
            return self::TWO;
        }

        $ours = app(StorefrontCurrency::class)->decimalsOf($currency);
        $theirs = $preflight->decimalsFor($currency);

        if ($theirs === null) {
            return $ours > self::TWO ? 'odoo_currency_decimals_were_not_read:' . $currency : $ours;
        }

        return $theirs < $ours ? 'odoo_currency_decimals_are_too_few:' . $currency : $ours;
    }
}
