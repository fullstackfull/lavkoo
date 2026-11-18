<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown inside a return-credit transaction when the wallet writer refuses the credit, so the order's return amount is not cleared
 * for money that never reached the customer's wallet.
 */
final class WalletCreditRefused extends RuntimeException
{
    public function __construct(
        public readonly int|string $customerId,
        public readonly float $amount,
    ) {
        parent::__construct(sprintf('Wallet credit of %s refused for customer #%s.', $amount, $customerId));
    }
}
