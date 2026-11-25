<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The market a per-category shipping cost belongs to (H1-14), beside the one a shipping method belongs to.
 *
 * Additive and nullable: a cost that exists today keeps the column empty, which is read as the DEFAULT storefront's, because every such row was typed for
 * the only storefront there has ever been. Nothing writes it until a market other than the default has a cost of its own.
 *
 * Unlike `shipping_methods`, this table has a create migration of its own (2022_03_13_121514), so it always exists where the platform does. The column is
 * appended, without a position, so MySQL 8 and MariaDB 11.4 add it without rebuilding the table; it carries an index, because the cost of a category is
 * read for every line of a basket. On MySQL and MariaDB it is compared byte for byte (ascii_bin), as PHP compares it.
 */
return new class extends Migration {
    private const TABLE = 'category_shipping_costs';

    private const COLUMN = 'storefront_country';

    private const INDEX = 'category_shipping_costs_market_index';

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

    /** Dropping the column would forget which market a cost was typed for, which cannot be worked out again, so it is refused while any cost carries one. */
    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        if (DB::table(self::TABLE)->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Category shipping costs belong to a market. Rolling this migration back would forget which.');
        }

        $indexed = collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::INDEX);

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexed) {
            if ($indexed) {
                $table->dropIndex(self::INDEX);
            }

            $table->dropColumn(self::COLUMN);
        });
    }
};
