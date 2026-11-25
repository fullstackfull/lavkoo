<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The market and the selling company a place stock lives in belongs to (G1-05, E11-F19).
 *
 * A warehouse is in a country. Until now `inventory_locations` carried `seller_id`, a type and a provider and nothing else, so the only
 * way to ask which market a balance belonged to was through the PRODUCT the balance was of -- which answers for the units, never for the
 * shelf. That is why the staff country scope stops at the stock screens: a restricted employee of Kuwait could be shown the Saudi
 * warehouse itself, with its code, its name and its figures, because nothing on the row said where it is.
 *
 * Additive and nullable, both columns. A location that exists today keeps them empty, and an empty market is read as the DEFAULT
 * storefront's by the one rule every market-owning table shares (`StorefrontMarketRows`): every location of this platform is a Saudi
 * location, because there has only ever been one storefront. So nothing changes for a shop that has not switched storefronts on, and the
 * three locations `InventoryLocationService::ensureDefaults()` creates are untouched -- no backfill writes a country nobody declared.
 *
 * `legal_entity_id` is the same shape as `products.legal_entity_id` and `orders.legal_entity_id`: a plain reference, no database
 * constraint, because `legal_entities` is itself a GCC table that an older installation may not have yet.
 *
 * The market carries an index: narrowing locations by market is a list screen's filter and a pool's membership question, both of which
 * run per request. The company does not: there are a handful of companies and a handful of locations each, so an index would be read
 * past rather than used. On MySQL and MariaDB the market is compared byte for byte (ascii_bin), as PHP compares it.
 */
return new class extends Migration {
    private const TABLE = 'inventory_locations';

    private const MARKET = 'storefront_country';

    private const COMPANY = 'legal_entity_id';

    private const MARKET_INDEX = 'inventory_locations_storefront_country_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLE, self::MARKET)) {
                $column = $table->char(self::MARKET, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }

                $table->index(self::MARKET, self::MARKET_INDEX);
            }

            if (!Schema::hasColumn(self::TABLE, self::COMPANY)) {
                $table->unsignedBigInteger(self::COMPANY)->nullable();
            }
        });
    }

    /**
     * Dropping either column would forget which country a warehouse is in and which company owns its stock, and neither can be worked
     * out again from what is left, so a rollback is refused while any location carries one.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([self::MARKET => 'country', self::COMPANY => 'selling company'] as $column => $what) {
            if (Schema::hasColumn(self::TABLE, $column) && DB::table(self::TABLE)->whereNotNull($column)->exists()) {
                throw new RuntimeException("Inventory locations name a {$what}. Rolling this migration back would forget which.");
            }
        }

        // Only when it is there: a database that has the column without the index -- a hand edit, or a test that built the table
        // itself -- must still be able to roll back, and dropping an index that does not exist is an error on every engine.
        $indexed = collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::MARKET_INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexed) {
            if ($indexed) {
                $table->dropIndex(self::MARKET_INDEX);
            }

            foreach ([self::MARKET, self::COMPANY] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
