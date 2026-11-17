<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside a sale's transaction when the wallet writer refuses the debit: the balance no longer covers it once the customer's row
 * is locked, the wallet is switched off, or the customer is gone.
 *
 * The balance check a caller makes first runs outside the lock, so a concurrent debit can still drain the wallet before this one. Raising
 * this exception rolls back everything the sale already wrote, so the sale is either complete or leaves nothing behind.
 */
class WalletDebitRefusedException extends RuntimeException
{
    public function __construct(public readonly int|string $customerId)
    {
        parent::__construct("The wallet debit of customer #{$customerId} was refused.");
    }
}
