<?php

namespace App\Services\Loyalty;

use App\Models\BusinessSetting;
use App\Utils\Convert;

/**
 * The one place that knows what a loyalty point is worth in wallet money, and the three answers it can give (E-14, finding LF-1).
 *
 * The platform has **two** formulas for the same conversion, and they disagree. For 100 points with `loyalty_point_exchange_rate` set to
 * 100 and the riyal at 3.75 to the dollar, the mobile apps credit 1.00 stored unit -- one dollar, 3.75 riyals -- and the website credits
 * 0.267 -- one riyal. The app pays **3.75 times** what the website pays for the same points, and the factor is the dollar rate of the
 * store's own currency, so it is 3.67 in the Emirates and 0.31 in Kuwait. Both formulas are older than this class; neither is a mistake
 * somebody made, they are two readings of the same setting that were never reconciled.
 *
 * **Which reading is right is the owner's, and this class does not answer it.** What it does is make the answer a one-line change instead
 * of a hunt through four call sites, and make each candidate answer testable before it is adopted:
 *
 *  - {@see AS_EACH_PATH_DOES_TODAY} -- **the default, and what ships**: each path gets the formula it has always used, to the digit. No
 *    customer's credit moves, no test of today's behaviour changes, and the divergence stays visible instead of being resolved by accident.
 *  - {@see ONE_DOLLAR_PER_RATE} -- option A: `rate` points buy one US dollar's worth, which is what the apps pay and what the admin field's
 *    own label promises ("Equivalent Points Needed to Redeem {1 USD in the default currency}"). Adopting it makes the website 3.75× more
 *    generous on a Saudi store.
 *  - {@see ONE_UNIT_IN_FORCE_PER_RATE} -- option B: `rate` points buy one unit of the currency in force, which is what the website pays.
 *    Adopting it makes the apps 3.75× less generous, on money customers have already been shown.
 *
 * Reading `docs/gcc/OPEN-FINANCIAL-DECISIONS.md` section 1 is how the choice is made; nothing here may be switched on without it.
 */
final class WhatAPointIsWorth
{
    /** Each path keeps its own formula: the platform exactly as it runs today. */
    public const AS_EACH_PATH_DOES_TODAY = 'as_each_path_does_today';

    /** Option A: the rate counts points to one US dollar, which is the stored unit. */
    public const ONE_DOLLAR_PER_RATE = 'one_dollar_per_rate';

    /** Option B: the rate counts points to one unit of the currency in force. */
    public const ONE_UNIT_IN_FORCE_PER_RATE = 'one_unit_in_force_per_rate';

    /** The path asking, because while the platform keeps two formulas the answer depends on who is asking. */
    public const WEB = 'web';

    public const API = 'api';

    /** What the configuration says, falling back to today's behaviour for anything it does not recognise: a typo must not move money. */
    public static function rule(): string
    {
        $named = (string) config('gcc.loyalty.point_value');

        return in_array($named, [self::ONE_DOLLAR_PER_RATE, self::ONE_UNIT_IN_FORCE_PER_RATE], true) ? $named : self::AS_EACH_PATH_DOES_TODAY;
    }

    /**
     * The wallet credit these points buy, in the unit the balance is stored in.
     *
     * @param string $path self::WEB or self::API -- which formula the caller has always used
     */
    public static function creditFor(float $points, string $path): float
    {
        $rule = self::rule();

        if ($rule === self::ONE_DOLLAR_PER_RATE) {
            return self::oneDollarPerRate($points);
        }

        if ($rule === self::ONE_UNIT_IN_FORCE_PER_RATE) {
            return self::oneUnitInForcePerRate($points);
        }

        return $path === self::API ? self::oneDollarPerRate($points) : self::oneUnitInForcePerRate($points);
    }

    /**
     * The API's formula: the points over the rate, in dollars, converted to the store's default currency and stored.
     *
     * `Convert::default(1)` is one dollar in the store's currency, so this is "points / rate dollars' worth".
     */
    private static function oneDollarPerRate(float $points): float
    {
        $rate = BusinessSetting::where('type', 'loyalty_point_exchange_rate')->first()->value;

        return ($points / $rate) * Convert::default(1);
    }

    /** The website's formula: the points over the rate in the currency in force, taken back to the unit the balance is stored in. */
    private static function oneUnitInForcePerRate(float $points): float
    {
        return (float) localToDefaultCurrency(amount: (float) loyaltyPointToLocalCurrency(amount: $points));
    }
}
