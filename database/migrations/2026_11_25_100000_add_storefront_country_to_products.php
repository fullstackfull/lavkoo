<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every product belongs to one storefront country (GCC-02/03), so its price, stock and availability are that country's own. Additive:
 * every product that exists today is a Saudi product, and nothing reads the column until storefronts are switched on. The index leads with
 * the country and then follows the columns Product::scopeActive() always filters together (see idx_products_type_status_request).
 * The column and the index are checked one by one, so a run that stopped between them can be repeated.
 */
return new class extends Migration {
    private const INDEX = 'products_storefront_active_idx';

    public function up(): void
    {
        if (!Schema::hasColumn('products', 'storefront_country')) {
            Schema::table('products', function (Blueprint $table) {
                $table->char('storefront_country', 2)->default('SA');
            });
        }

        if (!Schema::hasIndex('products', self::INDEX)) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['storefront_country', 'product_type', 'status', 'request_status'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('products', self::INDEX)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('products', 'storefront_country')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('storefront_country');
            });
        }
    }
};
