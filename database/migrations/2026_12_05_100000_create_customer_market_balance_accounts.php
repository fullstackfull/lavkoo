<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet account and one point account per customer per market (GCC-15, E-01, E-02), and the three columns that say which market a
 * ledger row belongs to (E-05). Expand only: the two tables are new and empty, the six columns are nullable with no default, and not one
 * existing row is read or written. Nothing is backfilled here, and no balance moves: `users.wallet_balance` and `users.loyalty_point`
 * stay the authority until a later slice switches a market over (E-07).
 *
 * The market is the two-letter country of `gcc.storefronts.countries`, never a name and never the id of another table, so an account
 * survives a storefront being renamed or switched off. The balances are DECIMAL(40,20), the width `users.wallet_balance` and
 * `users.loyalty_point` already have, so a later backfill copies a balance without losing a digit. On MySQL and MariaDB the market is
 * compared byte for byte (ascii_bin), as PHP compares it, like `products.storefront_country` and `orders.storefront_country`.
 *
 * The ledger columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table, and they carry
 * no index: the ledgers are the largest tables on the pilot server, and an index goes on only after the plans of a report on real data have
 * been read, exactly as `orders.storefront_country` left it.
 *
 * Running it again does nothing, and it rolls back while it is still dormant. Once a market account or an attributed ledger row exists the
 * rollback is refused: dropping the tables would forget money, and that cannot be worked out again.
 */
return new class extends Migration {
    private const WALLET_ACCOUNTS = 'customer_market_wallet_accounts';

    private const LOYALTY_ACCOUNTS = 'customer_market_loyalty_accounts';

    /** The ledgers a market is recorded on, and the balance each one belongs to. */
    private const LEDGERS = ['wallet_transactions', 'loyalty_point_transactions'];

    private const MARKET = 'storefront_country';

    private const CURRENCY = 'currency_code';

    private const LEGAL_ENTITY = 'legal_entity';

    public function up(): void
    {
        $this->createAccounts(accounts: self::WALLET_ACCOUNTS, balance: 'balance', index: 'customer_market_wallet_accounts');
        $this->createAccounts(accounts: self::LOYALTY_ACCOUNTS, balance: 'points', index: 'customer_market_loyalty_accounts');

        foreach (self::LEDGERS as $ledger) {
            $this->attributeLedger(ledger: $ledger);
        }
    }

    public function down(): void
    {
        $this->refuseWhileMoneyIsRecorded();

        Schema::dropIfExists(self::WALLET_ACCOUNTS);
        Schema::dropIfExists(self::LOYALTY_ACCOUNTS);

        foreach (self::LEDGERS as $ledger) {
            foreach ([self::MARKET, self::CURRENCY, self::LEGAL_ENTITY] as $column) {
                if (Schema::hasColumn($ledger, $column)) {
                    Schema::table($ledger, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }

    /**
     * @param string $accounts the table to create
     * @param string $balance the column that holds what the account is worth: money in the market's currency, or points of the market
     * @param string $index the prefix of the index names, which have to be unique across the database and are longer than MySQL allows if they are derived
     */
    private function createAccounts(string $accounts, string $balance, string $index): void
    {
        if (Schema::hasTable($accounts)) {
            return;
        }

        Schema::create($accounts, function (Blueprint $table) use ($balance, $index): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $market = $table->char('market', 2);

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $market->charset('ascii')->collation('ascii_bin');
            }

            // The currency the account is kept in, copied from the market's configuration when the account is opened. It is recorded on the
            // account itself so that a balance can never be read in a currency the configuration was changed to afterwards.
            $table->string('currency_code', 8);
            // The company that sells in this market, when one is configured (`gcc.storefronts.legal.legal_entity`). Nullable: the Saudi market
            // has traded without one recorded, and two markets of one company are still two accounts.
            $table->string(self::LEGAL_ENTITY, 64)->nullable();
            $table->decimal($balance, 40, 20)->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'market'], $index . '_customer_market_unique');
            $table->index('market', $index . '_market_idx');
        });
    }

    private function attributeLedger(string $ledger): void
    {
        if (!Schema::hasTable($ledger)) {
            return;
        }

        if (!Schema::hasColumn($ledger, self::MARKET)) {
            Schema::table($ledger, function (Blueprint $table): void {
                $market = $table->char(self::MARKET, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $market->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!Schema::hasColumn($ledger, self::CURRENCY)) {
            Schema::table($ledger, fn (Blueprint $table) => $table->string(self::CURRENCY, 8)->nullable());
        }

        if (!Schema::hasColumn($ledger, self::LEGAL_ENTITY)) {
            Schema::table($ledger, fn (Blueprint $table) => $table->string(self::LEGAL_ENTITY, 64)->nullable());
        }
    }

    private function refuseWhileMoneyIsRecorded(): void
    {
        foreach ([self::WALLET_ACCOUNTS, self::LOYALTY_ACCOUNTS] as $accounts) {
            if (Schema::hasTable($accounts) && DB::table($accounts)->exists()) {
                throw new RuntimeException("Customers hold market accounts in {$accounts}. Rolling this migration back would forget their balances.");
            }
        }

        foreach (self::LEDGERS as $ledger) {
            if (Schema::hasColumn($ledger, self::MARKET) && DB::table($ledger)->whereNotNull(self::MARKET)->exists()) {
                throw new RuntimeException("Rows of {$ledger} name the market they belong to. Rolling this migration back would forget which market they were.");
            }
        }
    }
};
