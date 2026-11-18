<?php

namespace Tests\Support\Refund;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Wallet\WalletTables;

/**
 * The order, refund and reward tables that the admin refund, return-amount and order-status flows read and write, with only the
 * columns those flows touch. The customer, wallet, loyalty ledger and settings tables come from WalletTables and LoyaltyTables.
 */
final class RefundTables
{
    private const TABLES = [
        'orders', 'order_details', 'order_details_rewards', 'refund_requests', 'refund_statuses', 'refund_transactions',
        'admin_wallets', 'seller_wallets', 'order_edit_histories', 'order_status_histories', 'order_transactions',
        'referral_customers', 'products', 'reviews', 'currencies', 'storages',
    ];

    public static function create(): void
    {
        WalletTables::guardTestDatabase();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->string('customer_type')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 40, 2)->default(0);
            $table->decimal('shipping_cost', 40, 2)->default(0);
            $table->decimal('edit_due_amount', 40, 2)->default(0);
            $table->decimal('edit_return_amount', 40, 2)->default(0);
            $table->string('order_type')->default('default_type');
            $table->boolean('is_pause')->default(false);
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('deliveryman_charge', 40, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 40, 2)->default(0);
            $table->decimal('discount', 40, 2)->default(0);
            $table->decimal('tax', 40, 2)->default(0);
            $table->string('variant')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->integer('refund_request')->default(0);
            $table->boolean('is_stock_decreased')->default(true);
            $table->timestamp('refund_started_at')->nullable();
            $table->string('digital_file_after_sell')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->string('reward_type');
            $table->decimal('reward_amount', 24, 4)->default(0);
            $table->longText('reward_details')->nullable();
            $table->boolean('reward_delivered')->default(false);
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 24, 2)->default(0);
            $table->text('refund_reason')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('change_by')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('refund_request_id');
            $table->string('change_by')->nullable();
            $table->unsignedBigInteger('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->timestamps();
        });
        Schema::create('refund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_for')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('payment_receiver_id')->nullable();
            $table->string('paid_by')->nullable();
            $table->string('paid_to')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount', 24, 2)->default(0);
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->decimal('inhouse_earning', 24, 2)->default(0);
            $table->decimal('pending_amount', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('seller_wallets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('total_earning', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('edit_by')->nullable();
            $table->unsignedBigInteger('edited_user_id')->nullable();
            $table->string('edited_user_name')->nullable();
            $table->decimal('order_return_amount', 24, 2)->default(0);
            $table->string('order_return_payment_status')->nullable();
            $table->string('order_return_payment_method')->nullable();
            $table->string('order_return_transaction_ref')->nullable();
            $table->string('order_return_payment_note')->nullable();
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->text('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('referral_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('delivered_notify')->default(false);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_type')->default('physical');
            $table->integer('current_stock')->default(0);
            $table->longText('variation')->nullable();
            $table->timestamps();
        });
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('storages', function (Blueprint $table): void {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code');
            $table->decimal('exchange_rate', 24, 6)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public static function drop(): void
    {
        WalletTables::guardTestDatabase();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public static function seedCurrency(int $id, string $code, float $exchangeRate): void
    {
        DB::table('currencies')->insert([
            'id' => $id, 'name' => $code, 'symbol' => $code, 'code' => $code, 'exchange_rate' => $exchangeRate, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
