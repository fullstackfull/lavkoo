<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The market a stock pool belongs to, as part of the pool's own KEY (M-656, M-745).
 *
 * A pool is what a channel may be offered of one physical stock, and until now its identity was `(location_id, product_id, variant_key)`
 * and nothing else. The product row names a market and the shelf names a market, but the POOL named neither -- so the row that the single
 * writer serialises every division on, the division it writes onto each listing (`allocation.pool`) and the figure an operator reads could
 * not be attributed to a country at all. Two countries' accounts offering one SKU met in one row, and only the market was wrong.
 *
 * **Every pool that exists today is the DEFAULT storefront's, and the column says so by holding `''`.** That is the same rule
 * `StorefrontMarketRows` states for every other market-owning table -- a row that names no market is the default storefront's -- written as
 * an empty string rather than NULL for one decisive reason: this column is part of a UNIQUE KEY, and on MySQL and MariaDB two rows whose
 * key contains NULL do not collide. A nullable market would therefore let a second pool row exist for one (location, product, variant),
 * and the `FOR UPDATE` on that row is the whole of the single-writer guarantee that keeps two processes from dividing one stock twice.
 * `''` collides with `''`, so the guarantee holds.
 *
 * Additive and dormant twice over: the column defaults to `''`, so every existing row keeps exactly the pool it had; and nothing WRITES
 * anything but `''` until `gcc.storefronts.enabled` is on and the products carry their own market (`PoolMarket`). A shop that has not
 * switched storefronts on cannot tell this ran.
 *
 * The unique key is replaced rather than added to: the old one, without the market, would refuse the second market's pool of a product and
 * a shelf that two markets share. The market comes FIRST in the new one so that narrowing the pools of one market uses it.
 */
return new class extends Migration {
    private const TABLE = 'inventory_pools';

    private const MARKET = 'storefront_country';

    private const OLD_UNIQUE = 'ipool_location_product_variant_unique';

    private const NEW_UNIQUE = 'ipool_market_location_product_variant_unique';

    private const KEY = [self::MARKET, 'location_id', 'product_id', 'variant_key'];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, self::MARKET)) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $column = $table->char(self::MARKET, 2)->default('');

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!$this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->unique(self::KEY, self::NEW_UNIQUE));
        }

        // Only after the new one exists: a moment with neither would let two divisions of one pool be written.
        if ($this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->dropUnique(self::OLD_UNIQUE));
        }
    }

    /**
     * Rolling back forgets which market a pool belongs to, and the old key cannot even hold the rows: two markets' pools of one shelf and
     * one product would collide on it. So a rollback is refused while any pool names a market, rather than losing rows to a failed index.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::MARKET)) {
            return;
        }

        if (DB::table(self::TABLE)->where(self::MARKET, '!=', '')->exists()) {
            throw new RuntimeException('Stock pools name a market. Rolling this migration back would forget which, and two markets\' pools would collide on the old key.');
        }

        if (!$this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->unique(['location_id', 'product_id', 'variant_key'], self::OLD_UNIQUE));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            if ($this->hasIndex(self::NEW_UNIQUE)) {
                $table->dropUnique(self::NEW_UNIQUE);
            }

            $table->dropColumn(self::MARKET);
        });
    }

    /** Dropping an index that is not there is an error on every engine, and a database built by hand may have the column without it. */
    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(static fn (array $index): bool => $index['name'] === $name);
    }
};
