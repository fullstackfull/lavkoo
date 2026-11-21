<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company that sells a product (GCC-13, D1-03). The column the application already reads and nothing had a migration for: it is the company
 * half of a product's selling scope, which App\Services\Storefront\StorefrontCatalog compares when an order is written (one selling company per
 * order), and the value App\Services\Storefront\LegalEntityRegistry answers with when it is asked who sells a product in a storefront.
 *
 * Additive and nullable: every product that exists today keeps it empty, which is read as a product that names no company — the state the whole
 * catalogue is in before anybody registers one, and the state a backfill leaves half the rows in while it runs. A product that names no company
 * never disagrees with one that does, so nothing is refused for lack of a company (StorefrontCatalog::sellingScopeOf says why at length).
 *
 * It is written by the admin product screens, from the company registered for the product's country, and read while legal entities are switched
 * on (GCC_LEGAL_ENTITIES, off by default). Nothing reads it while that switch is off.
 *
 * It adds the column and never alters one: on an installation whose products table already carries `legal_entity_id` with a different type —
 * a signed integer, a string, a narrower width — this migration sees the name, returns, and leaves that type in place without saying so. Check
 * the column's type before running it on a database that is not a stock one.
 *
 * The column is appended, without a position, so MySQL 8 and MariaDB 11.4 add it without rebuilding the table, and it carries no index: the
 * country column next to it was measured on 200,000 products and an index on it made the selective seller and category lists 5 to 15 times
 * slower (see 2026_11_25_100000_add_storefront_country_to_products). Add one only after reading the plans on real data.
 */
return new class extends Migration {
    private const COLUMN = 'legal_entity_id';

    public function up(): void
    {
        if (Schema::hasColumn('products', self::COLUMN)) {
            return;
        }

        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger(self::COLUMN)->nullable());
    }

    /** Dropping the column would forget which company sells which product, which cannot be worked out again, so it is refused while a product names one. */
    public function down(): void
    {
        if (!Schema::hasColumn('products', self::COLUMN)) {
            return;
        }

        if (DB::table('products')->whereNotNull(self::COLUMN)->exists()) {
            throw new RuntimeException('Products name a selling company. Rolling this migration back would forget which company sells them.');
        }

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(self::COLUMN));
    }
};
