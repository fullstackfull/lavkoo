<?php

namespace Tests\Feature\Storefront;

use App\Models\Order;
use App\Models\User;
use App\Services\Storefront\CouponCountry;
use App\Utils\OrderManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * What the customer API does with a coupon today, pinned through its two real routes (the apply route and the checkout) with the amounts written in the
 * fixtures: the cap of a percentage coupon, a fixed coupon above the eligible total, free delivery, first order, the limit for one customer and the bearer of a
 * vendor coupon. The one case that does not go through those routes is the posting of the bearer when a vendor's order is delivered: it calls
 * OrderManager::getWalletManageOnOrderStatusChange directly, on orders the real checkout made, and the seller app and delivery routes that call it are not
 * exercised. The rule that a minimum purchase greater than the eligible total refuses the coupon is OrderManager::getTotalCouponAmount's own and is proved at its
 * boundary by StorefrontCouponRedemptionTest, so no case here moves it. The platform runs as it ships (no storefront switch on), with one in-house product of
 * 100, one of 30 and one vendor product of 60, and 25 of shipping for each cart group.
 *
 * A refused coupon is not an error at the checkout: the order is placed without it, and records the code 0. The limit of a coupon counts the customer's orders that
 * carry that coupon, one for each order group and a canceled one included; the customer's orders without a coupon or with another one, and another customer's
 * orders, do not count.
 */
class CouponBehaviourPinsTest extends ApiAddressTestCase
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
    }

    protected function tearDown(): void
    {
        $this->dropTheTablesOfDelivery();
        CouponCountry::flush();

        parent::tearDown();
    }

    private function minimumNotReached(): string
    {
        return translate('minimum_purchase_amount_not_reached_for_this_coupon.');
    }

    private function notValidForThisUser(): string
    {
        return translate('sorry_this_coupon_is_not_valid_for_this_user') . '!';
    }

    private function assertOrderTotal(float $expected, object $order, string $message = ''): void
    {
        $this->assertEqualsWithDelta($expected, (float) $order->order_amount, 0.000001, $message);
    }

    private function assertOrderTookNoCoupon(object $order): void
    {
        $this->assertEqualsWithDelta(0.0, (float) $order->discount_amount, 0.000001);
        $this->assertSame('0', (string) $order->coupon_code, 'a coupon the checkout refused leaves the code 0 on the order');
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE + self::SHIPPING_COST, order: $order);
    }

    // ---- the cap of a percentage coupon ---------------------------------------------------------------------------------------------

    /** @return array<string, array{int, float, float}> the percentage, the cap, and what a cart of 100 is lowered by */
    public static function percentageCoupons(): array
    {
        return [
            'a percentage of the cart above the cap is cut to the cap' => [20, 10.0, 10.0],
            'a percentage of the cart equal to the cap is that amount' => [10, 10.0, 10.0],
            'a percentage of the cart below the cap is the percentage' => [3, 10.0, 3.0],
        ];
    }

    #[DataProvider('percentageCoupons')]
    public function test_a_percentage_coupon_takes_off_its_percentage_of_the_cart_and_never_more_than_its_cap(int $percentage, float $cap, float $discount): void
    {
        $this->giveCoupon(code: 'PERCENT', country: null, attributes: ['discount_type' => 'percentage', 'discount' => $percentage, 'max_discount' => $cap]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: $discount, response: $this->applyTheCoupon(customer: $customer, code: 'PERCENT'));

        $this->placeTheOrder(customer: $customer, code: 'PERCENT')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertSame('PERCENT', $order->coupon_code);
        $this->assertEqualsWithDelta($discount, (float) $order->discount_amount, 0.000001);
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE + self::SHIPPING_COST - $discount, order: $order, message: 'the order is lowered by the discount and by nothing else');
    }

    // ---- a fixed coupon against the eligible total ----------------------------------------------------------------------------------

    /** @return array<string, array{float}> */
    public static function fixedDiscountsTheCartOfOneHundredCovers(): array
    {
        return ['equal to the cart' => [100.0], 'one step below the cart' => [99.99]];
    }

    #[DataProvider('fixedDiscountsTheCartOfOneHundredCovers')]
    public function test_a_fixed_coupon_that_the_eligible_total_covers_is_applied_in_full(float $discount): void
    {
        $this->giveCoupon(code: 'FIXED', country: null, attributes: ['discount' => $discount, 'min_purchase' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: $discount, response: $this->applyTheCoupon(customer: $customer, code: 'FIXED'));

        $this->placeTheOrder(customer: $customer, code: 'FIXED')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertEqualsWithDelta($discount, (float) $order->discount_amount, 0.000001);
        $this->assertOrderTotal(expected: self::SHIPPING_COST + self::IN_HOUSE_PRICE - $discount, order: $order);
    }

    /** @return array<string, array{float}> */
    public static function fixedDiscountsAboveTheCartOfOneHundred(): array
    {
        return ['one step above the cart' => [100.01], 'far above the cart' => [150.0]];
    }

    #[DataProvider('fixedDiscountsAboveTheCartOfOneHundred')]
    public function test_a_fixed_coupon_above_the_eligible_total_is_refused_with_the_answer_of_a_minimum_not_reached(float $discount): void
    {
        $this->giveCoupon(code: 'FIXED', country: null, attributes: ['discount' => $discount, 'min_purchase' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: $this->minimumNotReached(), response: $this->applyTheCoupon(customer: $customer, code: 'FIXED'));

        $this->placeTheOrder(customer: $customer, code: 'FIXED')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertOrderTookNoCoupon($order);
    }

    public function test_a_percentage_coupon_whose_percentage_figure_is_above_the_eligible_total_is_refused_the_same_way(): void
    {
        $figure = self::SMALL_IN_HOUSE_PRICE + 1;
        $this->giveCoupon(code: 'FIGURE', country: null, attributes: ['discount_type' => 'percentage', 'discount' => $figure, 'max_discount' => 1000, 'min_purchase' => 0]);
        $smallCart = $this->makeCustomer();
        $this->fillTheCart($smallCart, self::SMALL_IN_HOUSE_PRODUCT);
        $largeCart = $this->makeCustomer();
        $this->fillTheCart($largeCart, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: $this->minimumNotReached(), response: $this->applyTheCoupon(customer: $smallCart, code: 'FIGURE'));
        $this->assertCouponApplied(discount: $figure / 100 * self::IN_HOUSE_PRICE, response: $this->applyTheCoupon(customer: $largeCart, code: 'FIGURE'));
    }

    // ---- free delivery --------------------------------------------------------------------------------------------------------------

    public function test_a_free_delivery_coupon_waives_the_shipping_of_the_cart_and_the_order_total_follows(): void
    {
        $this->giveCoupon(code: 'FREESHIP', country: null, attributes: ['coupon_type' => 'free_delivery', 'discount' => 0]);
        $paying = $this->makeCustomer();
        $this->fillTheCart($paying, self::IN_HOUSE_PRODUCT);
        $waived = $this->makeCustomer();
        $this->fillTheCart($waived, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: self::SHIPPING_COST, response: $this->applyTheCoupon(customer: $waived, code: 'FREESHIP'), type: 'free_delivery');

        $this->placeTheOrder(customer: $paying)->assertOk();
        $this->placeTheOrder(customer: $waived, code: 'FREESHIP')->assertOk();
        [$withShipping] = $this->ordersOf($paying);
        [$withoutShipping] = $this->ordersOf($waived);
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE + self::SHIPPING_COST, order: $withShipping, message: 'the cart and its shipping');
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE, order: $withoutShipping, message: 'the cart alone');
        $this->assertEqualsWithDelta(self::SHIPPING_COST, (float) $withoutShipping->shipping_cost, 0.000001, 'the order still records what the shipping cost');
        $this->assertEqualsWithDelta(self::SHIPPING_COST, (float) $withoutShipping->discount_amount, 0.000001, 'and the coupon waived exactly that');
        $this->assertSame('coupon_discount', $withoutShipping->discount_type);
        $this->assertSame('FREESHIP', $withoutShipping->coupon_code);
    }

    public function test_a_free_delivery_coupon_is_refused_when_the_cart_has_no_shipping_to_waive(): void
    {
        $this->giveCoupon(code: 'FREESHIP', country: null, attributes: ['coupon_type' => 'free_delivery', 'discount' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        DB::table('cart_shippings')->delete();

        $this->assertCouponRefused(message: translate('Not_applicable_for_current_shipping_cost'), response: $this->applyTheCoupon(customer: $customer, code: 'FREESHIP'));
    }

    public function test_a_free_delivery_coupon_does_not_look_at_its_minimum_purchase(): void
    {
        $this->giveCoupon(code: 'FREESHIP', country: null, attributes: ['coupon_type' => 'free_delivery', 'discount' => 0, 'min_purchase' => 500]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: self::SHIPPING_COST, response: $this->applyTheCoupon(customer: $customer, code: 'FREESHIP'), type: 'free_delivery');
    }

    // ---- first order ----------------------------------------------------------------------------------------------------------------

    /** The admin's own edit gives a first-order coupon a limit of 0, which the code never compares. */
    private function giveTheFirstOrderCoupon(): void
    {
        $this->giveCoupon(code: 'FIRST', country: null, attributes: ['coupon_type' => 'first_order', 'discount' => 5, 'limit' => 0]);
    }

    public function test_a_first_order_coupon_is_applied_for_a_customer_who_has_no_order_and_lowers_the_order(): void
    {
        $this->giveTheFirstOrderCoupon();
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: 5.0, response: $this->applyTheCoupon(customer: $customer, code: 'FIRST'), type: 'first_order');

        $this->placeTheOrder(customer: $customer, code: 'FIRST')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertSame('FIRST', $order->coupon_code);
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.000001);
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE + self::SHIPPING_COST - 5, order: $order);
    }

    public function test_a_first_order_coupon_is_refused_once_the_customer_has_an_order_and_the_checkout_places_the_next_one_without_it(): void
    {
        $this->giveTheFirstOrderCoupon();
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->placeTheOrder(customer: $customer)->assertOk();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'FIRST'));

        $this->placeTheOrder(customer: $customer, code: 'FIRST')->assertOk();
        [, $second] = $this->ordersOf($customer);
        $this->assertOrderTookNoCoupon($second);
    }

    public function test_a_first_order_coupon_is_refused_even_for_a_canceled_earlier_order_and_still_open_to_a_customer_with_none(): void
    {
        $this->giveTheFirstOrderCoupon();
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->placeTheOrder(customer: $customer)->assertOk();
        DB::table('orders')->where('customer_id', $customer->id)->update(['order_status' => 'canceled']);
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $newcomer = $this->makeCustomer();
        $this->fillTheCart($newcomer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'FIRST'));
        $this->assertCouponApplied(discount: 5.0, response: $this->applyTheCoupon(customer: $newcomer, code: 'FIRST'), type: 'first_order');
    }

    // ---- the limit for one customer -------------------------------------------------------------------------------------------------

    private function redeemTheCouponOnceMore(User $customer, string $code): void
    {
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->assertCouponApplied(discount: 5.0, response: $this->applyTheCoupon(customer: $customer, code: $code));
        $this->placeTheOrder(customer: $customer, code: $code)->assertOk();
    }

    public function test_a_customer_cannot_redeem_a_coupon_more_often_than_its_limit_and_another_customer_can(): void
    {
        $this->giveCoupon(code: 'TWICE', country: null, attributes: ['discount' => 5, 'limit' => 2]);
        $customer = $this->makeCustomer();
        $this->redeemTheCouponOnceMore(customer: $customer, code: 'TWICE');
        $this->redeemTheCouponOnceMore(customer: $customer, code: 'TWICE');

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'TWICE'));
        $this->placeTheOrder(customer: $customer, code: 'TWICE')->assertOk();
        [$first, $second, $third] = $this->ordersOf($customer);
        $this->assertSame(['TWICE', 'TWICE'], [$first->coupon_code, $second->coupon_code]);
        $this->assertOrderTookNoCoupon($third);

        $other = $this->makeCustomer();
        $this->fillTheCart($other, self::IN_HOUSE_PRODUCT);
        $this->assertCouponApplied(discount: 5.0, response: $this->applyTheCoupon(customer: $other, code: 'TWICE'));
    }

    public function test_only_the_orders_of_the_customer_that_carry_the_coupon_count_against_its_limit_not_the_ones_without_it_or_with_another(): void
    {
        $this->giveCoupon(code: 'TWICE', country: null, attributes: ['discount' => 5, 'limit' => 2]);
        $this->giveCoupon(code: 'OTHER', country: null, attributes: ['discount' => 5, 'limit' => 2]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->placeTheOrder(customer: $customer)->assertOk();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->placeTheOrder(customer: $customer, code: 'OTHER')->assertOk();

        $this->redeemTheCouponOnceMore(customer: $customer, code: 'TWICE');
        $this->redeemTheCouponOnceMore(customer: $customer, code: 'TWICE');

        $this->assertSame(['0', 'OTHER', 'TWICE', 'TWICE'], array_map(fn (object $order): string => (string) $order->coupon_code, $this->ordersOf($customer)), 'four orders of the customer, two of them with the coupon');
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'TWICE'));
        $this->assertCouponApplied(discount: 5.0, response: $this->applyTheCoupon(customer: $customer, code: 'OTHER'));
    }

    public function test_a_canceled_order_still_counts_against_the_limit_of_its_coupon(): void
    {
        $this->giveCoupon(code: 'ONCE', country: null, attributes: ['discount' => 5, 'limit' => 1]);
        $customer = $this->makeCustomer();
        $this->redeemTheCouponOnceMore(customer: $customer, code: 'ONCE');
        $this->assertSame(1, DB::table('orders')->where('customer_id', $customer->id)->update(['order_status' => 'canceled']));

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'ONCE'));
    }

    public function test_the_limit_counts_the_order_groups_of_the_customer_so_a_checkout_of_two_vendors_is_one_redemption(): void
    {
        $this->giveCoupon(code: 'EVERYWHERE', country: null, attributes: ['seller_id' => 0, 'discount' => 10, 'limit' => 2]);
        $customer = $this->makeCustomer();

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);
        $this->placeTheOrder(customer: $customer, code: 'EVERYWHERE')->assertOk();
        $this->assertSame(2, DB::table('orders')->where('coupon_code', 'EVERYWHERE')->count(), 'the checkout wrote an order for each of the two vendors');
        $this->assertSame(1, DB::table('orders')->distinct()->count('order_group_id'), 'and one order group for both');

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);
        $this->assertCouponApplied(discount: 10.0, response: $this->applyTheCoupon(customer: $customer, code: 'EVERYWHERE'));
        $this->placeTheOrder(customer: $customer, code: 'EVERYWHERE')->assertOk();

        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);
        $this->assertCouponRefused(message: $this->notValidForThisUser(), response: $this->applyTheCoupon(customer: $customer, code: 'EVERYWHERE'));
    }

    // ---- the vendor's coupon and who bears it ---------------------------------------------------------------------------------------

    /** @param array<string, mixed> $attributes */
    private function giveTheVendorCoupon(string $code, array $attributes = []): void
    {
        $this->giveCoupon(code: $code, country: null, attributes: $attributes + ['added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::VENDOR, 'discount' => 10]);
    }

    public function test_a_vendor_coupon_takes_its_discount_from_the_vendors_lines_only_and_the_order_of_the_vendor_carries_it_with_the_vendor_as_bearer(): void
    {
        $this->giveTheVendorCoupon(code: 'VENDOR', attributes: ['discount_type' => 'percentage', 'discount' => 10, 'max_discount' => 50]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);

        $this->assertCouponApplied(discount: self::VENDOR_PRICE / 10, response: $this->applyTheCoupon(customer: $customer, code: 'VENDOR'));

        $this->placeTheOrder(customer: $customer, code: 'VENDOR')->assertOk();
        $orders = collect($this->ordersOf($customer))->keyBy('seller_is');
        $vendorOrder = $orders['seller'];
        $inHouseOrder = $orders['admin'];
        $this->assertSame(self::VENDOR, (int) $vendorOrder->seller_id);
        $this->assertEqualsWithDelta(6.0, (float) $vendorOrder->discount_amount, 0.000001, 'ten percent of the vendor\'s 60, not of the whole cart');
        $this->assertSame('seller', $vendorOrder->coupon_discount_bearer);
        $this->assertOrderTotal(expected: self::VENDOR_PRICE + self::SHIPPING_COST - 6, order: $vendorOrder);
        $this->assertEqualsWithDelta(0.0, (float) $inHouseOrder->discount_amount, 0.000001, 'the in-house order is lowered by nothing');
        $this->assertOrderTotal(expected: self::IN_HOUSE_PRICE + self::SHIPPING_COST, order: $inHouseOrder);
        $this->assertSame(['VENDOR', 'seller'], [$inHouseOrder->coupon_code, $inHouseOrder->coupon_discount_bearer], 'it still records the code and the bearer of the coupon that was asked for');
    }

    public function test_an_in_house_coupon_for_every_vendor_is_borne_by_the_platform_on_the_order_of_a_vendor(): void
    {
        $this->giveCoupon(code: 'HOUSE', country: null, attributes: ['seller_id' => 0, 'coupon_bearer' => 'inhouse', 'discount' => 10]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::VENDOR_PRODUCT);

        $this->placeTheOrder(customer: $customer, code: 'HOUSE')->assertOk();

        [$order] = $this->ordersOf($customer);
        $this->assertSame(['seller', 'inhouse'], [$order->seller_is, $order->coupon_discount_bearer]);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount_amount, 0.000001);
    }

    public function test_a_vendor_coupon_is_refused_for_a_cart_with_none_of_that_vendors_lines(): void
    {
        $this->giveTheVendorCoupon(code: 'VENDOR');
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: translate('coupon_not_applicable_to_selected_vendors'), response: $this->applyTheCoupon(customer: $customer, code: 'VENDOR'));
    }

    public function test_a_fixed_vendor_coupon_is_compared_with_the_vendors_lines_and_not_with_the_whole_cart(): void
    {
        $this->giveTheVendorCoupon(code: 'COVERED', attributes: ['discount' => self::VENDOR_PRICE]);
        $this->giveTheVendorCoupon(code: 'ABOVE', attributes: ['discount' => self::VENDOR_PRICE + 0.01]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);

        $this->assertCouponApplied(discount: self::VENDOR_PRICE, response: $this->applyTheCoupon(customer: $customer, code: 'COVERED'));
        $this->assertCouponRefused(message: $this->minimumNotReached(), response: $this->applyTheCoupon(customer: $customer, code: 'ABOVE'));
    }

    /** @return array<string, array{int, float, float, float}> the vendor of the coupon, the discount, and what each of the two orders of the cart costs */
    public static function freeDeliveryCouponsOfAVendorAndOfEveryVendor(): array
    {
        return [
            'the coupon of one vendor waives the shipping of that vendor only' => [self::VENDOR, 25.0, 125.0, 60.0],
            'the coupon for every vendor waives the shipping of both' => [0, 50.0, 100.0, 60.0],
        ];
    }

    #[DataProvider('freeDeliveryCouponsOfAVendorAndOfEveryVendor')]
    public function test_a_free_delivery_coupon_waives_the_shipping_of_the_cart_groups_it_covers(int $sellerId, float $waived, float $inHouseTotal, float $vendorTotal): void
    {
        $this->giveCoupon(code: 'SHIP', country: null, attributes: ['coupon_type' => 'free_delivery', 'seller_id' => $sellerId, 'coupon_bearer' => 'seller', 'discount' => 0]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT, self::VENDOR_PRODUCT);

        $this->assertCouponApplied(discount: $waived, response: $this->applyTheCoupon(customer: $customer, code: 'SHIP'), type: 'free_delivery');

        $this->placeTheOrder(customer: $customer, code: 'SHIP')->assertOk();
        $orders = collect($this->ordersOf($customer))->keyBy('seller_is');
        $this->assertOrderTotal(expected: $inHouseTotal, order: $orders['admin']);
        $this->assertOrderTotal(expected: $vendorTotal, order: $orders['seller']);
    }

    private function dropTheTablesOfDelivery(): void
    {
        Schema::dropIfExists('seller_wallets');
        Schema::dropIfExists('transactions');
    }

    private function createTheTablesOfDelivery(): void
    {
        $this->dropTheTablesOfDelivery();
        Schema::create('seller_wallets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('total_earning', 40, 20)->default(0);
            $table->decimal('withdrawn', 40, 20)->default(0);
            $table->decimal('commission_given', 40, 20)->default(0);
            $table->decimal('pending_withdraw', 40, 20)->default(0);
            $table->decimal('delivery_charge_earned', 40, 20)->default(0);
            $table->decimal('collected_cash', 40, 20)->default(0);
            $table->decimal('total_tax_collected', 40, 20)->default(0);
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('payment_for')->nullable();
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->unsignedBigInteger('payment_receiver_id')->nullable();
            $table->string('paid_by')->nullable();
            $table->string('paid_to')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount', 40, 20)->default(0);
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->timestamps();
        });
    }

    private function vendorEarnings(): float
    {
        return (float) DB::table('seller_wallets')->where('seller_id', self::VENDOR)->value('total_earning');
    }

    public function test_when_the_vendors_order_is_delivered_the_vendor_pays_the_discount_of_its_own_coupon_and_the_platform_pays_that_of_an_in_house_one(): void
    {
        $this->createTheTablesOfDelivery();
        $this->giveTheVendorCoupon(code: 'VENDOR');
        $this->giveCoupon(code: 'HOUSE', country: null, attributes: ['seller_id' => 0, 'coupon_bearer' => 'inhouse', 'discount' => 10]);
        foreach (['VENDOR', 'HOUSE'] as $code) {
            $customer = $this->makeCustomer();
            $this->fillTheCart($customer, self::VENDOR_PRODUCT);
            $this->placeTheOrder(customer: $customer, code: $code)->assertOk();
        }
        [$vendorBears, $platformBears] = Order::query()->orderBy('id')->get()->all();

        OrderManager::getWalletManageOnOrderStatusChange(order: $vendorBears, received_by: 'seller');
        $this->assertEqualsWithDelta(self::VENDOR_PRICE - 10, $this->vendorEarnings(), 0.000001, 'the vendor earns the 60 less the 10 it gave');
        OrderManager::getWalletManageOnOrderStatusChange(order: $platformBears, received_by: 'seller');
        $this->assertEqualsWithDelta(self::VENDOR_PRICE - 10 + self::VENDOR_PRICE, $this->vendorEarnings(), 0.000001, 'and the 60 in full for the order whose discount the platform gave');

        $transactions = DB::table('transactions')->orderBy('order_id')->get()->all();
        $this->assertCount(2, $transactions);
        foreach ($transactions as $transaction) {
            $this->assertSame(['coupon_discount', 'disburse', 'expense'], [$transaction->payment_for, $transaction->payment_status, $transaction->transaction_type]);
            $this->assertEqualsWithDelta(10.0, (float) $transaction->amount, 0.000001);
        }
        $this->assertSame([$vendorBears->id, 'seller', 'admin', self::VENDOR], [(int) $transactions[0]->order_id, $transactions[0]->paid_by, $transactions[0]->paid_to, (int) $transactions[0]->payer_id]);
        $this->assertSame([$platformBears->id, 'admin', 'seller', 1], [(int) $transactions[1]->order_id, $transactions[1]->paid_by, $transactions[1]->paid_to, (int) $transactions[1]->payer_id]);
    }
}
