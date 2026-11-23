<?php

namespace App\Enums\Wallet;

/**
 * The two balances a customer holds in a market (GCC-15): the money of their wallet and the points of their loyalty account. Each one has
 * its own account table and its own ledger, and the column of the `users` row that held it before the accounts existed, and still holds it
 * while `gcc.storefronts.market_accounts.enabled` is off.
 */
enum MarketBalanceKind: string
{
    /** The column on both ledgers that names the market a movement belongs to. Empty on every row written before the markets existed. */
    public const MARKET_COLUMN = 'storefront_country';

    case Wallet = 'wallet';

    case Loyalty = 'loyalty';

    /** The one global column the platform keeps today. It stays the authority until a market is switched over (E-07). */
    public function legacyColumn(): string
    {
        return match ($this) {
            self::Wallet => 'wallet_balance',
            self::Loyalty => 'loyalty_point',
        };
    }

    public function accountsTable(): string
    {
        return match ($this) {
            self::Wallet => 'customer_market_wallet_accounts',
            self::Loyalty => 'customer_market_loyalty_accounts',
        };
    }

    /** The column of the account table that holds what the account is worth. */
    public function amountColumn(): string
    {
        return match ($this) {
            self::Wallet => 'balance',
            self::Loyalty => 'points',
        };
    }

    /** The append-only ledger the movements of this balance are written to. */
    public function ledgerTable(): string
    {
        return match ($this) {
            self::Wallet => 'wallet_transactions',
            self::Loyalty => 'loyalty_point_transactions',
        };
    }

    /**
     * The columns of the ledger that ADD to the balance. A wallet add-fund puts its bonus in `admin_bonus`, which
     * `App\Utils\CustomerManager::create_wallet_transaction` adds to `users.wallet_balance` without adding it to `credit`, so a net of
     * credit alone is short by every bonus ever given. A loyalty ledger has no such column.
     *
     * This restates `App\Services\Wallet\Reconciliation\LedgerSpec::wallet()` on the branch gcc/p1-wallet-reconcile-command, whose net
     * expression is `credit + admin_bonus - debit`; when the branches meet, this method is where the two agree.
     *
     * @return list<string>
     */
    public function ledgerCreditColumns(): array
    {
        return match ($this) {
            self::Wallet => ['credit', 'admin_bonus'],
            self::Loyalty => ['credit'],
        };
    }
}
