<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a wallet sale's debit did not happen, so the sale must not be recorded as paid.
 *
 * `CustomerTrait::createWalletTransaction()` has two ways of taking no money and saying so by returning false: the
 * `wallet_status` business setting being off, and its own save throwing. Both answers were discarded by the two
 * point-of-sale tills, which then wrote an order marked payment_status 'paid' with no wallet_transactions row and the
 * customer's balance untouched — the shop's own books saying a wallet paid for a sale that it did not.
 *
 * Raised inside the sale's transaction, so the order, its lines, the stock taken and the movement rows all roll back
 * with it, exactly as InsufficientStockException does.
 */
class WalletDebitFailedException extends RuntimeException
{
    public function __construct(
        public readonly int|string|null $userId = null,
        public readonly float $amount = 0.0,
    ) {
        parent::__construct(sprintf('The wallet debit of %s for customer %s did not happen.', $this->amount, $this->userId ?? '?'));
    }
}
