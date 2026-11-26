<?php

namespace Tests\Feature\Loyalty;

use App\Services\Loyalty\WhenADeliveryEarnsPoints;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsReferralRewardWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * Five channels deliver an order and five of them decide differently whether it earns points (E-14, finding LO-08).
 *
 * The table and what each disagreement costs are in `App\Services\Loyalty\WhenADeliveryEarnsPoints`. This drives the REAL channels -- the
 * admin panel, the vendor panel, seller API v2 and both seller API v3 endpoints -- through the world the referral characterization uses,
 * which is the only fixture in this repository that reaches all of them.
 *
 * **The default reading is the platform as it runs today, condition by condition, and the first group of tests is that measurement.** The
 * sharpest of them is the wallet: with the wallet switched OFF, the admin panel credits points and the other four credit nothing, which is
 * four channels refusing a reward over a setting that has nothing to do with points.
 *
 * **The second group pins a DEFECT rather than describing one.** A seller delivering one order twice credits the customer twice, because
 * the three seller-API sites never read the order's reward row and so never claim it, while the panels claim it first (IDEM-08) and credit
 * once. It is pinned under the default and REPAIRED by the `one_rule` reading, which is not adopted: what a customer is credited is the
 * owner's decision, not this branch's.
 *
 * Tests whose name says `currently` assert today's figure so that adopting a reading flips them on purpose.
 */
class ADeliveryEarnsUnderOneRuleTest extends TestCase
{
    use BuildsReferralRewardWorld;
    use RestoresLanguageFiles;

    private const CUSTOMER = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReferralRewardWorld();
        $this->migrateReferralRewardGrants();
        $this->buildLoyaltyLedger();
        $this->setSetting(type: 'loyalty_point_status', value: '1');
        $this->setSetting(type: 'loyalty_point_for_each_order', value: '1');
        $this->setSetting(type: 'loyalty_point_item_purchase_point', value: '10');
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::AS_EACH_CHANNEL_DOES_TODAY);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    // ---- the default reading is the platform of today, channel by channel ------------------------------------------------

    public function test_with_the_default_reading_every_channel_earns_where_it_earns_today(): void
    {
        foreach ($this->everyChannel() as $name => $deliver) {
            $this->anOrderWithAReward(id: 100);
            $deliver(100);

            $this->assertSame(10.0, $this->pointsCredited(), $name . ' earns on a delivery today');
            $this->startAgain();
        }
    }

    /**
     * The wallet has nothing to do with points, and four of the five channels refuse the reward when it is off. This is the disagreement
     * LO-08 names, measured rather than quoted.
     */
    public function test_currently_only_the_admin_panel_earns_when_the_wallet_is_switched_off(): void
    {
        $this->setSetting(type: 'wallet_status', value: '0');

        $earned = [];
        foreach ($this->everyChannel() as $name => $deliver) {
            $this->anOrderWithAReward(id: 100);
            $deliver(100);
            $earned[$name] = $this->pointsCredited();
            $this->startAgain();
        }

        $this->assertSame([
            'the admin panel' => 10.0,
            'the vendor panel' => 0.0,
            'seller API v2' => 0.0,
            'seller API v3 order-detail-status' => 0.0,
            'seller API v3 order-detail-info-update' => 0.0,
        ], $earned);
    }

    /** `loyalty_point_for_each_order` is an admin setting the panels honour and the seller APIs never read. */
    public function test_currently_the_seller_apis_earn_although_points_for_each_order_is_off(): void
    {
        $this->setSetting(type: 'loyalty_point_for_each_order', value: '0');

        $this->anOrderWithAReward(id: 100);
        $this->deliverThroughAdminPanel(100);
        $this->assertSame(0.0, $this->pointsCredited(), 'the admin panel honours the setting');

        $this->startAgain();
        $this->anOrderWithAReward(id: 100);
        $this->deliverThroughSellerApiV2(100);
        $this->assertSame(10.0, $this->pointsCredited(), 'seller API v2 does not');
    }

    // ---- the defect: a seller API delivery claims nothing, so one order can earn twice ----------------------------------

    /**
     * **Measured, and it narrows the defect rather than widening it:** all three seller-API sites refuse an order that is ALREADY
     * `delivered`, so a bare replay of the same call credits once. That guard is about the status, not about the reward, and a seller may
     * move an order off `delivered` and back -- which is a thing the seller app does, for a failed delivery re-attempted. Then the reward
     * row says the points were paid, and the seller API, which never reads it, pays them again. That is the sequence below, and it is
     * exactly what the panels' claim prevents.
     */
    public function test_currently_a_seller_api_pays_the_reward_again_after_the_order_is_delivered_a_second_time(): void
    {
        $this->anOrderWithAReward(id: 100);

        $this->deliverThroughSellerApiV3OrderDetailStatus(100);
        $this->assertSame(0, (int) DB::table('order_details_rewards')->where('order_id', 100)->value('reward_delivered'), 'the row is not even marked, so nothing says the points were paid');

        DB::table('orders')->where('id', 100)->update(['order_status' => 'processing']);
        $this->deliverThroughSellerApiV3OrderDetailStatus(100);

        $this->assertSame(20.0, $this->pointsCredited(), 'one order, two credits');
        $this->assertSame(2, DB::table('loyalty_point_transactions')->where('reference', 100)->count());
    }

    /** Seller API v2 is the one channel that refuses an unpaid order, and the panels pay the reward on one. */
    public function test_currently_only_seller_api_v2_refuses_an_unpaid_delivery(): void
    {
        $this->makeOrder(100, $this->undeliveredVendorOrder());
        DB::table('order_details_rewards')->insert(['order_id' => 100, 'reward_type' => 'loyalty_point', 'reward_amount' => 10, 'reward_delivered' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->deliverThroughSellerApiV2(100);
        $this->assertSame(0.0, $this->pointsCredited(), 'v2 asks for the payment');

        $this->startAgain();
        $this->makeOrder(100, $this->undeliveredVendorOrder());
        DB::table('order_details_rewards')->insert(['order_id' => 100, 'reward_type' => 'loyalty_point', 'reward_amount' => 10, 'reward_delivered' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->deliverThroughAdminPanel(100);
        $this->assertSame(10.0, $this->pointsCredited(), 'the admin panel does not');
    }

    public function test_the_admin_panel_delivering_one_order_twice_credits_it_once(): void
    {
        $this->anOrderWithAReward(id: 100);

        $this->deliverThroughAdminPanel(100);
        $this->deliverThroughAdminPanel(100);

        $this->assertSame(10.0, $this->pointsCredited(), 'the panel claims the reward row first');
    }

    /** Worse than a replay: the panel has already paid the reward and the seller API pays it again. */
    public function test_currently_a_seller_api_delivery_credits_a_reward_the_admin_panel_has_already_paid(): void
    {
        $this->anOrderWithAReward(id: 100);

        $this->deliverThroughAdminPanel(100);
        DB::table('orders')->where('id', 100)->update(['order_status' => 'processing']);
        $this->deliverThroughSellerApiV3OrderDetailStatus(100);

        $this->assertSame(20.0, $this->pointsCredited());
    }

    // ---- the one rule, which is not adopted -----------------------------------------------------------------------------

    public function test_under_one_rule_a_seller_api_delivery_of_one_order_twice_credits_it_once(): void
    {
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::ONE_RULE);
        $this->anOrderWithAReward(id: 100);

        $this->deliverThroughSellerApiV3OrderDetailStatus(100);
        $this->assertSame(10.0, $this->pointsCredited());

        DB::table('orders')->where('id', 100)->update(['order_status' => 'processing']);
        $this->deliverThroughSellerApiV3OrderDetailStatus(100);
        $this->assertSame(10.0, $this->pointsCredited(), 'a second delivery of the same order pays nothing, because the reward was claimed');
    }

    public function test_under_one_rule_a_reward_the_panel_has_paid_is_not_paid_again_by_the_seller_api(): void
    {
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::ONE_RULE);
        $this->anOrderWithAReward(id: 100);

        $this->deliverThroughAdminPanel(100);
        DB::table('orders')->where('id', 100)->update(['order_status' => 'processing']);
        $this->deliverThroughSellerApiV3OrderDetailStatus(100);

        $this->assertSame(10.0, $this->pointsCredited());
    }

    public function test_under_one_rule_the_wallet_being_off_stops_nothing_on_any_channel(): void
    {
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::ONE_RULE);
        $this->setSetting(type: 'wallet_status', value: '0');

        foreach ($this->everyChannel() as $name => $deliver) {
            $this->anOrderWithAReward(id: 100);
            $deliver(100);

            $this->assertSame(10.0, $this->pointsCredited(), $name . ' earns under the one rule whatever the wallet says');
            $this->startAgain();
        }
    }

    public function test_under_one_rule_points_for_each_order_being_off_stops_every_channel(): void
    {
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::ONE_RULE);
        $this->setSetting(type: 'loyalty_point_for_each_order', value: '0');

        foreach ($this->everyChannel() as $name => $deliver) {
            $this->anOrderWithAReward(id: 100);
            $deliver(100);

            $this->assertSame(0.0, $this->pointsCredited(), $name . ' honours the setting under the one rule');
            $this->startAgain();
        }
    }

    /** An order with no reward row has no points recorded for it, so none are paid -- on every channel, not only the panels. */
    public function test_under_one_rule_an_order_with_no_reward_row_earns_on_no_channel(): void
    {
        config()->set('gcc.loyalty.when_a_delivery_earns_points', WhenADeliveryEarnsPoints::ONE_RULE);

        foreach ($this->everyChannel() as $name => $deliver) {
            $this->makeOrder(100, ['payment_status' => 'paid'] + $this->undeliveredVendorOrder());
            $deliver(100);

            $this->assertSame(0.0, $this->pointsCredited(), $name . ' pays nothing for an order with no reward recorded');
            $this->startAgain();
        }
    }

    // ---- what the tests build and read ----------------------------------------------------------------------------------

    /** @return array<string, callable(int): mixed> */
    private function everyChannel(): array
    {
        return [
            'the admin panel' => fn (int $id) => $this->deliverThroughAdminPanel($id),
            'the vendor panel' => fn (int $id) => $this->deliverThroughVendorPanel($id),
            'seller API v2' => fn (int $id) => $this->deliverThroughSellerApiV2($id),
            'seller API v3 order-detail-status' => fn (int $id) => $this->deliverThroughSellerApiV3OrderDetailStatus($id),
            'seller API v3 order-detail-info-update' => fn (int $id) => $this->deliverThroughSellerApiV3UpdateOrderDetails($id),
        ];
    }

    /** Paid, because seller API v2 is the one channel that refuses an unpaid order and that disagreement has a test of its own. */
    private function anOrderWithAReward(int $id): void
    {
        $this->makeOrder($id, ['payment_status' => 'paid'] + $this->undeliveredVendorOrder());
        DB::table('order_details_rewards')->insert([
            'order_id' => $id, 'reward_type' => 'loyalty_point', 'reward_amount' => 10, 'reward_delivered' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Every channel is driven against its own fresh order, because a reward row that has been claimed stays claimed. */
    private function startAgain(): void
    {
        foreach (['orders', 'order_details_rewards', 'loyalty_point_transactions', 'order_status_histories', 'order_transactions'] as $table) {
            DB::table($table)->delete();
        }
        DB::table('users')->where('id', self::CUSTOMER)->update(['loyalty_point' => 0]);
    }

    private function pointsCredited(): float
    {
        return (float) DB::table('loyalty_point_transactions')->sum('credit');
    }

    private function buildLoyaltyLedger(): void
    {
        if (Schema::hasTable('loyalty_point_transactions')) {
            return;
        }

        Schema::create('loyalty_point_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->uuid('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('reference', 191)->nullable();
            $table->string('transaction_type', 191)->nullable();
            $table->timestamps();
        });
    }
}
