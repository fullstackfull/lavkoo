<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon belongs to one storefront (GCC-05 slice 5), because its amounts (the discount, the minimum purchase and the cap) are typed in that storefront's
 * currency. Additive: the column is nullable and appended without a position, no coupon that exists today is touched, and nothing reads it until the
 * storefront currency is switched on.
 *
 * NULL is the default storefront's coupon, in the platform's money, which is what every coupon is today. A country code means the amounts were typed in that
 * storefront's currency, as typed, at rate one. The country is set once by the admin form that creates the coupon, only for a storefront that is not the
 * default one, and never changes afterwards.
 *
 * The column carries no index: the coupon table is small, and a lookup is by code. On MySQL and MariaDB it is compared byte for byte (ascii_bin), as PHP
 * compares it, like the country of a product.
 */
return new class extends Migration {
    private const COLUMN = 'storefront_country';

    public function up(): void
    {
        if (!Schema::hasColumn('coupons', self::COLUMN)) {
            Schema::table('coupons', function (Blueprint $table) {
                $column = $table->char(self::COLUMN, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }
    }

    /**
     * Dropping the column would turn the coupons of another storefront into coupons of the default one, whose amounts are in another currency, and cannot be
     * undone, so it is refused while a coupon names a country. Delete those coupons first.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupons', self::COLUMN) && DB::table('coupons')->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Coupons of another storefront exist. Rolling this migration back would make them coupons of the default storefront.');
        }

        if (Schema::hasColumn('coupons', self::COLUMN)) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
