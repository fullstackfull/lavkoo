<?php

namespace App\Services\Wallet;

/**
 * The debit rule every wallet writer applies while the customer's row is locked: a debit may not take the balance below zero.
 *
 * Controllers also check the balance before they call a writer, but that check runs outside the lock. Only the check made here, while
 * the row is locked, stops two overlapping debits from both succeeding.
 */
final class WalletOverdraft
{
    /** Balances and debits are stored with up to twelve decimal places. This tolerance absorbs float representation error only. */
    private const TOLERANCE = 0.000001;

    public static function refuses(float $balance, float $debit): bool
    {
        return $debit > 0 && $balance - $debit < -self::TOLERANCE;
    }
}
