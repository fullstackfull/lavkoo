<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The market a shipping method belongs to (H1-14): local shipping methods, their costs and their delivery times belong to a storefront and its selling
 * company, and are never inherited by another.
 *
 * Additive and nullable. A method that exists today keeps the column empty, which is read as the DEFAULT storefront's -- every method of this platform is
 * a Saudi method, because there has only ever been one storefront -- so a shop that has not switched storefronts on lists and charges exactly what it
 * listed and charged before. Nothing writes the column until a market other than the default has a method of its own.
 *
 * `shipping_methods` comes from the installation dump rather than from a migration, so a database without the dump has no such table and this does
 * nothing at all. The column is appended, without a position, so MySQL 8 and MariaDB 11.4 add it without rebuilding the table; it carries an index,
 * because every listing of methods for a storefront filters on it and those listings are on the checkout path. On MySQL and MariaDB it is compared byte
 * for byte (ascii_bin), as PHP compares it, like `orders.storefront_country` and `products.storefront_country`.
 */
return new class extends Migration {
    private const TABLE = 'shipping_methods';

    private const COLUMN = 'storefront_country';

    private const INDEX = 'shipping_methods_storefront_country_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $column = $table->char(self::COLUMN, 2)->nullable();

            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $column->charset('ascii')->collation('ascii_bin');
            }

            $table->index(self::COLUMN, self::INDEX);
        });
    }

    /** Dropping the column would forget which market a method belongs to, which cannot be worked out again, so it is refused while any method carries one. */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Shipping methods belong to a market. Rolling this migration back would forget which.');
        }

        // The index is dropped only when it is there: a database that has the column without it -- a hand edit, or a
        // test that built the table itself -- must still be able to roll back, and dropping an index that does not
        // exist is an error on MySQL and on SQLite alike.
        $indexed = collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexed) {
            if ($indexed) {
                $table->dropIndex(self::INDEX);
            }

            $table->dropColumn(self::COLUMN);
        });
    }
};
