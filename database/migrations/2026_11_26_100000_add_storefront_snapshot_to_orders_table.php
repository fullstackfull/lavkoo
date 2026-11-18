<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an order was sold in (GCC-05): the storefront whose products it holds, the currency its amounts are in, and the rate that turns a stored
 * amount into that currency. Additive: every column is nullable and an order that exists today keeps all three empty, which is read as an order
 * of the default storefront in the platform's own currency settings. Nothing writes the columns until storefront currencies are switched on.
 *
 * The columns are appended, without a position, so MySQL 8 and MariaDB 11.4 add them without rebuilding the table. They carry no index: add one
 * only after reading the plans of a report on real data. On MySQL and MariaDB the country is compared byte for byte (ascii_bin), as PHP
 * compares it, like `products.storefront_country`.
 */
return new class extends Migration {
    private const COUNTRY = 'storefront_country';

    private const CURRENCY = 'currency_code';

    private const RATE = 'currency_rate';

    public function up(): void
    {
        if (!Schema::hasColumn('orders', self::COUNTRY)) {
            Schema::table('orders', function (Blueprint $table) {
                $column = $table->char(self::COUNTRY, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }

        if (!Schema::hasColumn('orders', self::CURRENCY)) {
            Schema::table('orders', fn (Blueprint $table) => $table->string(self::CURRENCY, 8)->nullable());
        }

        if (!Schema::hasColumn('orders', self::RATE)) {
            Schema::table('orders', fn (Blueprint $table) => $table->decimal(self::RATE, 20, 8)->nullable());
        }
    }

    /**
     * Dropping the columns would forget what a sold order was priced in, which cannot be worked out again, so it is refused while any order
     * carries a snapshot.
     */
    public function down(): void
    {
        $recorded = Schema::hasColumn('orders', self::COUNTRY) && DB::table('orders')->whereNotNull(self::COUNTRY)->exists();

        if ($recorded) {
            throw new RuntimeException('Orders carry a storefront snapshot. Rolling this migration back would forget what they were sold in.');
        }

        foreach ([self::COUNTRY, self::CURRENCY, self::RATE] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
