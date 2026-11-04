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
        $existing = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existing) {

            // composite indexes
            if (!in_array('idx_products_added_by_user_id', $existing)) {
                $table->index(['added_by', 'user_id'], 'idx_products_added_by_user_id');
            }
            if (!in_array('idx_products_added_by_request_status', $existing)) {
                $table->index(['added_by', 'request_status'], 'idx_products_added_by_request_status');
            }
            if (!in_array('idx_products_category_id_status', $existing)) {
                $table->index(['category_id', 'status'], 'idx_products_category_id_status');
            }
            if (!in_array('idx_products_sub_category_id_status', $existing)) {
                $table->index(['sub_category_id', 'status'], 'idx_products_sub_category_id_status');
            }
            if (!in_array('idx_products_sub_sub_category_id_status', $existing)) {
                $table->index(['sub_sub_category_id', 'status'], 'idx_products_sub_sub_category_id_status');
            }
            if (!in_array('idx_products_brand_id_status', $existing)) {
                $table->index(['brand_id', 'status'], 'idx_products_brand_id_status');
            }
            if (!in_array('idx_products_added_by_product_type_user', $existing)) {
                $table->index(['added_by', 'product_type', 'user_id'], 'idx_products_added_by_product_type_user');
            }

            //single column indexes
            if (!in_array('idx_products_slug', $existing)) {
                $table->index('slug', 'idx_products_slug');
            }
            if (!in_array('idx_products_current_stock', $existing)) {
                $table->index('current_stock', 'idx_products_current_stock');
            }
            if (!in_array('idx_products_created_at', $existing)) {
                $table->index('created_at', 'idx_products_created_at');
            }
            if (!in_array('idx_products_code', $existing)) {
                $table->index('code', 'idx_products_code');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->getExistingIndexes('products');

        Schema::table('products', function (Blueprint $table) use ($existing) {
            if (in_array('idx_products_added_by_user_id', $existing))              $table->dropIndex('idx_products_added_by_user_id');
            if (in_array('idx_products_added_by_request_status', $existing))       $table->dropIndex('idx_products_added_by_request_status');
            if (in_array('idx_products_category_id_status', $existing))            $table->dropIndex('idx_products_category_id_status');
            if (in_array('idx_products_sub_category_id_status', $existing))        $table->dropIndex('idx_products_sub_category_id_status');
            if (in_array('idx_products_sub_sub_category_id_status', $existing))    $table->dropIndex('idx_products_sub_sub_category_id_status');
            if (in_array('idx_products_brand_id_status', $existing))               $table->dropIndex('idx_products_brand_id_status');
            if (in_array('idx_products_added_by_product_type_user', $existing))    $table->dropIndex('idx_products_added_by_product_type_user');
            if (in_array('idx_products_slug', $existing))                          $table->dropIndex('idx_products_slug');
            if (in_array('idx_products_current_stock', $existing))                 $table->dropIndex('idx_products_current_stock');
            if (in_array('idx_products_created_at', $existing))                    $table->dropIndex('idx_products_created_at');
            if (in_array('idx_products_code', $existing))                          $table->dropIndex('idx_products_code');
        });
    }
};
