<?php

namespace Tests\Feature\Referral;

use App\Repositories\WalletTransactionRepository;
use App\Utils\CustomerManager;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsReferralRewardWorld;
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
 * The referral_reward_grants migration is applied, so every writer goes through ReferralRewardService::creditOnce(); the double-credit
 * pins are flipped to one credit and the beneficiary, count and currency pins are unchanged.
 * The wallet ledger is stored in USD; amounts below are read back from the ledger and the user row.
 */
class ReferralRewardCharacterizationTest extends TestCase
{
    use BuildsReferralRewardWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReferralRewardWorld();
        $this->migrateReferralRewardGrants();
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

    public function test_shared_writer_pays_once_when_called_twice_for_the_same_first_order(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(1, $this->referralRows()->count());
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
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

    public function test_delivery_man_delivery_writes_one_referral_row_for_one_first_order(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $response = $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertSame('earned_by_referral', $rows[0]->reference);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    /**
     * The shared writer runs first in this request, so its amount is the one credited.
     */
    public function test_delivery_man_delivery_credits_once_at_the_shared_writer_amount_on_a_sar_store(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $this->deliverThroughDeliveryManApp(orderId: 100);

        $credits = $this->referralRows()->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all();
        $this->assertSame([10.0], $credits);
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

    public function test_seller_api_v3_order_detail_status_writes_one_referral_row_for_one_first_order(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $response = $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $this->assertSame(1, $response->getData(true)['success']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    /**
     * The inline CustomerManager credit runs before the shared writer in this request, so its amount, divided by the SAR rate, is the one credited.
     */
    public function test_seller_api_v3_order_detail_status_currently_credits_once_at_the_customer_manager_amount_on_a_sar_store(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $credits = $this->referralRows()->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all();
        $this->assertSame([round(self::REWARD_SETTING / self::SAR_PER_USD, 6)], $credits);
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

    public function test_admin_update_status_pays_once_when_delivered_is_submitted_twice(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cash_on_delivery']);

        $firstResponse = $this->deliverThroughAdminPanel(orderId: 100);
        $secondResponse = $this->deliverThroughAdminPanel(orderId: 100);

        $this->assertSame(1, $firstResponse->getData(true)['status']);
        $this->assertSame(1, $secondResponse->getData(true)['status']);
        $rows = $this->referralRows(userId: null);
        $this->assertCount(1, $rows);
        $this->assertSame(self::REFERRER_ID, (int) $rows[0]->user_id);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]->credit, 0.000001);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
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
}
