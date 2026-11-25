<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which market an order line was sold in, and which carrier account its shipments run against (C-04).
 *
 * The ownership snapshot already freezes WHO fulfils a line. With six markets it has to freeze WHERE too, because the same carrier code
 * can have one account per country (`integration_settings` is unique on (provider, country) since `2026_12_23_100000`) and the figures a
 * market is answerable for are read off these rows. Reading the market back through the product would answer today's market and not the
 * one the order was placed in, which is the whole point of a snapshot.
 *
 * Additive, nullable, and NOTHING updates an existing row: a snapshot is immutable, and the emptiness of these two columns is the fact
 * that tells an order placed before markets existed from one placed in Saudi Arabia afterwards. How an empty one is read is the written
 * rule of {@see \App\Services\Fulfillment\OwnershipHistory} -- the default storefront, and the carrier's platform-wide account -- which
 * is a rule and not a guess, because there has only ever been one storefront and one account per carrier.
 *
 * On MySQL and MariaDB the market is compared byte for byte (ascii_bin), as PHP compares it and as `orders.storefront_country` and
 * `products.storefront_country` already are: a `char(2)` left to the server's own collation matches `kw` with `KW`, which passed on
 * SQLite and did not on MariaDB the last time a market column was built by hand.
 *
 * The account id is a plain unsigned integer and NOT a foreign key: the snapshot has to outlive the account row. Deleting a carrier's
 * credentials must not cascade into the immutable record of what shipped, and `provider_fulfillment_items` carries no other foreign key
 * for the same reason.
 */
return new class extends Migration {
    private const TABLE = 'provider_fulfillment_items';

    private const MARKET = 'storefront_country';

    private const ACCOUNT = 'fulfillment_account_id';

    private const MARKET_INDEX = 'pf_items_storefront_country_index';

    private const ACCOUNT_INDEX = 'pf_items_fulfillment_account_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, self::MARKET)) {
                $column = $table->char(self::MARKET, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }

                $table->index(self::MARKET, self::MARKET_INDEX);
            }

            if (!Schema::hasColumn(self::TABLE, self::ACCOUNT)) {
                $table->unsignedBigInteger(self::ACCOUNT)->nullable();
                $table->index(self::ACCOUNT, self::ACCOUNT_INDEX);
            }
        });
    }

    /** Refused once a line names a market: it cannot be worked out again, and a rollback would merge six markets' shipments into one. */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::MARKET, self::ACCOUNT] as $column) {
            if (Schema::hasColumn(self::TABLE, $column) && DB::table(self::TABLE)->whereNotNull($column)->exists()) {
                throw new RuntimeException('Order lines name the market and the account they were placed under. Rolling back would forget which.');
            }
        }

        $indexes = collect(Schema::getIndexes(self::TABLE))->pluck('name')->all();

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexes): void {
            foreach ([self::MARKET => self::MARKET_INDEX, self::ACCOUNT => self::ACCOUNT_INDEX] as $column => $index) {
                if (!Schema::hasColumn(self::TABLE, $column)) {
                    continue;
                }

                if (in_array($index, $indexes, true)) {
                    $table->dropIndex($index);
                }

                $table->dropColumn($column);
            }
        });
    }
};
