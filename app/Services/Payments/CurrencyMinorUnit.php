<?php

namespace App\Services\Payments;

use App\Services\Storefront\StorefrontCurrency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How many of a currency's smallest coins one unit of it is, and what an amount of money comes to in them (D2-V1).
 *
 * A gateway is never told an amount in riyals or dinars: it is told an integer number of the currency's smallest coin, and it is the
 * SENDER that must know how many of those go into one. Every outbound amount on this platform multiplies by 100 -- the riyal's, the
 * dirham's, the Qatari riyal's and the dollar's answer -- and three of the six markets price in thousandths: 1 KWD is 1,000 fils, 1 BHD
 * 1,000 fils and 1 OMR 1,000 baisa (ISO 4217, and `gcc.storefronts.currency.decimals` says so). An order of 10.234 KWD sent as
 * `round(10.234 * 100)` is 1,023 fils: the customer is charged 1.023 dinars for a basket of 10.234, and the shop collects a tenth of
 * what it sold.
 *
 * **Why the confirmation reads this too, and why it must move in the same step.** `GatewayConfirmations` asks whether the amount a
 * gateway says it took is the payment's, and it asked that question by multiplying the stored amount by 100 as well. The two halves are
 * one rule: charge 10,234 fils and check against 1,023 and no three-decimal payment would ever settle -- money taken by the gateway with
 * the order never placed, which is worse than the undercharge it was meant to fix. So there is one authority and one switch, and both
 * sides read it.
 *
 * **The switch ships OFF** (`gcc.payments.amount_in_the_currencys_minor_unit`). While it is off every amount is `round($amount * 100)`,
 * produced by PHP's own `round()` rather than reproduced, so the figure and its type are identical by construction and not by an argument
 * about which rounding mode matches which -- that is what {@see asItAlwaysWas} is for and it is the first thing the tests assert. SAR,
 * AED, QAR and USD are 100 whatever the switch says, because those currencies have two places, so Saudi Arabia, the United Arab Emirates
 * and Qatar do not move either way.
 *
 * How many places a currency has is not decided here: it comes from {@see StorefrontCurrency::decimalsOf()}, the one authority on it,
 * which the provider shipments, the Odoo postings, the wallet debit and the gateway refunds all read, and which answers two for every
 * currency it is not told about. An amount whose currency is not recorded is two places as well: guessing a scale for money nobody wrote
 * the currency of is how a riyal figure would be charged as a dinar one.
 *
 * Rounding is HALF_UP, which is what `round()` does to a positive amount, because this is the figure the customer is CHARGED and not a
 * cap: taking it down would undercharge, and the over-refund guard that rounds only downwards lives in {@see \App\Services\MyFatoorah\RefundMoney}
 * where a ceiling is what is being computed.
 *
 * What this deliberately does NOT reach: `App\Services\MyFatoorah\Money`, which is two-decimal by definition and decides whether a
 * payment is ACCEPTED, and the MyFatoorah initiation and verification paths that read it. An answer that is wrong by a halala there
 * releases an order that was underpaid -- a larger money change than this one, with its own evidence to gather.
 */
final class CurrencyMinorUnit
{
    /** The switch. Off as it ships: every amount is counted in hundredths, for every currency, exactly as it always has been. */
    public const SWITCH = 'gcc.payments.amount_in_the_currencys_minor_unit';

    /** The smallest coins in one unit of a currency with two places, and what every outbound amount was multiplied by before this existed. */
    public const AS_IT_ALWAYS_WAS = 100;

    /** What a currency with no recorded places is counted at: two, the same answer `StorefrontCurrency` gives a currency it is not told about. */
    private const TWO_PLACES = 2;

    /**
     * Decimals kept while a binary float is turned into an exact decimal.
     *
     * Ten, which is past what a double can carry for an amount of money, so the conversion neither invents a digit nor rounds one away
     * before the currency's own scale is applied.
     */
    private const FLOAT_DIGITS = 10;

    /** How many of this currency's smallest coins make one unit of it: 100 while the switch is off, and 1,000 for the dinar when it is on. */
    public static function inOneUnitOf(?string $currencyCode): int
    {
        if (!self::exactHandlingIsOn()) {
            return self::AS_IT_ALWAYS_WAS;
        }

        return 10 ** self::places($currencyCode);
    }

    /**
     * What this amount comes to in the currency's smallest coin, as the figure a gateway is given.
     *
     * The type is deliberately `int|float`: while the switch is off this is `round()`'s own float, the value and the type a gateway has
     * always been handed, and a shape change is a change.
     */
    public static function of(float|int|string|null $amount, ?string $currencyCode): int|float
    {
        if (!self::exactHandlingIsOn()) {
            return self::asItAlwaysWas($amount);
        }

        return self::decimal($amount)
            ->multipliedBy(self::inOneUnitOf($currencyCode))
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    /** The places this currency is counted at: its own while the switch is on, two otherwise. */
    public static function places(?string $currencyCode): int
    {
        if (!self::exactHandlingIsOn()) {
            return self::TWO_PLACES;
        }

        $code = trim((string) $currencyCode);

        return $code === '' ? self::TWO_PLACES : app(StorefrontCurrency::class)->decimalsOf($code);
    }

    private static function exactHandlingIsOn(): bool
    {
        return filter_var(config(self::SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** `round($amount * 100)`, which is what every outbound amount was and what it still is while the switch is off. */
    private static function asItAlwaysWas(float|int|string|null $amount): float
    {
        return round((float) $amount * self::AS_IT_ALWAYS_WAS);
    }

    private static function decimal(float|int|string|null $amount): BigDecimal
    {
        if ($amount === null || $amount === '') {
            return BigDecimal::zero();
        }

        return BigDecimal::of(is_float($amount)
            ? number_format($amount, self::FLOAT_DIGITS, '.', '')
            : (string) $amount);
    }
}
