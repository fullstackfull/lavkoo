<?php

namespace App\Services\Accounting\Odoo;

use App\Services\OperationalCosts\Support\Money;
use InvalidArgumentException;

/**
 * Money on its way into an Odoo monetary field — the one conversion in this integration where a rounding slip is a wrong number
 * in somebody's books.
 *
 * `Money` is an exact decimal (BigDecimal at scale 6). Odoo's monetary fields are Python floats, and XML-RPC carries them as
 * `<double>`. So there IS a conversion here and it cannot be avoided; what it must not do is any of the three things a careless
 * one does:
 *
 *  - **become an int.** PHP hands you `18` for `18.0`, and `49` posted where `49.00` was meant is right until the first figure
 *    with halalas in it. The return type is `float`, declared, and a test asserts the encoded XML says `<double>`.
 *  - **become a string.** Odoo would refuse it or coerce it by its own rules, neither of which we chose.
 *  - **carry more precision than the currency has.** `Money` keeps six decimal places because an allocation needs them; a
 *    monetary field holds two for SAR. Sending the unrounded figure makes Odoo round it, by its own rule, at a moment nobody
 *    is watching -- so it is rounded HERE, once, visibly, half-up, the same way `Money::toDisplay()` does it.
 *
 * ## And a negative amount is not a negative debit
 *
 * This is accounting, not arithmetic. An `account.move.line` carries `debit` and `credit`, and **both are non-negative**: the
 * direction is which column the figure sits in, not its sign. A refund, a reversal or a credit note is a positive number in the
 * other column. Writing `debit: -49.00` would be a different statement from `credit: 49.00` -- some tools accept it, the
 * resulting trial balance reads strangely, and a reversal becomes impossible to tell from a mistake. `debitAndCredit()` is the
 * only way this integration fills those two fields, and it never emits a negative.
 */
final class OdooMoney
{
    /** What a monetary field holds for a currency with two decimal places; the only currency this store settles in is SAR. */
    private const DEFAULT_DECIMALS = 2;

    /**
     * One amount for one monetary field, rounded to the currency's precision.
     *
     * @param int $decimals the currency's decimal places, where a currency with other than two is ever configured
     */
    public static function forMonetaryField(Money $amount, int $decimals = self::DEFAULT_DECIMALS): float
    {
        if ($decimals < 0 || $decimals > Money::STORAGE_SCALE) {
            throw new InvalidArgumentException('A currency\'s decimal places must be between 0 and ' . Money::STORAGE_SCALE . '.');
        }

        // Rounded as an exact decimal FIRST and only then made a float: rounding a float would round a value that is already an
        // approximation of the one we meant.
        return (float) $amount->toScale($decimals);
    }

    /**
     * The `debit` and `credit` of one journal line, from one signed amount.
     *
     * A positive amount is a debit, a negative one is a credit of its absolute value, and zero is both zero -- which Odoo accepts
     * and which a line that nets to nothing genuinely is. Exactly one of the two is ever non-zero, and neither is ever negative.
     *
     * @return array{debit: float, credit: float}
     */
    public static function debitAndCredit(Money $amount, int $decimals = self::DEFAULT_DECIMALS): array
    {
        $rounded = $amount->round($decimals);

        return $rounded->isNegative()
            ? ['debit' => 0.0, 'credit' => self::forMonetaryField($rounded->abs(), $decimals)]
            : ['debit' => self::forMonetaryField($rounded, $decimals), 'credit' => 0.0];
    }

    /**
     * The same two fields for the OPPOSITE side of the entry, so a two-line entry balances.
     *
     * Every journal entry this integration builds has at least two lines whose debits and credits are equal, because an
     * unbalanced `account.move` is refused by Odoo -- and rightly. Deriving the counter-line here rather than at each call site
     * means the two can never drift apart by a rounding step.
     *
     * @return array{debit: float, credit: float}
     */
    public static function counterOf(Money $amount, int $decimals = self::DEFAULT_DECIMALS): array
    {
        $side = self::debitAndCredit($amount, $decimals);

        return ['debit' => $side['credit'], 'credit' => $side['debit']];
    }
}
