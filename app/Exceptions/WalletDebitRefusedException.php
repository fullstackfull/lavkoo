<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a wallet sale cannot take the money it is about to charge for.
 *
 * It exists for the same reason `InsufficientStockException` does: the refusal has to happen *inside* the
 * order transaction, so the rollback takes the debit, the stock and the order rows with it. A controller
 * cannot `return` a response from inside that transaction, so the refusal travels out as an exception and
 * the caller turns it back into the answer the seller app already understands.
 *
 * Two shapes reach it, and the log says which: the balance is short, or the wallet writer refused the write
 * (the wallet add-on is off, or its own save failed). Both leave the sale unmade; neither may leave an order
 * stored as paid.
 */
class WalletDebitRefusedException extends RuntimeException
{
    public const REASON_INSUFFICIENT_BALANCE = 'insufficient_balance';
    public const REASON_WRITE_REFUSED = 'write_refused';

    public function __construct(
        public readonly int|string|null $customerId = null,
        public readonly float $amount = 0.0,
        public readonly string $reason = self::REASON_INSUFFICIENT_BALANCE,
    ) {
        parent::__construct(sprintf(
            'Wallet debit refused for customer %s (%s, amount %s).',
            $customerId ?? '?',
            $reason,
            number_format($amount, 2, '.', '')
        ));
    }
}
