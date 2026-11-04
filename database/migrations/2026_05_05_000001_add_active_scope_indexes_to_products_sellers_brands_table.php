<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    private function getExistingIndexes(string $table): array
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->unique()
            ->toArray();
    }

    public function up(): void
    {
        // products: composite index covering the three columns always filtered together
        // in Product::scopeActive() — eliminates full table scan before EXISTS subqueries run
        $productIndexes = $this->getExistingIndexes('products');
        Schema::table('products', function (Blueprint $table) use ($productIndexes) {
            if (!in_array('idx_products_type_status_request', $productIndexes)) {
                $table->index(['product_type', 'status', 'request_status'], 'idx_products_type_status_request');
            }
            // used in the correlated EXISTS subquery: sellers.id = products.user_id
            if (!in_array('idx_products_user_id', $productIndexes)) {
                $table->index('user_id', 'idx_products_user_id');
            }
        });

        // sellers: status is checked in the correlated subquery on every active() call
        $sellerIndexes = $this->getExistingIndexes('sellers');
        Schema::table('sellers', function (Blueprint $table) use ($sellerIndexes) {
            if (!in_array('idx_sellers_status', $sellerIndexes)) {
                $table->index('status', 'idx_sellers_status');
            }
        });

        // brands: status is checked in the correlated EXISTS subquery for physical products
        $brandIndexes = $this->getExistingIndexes('brands');
        Schema::table('brands', function (Blueprint $table) use ($brandIndexes) {
            if (!in_array('idx_brands_status', $brandIndexes)) {
                $table->index('status', 'idx_brands_status');
            }
        });

        // digital_product_authors: speeds up the correlated subcount per author in getProductAuthorList()
        $authorIndexes = $this->getExistingIndexes('digital_product_authors');
        Schema::table('digital_product_authors', function (Blueprint $table) use ($authorIndexes) {
            if (!in_array('idx_dpa_author_product', $authorIndexes)) {
                $table->index(['author_id', 'product_id'], 'idx_dpa_author_product');
            }
        });

        // digital_product_publishing_houses: same — correlated subcount per publishing house
        $phIndexes = $this->getExistingIndexes('digital_product_publishing_houses');
        Schema::table('digital_product_publishing_houses', function (Blueprint $table) use ($phIndexes) {
            if (!in_array('idx_dpph_house_product', $phIndexes)) {
                $table->index(['publishing_house_id', 'product_id'], 'idx_dpph_house_product');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $existing = $this->getExistingIndexes('products');
            if (in_array('idx_products_type_status_request', $existing)) $table->dropIndex('idx_products_type_status_request');
            if (in_array('idx_products_user_id', $existing))             $table->dropIndex('idx_products_user_id');
        });

        Schema::table('sellers', function (Blueprint $table) {
            if (in_array('idx_sellers_status', $this->getExistingIndexes('sellers'))) {
                $table->dropIndex('idx_sellers_status');
            }
        });

        Schema::table('brands', function (Blueprint $table) {
            if (in_array('idx_brands_status', $this->getExistingIndexes('brands'))) {
                $table->dropIndex('idx_brands_status');
            }
        });

        Schema::table('digital_product_authors', function (Blueprint $table) {
            if (in_array('idx_dpa_author_product', $this->getExistingIndexes('digital_product_authors'))) {
                $table->dropIndex('idx_dpa_author_product');
            }
        });

        Schema::table('digital_product_publishing_houses', function (Blueprint $table) {
            if (in_array('idx_dpph_house_product', $this->getExistingIndexes('digital_product_publishing_houses'))) {
                $table->dropIndex('idx_dpph_house_product');
            }
        });
    }
};
