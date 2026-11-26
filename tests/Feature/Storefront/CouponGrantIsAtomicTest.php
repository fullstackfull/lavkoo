<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Models\User;
use App\Services\Checkout\OrderRefused;
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponGrant;
use App\Services\Coupon\CouponRedemption;
use App\Services\Storefront\CouponCountry;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * I1-14, the owner's decision of 2026-11-26: a coupon's use and budget limits are enforced ATOMICALLY, inside the order's transaction,
 * under a lock on the coupon's row -- and the refusal is carried to the surface so that a customer whose coupon is spent is not told an
 * item in their cart went out of stock.
 *
 * `CouponAllowance` answers the same three questions at eligibility time, and that is a READ: two checkouts that both pass it before
 * either records a redemption are both granted the discount. {@see CouponGrant} asks them again where the answer can be binding.
 *
 * **What this file proves and what it cannot.** It proves the gate's rules, that the gate is reached from inside the transaction, that a
 * refusal rolls the whole order back and takes nothing from the cart or the shelf, and that each surface prints the refusal's own sentence.
 * It does NOT prove the locking: one process cannot contend with itself, and `lockForUpdate` compiles to nothing at all on SQLite. The
 * race itself is {@see \Tests\Feature\Concurrency\CouponGrantConcurrencyTest}, two independent connections against MariaDB, which is what
 * the owner's decision asks for in so many words.
 *
 * **How the interleaving is arranged here.** The redemption a second checkout would have committed is inserted while this checkout holds
 * its product locks, from a `Product::retrieved` listener that fires only inside a transaction -- the eligibility check reads its products
 * outside one, so it still passes, which is exactly the state a race leaves. What a second CONNECTION would have done differently is
 * commit that row; here it shares this transaction and rolls back with it, which is why the ledger is empty afterwards rather than holding
 * the other checkout's row. That difference is in the locking and the locking is the other file's subject.
 *
 * TEETH, each mutation applied and restored, on SQLite 3 (nothing here turns on the engine): letting the gate's refusal pass without
 * throwing -> 2 red; one surface put back to the stock sentence -> 1 red, naming both sentences; taking `OrderRefused::forget()` off the
 * top of `generateOrder` -> 1 red, on the reason outliving its call. The surface scan is per FILE, so it catches a NEW caller that words a
 * refusal its own way and not a second call site inside a file that already reads the reason; the mutation above is what covers that one.
 */
class CouponGrantIsAtomicTest extends ApiAddressTestCase
{
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    /** The locales a refusal has to read in: English, Saudi Arabic and Gulf Arabic are separate files on this platform. */
    private const LANGUAGES = ['en', 'sa', 'ae'];

    /** Every file that asks the order writer for an order, and so has to word its refusals. OrderManager itself names the method in prose. */
    private const THE_ORDER_WRITERS_CALLERS = ['app', 'Modules'];

    private bool $theOtherCheckoutHasRedeemedIt = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCouponCheckoutWorld();
        (require database_path('migrations/2027_01_08_100000_create_coupon_redemptions_table.php'))->up();
        (require database_path('migrations/2027_01_08_100100_add_cap_and_budget_to_coupons_table.php'))->up();
        CouponAllowance::forget();
        OrderRefused::forget();
    }

    protected function tearDown(): void
    {
        // Built from its real migration, so it is put back: a table a class drops or leaves behind is taken from every class after it.
        Schema::dropIfExists('coupon_redemptions');
        CouponAllowance::forget();
        CouponCountry::flush();
        OrderRefused::forget();

        parent::tearDown();
    }

    // ---- the dormancy ----------------------------------------------------------------------------------------------------------------

    /** With the switch off the gate reads nothing at all: no lock on the coupon, no count of the ledger, not one query. */
    public function test_with_the_limits_switch_off_the_gate_asks_the_database_nothing(): void
    {
        $this->giveCoupon(code: 'CAPPED', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $this->recordARedemption(code: 'CAPPED', group: 'another-group', discount: 5);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $refusal = CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('CAPPED', 5), customerId: 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNull($refusal, 'a coupon past its cap is granted while the switch is off, which is how the platform ships');
        $this->assertSame([], $queries, 'the gate read the database with the switch off');
    }

    /** And a cart whose groups carry no coupon is not judged either, however many limits the coupons of that shop have. */
    public function test_a_cart_that_takes_no_coupon_is_not_judged(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'CAPPED', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $this->recordARedemption(code: 'CAPPED', group: 'another-group', discount: 5);

        $refusedCodeOnTheGroup = [['coupon_code' => 0, 'coupon_discount' => 0]];

        $this->assertNull(CouponGrant::refusalForTheCart(vendorWiseCartList: $refusedCodeOnTheGroup, customerId: 1));
        $this->assertNull(CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('CAPPED', 0), customerId: 1), 'a coupon that takes nothing off is not a redemption');
    }

    // ---- the three rules, judged under the lock --------------------------------------------------------------------------------------

    public function test_the_total_cap_refuses_the_grant(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'CAPPED', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $this->recordARedemption(code: 'CAPPED', group: 'another-group', discount: 5);

        $this->assertSame(
            CouponAllowance::CAP_REACHED,
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('CAPPED', 5), customerId: 1),
        );
    }

    public function test_the_budget_refuses_a_discount_that_would_overshoot_what_is_left_of_it(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'TIGHT', country: null, attributes: ['discount' => 5, 'budget' => 8]);
        $this->recordARedemption(code: 'TIGHT', group: 'another-group', discount: 7);

        $this->assertSame(
            CouponAllowance::BUDGET_SPENT,
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('TIGHT', 5), customerId: 1),
            'one of the eight is left and a discount of five does not fit it',
        );
        $this->assertNull(
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('TIGHT', 1), customerId: 1),
            'and a discount that does fit what is left is granted',
        );
    }

    public function test_the_customers_own_limit_refuses_the_grant_and_a_first_order_coupon_is_not_asked(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'ONCE', country: null, attributes: ['discount' => 5, 'limit' => 1]);
        $this->recordARedemption(code: 'ONCE', group: 'their-earlier-group', discount: 5, customerId: 44);

        $this->assertSame(
            CouponGrant::CUSTOMER_LIMIT_REACHED,
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('ONCE', 5), customerId: 44),
        );
        $this->assertNull(
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('ONCE', 5), customerId: 45),
            'another customer has their own limit',
        );

        DB::table('coupons')->where('code', 'ONCE')->update(['coupon_type' => 'first_order']);
        $this->assertNull(
            CouponGrant::refusalForTheCart(vendorWiseCartList: $this->oneGroupTaking('ONCE', 5), customerId: 44),
            'a first-order coupon is ruled by whether the customer has ordered before, which is not a question for this ledger',
        );
    }

    /** The discount judged is the WHOLE order group's, because that is what one redemption records: a checkout of two vendors is one. */
    public function test_the_discount_judged_is_the_whole_order_groups(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'BOTH', country: null, attributes: ['discount' => 5, 'budget' => 6]);
        $twoGroups = [['coupon_code' => 'BOTH', 'coupon_discount' => 3], ['coupon_code' => 'BOTH', 'coupon_discount' => 4]];

        $this->assertSame(CouponAllowance::BUDGET_SPENT, CouponGrant::refusalForTheCart(vendorWiseCartList: $twoGroups, customerId: 1), 'three and four are seven, which is over a budget of six');

        DB::table('coupons')->where('code', 'BOTH')->update(['budget' => 7]);
        $this->assertNull(CouponGrant::refusalForTheCart(vendorWiseCartList: $twoGroups, customerId: 1), 'and seven fits a budget of seven exactly');
    }

    // ---- the refusal at the checkout, and what it leaves behind ----------------------------------------------------------------------

    /**
     * The whole of it, through the real checkout: the quote passes, the last use goes while the order is being written, and the customer is
     * told their coupon is spent -- with no order, no stock taken and their cart where it was.
     */
    public function test_the_checkout_refuses_the_order_and_names_the_coupon_when_the_last_use_goes_while_it_writes(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'LAST', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->applyTheCoupon(customer: $customer, code: 'LAST')->assertOk();
        $stockBefore = (int) DB::table('products')->where('id', self::IN_HOUSE_PRODUCT)->value('current_stock');
        $this->letAnotherCheckoutTakeTheLastUse(code: 'LAST', discount: 5);

        $response = $this->placeTheOrder(customer: $customer, code: 'LAST');

        $response->assertStatus(403);
        $this->assertSame(
            translate(CouponAllowance::CAP_REACHED) . ' ' . translate('your_cart_has_not_been_changed'),
            $response->json('message'),
            'the customer is told which rule refused them, not that an item went out of stock',
        );
        $this->assertSame(0, DB::table('orders')->count(), 'the whole order rolled back');
        $this->assertSame(0, DB::table('order_details')->count());
        $this->assertSame($stockBefore, (int) DB::table('products')->where('id', self::IN_HOUSE_PRODUCT)->value('current_stock'), 'and no unit left the shelf');
        $this->assertSame(1, DB::table('carts')->where('customer_id', $customer->id)->count(), 'the cart is where it was, so the customer can order again without the coupon');
    }

    /** The order the customer gets when they do order again: the same cart, no coupon, full price, and no refusal reported. */
    public function test_the_same_cart_goes_through_without_the_coupon(): void
    {
        $this->switchTheLimitsOn();
        $this->giveCoupon(code: 'LAST', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->letAnotherCheckoutTakeTheLastUse(code: 'LAST', discount: 5);
        $this->placeTheOrder(customer: $customer, code: 'LAST')->assertStatus(403);

        $this->placeTheOrder(customer: $customer)->assertOk();

        [$order] = $this->ordersOf($customer);
        $this->assertSame('0', (string) $order->coupon_code);
        $this->assertEqualsWithDelta(self::IN_HOUSE_PRICE + self::SHIPPING_COST, (float) $order->order_amount, 0.000001);
        $this->assertNull(OrderRefused::reason(), 'a reason does not outlive the call that set it');
    }

    /**
     * A PAID order is never refused over its coupon, for the same reason the paid path allows an oversold line: the gateway has already
     * taken the discounted amount, and refusing would leave the customer paid with nothing to show for it. It is reported and allowed.
     */
    public function test_a_paid_order_is_reported_and_allowed_rather_than_refused(): void
    {
        $this->switchTheLimitsOn();
        $couponId = $this->giveCoupon(code: 'LAST', country: null, attributes: ['discount' => 5, 'total_uses_limit' => 1]);
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $this->letAnotherCheckoutTakeTheLastUse(code: 'LAST', discount: 5);

        $orderIds = $this->placeThePaidOrder($customer, 'LAST');

        $this->assertCount(1, $orderIds, 'the paid order was written');
        $order = DB::table('orders')->where('id', $orderIds[0])->first();
        $this->assertSame('LAST', $order->coupon_code);
        $this->assertEqualsWithDelta(5.0, (float) $order->discount_amount, 0.000001);
        $this->assertNull(OrderRefused::reason(), 'and nothing was refused, so there is no reason to report to the customer');
        $this->assertEqualsWithDelta(10.0, (float) CouponRedemption::spent($couponId), 0.000001, 'the coupon is over its cap by one redemption, in the ledger, where a human can reconcile it');
    }

    // ---- the words, and the surfaces that print them -------------------------------------------------------------------------------

    /** Every sentence this refusal can print reads in all three of this platform's languages, or a customer is shown a key. */
    public function test_every_refusal_this_gate_can_give_reads_in_english_and_in_both_arabics(): void
    {
        $keys = [CouponAllowance::CAP_REACHED, CouponAllowance::BUDGET_SPENT, CouponGrant::CUSTOMER_LIMIT_REACHED, 'your_cart_has_not_been_changed'];

        foreach (self::LANGUAGES as $locale) {
            $texts = array_merge(
                (array) include base_path("resources/lang/{$locale}/messages.php"),
                (array) include base_path("resources/lang/{$locale}/new-messages.php"),
            );

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $texts, "resources/lang/{$locale} has no text for {$key}");
                $this->assertNotSame($key, (string) $texts[$key]);

                if ($locale !== 'en') {
                    $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $texts[$key], "resources/lang/{$locale} answers {$key} in Latin script");
                }
            }
        }
    }

    /**
     * Every surface that asks the order writer for an order reads the refusal's reason.
     *
     * The order writer answers a refusal with an empty list, and six surfaces word that answer for a customer. A seventh that is added
     * later and words it as "an item went out of stock" is the defect this decision was about, and nothing but a scan can catch it: the
     * sentence is correct code, it compiles, and its own tests pass.
     */
    public function test_every_surface_that_places_an_order_reads_the_refusals_reason(): void
    {
        $callers = [];

        foreach (Finder::create()->files()->name('*.php')->in(array_map(base_path(...), self::THE_ORDER_WRITERS_CALLERS)) as $file) {
            $source = (string) file_get_contents($file->getRealPath());

            if (str_contains($source, 'OrderManager::generateOrder(') && $file->getRelativePathname() !== 'Utils/OrderManager.php') {
                $callers[$file->getRelativePathname()] = str_contains($source, 'OrderRefused');
            }
        }

        $this->assertGreaterThanOrEqual(3, count($callers), 'the scan found fewer files calling the order writer than there are: a path has moved');
        $this->assertSame([], array_keys(array_filter($callers, static fn (bool $reads): bool => !$reads)), 'these place an order and never read why it was refused');
    }

    // ---- the world -------------------------------------------------------------------------------------------------------------------

    private function switchTheLimitsOn(): void
    {
        config(['gcc.coupons.limits.enabled' => true, 'gcc.coupons.limits.per_store' => false]);
        CouponAllowance::forget();
    }

    /** @return list<array{coupon_code: string, coupon_discount: float|int}> one vendor group taking this coupon, as `getVendorWiseCartList` builds it */
    private function oneGroupTaking(string $code, float|int $discount): array
    {
        return [['coupon_code' => $code, 'coupon_discount' => $discount]];
    }

    private function recordARedemption(string $code, string $group, float $discount, int $customerId = 1): void
    {
        DB::table('coupon_redemptions')->insert([
            'coupon_id' => DB::table('coupons')->where('code', $code)->value('id'),
            'coupon_code' => $code,
            'customer_id' => $customerId,
            'order_group_id' => $group,
            'discount' => $discount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The redemption another checkout would have committed, written while this one holds its product locks.
     *
     * `Product::retrieved` fires on the locking read `generateOrder` takes inside its transaction, and on nothing the eligibility check
     * does, because that reads its products outside one -- so the quote still passes and the gate meets a coupon that has just been spent,
     * which is the state the race leaves. Once, because the order writer re-reads each product as it writes the lines.
     */
    private function letAnotherCheckoutTakeTheLastUse(string $code, float $discount): void
    {
        Product::retrieved(function () use ($code, $discount): void {
            if ($this->theOtherCheckoutHasRedeemedIt || DB::transactionLevel() === 0) {
                return;
            }

            $this->theOtherCheckoutHasRedeemedIt = true;
            $this->recordARedemption(code: $code, group: 'the-other-checkouts-group', discount: $discount);
        });
    }

    /**
     * The order the payment settlement path writes: paid, and opted out of refusal (`stock_policy`), which is the one caller that may not
     * be refused because the money has already moved.
     *
     * @return list<int>
     */
    private function placeThePaidOrder(User $customer, string $code): array
    {
        request()->merge(['user' => $customer]);
        request()->setUserResolver(static fn (): User => $customer);

        return OrderManager::generateOrder(data: [
            'is_guest' => 0,
            'customer_id' => $customer->id,
            'order_status' => 'confirmed',
            'payment_method' => 'digital_payment',
            'payment_status' => 'paid',
            'transaction_ref' => '',
            'coupon_code' => $code,
            'requestObj' => request(),

            // The gateway has already taken the discounted amount, so this order is never refused -- not for an oversold line and not for
            // a coupon past its cap. It is the one caller that opts out, and this is the flag it opts out with.
            'stock_policy' => 'allow_oversell',
        ]);
    }
}
