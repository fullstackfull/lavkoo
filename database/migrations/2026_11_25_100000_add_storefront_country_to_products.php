<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every product belongs to one storefront country (GCC-02/03), so its price, stock and availability are that country's own. Additive:
 * every product that exists today is a Saudi product, and nothing reads the column until storefronts are switched on.
 *
 * The index is (country, id). The public lists are ordered by id, newest first, and a later import of another country's catalogue gets
 * the highest ids, so without the id in the index the Saudi storefront steps over every newer foreign row before it finds its own (measured
 * on 200,000 rows: 1.9 ms became 281 ms). A four-column index on the columns the active scope filters was never chosen by the optimizer.
 *
 * On MySQL and MariaDB the column is compared byte for byte (ascii_bin), as PHP compares it, so 'sa' is not 'SA' in either place.
 * The column and the index are checked one by one, so a run that stopped between them can be repeated.
 */
return new class extends Migration {
    private const COLUMN = 'storefront_country';

    private const DEFAULT_COUNTRY = 'SA';

    private const INDEX = 'products_storefront_id_idx';

    public function up(): void
    {
        if (!Schema::hasColumn('products', self::COLUMN)) {
            Schema::table('products', function (Blueprint $table) {
                $column = $table->char(self::COLUMN, 2)->default(self::DEFAULT_COUNTRY);

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!Schema::hasIndex('products', self::INDEX)) {
            Schema::table('products', function (Blueprint $table) {
                $table->index([self::COLUMN, 'id'], self::INDEX);
            });
        }
    }

    /**
     * Dropping the column would merge the catalogues of every country into the Saudi one and cannot be undone, so it is refused while a
     * product of another country exists. Move or delete those products first.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', self::COLUMN) && DB::table('products')->where(self::COLUMN, '<>', self::DEFAULT_COUNTRY)->exists()) {
            throw new RuntimeException('Products of another country exist. Rolling this migration back would merge them into the Saudi catalogue.');
        }

        if (Schema::hasIndex('products', self::INDEX)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('products', self::COLUMN)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
