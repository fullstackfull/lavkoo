<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An SEO redirect belongs to one storefront (A1-V2).
 *
 * A redirect maps one path of the shop to another, and with six markets the same path means a different page in each: `/offers` in Kuwait is
 * not `/offers` in Riyadh, and a rule written for one market applied to all of them sends a shopper of another market to a page that is not
 * theirs -- with a 301, which a search engine and a browser both remember.
 *
 * Additive: the column is nullable and appended without a position, no rule that exists today is touched, and NULL keeps meaning what every
 * rule means now -- the default storefront's. A country code means the rule was written for that market and is applied there and nowhere
 * else, which is `StorefrontMarketRows`' rule, the same one the coupons, the themes and the shipping methods follow.
 *
 * The column carries no index: the rule set is small, cached per market, and read as a whole rather than looked up by country. On MySQL and
 * MariaDB it is compared byte for byte (ascii_bin), as PHP compares it, like the country of a product.
 */
return new class extends Migration {
    private const COLUMN = 'storefront_country';

    public function up(): void
    {
        if (Schema::hasTable('redirects') && !Schema::hasColumn('redirects', self::COLUMN)) {
            Schema::table('redirects', function (Blueprint $table) {
                $column = $table->char(self::COLUMN, 2)->nullable();

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->charset('ascii')->collation('ascii_bin');
                }
            });
        }
    }

    /**
     * Dropping the column would turn a rule written for one market into a rule of the default storefront, which would then redirect the live
     * Saudi shop to a page of another market. It is refused while a rule names a country: delete those rules first.
     */
    public function down(): void
    {
        if (!Schema::hasTable('redirects') || !Schema::hasColumn('redirects', self::COLUMN)) {
            return;
        }

        if (DB::table('redirects')->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Redirects of another storefront exist. Rolling this migration back would make them redirects of the default storefront.');
        }

        Schema::table('redirects', function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
