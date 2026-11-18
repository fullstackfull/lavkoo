<?php

namespace Tests\Support;

use App\Events\OrderStatusEvent;
use App\Http\Controllers\Admin\Order\OrderController as AdminOrderController;
use App\Http\Controllers\RestAPI\v2\delivery_man\DeliveryManController;
use App\Http\Controllers\RestAPI\v2\seller\OrderController as SellerApiV2OrderController;
use App\Http\Controllers\RestAPI\v3\seller\OrderController as SellerApiV3OrderController;
use App\Http\Controllers\Vendor\Order\OrderController as VendorOrderController;
use App\Models\Seller;
use App\Services\DeliveryManTransactionService;
use App\Services\DeliveryManWalletService;
use App\Services\OrderStatusHistoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A referrer, a referred customer, a vendor, a courier and the minimum tables the referral reward writers and the five
 * delivery entry points read, on SQLite. The delivery helpers call the real controller methods.
 */
trait BuildsReferralRewardWorld
{
    use RestoresLanguageFiles;

    private const REFERRER_ID = 1;

    private const REFEREE_ID = 2;

    private const SELLER_ID = 5;

    private const DELIVERY_MAN_ID = 7;

    private const REWARD_SETTING = 10;

    private const SAR_PER_USD = 3.75;

    private const SELLER_V2_AUTH_TOKEN = 'characterization-fixture-seller-token-0001';

    protected function setUpReferralRewardWorld(): void
    {
        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Event::fake([OrderStatusEvent::class]);

        $this->createTables();
        $this->seedCurrencies();
        $this->seedSettings();
        $this->seedUsers();
    }

    protected function migrateReferralRewardGrants(): void
    {
        $this->referralRewardGrantsMigration()->up();
    }

    protected function referralRewardGrantsMigration(): object
    {
        return require base_path('database/migrations/2026_11_23_100000_create_referral_reward_grants_table.php');
    }

    protected function undeliveredVendorOrder(): array
    {
        return ['order_status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller'];
    }

    /**
     * The seller_api_auth middleware puts the authenticated seller on the request as `seller`; these helpers seed the same key.
     */
    protected function deliverThroughSellerApiV3OrderDetailStatus(int $orderId)
    {
        $request = Request::create('/api/v3/seller/orders/order-detail-status/' . $orderId, 'PUT', ['id' => $orderId, 'order_status' => 'delivered']);
        $request['seller'] = Seller::find(self::SELLER_ID);

        return app(SellerApiV3OrderController::class)->order_detail_status($request);
    }

    protected function deliverThroughSellerApiV3UpdateOrderDetails(int $orderId)
    {
        $request = Request::create('/api/v3/seller/orders/order-detail-info-update', 'POST', ['order_id' => $orderId, 'payment_status' => 'paid', 'order_status' => 'delivered']);
        $request['seller'] = Seller::find(self::SELLER_ID);

        return app(SellerApiV3OrderController::class)->updateOrderDetails($request);
    }

    protected function deliverThroughSellerApiV2(int $orderId)
    {
        $request = Request::create('/api/v2/seller/orders/order-detail-status/' . $orderId, 'PUT', ['id' => $orderId, 'order_status' => 'delivered'], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::SELLER_V2_AUTH_TOKEN]);

        return app(SellerApiV2OrderController::class)->order_detail_status($request);
    }

    protected function deliverThroughAdminPanel(int $orderId)
    {
        $request = Request::create('/admin/orders/status', 'POST', ['id' => $orderId, 'order_status' => 'delivered']);

        return app(AdminOrderController::class)->updateStatus(
            $request,
            app(DeliveryManTransactionService::class),
            app(DeliveryManWalletService::class),
            app(OrderStatusHistoryService::class),
        );
    }

    protected function deliverThroughVendorPanel(int $orderId)
    {
        $this->actingAs((new Seller())->forceFill(['id' => self::SELLER_ID, 'f_name' => 'Vendor']), 'seller');
        DB::table('order_transactions')->insert(['order_id' => $orderId, 'status' => 'disburse']);

        $request = Request::create('/vendor/orders/status', 'POST', ['id' => $orderId, 'order_status' => 'delivered']);

        return app(VendorOrderController::class)->updateStatus(
            $request,
            app(DeliveryManTransactionService::class),
            app(DeliveryManWalletService::class),
            app(OrderStatusHistoryService::class),
        );
    }

    protected function deliverThroughDeliveryManApp(int $orderId)
    {
        $request = Request::create('/api/v2/delivery-man/update-order-status', 'PUT', ['order_id' => $orderId, 'status' => 'delivered']);
        $request->merge(['delivery_man' => ['id' => self::DELIVERY_MAN_ID]]);

        return app(DeliveryManController::class)->update_order_status($request);
    }

    protected function referralRows(?int $userId = self::REFERRER_ID)
    {
        return DB::table('wallet_transactions')
            ->where('reference', 'earned_by_referral')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get();
    }

    protected function walletBalance(int $userId): float
    {
        return (float) DB::table('users')->where('id', $userId)->value('wallet_balance');
    }

    protected function makeOrder(int $id, array $attributes = []): void
    {
        DB::table('orders')->insert($attributes + [
            'id' => $id, 'customer_id' => self::REFEREE_ID, 'is_guest' => 0, 'customer_type' => 'customer',
            'order_status' => 'delivered', 'payment_status' => 'paid', 'payment_method' => 'cash_on_delivery', 'order_type' => 'default_type',
            'seller_id' => null, 'seller_is' => 'admin', 'order_amount' => 100, 'shipping_cost' => 0, 'edit_due_amount' => 0, 'edit_return_amount' => 0,
            'deliveryman_charge' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function setSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    protected function useMultiCurrencyWithSarDefault(): void
    {
        $this->setSetting(type: 'currency_model', value: 'multi_currency');
        $this->setSetting(type: 'system_default_currency', value: '2');
    }

    protected function seedSettings(): void
    {
        foreach ([
            'wallet_status' => '1', 'ref_earning_status' => '1', 'ref_earning_exchange_rate' => (string) self::REWARD_SETTING,
            'currency_model' => 'single_currency', 'system_default_currency' => '1', 'decimal_point_settings' => '2',
            'loyalty_point_status' => '0', 'loyalty_point_for_each_order' => '0', 'loyalty_point_exchange_rate' => '1',
        ] as $type => $value) {
            $this->setSetting(type: $type, value: $value);
        }
    }

    protected function seedCurrencies(): void
    {
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => self::SAR_PER_USD, 'status' => 1],
        ]);
    }

    protected function seedUsers(): void
    {
        DB::table('users')->insert([
            ['id' => self::REFERRER_ID, 'f_name' => 'Referrer', 'referred_by' => null, 'wallet_balance' => 0],
            ['id' => self::REFEREE_ID, 'f_name' => 'Referee', 'referred_by' => self::REFERRER_ID, 'wallet_balance' => 0],
        ]);
        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Vendor', 'auth_token' => self::SELLER_V2_AUTH_TOKEN, 'status' => 'approved']);
        DB::table('shops')->insert(['seller_id' => self::SELLER_ID, 'author_type' => 'vendor', 'name' => 'Vendor shop']);
        DB::table('delivery_men')->insert(['id' => self::DELIVERY_MAN_ID, 'f_name' => 'Courier']);
    }

    protected function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image')->nullable();
            $table->string('password')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->decimal('wallet_balance', 18, 12)->default(0);
            $table->decimal('loyalty_point', 18, 12)->default(0);
            $table->string('referral_code')->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->boolean('is_active')->default(1);
            $table->string('app_language')->default('en');
            $table->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code');
            $table->decimal('exchange_rate', 24, 12);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('transaction_id');
            $table->decimal('credit', 21, 12)->default(0);
            $table->decimal('debit', 21, 12)->default(0);
            $table->decimal('admin_bonus', 21, 12)->default(0);
            $table->decimal('balance', 21, 12)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(0);
            $table->string('customer_type')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('order_status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('order_type')->default('default_type');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->decimal('order_amount', 24, 6)->default(0);
            $table->decimal('shipping_cost', 24, 6)->default(0);
            $table->decimal('edit_due_amount', 24, 6)->default(0);
            $table->decimal('edit_return_amount', 24, 6)->default(0);
            $table->decimal('deliveryman_charge', 24, 6)->default(0);
            $table->decimal('admin_commission', 24, 6)->default(0);
            $table->decimal('discount_amount', 24, 6)->default(0);
            $table->decimal('total_tax_amount', 24, 6)->default(0);
            $table->string('shipping_responsibility')->nullable();
            $table->boolean('is_pause')->default(0);
            $table->string('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->text('product_details')->nullable();
            $table->string('digital_file_after_sell')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->boolean('is_stock_decreased')->default(1);
            $table->integer('qty')->default(1);
            $table->timestamp('refund_started_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_method')->nullable();
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->string('order_due_payment_note')->nullable();
            $table->decimal('order_due_amount', 24, 6)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('reward_type')->nullable();
            $table->decimal('reward_amount', 24, 6)->default(0);
            $table->boolean('reward_delivered')->default(0);
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('status')->nullable();
            $table->string('cause')->nullable();
            $table->timestamps();
        });
        Schema::create('order_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('status')->nullable();
            $table->string('transaction_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('seller_is')->nullable();
            $table->decimal('order_amount', 24, 6)->default(0);
            $table->decimal('seller_amount', 24, 6)->default(0);
            $table->decimal('admin_commission', 24, 6)->default(0);
            $table->string('received_by')->nullable();
            $table->decimal('delivery_charge', 24, 6)->default(0);
            $table->decimal('tax', 24, 6)->default(0);
            $table->string('delivered_by')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->decimal('withdrawn', 24, 6)->default(0);
            $table->decimal('commission_earned', 24, 6)->default(0);
            $table->decimal('inhouse_earning', 24, 6)->default(0);
            $table->decimal('delivery_charge_earned', 24, 6)->default(0);
            $table->decimal('pending_amount', 24, 6)->default(0);
            $table->decimal('total_tax_collected', 24, 6)->default(0);
            $table->timestamps();
        });
        Schema::create('seller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->decimal('withdrawn', 24, 6)->default(0);
            $table->decimal('commission_given', 24, 6)->default(0);
            $table->decimal('total_earning', 24, 6)->default(0);
            $table->decimal('pending_withdraw', 24, 6)->default(0);
            $table->decimal('delivery_charge_earned', 24, 6)->default(0);
            $table->decimal('collected_cash', 24, 6)->default(0);
            $table->decimal('total_tax_collected', 24, 6)->default(0);
            $table->timestamps();
        });
        Schema::create('deliveryman_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_man_id');
            $table->decimal('current_balance', 24, 6)->default(0);
            $table->decimal('cash_in_hand', 24, 6)->default(0);
            $table->decimal('pending_withdraw', 24, 6)->default(0);
            $table->decimal('total_withdraw', 24, 6)->default(0);
            $table->timestamps();
        });
        Schema::create('referral_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('refer_by')->nullable();
            $table->boolean('delivered_notify')->default(0);
            $table->boolean('is_used')->default(0);
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });
    }
}
