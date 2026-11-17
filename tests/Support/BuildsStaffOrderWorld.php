<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The orders, their lines, the refund requests and the customers the staff country scope of the order screens reads (GCC-14c, slice 2), on top of the product and
 * employee tables of BuildsStaffScopeWorld.
 *
 * An order carries no country: it follows from the products of its lines. So a test says which products an order holds, and that is all the country it has. A line whose
 * product id is not a product is a deleted product; a product saved with an empty country is a product without one (the column is NOT NULL, so that is how a product
 * ends up with none).
 *
 * Employees: the ones of BuildsStaffScopeWorld, plus 7 who holds the orders and the POS screens (role 5) and 8 who holds the POS only (role 6).
 */
trait BuildsStaffOrderWorld
{
    use BuildsStaffScopeWorld;

    protected function buildStaffOrderTables(): void
    {
        $this->buildStaffAdminTables();
        $this->buildStaffProductTables();
        $this->runStaffScopeMigration();
        $this->addStaffOrderRoles();

        foreach (['refund_requests', 'order_edit_histories', 'order_status_histories', 'order_expected_delivery_histories', 'orders', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('order_details');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->integer('loyalty_point')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('users')->insert([['id' => 1, 'f_name' => 'Customer', 'l_name' => 'One', 'phone' => '500000001', 'email' => 'c1@example.test']]);

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('customer_type')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('order_status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->string('payment_by')->nullable();
            $table->text('payment_note')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('init_order_amount', 24, 2)->default(0);
            $table->decimal('edit_due_amount', 24, 2)->default(0);
            $table->decimal('edit_return_amount', 24, 2)->default(0);
            $table->decimal('total_tax_amount', 24, 2)->default(0);
            $table->string('tax_type')->nullable();
            $table->string('tax_model')->nullable();
            $table->decimal('paid_amount', 24, 2)->default(0);
            $table->decimal('bring_change_amount', 24, 2)->default(0);
            $table->string('bring_change_amount_currency')->nullable();
            $table->decimal('admin_commission', 24, 2)->default(0);
            $table->boolean('is_pause')->default(false);
            $table->text('cause')->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->string('coupon_discount_bearer')->nullable();
            $table->string('shipping_responsibility')->nullable();
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->decimal('shipping_cost', 24, 2)->default(0);
            $table->boolean('is_shipping_free')->default(false);
            $table->string('order_group_id')->default('def-order-group');
            $table->string('verification_code')->nullable();
            $table->boolean('verification_status')->default(false);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->text('shipping_address_data')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->timestamp('deliveryman_assigned_at')->nullable();
            $table->decimal('deliveryman_charge', 24, 2)->default(0);
            $table->date('expected_delivery_date')->nullable();
            $table->text('order_note')->nullable();
            $table->unsignedBigInteger('billing_address')->nullable();
            $table->text('billing_address_data')->nullable();
            $table->string('order_type')->default('default_type');
            $table->decimal('extra_discount', 24, 2)->default(0);
            $table->string('extra_discount_type')->nullable();
            $table->decimal('refer_and_earn_discount', 24, 2)->default(0);
            $table->string('free_delivery_bearer')->nullable();
            $table->boolean('checked')->default(false);
            $table->string('shipping_type')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->integer('edited_status')->default(0);
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 2)->default(10);
            $table->decimal('tax', 24, 2)->default(0);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('tax_model')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('digital_file_after_sell')->nullable();
            $table->string('variant')->nullable();
            $table->text('variation')->nullable();
            $table->integer('refund_request')->default(0);
            $table->boolean('is_stock_decreased')->default(false);
            $table->timestamp('refund_started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->default('pending');
            $table->integer('approved_count')->default(0);
            $table->integer('denied_count')->default(0);
            $table->decimal('amount', 24, 2)->default(10);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('refund_reason')->nullable();
            $table->text('images')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('change_by')->nullable();
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('order_due_amount', 24, 2)->default(0);
            $table->decimal('order_return_amount', 24, 2)->default(0);
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    /** The roles of the employees who work with orders and the POS: the master admin and the catalog employees of BuildsStaffScopeWorld have none of these modules. */
    private function addStaffOrderRoles(): void
    {
        DB::table('admin_roles')->insert([
            ['id' => 5, 'name' => 'Orders and POS', 'module_access' => json_encode(['orders', 'pos']), 'status' => 1],
            ['id' => 6, 'name' => 'POS', 'module_access' => json_encode(['pos']), 'status' => 1],
        ]);
        DB::table('admins')->insert([
            ['id' => 7, 'name' => 'Orders and POS', 'email' => 'seven@example.test', 'admin_role_id' => 5],
            ['id' => 8, 'name' => 'POS only', 'email' => 'eight@example.test', 'admin_role_id' => 6],
        ]);
    }

    /**
     * One order that holds a line for each product given, in that order. A product id that is not a product is a deleted product, and no product at all is an order
     * without a line.
     *
     * @param list<int> $products
     */
    protected function insertStaffOrder(int $id, array $products, array $extra = []): void
    {
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'customer_id' => 1,
            'is_guest' => 0,
            'order_status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 10 * max(1, count($products)),
            'seller_is' => 'admin',
            'seller_id' => 1,
            'order_type' => 'default_type',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        foreach ($products as $productId) {
            DB::table('order_details')->insert([
                'order_id' => $id,
                'product_id' => $productId,
                'seller_id' => 1,
                'product_details' => json_encode(['name' => "Product {$productId}", 'product_type' => 'physical']),
                'delivery_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** One refund request about the first line of an order that holds the product, or about no line at all when none is given. */
    protected function insertStaffRefund(int $id, int $orderId, ?int $aboutProduct, array $extra = []): void
    {
        $line = $aboutProduct === null ? null : DB::table('order_details')->where('order_id', $orderId)->where('product_id', $aboutProduct)->value('id');

        DB::table('refund_requests')->insert(array_merge([
            'id' => $id,
            'order_details_id' => $line,
            'customer_id' => 1,
            'status' => 'pending',
            'amount' => 10,
            'product_id' => $aboutProduct,
            'order_id' => $orderId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @return list<int> the ids of the lines of an order */
    protected function staffOrderLineIds(int $orderId): array
    {
        return DB::table('order_details')->where('order_id', $orderId)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
