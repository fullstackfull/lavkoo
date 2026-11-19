<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet account and one point account per customer per market (GCC-15, E-01, E-02), and the one column that says which market a ledger
 * row belongs to (E-05). Expand only: the two tables are new and empty, the two columns are nullable with no default, and not one existing
 * row is read or written. Nothing is backfilled here, and no balance moves: `users.wallet_balance` and `users.loyalty_point` stay the
 * authority until a later slice switches a market over (E-07).
 *
 * WHICH STREAM OWNS WHICH LEDGER COLUMN. The full attribution of a ledger row — what it was for, who made it, its storefront, its currency
 * and the selling company — belongs to the E-05 stream, whose migration is
 * 2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers (branch gcc/p1-wallet-ledger-attribution). This one adds nothing of that
 * set but `storefront_country`, which its reconciliation cannot do without, and it is timestamped AFTER that migration so that on a
 * database where both are pending E-05 runs first and this one finds the column already there. It adds no `currency_code` and no selling
 * company to either ledger: E-05 owns `wallet_transactions.currency_code` and `legal_entity_id` on both, and one concept may not have two
 * columns. The rollback here drops a ledger column ONLY when E-05 is not recorded in the `migrations` table, so rolling this migration back
 * on a database that has E-05 never takes away a column that stream's writers are filling.
 *
 * The market is the two-letter country of `gcc.storefronts.countries`, never a name and never the id of another table, so an account
 * survives a storefront being renamed or switched off. The balances are DECIMAL(40,20), the width `users.wallet_balance` and
 * `users.loyalty_point` already have, so a later backfill copies a balance without losing a digit. On MySQL and MariaDB the market is
 * compared byte for byte (ascii_bin), as PHP compares it, like `products.storefront_country` and `orders.storefront_country`.
 *
 * The ledger column is appended, without a position, so MySQL 8 and MariaDB 11.4 add it without rebuilding the table, and it carries no
 * index: the ledgers are the largest tables on the pilot server, and an index goes on only after the plans of a report on real data have
 * been read, exactly as `orders.storefront_country` left it.
 *
 * Running it again does nothing, and it rolls back while it is still dormant. Once a market account exists, or a ledger row carries a
 * column this rollback would drop, the rollback is refused: dropping it would forget money, and that cannot be worked out again.
 */
return new class extends Migration {
    private const WALLET_ACCOUNTS = 'customer_market_wallet_accounts';

    private const LOYALTY_ACCOUNTS = 'customer_market_loyalty_accounts';

    /** The ledgers a market is recorded on. */
    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions'];

    private const MARKET = 'storefront_country';

    private const LEGAL_ENTITY = 'legal_entity';

    /** Every ledger column this migration adds, and so every ledger column its rollback may drop. */
    private const LEDGER_COLUMNS = [self::MARKET];

    /** The E-05 migration that owns the attribution of a ledger row, `storefront_country` included, where it has run. */
    private const LEDGER_ATTRIBUTION_MIGRATION = '2026_12_05_100000_add_attribution_to_wallet_and_loyalty_ledgers';

    public function up(): void
    {
        $this->createAccounts(accounts: self::WALLET_ACCOUNTS, balance: 'balance');
        $this->createAccounts(accounts: self::LOYALTY_ACCOUNTS, balance: 'points');

        foreach (self::LEDGERS as $ledger) {
            $this->attributeLedger(ledger: $ledger);
        }
    }

    public function down(): void
    {
        $ledgerColumns = $this->ledgerColumnsBelongToTheAttributionMigration() ? [] : self::LEDGER_COLUMNS;

        $this->refuseWhileMoneyIsRecorded(ledgerColumns: $ledgerColumns);

        Schema::dropIfExists(self::WALLET_ACCOUNTS);
        Schema::dropIfExists(self::LOYALTY_ACCOUNTS);

        foreach (self::LEDGERS as $ledger) {
            foreach ($ledgerColumns as $column) {
                if (Schema::hasColumn($ledger, $column)) {
                    Schema::table($ledger, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }

    /**
     * The index names are given rather than derived, so that they stay what they are if a column is ever renamed.
     *
     * @param string $accounts the table to create
     * @param string $balance the column that holds what the account is worth: money in the market's currency, or points of the market
     */
    private function createAccounts(string $accounts, string $balance): void
    {
        if (Schema::hasTable($accounts)) {
            return;
        }

        Schema::create($accounts, function (Blueprint $table) use ($accounts, $balance): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $market = $table->char('market', 2);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $market->charset('ascii')->collation('ascii_bin');
            }

            // The currency the account is kept in, copied when the account is opened from the one authority on what a storefront sells in
            // (App\Services\Storefront\StorefrontCurrency). It is recorded on the account itself so that a balance can never be read in a
            // currency the configuration was changed to afterwards.
            $table->string('currency_code', 8);
            // The company the platform was selling as when the account was opened (`gcc.storefronts.legal.legal_entity`). One platform-wide
            // value today, so every market's account carries the same one. Nullable: the Saudi market has traded without one recorded.
            $table->string(self::LEGAL_ENTITY, 64)->nullable();
            $table->decimal($balance, 40, 20)->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'market'], $accounts . '_customer_market_unique');
            $table->index('market', $accounts . '_market_idx');
        });
    }

    private function attributeLedger(string $ledger): void
    {
        if (!Schema::hasTable($ledger) || Schema::hasColumn($ledger, self::MARKET)) {
            return;
        }

        Schema::table($ledger, function (Blueprint $table): void {
            $market = $table->char(self::MARKET, 2)->nullable();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $market->charset('ascii')->collation('ascii_bin');
            }
        });
    }

    /** @param list<string> $ledgerColumns the ledger columns the rollback is about to drop; empty when another migration owns them */
    private function refuseWhileMoneyIsRecorded(array $ledgerColumns): void
    {
        foreach ([self::WALLET_ACCOUNTS, self::LOYALTY_ACCOUNTS] as $accounts) {
            if (Schema::hasTable($accounts) && DB::table($accounts)->exists()) {
                throw new RuntimeException("Customers hold market accounts in {$accounts}. Rolling this migration back would forget their balances.");
            }
        }

        foreach (self::LEDGERS as $ledger) {
            foreach ($ledgerColumns as $column) {
                if (Schema::hasColumn($ledger, $column) && DB::table($ledger)->whereNotNull($column)->exists()) {
                    throw new RuntimeException("Rows of {$ledger} carry what this rollback would drop ({$column}). Rolling this migration back would forget which market they were.");
                }
            }
        }
    }

    /** Whether the E-05 migration has run here, in which case the ledger columns are its to keep and its own rollback drops them. */
    private function ledgerColumnsBelongToTheAttributionMigration(): bool
    {
        return Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', self::LEDGER_ATTRIBUTION_MIGRATION)->exists();
    }
};
