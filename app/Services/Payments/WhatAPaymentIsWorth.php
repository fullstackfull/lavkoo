<?php

namespace App\Services\Payments;

use App\Models\Currency;

/**
 * What a payment a gateway took in some currency is worth in the unit this platform STORES money in (E-V1, finding
 * "a 100 riyal top-up reads back as 375").
 *
 * The stored unit is the dollar: `usdToDefaultCurrency()` multiplies a stored figure by `default_rate / usd_rate` to
 * show it, and `usdToAnotherCurrencyConverter()` multiplies it by `code_rate / usd_rate` to charge it. The inverse of
 * both is therefore `amount * usd_rate / rate_of_the_currency_paid`, and that is the one arithmetic every other
 * conversion on the platform agrees with.
 *
 * `Convert::usdPaymentModule()` does not do that. For a non-USD default currency it converts the payment into the
 * DEFAULT currency and stops, so its answer is `default_rate` times the stored figure -- 3.75 times, on a Saudi store.
 * Measured end to end in `tests/Feature/Wallet/ATopUpCreditsWhatWasPaidTest.php` and
 * `tests/Feature/Payments/WhatAPaymentIsWorthTest.php`: under `multi_currency` with the riyal at 3.75, a customer who
 * pays 100 riyals into the wallet has 100 stored where 26.67 is what 100 riyals is worth, and every screen that puts a
 * stored figure back into riyals then shows 375. The customer paid 100 and the account reads 375.
 *
 * **Which figure is right is not a coding decision, and this class does not take it.** Correcting the conversion
 * changes what a stored unit is worth, which is the same question as open financial decision 1 (what a loyalty point is
 * worth), and it reaches every caller of `usdPaymentModule`: the wallet top-up's credit, the amount a top-up hands the
 * gateway, the minimum and maximum top-up checks, and the price-range filter of the catalogue on the website and in the
 * seller API. So both formulas live here and the setting ships as today's.
 *
 *  - {@see AS_TODAY} -- **the default, and what ships**: `usdPaymentModule`'s own arithmetic, branch for branch,
 *    returning the same digits for every input. Nothing moves.
 *  - {@see THE_RATE_OF_THE_CURRENCY_PAID} -- the correction: `amount * usd_rate / rate_paid`, the exact inverse of the
 *    conversion that charged the gateway. On a Saudi store a 100 riyal top-up then stores 26.67 and reads back as 100.
 *
 * Turning it on is the owner's call, and it is not only a code change: a platform that has been running
 * `multi_currency` has balances stored at the old reading, and what happens to those is part of the decision. Read
 * `docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 4 before switching it.
 */
final class WhatAPaymentIsWorth
{
    /** `Convert::usdPaymentModule`'s own arithmetic: the platform exactly as it runs today. */
    public const AS_TODAY = 'as_today';

    /** The correction: the payment at the rate of the currency it was taken in, which is what charged it. */
    public const THE_RATE_OF_THE_CURRENCY_PAID = 'the_rate_of_the_currency_paid';

    /** What the configuration says, falling back to today's behaviour for anything it does not recognise: a typo must not move money. */
    public static function rule(): string
    {
        $named = (string) config('gcc.payments.what_a_payment_is_worth');

        return $named === self::THE_RATE_OF_THE_CURRENCY_PAID ? $named : self::AS_TODAY;
    }

    /**
     * The payment in the unit a balance and a price are stored in.
     *
     * @param float|int|string|null $amount the figure the gateway took
     * @param string|null $currency the code it took it in
     */
    public static function storedUnitsFor(float|int|string|null $amount, ?string $currency): float|int|null
    {
        $currency = strtoupper(trim((string) $currency));

        if (getWebConfig(name: 'currency_model') != 'multi_currency') {
            return floatval($amount);
        }

        return self::rule() === self::THE_RATE_OF_THE_CURRENCY_PAID
            ? self::atTheRateOfTheCurrencyPaid($amount, $currency)
            : self::asTheModuleHasAlwaysDoneIt($amount, $currency);
    }

    /**
     * The correction. One expression, and it is the inverse of `usdToAnotherCurrencyConverter()`, which is the function
     * that turned the stored figure into the amount the gateway was asked for in the first place.
     *
     * A code the currency table does not carry keeps the module's own fallback of a rate of 1, so an unknown currency
     * cannot divide by zero and cannot silently become free.
     */
    private static function atTheRateOfTheCurrencyPaid(float|int|string|null $amount, string $currency): float|int
    {
        $usdRate = floatval(Currency::where(['code' => 'USD'])->first()->exchange_rate ?? 1);
        $paidRate = floatval(Currency::where(['code' => $currency])->first()->exchange_rate ?? 1);

        if ($paidRate == 0.0) {
            return floatval($amount);
        }

        return floatval($amount) * $usdRate / $paidRate;
    }

    /**
     * `Convert::usdPaymentModule`'s body as it stood, moved here unchanged so that the default is provably the same
     * platform. The `>=` / `<=` pairs look redundant and are not quite -- they are how the function has always decided
     * which way round to divide -- so they are kept rather than simplified: this branch is a record of behaviour, not
     * code to improve.
     */
    private static function asTheModuleHasAlwaysDoneIt(float|int|string|null $amount, string $currency): float|int|null
    {
        if ($currency == 'USD') {
            return floatval($amount);
        }

        $default = Currency::find(getWebConfig(name: 'system_default_currency'));
        $currentCurrency = Currency::where(['code' => $currency])->first()->exchange_rate ?? 1;

        if ($default['code'] == 'USD') {
            return floatval($amount) / $currentCurrency;
        }
        $rate = $default['exchange_rate'] >= $currentCurrency ? ($default['exchange_rate'] / $currentCurrency) : ($currentCurrency / $default['exchange_rate']);
        $defaultAmount = $default['exchange_rate'] <= $currentCurrency ? floatval($amount) / floatval($rate) : floatval($amount) * floatval($rate);
        $usdRate = Currency::where(['code' => 'USD'])->first()->exchange_rate;

        return floatval($default['exchange_rate']) >= floatval($usdRate) ? floatval($defaultAmount) * floatval($usdRate) : floatval($defaultAmount) / floatval($usdRate);
    }
}
