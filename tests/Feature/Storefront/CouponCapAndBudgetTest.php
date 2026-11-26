<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponRedemption;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontResolver;
use App\Utils\OrderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * I1-14: a coupon has a total cap and a spending budget, its redemptions are recorded rather than counted, and its per-customer limit can
 * be counted within one market.
 *
 * `coupons.limit` is the PER-CUSTOMER limit and always has been, and a redemption was a COUNT over `orders` grouped by `order_group_id`
 * ({@see OrderManager::countCouponUses}). That count answers the per-customer limit and nothing else: it carries no market, so a
 * limit cannot be counted per store (Q-04) without re-reading every order's storefront; it carries no amount, so the discount a coupon has
 * given away is written nowhere; and nothing anywhere caps how many times a coupon may be redeemed in total, so a code that leaks costs
 * whatever the campaign's traffic decides.
 *
 * Everything here goes through the two real routes a customer reaches a coupon by -- the apply route and the checkout -- on the world
 * {@see CouponBehaviourPinsTest} pins today's behaviour on: one in-house product of 100, 25 of shipping, no storefront switch on.
 *
 * **The first test is the dormancy and it is the one that matters most**: with `gcc.coupons.limits.enabled` off, which is how this ships, a
 * coupon carrying a cap of 1 and a budget of 1 is redeemed again and again and no ledger row is written. The switch is what makes any of
 * the rest true.
 *
 * **What this does NOT prove, and it is a criterion rather than an omission:** two CONCURRENT checkouts. The cap and the budget are read
 * where every other coupon refusal is read -- the eligibility functions, before the order's money is computed -- so two checkouts that both
 * read the last remaining use before either records it are both granted the discount. The ledger's unique key makes the RECORDED count
 * exact (the replay case below), and `coupon_redemption_group_unique` is what two checkouts of ONE order group contend on; it does not make
 * the grant atomic. The handback states the two ways of closing that and why neither was slipped in here.
 */
class CouponCapAndBudgetTest extends ApiAddressTestCase
{
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCouponCheckoutWorld();
        (require database_path('migrations/2027_01_08_100000_create_coupon_redemptions_table.php'))->up();
        (require database_path('migrations/2027_01_08_100100_add_cap_and_budget_to_coupons_table.php'))->up();
        CouponAllowance::forget();
    }

    protected function tearDown(): void
    {
        // Built from its real migration, so it is put back: a table a class drops or leaves behind is taken from every class after it.
        Schema::dropIfExists('coupon_redemptions');
        CouponAllowance::forget();
        CouponCountry::flush();

        parent::tearDown();
    }

    // ---- dormancy --------------------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_a_cap_and_a_budget_are_not_read_and_no_redemption_is_recorded(): void
    {
        $this->giveCoupon(code: 'CAPPED', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1, 'budget' => 1]);

        $this->redeemItOnce($this->makeCustomer(), 'CAPPED');
        $this->redeemItOnce($this->makeCustomer(), 'CAPPED');
        $this->redeemItOnce($this->makeCustomer(), 'CAPPED');

        $this->assertSame(3, DB::table('orders')->where('coupon_code', 'CAPPED')->count(), 'every one of them took the coupon');
        $this->assertSame(0, DB::table('coupon_redemptions')->count(), 'and the ledger is empty, because nothing writes it');
    }

    /** And a coupon that names no cap and no budget is unlimited WITH the switch on, which is every coupon that exists. */
    public function test_a_coupon_with_no_cap_and_no_budget_is_unlimited_with_the_switch_on(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'OPEN', country: null, attributes: ['discount' => 5]);

        $this->redeemItOnce($this->makeCustomer(), 'OPEN');
        $this->redeemItOnce($this->makeCustomer(), 'OPEN');

        $this->assertSame(2, DB::table('orders')->where('coupon_code', 'OPEN')->count());
        $this->assertSame(2, DB::table('coupon_redemptions')->count(), 'the redemptions are recorded even where nothing limits them');
    }

    // ---- the ledger ------------------------------------------------------------------------------------------------------------------

    public function test_a_redemption_records_the_coupon_the_group_the_customer_and_the_discount(): void
    {
        $this->switchTheLimitsOn();
        $couponId = $this->giveCoupon(code: 'FIVE', country: null, attributes: ['discount' => 5]);
        $customer = $this->makeCustomer();

        $this->redeemItOnce($customer, 'FIVE');

        $row = DB::table('coupon_redemptions')->first();
        $this->assertSame($couponId, (int) $row->coupon_id);
        $this->assertSame('FIVE', $row->coupon_code);
        $this->assertSame($customer->id, (int) $row->customer_id);
        $this->assertSame(DB::table('orders')->value('order_group_id'), $row->order_group_id);
        $this->assertEqualsWithDelta(5.0, (float) $row->discount, 0.000001);
        // The VALUE and not its spelling: SQLite answers a SUM over decimal(18,12) as a number and MariaDB as '5.000000000000'.
        $this->assertEqualsWithDelta(5.0, (float) CouponRedemption::spent($couponId), 0.000001);
    }

    /** One order GROUP is one redemption, so a checkout of two vendors writes one row -- and its discount is the group's, not one order's. */
    public function test_a_checkout_of_two_vendors_is_one_redemption_carrying_the_whole_groups_discount(): void
    {
        $this->switchTheLimitsOn();
        $couponId = $this->giveCoupon(code: 'BOTH', country: null, attributes: ['discount' => 5, 'coupon_type' => 'discount_on_purchase', 'seller_id' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);
        $this->applyTheCoupon(customer: $customer, code: 'BOTH')->assertOk();
        $this->placeTheOrder(customer: $customer, code: 'BOTH')->assertOk();

        $this->assertSame(2, DB::table('orders')->where('coupon_code', 'BOTH')->count(), 'two orders under one group');
        $this->assertSame(1, DB::table('coupon_redemptions')->count(), 'and one redemption');
        $this->assertEqualsWithDelta(
            (float) DB::table('orders')->where('coupon_code', 'BOTH')->sum('discount_amount'),
            (float) CouponRedemption::spent($couponId),
            0.000001,
            'the spend is the whole group\'s discount, counted once',
        );
    }

    /**
     * A replayed order group writes nothing further.
     *
     * MEASURED, and recorded rather than claimed: what this proves is the PHP guard in `updateOrInsert`, which reads the pair and then
     * inserts or updates. Replacing the unique key with a plain index in the migration leaves this case GREEN, because one process never
     * contends with itself. The KEY is what two concurrent writers contend on, and that is the test below.
     */
    public function test_recording_the_same_order_group_again_writes_nothing_further(): void
    {
        $this->switchTheLimitsOn();
        $couponId = $this->giveCoupon(code: 'ONCE', country: null, attributes: ['discount' => 5]);
        $this->redeemItOnce($this->makeCustomer(), 'ONCE');
        $spent = CouponRedemption::spent($couponId);

        CouponRedemption::record(DB::table('orders')->value('id'));
        CouponRedemption::record(DB::table('orders')->value('id'));

        $this->assertSame(1, DB::table('coupon_redemptions')->count());
        $this->assertSame($spent, CouponRedemption::spent($couponId), 'and the spend did not grow');
    }

    /**
     * The live key, asserted on the table the migration built, because the replay case above cannot: two writers that have not seen each
     * other's row both insert, and the only thing that can refuse the second is the database.
     *
     * The rows are inserted directly, which is the one place in this file that does not go through a route -- there is no way to make two
     * processes race from inside one test, and a second `insert()` of the same pair is what the loser of such a race does.
     */
    public function test_the_ledgers_own_key_refuses_a_second_row_for_the_same_coupon_and_order_group(): void
    {
        $row = ['coupon_id' => 1, 'coupon_code' => 'RACE', 'order_group_id' => 'one-group', 'discount' => 5, 'created_at' => now(), 'updated_at' => now()];
        DB::table('coupon_redemptions')->insert($row);

        try {
            DB::table('coupon_redemptions')->insert($row);
            $this->fail('the second insert was accepted: the unique key of coupon_redemptions is not there');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DB::table('coupon_redemptions')->count(), 'one redemption per coupon and order group, enforced by the database');
        }

        // The same pair for ANOTHER coupon is a different redemption and is accepted, or the key would be refusing the wrong thing.
        DB::table('coupon_redemptions')->insert(['coupon_id' => 2] + $row);
        $this->assertSame(2, DB::table('coupon_redemptions')->count());
    }

    // ---- the total cap ---------------------------------------------------------------------------------------------------------------

    public function test_a_coupon_with_a_total_cap_refuses_the_next_redemption_whoever_asks(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'TWO', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 2]);
        $this->redeemItOnce($this->makeCustomer(), 'TWO');
        $this->redeemItOnce($this->makeCustomer(), 'TWO');

        $third = $this->makeCustomer();
        $this->fillTheCart($third, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->fullyRedeemed(), response: $this->applyTheCoupon(customer: $third, code: 'TWO'));
        $this->placeTheOrder(customer: $third, code: 'TWO')->assertOk();

        $order = DB::table('orders')->where('customer_id', $third->id)->first();
        $this->assertSame('0', (string) $order->coupon_code, 'the order is placed without the coupon, as a refused coupon always is');
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.000001);
        $this->assertSame(2, DB::table('coupon_redemptions')->count(), 'and no third redemption was recorded');
    }

    /** The cap counts every customer's redemptions, which is what makes it a TOTAL cap and not a second per-customer limit. */
    public function test_the_cap_is_reached_by_one_customer_as_well_as_by_several(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'ONE', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1, 'limit' => 9]);
        $customer = $this->makeCustomer();
        $this->redeemItOnce($customer, 'ONE');

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->fullyRedeemed(), response: $this->applyTheCoupon(customer: $customer, code: 'ONE'));
    }

    // ---- the budget ------------------------------------------------------------------------------------------------------------------

    public function test_a_coupon_stops_when_the_discounts_it_has_given_reach_its_budget(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'BUDGET', country: null, attributes: ['discount' => 5, 'budget' => 10]);
        $this->redeemItOnce($this->makeCustomer(), 'BUDGET');
        $this->redeemItOnce($this->makeCustomer(), 'BUDGET');

        $third = $this->makeCustomer();
        $this->fillTheCart($third, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->budgetSpent(), response: $this->applyTheCoupon(customer: $third, code: 'BUDGET'));
        $this->assertSame(2, DB::table('coupon_redemptions')->count());
    }

    /**
     * A budget counts MONEY and not redemptions, so a discount that would overshoot what is left is refused while a smaller one would not
     * be. 7 of a budget of 8 leaves 1, and the 5 this coupon gives does not fit.
     */
    public function test_a_discount_that_would_overshoot_what_is_left_of_the_budget_is_refused(): void
    {
        $this->switchTheLimitsOn();
        $couponId = $this->giveCoupon(code: 'TIGHT', country: null, attributes: ['discount' => 5, 'budget' => 8]);
        $this->redeemItOnce($this->makeCustomer(), 'TIGHT');

        $this->assertEqualsWithDelta(5.0, (float) CouponRedemption::spent($couponId), 0.000001, 'five of the eight are gone');
        $second = $this->makeCustomer();
        $this->fillTheCart($second, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->budgetSpent(), response: $this->applyTheCoupon(customer: $second, code: 'TIGHT'));
    }

    /** What the administrator is shown beside the coupon: what it has given away against what it was allowed to, in its storefront's money. */
    public function test_the_spend_is_readable_for_the_administrator_in_the_storefronts_money(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'SPEND', country: null, attributes: ['discount' => 5, 'budget' => 20, 'total_uses_limit' => 4]);
        $this->redeemItOnce($this->makeCustomer(), 'SPEND');

        $spend = CouponAllowance::spendOf(DB::table('coupons')->where('code', 'SPEND')->first());

        $this->assertIsArray($spend);
        $this->assertSame([1, 4], [$spend['uses'], $spend['cap']]);
        $this->assertStringContainsString('5', $spend['spent']);
        $this->assertStringContainsString('20', $spend['budget']);
        $this->assertNull(CouponAllowance::spendOf(DB::table('coupons')->where('code', 'SPEND')->first(['id'])), 'a coupon with no budget has no spend to read against one');
    }

    // ---- the per-store count (Q-04) --------------------------------------------------------------------------------------------------

    /**
     * Q-04's two readings, measured rather than argued. The same two orders are counted differently by the same coupon: over every market
     * the customer has used it twice and is at the limit; within one market they have used it once in each and are not.
     *
     * It is the ORDER's market that counts, not the request's, which is why the two orders are given different markets and the count is
     * taken while a KUWAITI storefront is being served.
     */
    public function test_the_per_customer_limit_counts_every_market_by_default_and_one_market_when_the_owner_asks_for_it(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'TWICE', country: null, attributes: ['discount' => 5, 'limit' => 2]);
        $customer = $this->makeCustomer();
        $this->redeemItOnce($customer, 'TWICE');
        $this->redeemItOnce($customer, 'TWICE');
        DB::table('coupon_redemptions')->orderBy('id')->limit(1)->update(['storefront_country' => 'SA']);
        DB::table('coupon_redemptions')->orderByDesc('id')->limit(1)->update(['storefront_country' => 'KW']);

        $this->assertSame(2, OrderManager::countCouponUses(customerId: $customer->id, couponCode: 'TWICE'), 'every market: at the limit');

        config(['gcc.coupons.limits.per_store' => true, 'gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        StorefrontCatalog::flush();
        StorefrontResolver::fromConfig()->context(explicitChoice: 'KW')->serve(function () use ($customer): void {
            $this->assertSame('KW', CouponAllowance::marketOfTheCount());
            $this->assertSame(1, OrderManager::countCouponUses(customerId: $customer->id, couponCode: 'TWICE'), 'one market: one of the two');
        });
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------------

    private function switchTheLimitsOn(): void
    {
        config(['gcc.coupons.limits.enabled' => true, 'gcc.coupons.limits.per_store' => false]);
        CouponAllowance::forget();
    }

    private function redeemItOnce(User $customer, string $code): void
    {
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->applyTheCoupon(customer: $customer, code: $code)->assertOk();
        $this->placeTheOrder(customer: $customer, code: $code)->assertOk();
    }

    private function fullyRedeemed(): string
    {
        return translate(CouponAllowance::CAP_REACHED) . '!';
    }

    private function budgetSpent(): string
    {
        return translate(CouponAllowance::BUDGET_SPENT) . '!';
    }
}
