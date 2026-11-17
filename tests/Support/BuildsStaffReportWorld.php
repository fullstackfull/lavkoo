<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the people screens, the dashboard and the reports read besides the orders of BuildsStaffOrderWorld (GCC-14c, slice 3): the addresses of a customer, the wallets of
 * the shop, of a vendor and of a delivery man, the ratings, the wish lists, the restock requests and the messages. Only the columns the screens read are built.
 *
 * Employees: the ones of BuildsStaffOrderWorld, plus 9 who holds the people, report and order screens and the dashboard (role 7).
 */
trait BuildsStaffReportWorld
{
    use BuildsStaffOrderWorld;

    protected function buildStaffReportTables(): void
    {
        $this->buildStaffOrderTables();
        $this->addStaffReportRole();

        foreach (['shipping_types', 'coupons', 'order_delivery_verifications', 'offline_payments', 'shipping_methods', 'delivery_country_codes', 'delivery_zip_codes', 'shipping_addresses', 'seller_wallets', 'admin_wallets', 'deliveryman_wallets', 'wishlists', 'chattings', 'restock_product_customers', 'restock_products'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->integer('rating')->default(5);
            $table->text('comment')->nullable();
            $table->string('added_by')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->default(0);
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('coupon_type')->nullable();
            $table->string('coupon_bearer')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->decimal('min_purchase', 24, 2)->default(0);
            $table->decimal('max_discount', 24, 2)->default(0);
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->integer('limit')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->string('creator_type')->nullable();
            $table->string('title')->nullable();
            $table->decimal('cost', 24, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('delivery_country_codes', function (Blueprint $table) {
            $table->id();
            $table->string('country_code')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_zip_codes', function (Blueprint $table) {
            $table->id();
            $table->string('zipcode')->nullable();
            $table->timestamps();
        });
        Schema::create('shipping_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('contact_person_name')->nullable();
            $table->string('address_type')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('total_earning', 24, 2)->default(0);
            $table->decimal('withdrawn', 24, 2)->default(0);
            $table->decimal('pending_withdraw', 24, 2)->default(0);
            $table->decimal('commission_given', 24, 2)->default(0);
            $table->decimal('delivery_charge_earned', 24, 2)->default(0);
            $table->decimal('collected_cash', 24, 2)->default(0);
            $table->decimal('total_tax_collected', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->decimal('inhouse_earning', 24, 2)->default(0);
            $table->decimal('withdrawn', 24, 2)->default(0);
            $table->decimal('commission_earned', 24, 2)->default(0);
            $table->decimal('delivery_charge_earned', 24, 2)->default(0);
            $table->decimal('pending_amount', 24, 2)->default(0);
            $table->decimal('total_tax_collected', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('deliveryman_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('current_balance', 24, 2)->default(0);
            $table->decimal('cash_in_hand', 24, 2)->default(0);
            $table->decimal('total_withdraw', 24, 2)->default(0);
            $table->decimal('pending_withdraw', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
        });
        Schema::create('chattings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->boolean('seen_by_admin')->default(false);
            $table->string('notification_receiver')->nullable();
            $table->boolean('seen_notification')->default(false);
            $table->timestamps();
        });
        Schema::create('restock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        Schema::create('restock_product_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restock_product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('variant')->nullable();
            $table->timestamps();
        });
    }

    private function addStaffReportRole(): void
    {
        DB::table('admin_roles')->insert(['id' => 7, 'name' => 'People and reports', 'module_access' => json_encode(['people', 'reports', 'orders', 'dashboard']), 'status' => 1]);
        DB::table('admins')->insert(['id' => 9, 'name' => 'People and reports', 'email' => 'nine@example.test', 'admin_role_id' => 7]);
    }
}
