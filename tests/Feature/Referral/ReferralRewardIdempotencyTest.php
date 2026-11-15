<?php

namespace Tests\Feature\Referral;

use App\Models\ReferralRewardGrant;
use App\Services\Referral\ReferralRewardService;
use App\Utils\OrderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsReferralRewardWorld;
use Tests\TestCase;

/**
 * The first-order referral reward is granted at most once per (referee, beneficiary), whichever delivery path runs,
 * however often it runs, and whichever writer in one request runs first.
 */
class ReferralRewardIdempotencyTest extends TestCase
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

    public function test_a_grant_records_the_referee_the_beneficiary_the_order_and_the_wallet_row(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $walletRow = $this->referralRows()->sole();
        $grant = $this->grantRows()->sole();
        $this->assertSame(self::REFEREE_ID, (int) $grant->referee_id);
        $this->assertSame(self::REFERRER_ID, (int) $grant->beneficiary_id);
        $this->assertSame(100, (int) $grant->order_id);
        $this->assertSame((int) $walletRow->id, (int) $grant->wallet_transaction_id);
    }

    public function test_two_sequential_calls_of_the_guarded_method_run_the_credit_once(): void
    {
        $this->makeOrder(id: 100);
        $creditCalls = 0;
        $credit = function () use (&$creditCalls) {
            $creditCalls++;

            return OrderManager::createWalletTransaction(user_id: self::REFERRER_ID, amount: 10, transaction_type: 'add_fund_by_admin', reference: 'earned_by_referral');
        };

        $first = $this->referralRewards()->creditOnce(refereeId: self::REFEREE_ID, beneficiaryId: self::REFERRER_ID, orderId: 100, credit: $credit);
        $second = $this->referralRewards()->creditOnce(refereeId: self::REFEREE_ID, beneficiaryId: self::REFERRER_ID, orderId: 100, credit: $credit);

        $this->assertNotFalse($first);
        $this->assertFalse($second);
        $this->assertSame(1, $creditCalls);
        $this->assertSame(1, $this->referralRows()->count());
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    /**
     * A concurrent request that committed its grant first leaves the row in place before this request reaches the credit.
     */
    public function test_a_grant_already_committed_by_another_request_prevents_the_credit(): void
    {
        $this->makeOrder(id: 100);
        $this->insertCommittedGrant(orderId: 100);
        $creditCalls = 0;

        $result = $this->referralRewards()->creditOnce(refereeId: self::REFEREE_ID, beneficiaryId: self::REFERRER_ID, orderId: 100, credit: function () use (&$creditCalls) {
            $creditCalls++;

            return false;
        });
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertFalse($result);
        $this->assertSame(0, $creditCalls);
        $this->assertSame(0, $this->referralRows(userId: null)->count());
        $this->assertEqualsWithDelta(0.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_a_grant_already_committed_by_another_request_prevents_both_delivery_man_writers(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);
        $this->insertCommittedGrant(orderId: 100);

        $response = $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->referralRows(userId: null)->count());
        $this->assertSame(1, $this->grantRows()->count());
    }

    public function test_wallet_off_credits_nothing_and_leaves_no_grant_so_a_later_delivery_still_pays(): void
    {
        $this->setSetting(type: 'wallet_status', value: '0');
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(0, $this->referralRows()->count());
        $this->assertSame(0, $this->grantRows()->count());

        $this->setSetting(type: 'wallet_status', value: '1');
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(1, $this->referralRows()->count());
        $this->assertSame(1, $this->grantRows()->count());
    }

    public function test_an_error_inside_the_credit_propagates_and_rolls_the_grant_back(): void
    {
        $this->makeOrder(id: 100);

        $this->assertThrows(
            fn () => $this->referralRewards()->creditOnce(refereeId: self::REFEREE_ID, beneficiaryId: self::REFERRER_ID, orderId: 100, credit: fn () => throw new RuntimeException('wallet writer failed')),
            RuntimeException::class,
        );

        $this->assertSame(0, $this->grantRows()->count());
        $this->assertSame(0, $this->referralRows()->count());
    }

    public function test_a_later_order_of_a_referee_already_rewarded_pays_nothing_after_the_first_order_is_returned(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed']);
        $this->deliverThroughAdminPanel(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'returned');
        $this->makeOrder(id: 101, attributes: ['order_status' => 'confirmed']);

        $this->deliverThroughAdminPanel(orderId: 101);

        $this->assertSame('delivered', DB::table('orders')->where('id', 101)->value('order_status'));
        $this->assertSame(1, $this->referralRows()->count());
        $this->assertSame(100, (int) $this->grantRows()->sole()->order_id);
    }

    public function test_shared_writer_grant_keeps_todays_amount_on_a_single_currency_store(): void
    {
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame([10.0], $this->referralCredits());
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
    }

    public function test_shared_writer_grant_keeps_todays_amount_on_a_multi_currency_store(): void
    {
        $this->useMultiCurrencyWithSarDefault();
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame([10.0], $this->referralCredits());
    }

    public function test_delivery_man_delivering_twice_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $this->deliverThroughDeliveryManApp(orderId: 100);
        $secondResponse = $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame(0, $secondResponse->getData(true)['success']);
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_delivery_man_redelivering_after_a_revert_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $this->deliverThroughDeliveryManApp(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'out_for_delivery');
        $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_delivery_man_second_writer_in_the_same_request_grants_nothing(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'out_for_delivery', 'delivery_man_id' => self::DELIVERY_MAN_ID]);

        $this->deliverThroughDeliveryManApp(orderId: 100);

        $this->assertGrantedOnceToTheReferrer();
        $this->assertSame((int) $this->referralRows()->sole()->id, (int) $this->grantRows()->sole()->wallet_transaction_id);
    }

    public function test_seller_api_v3_order_detail_status_delivering_twice_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);
        $secondResponse = $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $this->assertSame(0, $secondResponse->getData(true)['success']);
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_seller_api_v3_order_detail_status_redelivering_after_a_revert_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'confirmed');
        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_seller_api_v3_order_detail_status_second_writer_in_the_same_request_grants_nothing(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3OrderDetailStatus(orderId: 100);

        $this->assertGrantedOnceToTheReferrer();
        $this->assertSame((int) $this->referralRows()->sole()->id, (int) $this->grantRows()->sole()->wallet_transaction_id);
    }

    public function test_seller_api_v3_update_order_details_delivering_twice_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);
        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);

        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_seller_api_v3_update_order_details_redelivering_after_a_revert_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'confirmed');
        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);

        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_admin_redelivering_after_a_revert_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed']);

        $this->deliverThroughAdminPanel(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'returned');
        $this->deliverThroughAdminPanel(orderId: 100);

        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_seller_api_v2_delivering_twice_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'payment_status' => 'paid', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $this->deliverThroughSellerApiV2(orderId: 100);
        $this->deliverThroughSellerApiV2(orderId: 100);

        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_seller_api_v2_redelivering_after_a_revert_grants_once(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'payment_status' => 'paid', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $this->deliverThroughSellerApiV2(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'confirmed');
        $this->deliverThroughSellerApiV2(orderId: 100);

        $this->assertSame('delivered', DB::table('orders')->where('id', 100)->value('order_status'));
        $this->assertGrantedOnceToTheReferrer();
    }

    public function test_a_reward_paid_by_one_path_is_not_paid_again_by_another_path_after_a_revert(): void
    {
        $this->makeOrder(id: 100, attributes: $this->undeliveredVendorOrder());

        $this->deliverThroughSellerApiV3UpdateOrderDetails(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'returned');
        $this->deliverThroughAdminPanel(orderId: 100);

        $this->assertGrantedOnceToTheReferrer();
    }

    /**
     * The vendor panel still credits the referee (pinned defect), so its grant is keyed (referee, referee) and cannot block a referrer's grant.
     */
    public function test_vendor_panel_redelivering_after_a_revert_grants_once_to_the_pinned_beneficiary(): void
    {
        $this->makeOrder(id: 100, attributes: ['order_status' => 'confirmed', 'seller_id' => self::SELLER_ID, 'seller_is' => 'seller']);

        $this->deliverThroughVendorPanel(orderId: 100);
        $this->setOrderStatus(orderId: 100, status: 'confirmed');
        $this->deliverThroughVendorPanel(orderId: 100);

        $this->assertSame(1, $this->referralRows(userId: self::REFEREE_ID)->count());
        $this->assertSame(0, $this->referralRows(userId: self::REFERRER_ID)->count());
        $grant = $this->grantRows()->sole();
        $this->assertSame(self::REFEREE_ID, (int) $grant->referee_id);
        $this->assertSame(self::REFEREE_ID, (int) $grant->beneficiary_id);
    }

    public function test_before_the_migration_runs_the_writers_behave_as_today(): void
    {
        $this->referralRewardGrantsMigration()->down();
        $this->makeOrder(id: 100);

        OrderManager::generateReferBonusForFirstOrder(orderId: 100);
        OrderManager::generateReferBonusForFirstOrder(orderId: 100);

        $this->assertSame(2, $this->referralRows()->count());
    }

    public function test_the_migration_is_additive_repeatable_and_reversible(): void
    {
        $this->referralRewardGrantsMigration()->down();
        DB::table('wallet_transactions')->insert(['user_id' => self::REFERRER_ID, 'transaction_id' => 'historical-referral-row', 'credit' => 10, 'balance' => 10, 'transaction_type' => 'add_fund_by_admin', 'reference' => 'earned_by_referral']);
        $walletRowsBefore = DB::table('wallet_transactions')->get()->toArray();

        $this->referralRewardGrantsMigration()->up();
        $this->referralRewardGrantsMigration()->up();

        $uniqueIndex = collect(Schema::getIndexes('referral_reward_grants'))->firstWhere('name', 'referral_reward_grants_referee_beneficiary_unique');
        $this->assertTrue($uniqueIndex['unique']);
        $this->assertSame(['referee_id', 'beneficiary_id'], $uniqueIndex['columns']);
        $this->assertSame(0, $this->grantRows()->count());
        $this->assertEquals($walletRowsBefore, DB::table('wallet_transactions')->get()->toArray());

        $this->referralRewardGrantsMigration()->down();

        $this->assertFalse(Schema::hasTable('referral_reward_grants'));
        $this->assertEquals($walletRowsBefore, DB::table('wallet_transactions')->get()->toArray());
    }

    private function assertGrantedOnceToTheReferrer(): void
    {
        $this->assertSame(1, $this->referralRows(userId: null)->count());
        $this->assertSame(self::REFERRER_ID, (int) $this->referralRows(userId: null)->sole()->user_id);
        $this->assertEqualsWithDelta(10.0, $this->walletBalance(self::REFERRER_ID), 0.000001);
        $this->assertSame(1, $this->grantRows()->count());
    }

    private function referralRewards(): ReferralRewardService
    {
        return app(ReferralRewardService::class);
    }

    private function insertCommittedGrant(int $orderId): void
    {
        ReferralRewardGrant::create(['referee_id' => self::REFEREE_ID, 'beneficiary_id' => self::REFERRER_ID, 'order_id' => $orderId]);
    }

    private function setOrderStatus(int $orderId, string $status): void
    {
        DB::table('orders')->where('id', $orderId)->update(['order_status' => $status]);
    }

    private function grantRows(): Collection
    {
        return DB::table('referral_reward_grants')->orderBy('id')->get();
    }

    /** @return list<float> */
    private function referralCredits(): array
    {
        return $this->referralRows()->pluck('credit')->map(fn ($credit) => round((float) $credit, 6))->all();
    }
}
