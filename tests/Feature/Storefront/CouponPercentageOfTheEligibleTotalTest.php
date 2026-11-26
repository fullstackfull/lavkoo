<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponDiscount;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * A percentage coupon is judged by the discount it would give, never by its figure of per cent (the owner's decision of 2026-11-26).
 *
 * `coupons.discount` holds two kinds of number and `coupons.discount_type` says which. `OrderManager::getTotalCouponAmount` refused a
 * coupon whose `discount` was larger than the eligible total -- right for an amount of money, nonsense for a percentage, where 31 means 31
 * per cent. So a 31% coupon was refused on a cart of 30 and applied to a cart of 100, and the sentence the customer was given named the
 * minimum purchase, which was neither the rule that refused them nor true of their cart.
 *
 * **The first test is the dormancy and it is the one that matters most**: with `gcc.coupons.percentage_of_the_eligible_total` off, which is
 * how this ships, the defect is exactly where it was. The owner has approved the correction and NOT its release, and switching it on
 * changes what a customer is charged -- so the switch is the release, and this file is the measurement of what it moves.
 *
 * Everything goes through the two real routes a customer reaches a coupon by (the apply route and the checkout), on the world
 * {@see CouponBehaviourPinsTest} pins today's behaviour on: one in-house product of 100, one of 30, 25 of shipping, no storefront switch
 * on -- and, for the three-decimal markets, the dinar and rial storefronts of {@see BuildsSixStorefrontCheckoutWorld}, where the cart is
 * priced in the storefront's own money.
 *
 * TEETH, each mutation applied and restored, measured on SQLite 3 and identical on MariaDB 10.11.14 (nothing here turns on the engine):
 * putting the figure of per cent back into the condition with the correction ON -> 3 red (the 31% cart, the over-discount, and the
 * percentage its maximum cuts down to size); rounding the share to two decimals -> 6 red, among them a budget half a fils SHORT of the
 * share allowing the coupon; rounding it to three -> 5 red, among them a budget EQUAL to the share refusing it. Those last two are the
 * owner's "must not round up past the budget or down past the minimum", in both directions, on the three-decimal currencies. Making the
 * minimum purchase inclusive (`>=`) -> 2 red, one in each currency, on the cart that is exactly at its minimum.
 */
class CouponPercentageOfTheEligibleTotalTest extends ApiAddressTestCase
{
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    /** The three markets of the six whose currency carries THREE decimals, with the product each sells and what it costs in that money. */
    private const THREE_DECIMAL_MARKETS = [
        'Kuwait, the dinar' => ['KW', 3, 30.0],
        'Bahrain, the dinar' => ['BH', 5, 12.5],
        'Oman, the rial' => ['OM', 6, 14.0],
    ];

    /** A figure with three decimals of its own, so that a share of a three-decimal cart lands past the third decimal and has somewhere to be lost. */
    private const AWKWARD_PERCENTAGE = 12.345;

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

    // ---- the dormancy, and the defect it keeps ------------------------------------------------------------------------------------

    /**
     * With the switch off the figure of per cent is still compared with an amount of money, which is the defect
     * {@see CouponRefusalNamesItsOwnRuleTest} and {@see CouponBehaviourPinsTest} recorded rather than fixed.
     */
    public function test_with_the_switch_off_a_percentage_coupon_is_still_judged_by_its_figure_of_per_cent(): void
    {
        $this->assertFalse(CouponDiscount::isJudgedByItsShare(), 'the correction ships off');
        $this->givePercentageCoupon(figure: 31, maximumDiscount: 1000);

        $this->assertCouponRefused(message: $this->minimumNotReached(), response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::SMALL_IN_HOUSE_PRODUCT), code: 'PCT'));
        $this->assertCouponApplied(discount: 31.0, response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'PCT'));
    }

    // ---- what the correction moves -------------------------------------------------------------------------------------------------

    /** The owner's own example: 31 per cent of a cart of 30 is 9.30, and a cart of 30 is not "below the minimum purchase". */
    public function test_a_percentage_coupon_takes_its_share_of_a_cart_worth_less_than_its_figure_of_per_cent(): void
    {
        $this->judgeAPercentageByItsShare();
        $this->givePercentageCoupon(figure: 31, maximumDiscount: 1000);
        $customer = $this->customerWithACartOf(self::SMALL_IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: 9.3, response: $this->applyTheCoupon(customer: $customer, code: 'PCT'));

        $this->placeTheOrder(customer: $customer, code: 'PCT')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertSame('PCT', $order->coupon_code);
        $this->assertEqualsWithDelta(9.3, (float) $order->discount_amount, 0.000001, 'the checkout charges what the apply route quoted');
        $this->assertEqualsWithDelta(self::SMALL_IN_HOUSE_PRICE + self::SHIPPING_COST - 9.3, (float) $order->order_amount, 0.000001);
    }

    /**
     * A share larger than the lines it applies to is refused, and told the true sentence.
     *
     * It takes a figure above 100 to get there, which the admin form refuses (`CouponService::add` answers "when discount type percentage,
     * discount amount will be less than 100"), so such a coupon can only come from an edit of the table. The same cart is refused under
     * both readings; what the correction changes is that the sentence stops naming a minimum purchase the cart has met.
     */
    public function test_a_percentage_above_one_hundred_is_refused_because_its_share_is_more_than_the_items(): void
    {
        $this->givePercentageCoupon(figure: 150, maximumDiscount: 1000);

        $this->assertCouponRefused(message: $this->minimumNotReached(), response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'PCT'));

        $this->judgeAPercentageByItsShare();

        $this->assertCouponRefused(
            message: translate('this_coupons_discount_is_more_than_the_items_it_applies_to'),
            response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'PCT'),
        );
    }

    /**
     * And the other direction, which is the one with money in it: a figure above 100 on a cart worth MORE than that figure passes the old
     * condition and takes off a share larger than the cart.
     *
     * 150 per cent of a cart of 160 is 240. With the correction off the condition compares 150 with 160, is satisfied, and the customer is
     * given a discount of 240 on a cart of 160 -- 80 more than everything in it, shipping included. With it on, the discount is compared
     * with the lines it applies to and refused. So the correction closes an over-discount as well as a false refusal, and this is the case
     * that says which way the money goes.
     */
    public function test_a_percentage_above_one_hundred_discounts_more_than_the_whole_cart_until_the_correction_is_on(): void
    {
        $this->givePercentageCoupon(figure: 150, maximumDiscount: 1000, attributes: ['seller_id' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);
        $eligible = self::IN_HOUSE_PRICE + self::VENDOR_PRICE;

        $this->assertCouponApplied(discount: $eligible * 1.5, response: $this->applyTheCoupon(customer: $customer, code: 'PCT'));

        $this->judgeAPercentageByItsShare();

        $this->assertCouponRefused(
            message: translate('this_coupons_discount_is_more_than_the_items_it_applies_to'),
            response: $this->applyTheCoupon(customer: $customer, code: 'PCT'),
        );
    }

    /** @return array<string, array{bool}> the correction off, which is how it ships, and on */
    public static function bothReadings(): array
    {
        return ['the figure of per cent, as it ships' => [false], 'the share of the eligible total' => [true]];
    }

    /**
     * A MONEY coupon is the same coupon under both readings, which is the whole claim about this switch's blast radius: its figure and the
     * discount it gives are the same number, so every cart it refuses it still refuses, with the same sentence, and every cart it applies
     * to it still applies to.
     */
    #[DataProvider('bothReadings')]
    public function test_a_money_coupon_does_not_move_under_either_reading(bool $byItsShare): void
    {
        if ($byItsShare) {
            $this->judgeAPercentageByItsShare();
        }
        $this->giveCoupon(code: 'COVERED', country: null, attributes: ['discount' => 99.99, 'min_purchase' => 0]);
        $this->giveCoupon(code: 'TOO_BIG', country: null, attributes: ['discount' => 100.01, 'min_purchase' => 0]);

        $this->assertCouponApplied(discount: 99.99, response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'COVERED'));
        $this->assertCouponRefused(
            message: translate('this_coupons_discount_is_more_than_the_items_it_applies_to'),
            response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'TOO_BIG'),
        );
    }

    // ---- the limits the correction must preserve -----------------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> a minimum purchase on each side of the cart of 100, and whether the coupon may be used */
    public static function minimumsAroundTheCart(): array
    {
        return [
            'a minimum equal to the cart' => ['100.00', true],
            'a minimum one halala below the cart' => ['99.99', true],
            'a minimum one halala above the cart' => ['100.01', false],
        ];
    }

    /**
     * The minimum purchase keeps its precedence and its boundary. `min_purchase > eligible total` is the rule
     * {@see StorefrontCouponRedemptionTest} proves in two currencies and nothing here moves it -- a cart exactly at the minimum may use
     * the coupon, and one unit of the currency below it may not.
     */
    #[DataProvider('minimumsAroundTheCart')]
    public function test_the_minimum_purchase_keeps_its_rule_and_its_boundary_for_a_percentage_coupon(string $minimum, bool $mayBeUsed): void
    {
        $this->judgeAPercentageByItsShare();
        $this->givePercentageCoupon(figure: 10, maximumDiscount: 1000, minimumPurchase: $minimum);
        $customer = $this->customerWithACartOf(self::IN_HOUSE_PRODUCT);

        $response = $this->applyTheCoupon(customer: $customer, code: 'PCT');

        $mayBeUsed
            ? $this->assertCouponApplied(discount: 10.0, response: $response)
            : $this->assertCouponRefused(message: $this->minimumNotReached(), response: $response);
    }

    /** @return array<string, array{float, float, float}> the percentage, its maximum discount, and what a cart of 100 is lowered by */
    public static function maximumDiscounts(): array
    {
        return [
            'a share above the maximum is cut to the maximum' => [20.0, 10.0, 10.0],
            'a share equal to the maximum is that amount' => [10.0, 10.0, 10.0],
            'a share below the maximum is the share' => [3.0, 10.0, 3.0],
        ];
    }

    /** The maximum discount keeps its rule too: it caps the share, and it caps it after the share is worked out. */
    #[DataProvider('maximumDiscounts')]
    public function test_the_maximum_discount_still_caps_the_share(float $figure, float $maximumDiscount, float $discount): void
    {
        $this->judgeAPercentageByItsShare();
        $this->givePercentageCoupon(figure: $figure, maximumDiscount: $maximumDiscount);

        $this->assertCouponApplied(discount: $discount, response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::IN_HOUSE_PRODUCT), code: 'PCT'));
    }

    /** A maximum discount that makes a big percentage small is applied rather than refused: what is judged is the capped share, not the figure. */
    public function test_a_percentage_the_maximum_discount_cuts_down_to_size_is_applied(): void
    {
        $this->judgeAPercentageByItsShare();
        $this->givePercentageCoupon(figure: 90, maximumDiscount: 10);

        $this->assertCouponApplied(discount: 10.0, response: $this->applyTheCoupon(customer: $this->customerWithACartOf(self::SMALL_IN_HOUSE_PRODUCT), code: 'PCT'));
    }

    /**
     * A store whose `max_discount` still allows NULL answers exactly what it answered before: `$share > null` is true, so the discount
     * becomes null and the coupon is refused as "not valid". Asserted on the arithmetic directly, because the column is NOT NULL on any
     * store that has run the 2026_05_07 migration and the case cannot be built through the routes.
     */
    public function test_a_percentage_coupon_whose_maximum_discount_is_null_answers_as_it_always_has(): void
    {
        $this->judgeAPercentageByItsShare();

        $this->assertNull(CouponDiscount::of(eligibleTotal: 100, discountType: 'percentage', figure: 10, maximumDiscount: null));
        $this->assertSame(10, CouponDiscount::of(eligibleTotal: 100, discountType: 'amount', figure: 10, maximumDiscount: null), 'a money coupon never looks at its maximum');
    }

    // ---- the three-decimal currencies ------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, int, float}> */
    public static function threeDecimalMarkets(): array
    {
        return self::THREE_DECIMAL_MARKETS;
    }

    /**
     * The sharp case the owner named: a percentage of a THREE-DECIMAL amount, which must not be rounded to two anywhere.
     *
     * 12.345% of 30.000 dinars is 3.70350 -- a figure whose third decimal is followed by a half fils. Rounded to two it is 3.70, five fils
     * the customer is owed; rounded to three it is 3.704 or 3.703, half a fils either way. The assertion is the exact quotient, through the
     * apply route and then on the order row, with the two roundings named so that a future rounding of this number fails HERE and says what
     * it did.
     */
    #[DataProvider('threeDecimalMarkets')]
    public function test_a_percentage_of_a_three_decimal_cart_keeps_every_decimal_of_its_share(string $market, int $product, float $price): void
    {
        $this->judgeAPercentageByItsShare();
        $this->serveTheStorefronts();
        $this->givePercentageCoupon(figure: self::AWKWARD_PERCENTAGE, maximumDiscount: 1000, market: $market);
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: $market, productId: $product);
        $share = $price * self::AWKWARD_PERCENTAGE / 100;

        $this->assertCouponApplied(discount: $share, response: $this->applyFrom(market: $market, customer: $customer, code: 'PCT'));

        $this->placeFrom(market: $market, customer: $customer, code: 'PCT')->assertOk();
        $discountCharged = (float) DB::table('orders')->where('customer_id', $customer->id)->value('discount_amount');
        $this->assertEqualsWithDelta($share, $discountCharged, 0.0000001, 'the order is lowered by the exact share');
        $this->assertNotEqualsWithDelta(round($share, 2), $discountCharged, 0.0000001, 'the share was rounded to two decimals, which is the halala and not the fils');
        $this->assertNotEqualsWithDelta(round($share, 3), $discountCharged, 0.0000001, 'the share was rounded to the fils, and half a fils of it is gone');
    }

    /** @return array<string, array{string, bool}> a minimum purchase on either side of a cart of 12.500 dinars, and whether the coupon may be used */
    public static function minimumsAroundTheThreeDecimalCart(): array
    {
        return [
            'a minimum equal to the cart' => ['12.500', true],
            'a minimum one fils below the cart' => ['12.499', true],
            'a minimum one fils above the cart' => ['12.501', false],
        ];
    }

    /**
     * The minimum purchase's boundary again, in a currency whose smallest coin is a THOUSANDTH: a Bahraini cart of 12.500 dinars meets a
     * minimum of 12.500 and one of 12.499, and does not meet 12.501.
     *
     * The rule is `min_purchase > eligible total` and it has nothing to do with the percentage, which is exactly the point.
     * {@see StorefrontCouponRedemptionTest} proves this boundary for a MONEY coupon in dinars and in riyals; what is proved here is that
     * putting a percentage coupon through the corrected reading does not move it by a fils.
     */
    #[DataProvider('minimumsAroundTheThreeDecimalCart')]
    public function test_the_minimum_purchase_boundary_is_the_same_for_a_percentage_coupon_in_a_three_decimal_currency(string $minimum, bool $mayBeUsed): void
    {
        $this->judgeAPercentageByItsShare();
        $this->serveTheStorefronts();
        $this->givePercentageCoupon(figure: 2.5, maximumDiscount: 1000, minimumPurchase: $minimum, market: 'BH');
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'BH', productId: 5);

        $response = $this->applyFrom(market: 'BH', customer: $customer, code: 'PCT');

        $mayBeUsed
            ? $this->assertCouponApplied(discount: 0.3125, response: $response)
            : $this->assertCouponRefused(message: $this->minimumNotReached(), response: $response);
    }

    /** @return array<string, array{string, bool}> a budget on either side of the exact share, and whether the coupon may be used */
    public static function budgetsAroundTheShare(): array
    {
        return [
            'a budget equal to the share, half a fils and all' => ['0.312500', true],
            'a budget half a fils short of the share' => ['0.312000', false],
            'a budget half a fils above the share' => ['0.313000', true],
        ];
    }

    /**
     * And the budget is judged against that same exact share, which is the other half of "do not round it".
     *
     * 2.5% of 12.5 Bahraini dinars is 0.3125 -- 312 fils and a half. Rounded DOWN to the fils (0.312) it would fit a budget of 0.312 that
     * it has actually overshot; rounded UP (0.313) it would be refused by a budget of 0.3125 that it fits exactly. Both directions are
     * wrong and both are money, so the boundary is asserted on three budgets half a fils apart.
     *
     * The figures are deliberately ones a double holds EXACTLY (0.3125 is five sixteenths), because the comparison inside
     * `CouponAllowance` is a float one: with 12.345% of 30 dinars the quotient a double holds is a whisker ABOVE the stored decimal, so an
     * "equal to the budget" case built on it would be refused by a hair's breadth and would be measuring PHP rather than the rule. Named
     * here rather than papered over -- a budget met to the last decimal is knife-edge for any share a double cannot hold exactly, and that
     * is a property of the existing comparison and not of this correction.
     */
    #[DataProvider('budgetsAroundTheShare')]
    public function test_the_budget_is_judged_against_the_exact_share_of_a_three_decimal_cart(string $budget, bool $mayBeUsed): void
    {
        $this->judgeAPercentageByItsShare();
        $this->switchTheLimitsOn();
        $this->serveTheStorefronts();
        $this->givePercentageCoupon(figure: 2.5, maximumDiscount: 1000, market: 'BH', attributes: ['budget' => $budget]);
        $customer = $this->makeCustomer();
        $this->fillTheCartFrom(customer: $customer, country: 'BH', productId: 5);
        $share = 12.5 * 2.5 / 100;

        $this->assertSame(0.3125, $share, 'the share is 312 fils and a half');

        $response = $this->applyFrom(market: 'BH', customer: $customer, code: 'PCT');

        $mayBeUsed
            ? $this->assertCouponApplied(discount: $share, response: $response)
            : $this->assertCouponRefused(message: translate(CouponAllowance::BUDGET_SPENT) . '!', response: $response);
    }

    // ---- the world ---------------------------------------------------------------------------------------------------------------

    private function judgeAPercentageByItsShare(): void
    {
        config(['gcc.coupons.percentage_of_the_eligible_total' => true]);
    }

    private function switchTheLimitsOn(): void
    {
        config(['gcc.coupons.limits.enabled' => true, 'gcc.coupons.limits.per_store' => false]);
        CouponAllowance::forget();
    }

    /**
     * The storefronts back on, which `buildCouponCheckoutWorld` switched off to run the platform as it ships. A storefront's own coupon is
     * only found from that storefront, and its cart is only priced in that storefront's money, so the three-decimal cases need both.
     */
    private function serveTheStorefronts(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.currency.enabled' => true]);
        StorefrontCatalog::flush();
        CouponCountry::flush();
    }

    /** @param array<string, mixed> $attributes */
    private function givePercentageCoupon(float|int $figure, float|int $maximumDiscount, string $minimumPurchase = '0', ?string $market = null, array $attributes = []): int
    {
        return $this->giveCoupon(code: 'PCT', country: $market, attributes: $attributes + [
            'discount_type' => 'percentage',
            'discount' => $figure,
            'max_discount' => $maximumDiscount,
            'min_purchase' => $minimumPurchase,
        ]);
    }

    private function customerWithACartOf(int $product): User
    {
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, $product);

        return $customer;
    }

    /** The apply route of a storefront: a request no storefront middleware has reached binds nothing, so the header is what names it. */
    private function applyFrom(string $market, User $customer, string $code): TestResponse
    {
        session()->flush();
        app()->forgetInstance(StorefrontContext::class);
        $this->serveAsHttpRequest(uri: self::APPLY_ROUTE);

        return $this->withoutHeader('X-Storefront')->asCustomer($customer)->getJson(uri: self::APPLY_ROUTE . "?code={$code}", headers: $this->storefrontHeader($market));
    }

    private function placeFrom(string $market, User $customer, string $code): TestResponse
    {
        session()->flush();

        return $this->asCustomer($customer)->getJson(uri: self::PLACE_ORDER_ROUTE . "?coupon_code={$code}", headers: $this->storefrontHeader($market));
    }

    private function minimumNotReached(): string
    {
        return translate('minimum_purchase_amount_not_reached_for_this_coupon.');
    }
}
