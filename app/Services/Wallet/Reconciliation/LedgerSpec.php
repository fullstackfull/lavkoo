<?php

namespace App\Services\Wallet\Reconciliation;

/** One ledger that a stored balance must equal the sum of: where its rows are, which user they belong to, and how a row moves the balance. */
final readonly class LedgerSpec
{
    /** @param list<string> $netColumns the columns of the ledger that $netExpression reads */
    public function __construct(
        public string $label,
        public string $table,
        public string $ownerColumn,
        public string $balanceColumn,
        public string $netExpression,
        public array $netColumns,
    ) {
    }

    /** `users.wallet_balance` against the wallet ledger: a credit and the bonus of an add-fund add to the balance, a debit takes from it. */
    public static function wallet(): self
    {
        return new self(label: 'wallet', table: 'wallet_transactions', ownerColumn: 'user_id', balanceColumn: 'wallet_balance', netExpression: 'credit + admin_bonus - debit', netColumns: ['credit', 'admin_bonus', 'debit']);
    }

    /** `users.loyalty_point` against the loyalty ledger. */
    public static function loyalty(): self
    {
        return new self(label: 'loyalty', table: 'loyalty_point_transactions', ownerColumn: 'user_id', balanceColumn: 'loyalty_point', netExpression: 'credit - debit', netColumns: ['credit', 'debit']);
    }
}
