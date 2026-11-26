<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\CouponCountry;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * I1-11: a coupon refusal says which rule refused it, and says something true.
 *
 * One condition in `OrderManager::getTotalCouponAmount` carries three different facts and gave all three one sentence:
 *
 *     $onlyProductTotalAmount <= 0 || $couponDiscount > $onlyProductTotalAmount || $coupon['min_purchase'] > $onlyProductTotalAmount
 *
 * Only the third is "the minimum purchase amount has not been reached". The second is the one a customer meets, and the sentence was
 * FALSE of their cart: a cart of 100 with a 150-off coupon whose minimum is 0 was told the minimum was not reached, so the customer who
 * adds items to reach a minimum they already met is sent to do the one thing that cannot help -- and a bigger cart would not fix it
 * either, because the discount is compared with the lines the coupon applies to and the coupon is the thing that is too large.
 *
 * The condition is UNCHANGED and nothing here moves `min_purchase > eligible total`: the same carts are refused, and what changed is which
 * fact the customer is told. The order of truth is asserted below -- a cart that is BOTH below its minimum and smaller than the discount is
 * told about the minimum, because that sentence is then correct and it is the rule the admin form is about.
 *
 * Two cases keep the old sentence on purpose, and both are pinned here as records rather than as intentions: a PERCENTAGE coupon, where
 * the condition compares a figure of per cent with an amount of money, and a cart with nothing eligible at all.
 *
 * **The percentage case has since been CORRECTED, behind a switch that ships off** (the owner's decision of 2026-11-26,
 * `gcc.coupons.percentage_of_the_eligible_total`, measured in {@see CouponPercentageOfTheEligibleTotalTest}). The case below therefore
 * still pins what this platform does as it ships, and is no longer a statement that the defect is unfixed: it is the dormancy of the
 * correction, read from the refusal's side.
 */
class CouponRefusalNamesItsOwnRuleTest extends ApiAddressTestCase
{
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    /** The locales a refusal has to read in: English, Saudi Arabic and Gulf Arabic are separate files on this platform. */
    private const LANGUAGES = ['en', 'sa', 'ae'];

    private const MINIMUM_NOT_REACHED = 'minimum_purchase_amount_not_reached_for_this_coupon.';

    private const DISCOUNT_IS_MORE_THAN_THE_ITEMS = 'this_coupons_discount_is_more_than_the_items_it_applies_to';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCouponCheckoutWorld();
    }

    protected function tearDown(): void
    {
        CouponCountry::flush();

        parent::tearDown();
    }

    /** The defect itself: the minimum is reached, and the coupon is refused because it is larger than the lines it applies to. */
    public function test_a_fixed_discount_larger_than_the_eligible_lines_says_so_rather_than_blaming_the_minimum(): void
    {
        $customer = $this->refuse(code: 'TOO_BIG', attributes: ['discount' => 150, 'min_purchase' => 20], expected: self::DISCOUNT_IS_MORE_THAN_THE_ITEMS);

        $this->placeTheOrder(customer: $customer, code: 'TOO_BIG')->assertOk();
        [$order] = $this->ordersOf($customer);
        $this->assertSame('0', (string) $order->coupon_code, 'and the order is placed without it, exactly as before');
    }

    /** A cart genuinely below its minimum keeps the sentence that is true of it. The rule itself is untouched. */
    public function test_a_cart_below_its_minimum_is_still_told_about_the_minimum(): void
    {
        $this->refuse(code: 'MIN_200', attributes: ['discount' => 5, 'min_purchase' => 200], expected: self::MINIMUM_NOT_REACHED);
    }

    /**
     * Both facts true at once, which is the case the order of the two tests above has to settle: a cart of 100 below a minimum of 200 with
     * a discount of 150. The minimum sentence wins, because it is correct AND it is the rule that tells the customer something they can act
     * on -- adding items reaches the minimum, and no cart makes a coupon smaller.
     */
    public function test_a_cart_that_is_both_below_its_minimum_and_smaller_than_the_discount_is_told_about_the_minimum(): void
    {
        $this->refuse(code: 'BOTH', attributes: ['discount' => 150, 'min_purchase' => 200], expected: self::MINIMUM_NOT_REACHED);
    }

    /**
     * RECORDED, not intended: a percentage coupon keeps the minimum sentence because the condition compares a figure of per cent with an
     * amount of money. 31 per cent is refused on a cart of 30 and applied to a cart of 100 (CouponBehaviourPinsTest pins both), so the
     * fault is in the CONDITION. Correcting it lets through a coupon this platform refuses today, which changes what a customer is charged,
     * and is not a wording correction.
     *
     * The owner approved that correction on 2026-11-26 and NOT its release, so it is switched off and this is what a customer still meets.
     * With `gcc.coupons.percentage_of_the_eligible_total` on, this cart takes 31% of 100 and a cart whose share really is bigger than its
     * lines is told the OTHER sentence -- which is the sentence this file exists to make true.
     * {@see CouponPercentageOfTheEligibleTotalTest} holds both readings.
     */
    public function test_a_percentage_figure_above_the_eligible_total_keeps_the_old_sentence_and_the_reason_is_a_defect_in_the_condition(): void
    {
        $customer = $this->makeCustomer();
        $this->giveCoupon(code: 'PCT', country: null, attributes: ['discount_type' => 'percentage', 'discount' => 150, 'max_discount' => 1000, 'min_purchase' => 0]);
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: translate(self::MINIMUM_NOT_REACHED), response: $this->applyTheCoupon(customer: $customer, code: 'PCT'));
    }

    /** RECORDED: a cart with nothing the coupon applies to keeps the old sentence too. Its honest answer is a third one again, unwritten. */
    public function test_a_cart_with_nothing_the_coupon_applies_to_keeps_the_old_sentence(): void
    {
        $customer = $this->makeCustomer();
        $this->giveCoupon(code: 'VENDOR_ONLY', country: null, attributes: ['discount' => 5, 'min_purchase' => 0, 'seller_id' => self::VENDOR]);
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $response = $this->applyTheCoupon(customer: $customer, code: 'VENDOR_ONLY');

        $response->assertStatus(202);
        $this->assertNotSame(translate(self::DISCOUNT_IS_MORE_THAN_THE_ITEMS), $response->json(), 'the new sentence is not used where it would be untrue');
    }

    /** The new sentence reads in all three of this platform's languages, or a customer is shown its key. */
    public function test_the_new_refusal_reads_in_english_and_in_both_arabics(): void
    {
        foreach (self::LANGUAGES as $locale) {
            $texts = (array) include base_path("resources/lang/{$locale}/new-messages.php");

            $this->assertArrayHasKey(self::DISCOUNT_IS_MORE_THAN_THE_ITEMS, $texts, "resources/lang/{$locale} has no text for the new refusal");
            $text = (string) $texts[self::DISCOUNT_IS_MORE_THAN_THE_ITEMS];
            $this->assertNotSame(self::DISCOUNT_IS_MORE_THAN_THE_ITEMS, $text);
            $this->assertNotSame($texts[self::MINIMUM_NOT_REACHED] ?? null, $text, 'a second key with the first key\'s words is the defect with extra steps');

            if ($locale !== 'en') {
                $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, "resources/lang/{$locale} answers the new refusal in Latin script");
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function refuse(string $code, array $attributes, string $expected): User
    {
        $customer = $this->makeCustomer();
        $this->giveCoupon(code: $code, country: null, attributes: $attributes);
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponRefused(message: translate($expected), response: $this->applyTheCoupon(customer: $customer, code: $code));

        return $customer;
    }

    /** @return list<object> */
    private function ordersOf(User $customer): array
    {
        return DB::table('orders')->where('customer_id', $customer->id)->orderBy('id')->get()->all();
    }
}
