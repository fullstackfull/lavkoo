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
        $existing = $this->getExistingIndexes('order_details');

        Schema::table('order_details', function (Blueprint $table) use ($existing) {

          //composite indexes
            if (!in_array('idx_order_details_order_id_delivery_status', $existing)) {
                $table->index(['order_id', 'delivery_status'], 'idx_order_details_order_id_delivery_status');
            }
            if (!in_array('idx_order_details_product_id_delivery_status', $existing)) {
                $table->index(['product_id', 'delivery_status'], 'idx_order_details_product_id_delivery_status');
            }
            if (!in_array('idx_order_details_seller_id_delivery_status', $existing)) {
                $table->index(['seller_id', 'delivery_status'], 'idx_order_details_seller_id_delivery_status');
            }
            if (!in_array('idx_order_details_order_id_payment_status', $existing)) {
                $table->index(['order_id', 'payment_status'], 'idx_order_details_order_id_payment_status');
            }

            //single column indexes
            if (!in_array('idx_order_details_delivery_status', $existing)) {
                $table->index('delivery_status', 'idx_order_details_delivery_status');
            }
            if (!in_array('idx_order_details_refund_request', $existing)) {
                $table->index('refund_request', 'idx_order_details_refund_request');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->getExistingIndexes('order_details');

        Schema::table('order_details', function (Blueprint $table) use ($existing) {
            if (in_array('idx_order_details_order_id_delivery_status', $existing))     $table->dropIndex('idx_order_details_order_id_delivery_status');
            if (in_array('idx_order_details_product_id_delivery_status', $existing))   $table->dropIndex('idx_order_details_product_id_delivery_status');
            if (in_array('idx_order_details_seller_id_delivery_status', $existing))    $table->dropIndex('idx_order_details_seller_id_delivery_status');
            if (in_array('idx_order_details_order_id_payment_status', $existing))      $table->dropIndex('idx_order_details_order_id_payment_status');
            if (in_array('idx_order_details_delivery_status', $existing))              $table->dropIndex('idx_order_details_delivery_status');
            if (in_array('idx_order_details_refund_request', $existing))               $table->dropIndex('idx_order_details_refund_request');
        });
    }
};
