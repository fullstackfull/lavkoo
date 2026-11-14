<?php

namespace Tests\Feature\Referral;

use App\Events\OrderStatusEvent;
use App\Http\Controllers\Admin\Order\OrderController as AdminOrderController;
use App\Http\Controllers\RestAPI\v2\delivery_man\DeliveryManController;
use App\Http\Controllers\RestAPI\v2\seller\OrderController as SellerApiV2OrderController;
use App\Http\Controllers\RestAPI\v3\seller\OrderController as SellerApiV3OrderController;
use App\Http\Controllers\Vendor\Order\OrderController as VendorOrderController;
use App\Models\Seller;
use App\Repositories\WalletTransactionRepository;
use App\Services\DeliveryManTransactionService;
use App\Services\DeliveryManWalletService;
use App\Services\OrderStatusHistoryService;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Characterization of the referral reward as it is paid today (audit E04 section 5, risk R-039).
 *
 * The referral setting `ref_earning_exchange_rate` is stored in USD: the admin save converts the amount the admin enters,
 * in the default currency, with currencyConverter().
 *
 * Three wallet writers pay it:
 *  - OrderManager::generateReferBonusForFirstOrder(), called from the admin panel, seller API v2 and v3, and the delivery-man app.
 *    It passes usdToDefaultCurrency(setting) to OrderManager::createWalletTransaction(), so the ledger receives the stored USD amount.
 *  - The inline vendor-panel block in Vendor\Order\OrderController::updateStatus(), which passes floatval(setting) to
 *    WalletTransactionRepository::addWalletTransaction(). That divides by the default-currency rate again, and credits the
 *    ordering customer (the referee) instead of the referrer.
 *  - CustomerManager::create_wallet_transaction(), called inline from the delivery-man app and from seller API v3
 *    (order_detail_status and updateOrderDetails). It passes floatval(setting), which Convert::usd() divides by the
 *    default-currency rate again.
 *
 * Tests whose name contains "currently" pin a defect or a disagreement between writers: they assert today's value so that a fix flips them.
 * The wallet ledger is stored in USD; amounts below are read back from the ledger and the user row.
 */
class ReferralRewardCharacterizationTest extends TestCase
{
    use RestoresLanguageFiles;

    private const REFERRER_ID = 1;

    private const REFEREE_ID = 2;

    private const SELLER_ID = 5;

    private const DELIVERY_MAN_ID = 7;

    private const REWARD_SETTING = 10;

    private const SAR_PER_USD = 3.75;

    private const SELLER_V2_AUTH_TOKEN = 'characterization-fixture-seller-token-0001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Http::preventStrayRequests();
        Event::fake([OrderStatusEvent::class]);

        $this->createTables();
        $this->seedCurrencies();
        $this->seedSettings();
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_shared_writer_credits_the_referrer_on_the_referees_first_delivered_paid_order(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $rows = $this->referralRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertSame(0, $this->referralRows(userId: self::REFEREE_ID)->count());
    }

    public function test_shared_writer_pays_the_setting_as_a_credit_with_the_earned_by_referral_reference(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $row = $this->referralRows()->first();
        $this->assertSame('earned_by_referral', $row->reference);
        $this->assertSame('add_fund_by_admin', $row->transaction_type);
        $this->assertEqualsWithDelta(10.0, (float) $row->credit, 0.000001);
        $this->assertEqualsWithDelta(0.0, (float) $row->debit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_shared_writer_treats_the_setting_as_usd_so_a_multi_currency_ledger_stores_the_setting_unchanged(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertEqualsWithDelta(10.0, (float) $this->referralRows()->first()->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_shared_writer_pays_nothing_on_the_referees_second_delivered_paid_order(): void
    {
        $this->makeOrder(id: 100);
        $this->makeOrder(id: 101);

        OrderManager::generateReferBonusForFirstOrder(orderId: 101);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_shared_writer_currently_pays_again_when_called_twice_for_the_same_first_order(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(2, $this->referralRows()->count());
        $this->assertEqualsWithDelta(20.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_shared_writer_pays_nothing_while_the_referral_setting_is_off(): void
    {
        $this->setSetting(type: 'ref_earning_status', value: '0');
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_shared_writer_pays_nothing_while_the_wallet_is_off(): void
    {
        $this->setSetting(type: 'wallet_status', value: '0');
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_shared_writer_pays_nothing_before_the_order_is_delivered(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed']);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_shared_writer_pays_nothing_for_a_guest_order(): void
    {
        $this->makeOrder(id: 100, attributes: ['is_guest' => 1]);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_shared_writer_pays_nothing_when_the_customer_was_not_referred(): void
    {
        DB::table('users')->where('id', self::REFEREE_ID)->update(['referred_by' => null]);
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_customer_manager_writer_stores_the_setting_as_a_referral_credit_on_a_single_currency_store(): void
    {
        CustomerManager::create_wallet_transaction(self::REFERRER_ID, floatval(self::REWARD_SETTING), 'add_fund_by_admin', 'earned_by_referral');

        $row = $this->referralRows()->first();
        $this->assertSame(self::REFERRER_ID, (int) $row->user_id);
        $this->assertSame('add_fund_by_admin', $row->transaction_type);
        $this->assertEqualsWithDelta(10.0, (float) $row->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_customer_manager_writer_currently_treats_the_setting_as_default_currency_and_divides_it_by_the_sar_rate(): void
    {
        $this->useMultiCurrencyWithSarDefault();

        CustomerManager::create_wallet_transaction(self::REFERRER_ID, floatval(self::REWARD_SETTING), 'add_fund_by_admin', 'earned_by_referral');

        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, (float) $this->referralRows()->first()->credit, 0.000001);
        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_repository_writer_stores_the_setting_as_a_referral_credit_on_a_single_currency_store(): void
    {
        app(WalletTransactionRepository::class)->addWalletTransaction(user_id: (string) self::REFERRER_ID, amount: floatval(self::REWARD_SETTING), transactionType: 'add_fund_by_admin', reference: 'earned_by_referral');

        $row = $this->referralRows()->first();
        $this->assertSame(self::REFERRER_ID, (int) $row->user_id);
        $this->assertSame('add_fund_by_admin', $row->transaction_type);
        $this->assertEqualsWithDelta(10.0, (float) $row->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_repository_writer_currently_treats_the_setting_as_default_currency_and_divides_it_by_the_sar_rate(): void
    {
        $this->useMultiCurrencyWithSarDefault();

        app(WalletTransactionRepository::class)->addWalletTransaction(user_id: (string) self::REFERRER_ID, amount: floatval(self::REWARD_SETTING), transactionType: 'add_fund_by_admin', reference: 'earned_by_referral');

        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, (float) $this->referralRows()->first()->credit, 0.000001);
        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_vendor_panel_delivery_currently_credits_the_referee_instead_of_the_referrer(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $response = $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertSame(1, $response->getData(true)['status']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame('add_fund_by_admin', $rows[0]->transaction_type);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]->credit, 0.000001);
        $this->assertSame(self::REFEREE_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(0.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFEREE_ID), 0.000001);
    }

    public function test_vendor_panel_delivery_currently_divides_the_setting_by_the_sar_rate_unlike_the_shared_writer(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, (float) $this->referralRows(userId: null)->first()->credit, 0.000001);
    }

    public function test_vendor_panel_delivery_pays_nothing_on_the_referees_second_order(): void
    {
        $this->makeOrder(id: 99);
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $response = $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertSame(1, $response->getData(true)['status']);
        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertSame(0, $this->referralRows(userId: null)->count());
    }

    public function test_delivery_man_delivery_currently_writes_two_referral_rows_for_one_first_order(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $response = $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->referralRows();
        $this->assertCount(2, $rows);
        $this->assertSame([self::REFERRER_ID, self::REFERRER_ID], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['earned_by_referral', 'earned_by_referral'], $rows->pluck('reference')->all());
        $this->assertEqualsWithDelta(20.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_delivery_man_delivery_currently_stores_two_different_amounts_for_the_same_reward_on_a_sar_store(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $this->deliverThroughDeliveryManApp(orderId: 100);

        $credits = $this->referralRows()->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all();
        $this->assertSame([10.0, round(self::REWARD_SETTING / self::SAR_PER_USD, 6)], $credits);
    }

    public function test_delivery_man_delivery_pays_nothing_on_the_referees_second_order(): void
    {
        $this->makeOrder(id: 99);
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $response = $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertSame(0, $this->referralRows(userId: null)->count());
    }

    public function test_seller_api_v3_order_detail_status_currently_writes_two_referral_rows_for_one_first_order(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $response = $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $this->assertSame(1, $response->getData(true)['success']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(2, $rows);
        $this->assertSame([self::REFERRER_ID, self::REFERRER_ID], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([10.0, 10.0], $rows->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all());
        $this->assertEqualsWithDelta(20.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    /**
     * The inline CustomerManager credit is written before the shared writer's credit in the same request.
     */
    public function test_seller_api_v3_order_detail_status_currently_stores_two_different_amounts_on_a_sar_store(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $credits = $this->referralRows()->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all();
        $this->assertSame([round(self::REWARD_SETTING / self::SAR_PER_USD, 6), 10.0], $credits);
    }

    public function test_seller_api_v3_update_order_details_pays_the_referrer_once_on_a_single_currency_store(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $response = $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_seller_api_v3_update_order_details_currently_divides_the_setting_by_the_sar_rate(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);

        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(self::REWARD_SETTING / self::SAR_PER_USD, (float) $rows[0]->credit, 0.000001);
    }

    public function test_admin_update_status_currently_pays_again_when_delivered_is_submitted_twice(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery']);

        $firstResponse = $this->deliverThroughAdminPanel(orderId: 100);
        $secondResponse = $this->deliverThroughAdminPanel(orderId: 100);

        $this->assertSame(1, $firstResponse->getData(true)['status']);
        $this->assertSame(1, $secondResponse->getData(true)['status']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(2, $rows);
        $this->assertSame([self::REFERRER_ID, self::REFERRER_ID], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([10.0, 10.0], $rows->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all());
        $this->assertEqualsWithDelta(20.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_vendor_panel_delivery_currently_counts_a_cancelled_order_toward_the_first_order_check(): void
    {
        $this->makeOrder(id: 99, attributes: ['order_status' => 'canceled', 'payment_status' => 'unpaid']);
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $response = $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertSame(1, $response->getData(true)['status']);
        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertSame(0, $this->referralRows(userId: null)->count());
    }

    public function test_vendor_panel_redelivery_pays_nothing(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $firstResponse = $this->deliverThroughVendorPanel(orderId: 100);
        $secondResponse = $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertSame(1, $firstResponse->getData(true)['status']);
        $this->assertSame(0, $secondResponse->getData(true)['status']);
        $this->assertSame(translate('order_is_already_delivered.'), $secondResponse->getData(true)['message']);
        $this->assertSame(1, $this->referralRows(userId: null)->count());
    }

    public function test_seller_api_v2_order_detail_status_pays_the_referrer_once_through_the_shared_writer(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'payment_status' => 'paid', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $response = $this->deliverThroughSellerApiV2(orderId: 100);

        $this->assertSame(1, $response->getData(true)['success']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    private function undeliveredVendorOrder(): array
    {
        return ['order_status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller'];
    }

    /**
     * The seller_api_auth middleware puts the authenticated seller on the request as `seller`; these helpers seed the same key.
     */
    private function deliverThroughSellerApiV3OrderDetailStatus(int $orderId)
    {
        $request = Request::create('/api/v3/seller/orders/order-detail-status/' . $orderId, 'PUT', ['id' => $orderId, 'order_status' => 'delivered']);
        $request['seller'] = Seller::find(self::SELLER_ID);

        return app(SellerApiV3OrderController::class)->order_detail_status($request);
    }

    private function deliverThroughSellerApiV3UpdateOrderDetails(int $orderId)
    {
        $request = Request::create('/api/v3/seller/orders/order-detail-info-update', 'POST', ['order_id' => $orderId, 'payment_status' => 'paid', 'order_status' => 'delivered']);
        $request['seller'] = Seller::find(self::SELLER_ID);

        return app(SellerApiV3OrderController::class)->updateOrderDetails($request);
    }

    private function deliverThroughSellerApiV2(int $orderId)
    {
        $request = Request::create('/api/v2/seller/orders/order-detail-status/' . $orderId, 'PUT', ['id' => $orderId, 'order_status' => 'delivered'], server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::SELLER_V2_AUTH_TOKEN]);

        return app(SellerApiV2OrderController::class)->order_detail_status($request);
    }

    private function deliverThroughAdminPanel(int $orderId)
    {
        $request = Request::create('/admin/orders/status', 'POST', ['id' => $orderId, 'order_status' => 'delivered']);

        return app(AdminOrderController::class)->updateStatus(
            $request,
            app(DeliveryManTransactionService::class),
            app(DeliveryManWalletService::class),
            app(OrderStatusHistoryService::class),
        );
    }

    private function deliverThroughVendorPanel(int $orderId)
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

    private function deliverThroughDeliveryManApp(int $orderId)
    {
        $request = Request::create('/api/v2/delivery-man/update-order-status', 'PUT', ['order_id' => $orderId, 'status' => 'delivered']);
        $request->merge(['delivery_man' => ['id' => self::DELIVERY_MAN_ID]]);

        return app(DeliveryManController::class)->update_order_status($request);
    }

    private function referralRows(?int $userId = self::REFERRER_ID)
    {
        return DB::table('wallet_transactions')
            ->where('reference', 'earned_by_referral')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get();
    }

    private function walletBalance(int $userId): float
    {
        return (float) DB::table('users')->where('id', $userId)->value('wallet_balance');
    }

    private function makeOrder(int $id, array $attributes = []): void
    {
        DB::table('orders')->insert($attributes + [
            'id' => $id, 'customer_id' => self::REFEREE_ID, 'is_guest' => 0, 'customer_type' => 'customer',
            'order_status' => 'delivered', 'payment_status' => 'paid', 'payment_method' => 'cash_on_delivery', 'order_type' => 'default_type',
            'seller_id' => null, 'seller_is' => 'admin', 'order_amount' => 100, 'shipping_cost' => 0, 'edit_due_amount' => 0, 'edit_return_amount' => 0,
            'deliveryman_charge' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function useMultiCurrencyWithSarDefault(): void
    {
        $this->setSetting(type: 'currency_model', value: 'multi_currency');
        $this->setSetting(type: 'system_default_currency', value: '2');
    }

    private function seedSettings(): void
    {
        foreach ([
            'wallet_status' => '1', 'ref_earning_status' => '1', 'ref_earning_exchange_rate' => (string) self::REWARD_SETTING,
            'currency_model' => 'single_currency', 'system_default_currency' => '1', 'decimal_point_settings' => '2',
            'loyalty_point_status' => '0', 'loyalty_point_for_each_order' => '0', 'loyalty_point_exchange_rate' => '1',
        ] as $type => $value) {
            $this->setSetting(type: $type, value: $value);
        }
    }

    private function seedCurrencies(): void
    {
        DB::table('currencies')->insert([
            ['id' => 1, 'name' => 'US Dollar', 'symbol' => '$', 'code' => 'USD', 'exchange_rate' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => self::SAR_PER_USD, 'status' => 1],
        ]);
    }

    private function seedUsers(): void
    {
        DB::table('users')->insert([
            ['id' => self::REFERRER_ID, 'f_name' => 'Referrer', 'referred_by' => null, 'wallet_balance' => 0],
            ['id' => self::REFEREE_ID, 'f_name' => 'Referee', 'referred_by' => self::REFERRER_ID, 'wallet_balance' => 0],
        ]);
        DB::table('sellers')->insert(['id' => self::SELLER_ID, 'f_name' => 'Vendor', 'auth_token' => self::SELLER_V2_AUTH_TOKEN]);
        DB::table('shops')->insert(['seller_id' => self::SELLER_ID, 'author_type' => 'vendor', 'name' => 'Vendor shop']);
        DB::table('delivery_men')->insert(['id' => self::DELIVERY_MAN_ID, 'f_name' => 'Courier']);
    }

    private function createTables(): void
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
