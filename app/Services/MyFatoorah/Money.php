<?php

namespace App\Services\MyFatoorah;

/**
 * Exact comparison of two money amounts.
 *
 * Deliberately not `==` on floats: 150.00 read back from the database and 150.00 parsed out of JSON are
 * not reliably the same float, and an amount check that is occasionally wrong is worse than none — it
 * would either release orders that were underpaid or flag correct payments as mismatched.
 *
 * Deliberately not bcmath either: `bccomp` needs an extension that is not guaranteed to be loaded, and
 * a payment check that throws because of a missing extension fails in the worst possible place. SAR has
 * two decimal places, so the amounts are compared as whole halalas in integer arithmetic, which is
 * exact on every build of PHP.
 */
final class Money
{
    /** Are two amounts the same, to the halala? */
    public static function equal(string|float|int|null $left, string|float|int|null $right): bool
    {
        return self::minorUnits($left) === self::minorUnits($right);
    }

    /** Is $left greater than $right, by more than a halala? */
    public static function greaterThan(string|float|int|null $left, string|float|int|null $right): bool
    {
        return self::minorUnits($left) > self::minorUnits($right);
    }

    /** Normalise to a string with exactly two decimals, which is how amounts are stored. */
    public static function format(string|float|int|null $amount): string
    {
        return number_format(self::minorUnits($amount) / 100, 2, '.', '');
    }

    /**
     * The amount in whole halalas.
     *
     * `round()` before the integer cast matters: (int) (1.15 * 100) is 114 on a binary float, because
     * 1.15 is stored slightly below 1.15. That would read a correct payment as one halala short.
     */
    private static function minorUnits(string|float|int|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }
}
