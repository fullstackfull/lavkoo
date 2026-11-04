<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Returns all existing index names on a given table.
     */
    private function getExistingIndexes(string $table): array
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->unique()
            ->toArray();
    }

    public function up(): void
    {
        $existing = $this->getExistingIndexes('orders');

        Schema::table('orders', function (Blueprint $table) use ($existing) {

            //composite indexes
            if (!in_array('idx_orders_seller_is_seller_id', $existing)) {
                $table->index(['seller_is', 'seller_id'], 'idx_orders_seller_is_seller_id');
            }
            if (!in_array('idx_orders_status_created_at', $existing)) {
                $table->index(['order_status', 'created_at'], 'idx_orders_status_created_at');
            }
            if (!in_array('idx_orders_customer_id_status', $existing)) {
                $table->index(['customer_id', 'order_status'], 'idx_orders_customer_id_status');
            }
            if (!in_array('idx_orders_edited_due', $existing)) {
                $table->index(['edited_status', 'edit_due_amount'], 'idx_orders_edited_due');
            }
            if (!in_array('idx_orders_edited_return', $existing)) {
                $table->index(['edited_status', 'edit_return_amount'], 'idx_orders_edited_return');
            }
            if (!in_array('idx_orders_payment_method_seller_is', $existing)) {
                $table->index(['payment_method', 'seller_is'], 'idx_orders_payment_method_seller_is');
            }
            if (!in_array('idx_orders_seller_id_payment_status', $existing)) {
                $table->index(['seller_id', 'payment_status'], 'idx_orders_seller_id_payment_status');
            }
            if (!in_array('idx_orders_deliveryman_status', $existing)) {
                $table->index(['delivery_man_id', 'order_status'], 'idx_orders_deliveryman_status');
            }

        //single column indexes

            if (!in_array('idx_orders_created_at', $existing)) {
                $table->index('created_at', 'idx_orders_created_at');
            }
            if (!in_array('idx_orders_payment_status', $existing)) {
                $table->index('payment_status', 'idx_orders_payment_status');
            }
            if (!in_array('idx_orders_order_type', $existing)) {
                $table->index('order_type', 'idx_orders_order_type');
            }
            if (!in_array('idx_orders_is_guest', $existing)) {
                $table->index('is_guest', 'idx_orders_is_guest');
            }
            if (!in_array('idx_orders_coupon_code', $existing)) {
                $table->index('coupon_code', 'idx_orders_coupon_code');
            }
            if (!in_array('idx_orders_checked', $existing)) {
                $table->index('checked', 'idx_orders_checked');
            }
            if (!in_array('idx_orders_transaction_ref', $existing)) {
                $table->index('transaction_ref', 'idx_orders_transaction_ref');
            }
            if (!in_array('idx_orders_order_group_id', $existing)) {
                $table->index('order_group_id', 'idx_orders_order_group_id');
            }
            if (!in_array('idx_orders_shipping_responsibility', $existing)) {
                $table->index('shipping_responsibility', 'idx_orders_shipping_responsibility');
            }
        });
    }

    public function down(): void
    {
        $existing = $this->getExistingIndexes('orders');

        Schema::table('orders', function (Blueprint $table) use ($existing) {
            if (in_array('idx_orders_seller_is_seller_id', $existing))      $table->dropIndex('idx_orders_seller_is_seller_id');
            if (in_array('idx_orders_status_created_at', $existing))        $table->dropIndex('idx_orders_status_created_at');
            if (in_array('idx_orders_customer_id_status', $existing))       $table->dropIndex('idx_orders_customer_id_status');
            if (in_array('idx_orders_edited_due', $existing))               $table->dropIndex('idx_orders_edited_due');
            if (in_array('idx_orders_edited_return', $existing))            $table->dropIndex('idx_orders_edited_return');
            if (in_array('idx_orders_payment_method_seller_is', $existing)) $table->dropIndex('idx_orders_payment_method_seller_is');
            if (in_array('idx_orders_seller_id_payment_status', $existing)) $table->dropIndex('idx_orders_seller_id_payment_status');
            if (in_array('idx_orders_deliveryman_status', $existing))       $table->dropIndex('idx_orders_deliveryman_status');
            if (in_array('idx_orders_created_at', $existing))              $table->dropIndex('idx_orders_created_at');
            if (in_array('idx_orders_payment_status', $existing))          $table->dropIndex('idx_orders_payment_status');
            if (in_array('idx_orders_order_type', $existing))              $table->dropIndex('idx_orders_order_type');
            if (in_array('idx_orders_is_guest', $existing))                $table->dropIndex('idx_orders_is_guest');
            if (in_array('idx_orders_coupon_code', $existing))             $table->dropIndex('idx_orders_coupon_code');
            if (in_array('idx_orders_checked', $existing))                 $table->dropIndex('idx_orders_checked');
            if (in_array('idx_orders_transaction_ref', $existing))         $table->dropIndex('idx_orders_transaction_ref');
            if (in_array('idx_orders_order_group_id', $existing))          $table->dropIndex('idx_orders_order_group_id');
            if (in_array('idx_orders_shipping_responsibility', $existing))  $table->dropIndex('idx_orders_shipping_responsibility');
        });
    }
};
