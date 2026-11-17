<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every product belongs to one storefront country (GCC-02/03), so its price, stock and availability are that country's own. Additive:
 * every product that exists today is a Saudi product, and nothing reads the column until storefronts are switched on.
 *
 * The column carries no index. A (country, id) index was measured on 200,000 products and made the optimizer merge it with the selective
 * seller and category indexes, so the lists of a vendor or a category became 5 to 15 times slower with the flag on, even while every
 * product was Saudi, and every count became slower too. Without it the one cost left is the id-ordered public list of a storefront whose
 * rows are older than another country's import: it steps over the newer foreign rows before it finds its own. Add an index only after
 * looking at the plans on real data (docs/gcc/STOREFRONT_FLAG_RUNBOOK.md).
 *
 * On MySQL and MariaDB the column is compared byte for byte (ascii_bin), as PHP compares it, so 'sa' is not 'SA' in either place.
 */
return new class extends Migration {
    private const COLUMN = 'storefront_country';

    private const DEFAULT_COUNTRY = 'SA';

    /** The index the runbook tells an operator to add once real data shows a need. It must not outlive the column. */
    private const OPERATOR_INDEX = 'products_storefront_id_idx';

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

        if (Schema::hasIndex('products', self::OPERATOR_INDEX)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(self::OPERATOR_INDEX);
            });
        }

        if (Schema::hasColumn('products', self::COLUMN)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
