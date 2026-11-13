<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown inside the order transaction when the wallet writer refuses the debit that pays for the order, so the order, its items
 * and its stock movements roll back with it. A paid order is never committed without the debit that paid for it.
 */
final class WalletPaymentRefused extends RuntimeException
{
    public function __construct(
        public readonly int|string $customerId,
        public readonly float $amount,
    ) {
        parent::__construct(sprintf('Wallet payment of %s refused for customer #%s.', $amount, $customerId));
    }
}
