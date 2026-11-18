<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\User;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * I1-12.3 of the master plan: a coupon created for the Kuwaiti storefront is stored, shown and redeemed in dinars with no Saudi conversion. The first two halves are
 * StorefrontCouponAdminTest and StorefrontCouponAdminViewTest; this is the third. A customer with a cart of 30.000 dinars applies a coupon typed in dinars (a
 * discount of 1.505 and a minimum purchase of 10.505, stored as typed) through the real routes of the customer API, the apply route and the checkout, and the
 * answer is the amount as stored, never 1.505 divided by the riyal's rate. One case follows a coupon the whole way: typed in the real admin form, stored, redeemed. A coupon is refused when its minimum is greater than the eligible total, the rule of the
 * repository, proved with a minimum one step of the currency above the cart, equal to it and one step below. The Saudi coupon keeps its answer and the same rule in
 * riyals, and each storefront is a stranger to the code of the other. Every case runs on a platform that sells in one currency and on one that converts from a base
 * currency with the riyal at 3.75, which is where a Saudi conversion of a dinar amount would show.
 *
 * The apply route binds no storefront of its own: the request names it, and the test process serves it as a web request that no storefront middleware has reached.
 * The second finder of a coupon (the group-wise one of the checkout) is not proved here: it reads the code the first one has already found and a code is unique, so
 * no route tells it from the first. CouponFindersAreScopedTest guards that it is scoped.
 */
class StorefrontCouponRedemptionTest extends ApiAddressTestCase
{
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;

    private const APPLY = '/api/v1/coupon/apply';

    private const COD = '/api/v1/customer/order/place';

    private const KUWAITI = 'DINAR';

    private const SAUDI = 'RIYAL';

    private const KUWAITI_DISCOUNT = 1.505;

    private const SAUDI_DISCOUNT = 10.0;

    /** The product each of the two storefronts sells (a cart of 100.00 riyals, a cart of 30.000 dinars), the coupon typed for it and the nearest minimums on either side of the cart. */
    private const CARTS = [
        'SA' => ['product' => 1, 'code' => self::SAUDI, 'discount' => self::SAUDI_DISCOUNT, 'above' => '100.01', 'equal' => '100.00', 'below' => '99.99'],
        'KW' => ['product' => 3, 'code' => self::KUWAITI, 'discount' => self::KUWAITI_DISCOUNT, 'above' => '30.001', 'equal' => '30.000', 'below' => '29.999'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSixStorefrontCheckoutWorld();
        $this->setSetting(type: 'minimum_order_amount_status', value: '0');
        $this->buildTheCouponsOfTheCheckout();
        $this->giveCoupon(code: self::KUWAITI, country: 'KW', attributes: ['discount' => self::KUWAITI_DISCOUNT, 'min_purchase' => 10.505]);
        // The minimum of each is one the cart of the other storefront would meet, so that only the storefront can keep a code away from the wrong cart.
        $this->giveCoupon(code: self::SAUDI, country: null, attributes: ['discount' => self::SAUDI_DISCOUNT, 'min_purchase' => 20]);
    }

    protected function tearDown(): void
    {
        CouponCountry::flush();

        parent::tearDown();
    }

    /** The coupons table of the checkout world only knows what the cart reads; a coupon is applied with what the admin form stores, and an order keeps its amounts as the real table does. */
    private function buildTheCouponsOfTheCheckout(): void
    {
        Schema::table(table: 'coupons', callback: function (Blueprint $table) {
            $table->string('added_by')->default('admin');
            $table->string('coupon_bearer')->default('inhouse');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->string('title')->nullable();
            $table->decimal('min_purchase', 40, 20)->default(0);
            $table->decimal('max_discount', 40, 20)->default(0);
            $table->decimal('discount', 40, 20)->default(0);
            $table->string('discount_type')->default('amount');
            $table->integer('limit')->nullable();
        });
        $this->runCouponCountryMigration();
        CouponCountry::flush();

        (require database_path('migrations/2025_07_24_104352_create_order_details_rewards_table.php'))->up();
        Schema::table(table: 'orders', callback: function (Blueprint $table) {
            $table->decimal('discount_amount', 40, 20)->nullable()->change();
            $table->decimal('order_amount', 40, 20)->default(0)->change();
        });
    }

    /**
     * The gates of the panel (an admin signed in, with the right to a module) are not what is under test: the form passes them and they are put back for what follows.
     *
     * @param array<string, mixed> $form what the admin types, over the fields every coupon of the test shares
     */
    private function typeTheCouponInTheAdminForm(array $form): void
    {
        $gates = [AdminMiddleware::class, ModulePermissionMiddleware::class];

        $this->withoutMiddleware($gates);
        $this->postJson(uri: route('admin.coupon.add'), data: $form + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Typed in the form', 'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addDay()->toDateString(),
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount',
        ])->assertOk()->assertJsonPath('status', 1);
        $this->withMiddleware($gates);
    }

    /** @return array<string, array{string}> */
    public static function platforms(): array
    {
        return ['a platform that sells in one currency' => ['single_currency'], 'a platform that converts from a base currency, the riyal at 3.75' => ['multi_currency']];
    }

    /** @return array<string, array{string, string, string}> the platform, the storefront and the minimum purchase of its coupon, for each minimum the cart of that storefront meets */
    public static function minimumsTheCartMeets(): array
    {
        return self::minimumsOfEveryPlatformAndStorefront(positions: ['equal', 'below']);
    }

    /** @return array<string, array{string, string, string}> the same, for the minimum that is greater than the cart */
    public static function minimumsGreaterThanTheCart(): array
    {
        return self::minimumsOfEveryPlatformAndStorefront(positions: ['above']);
    }

    /**
     * @param list<string> $positions
     * @return array<string, array{string, string, string}>
     */
    private static function minimumsOfEveryPlatformAndStorefront(array $positions): array
    {
        $wording = ['above' => 'above', 'equal' => 'equal to', 'below' => 'below'];

        $cases = [];
        foreach (self::platforms() as $platform => [$model]) {
            foreach (self::CARTS as $storefront => $cart) {
                foreach ($positions as $position) {
                    $cases["{$storefront}, {$platform}, a minimum {$wording[$position]} the cart ({$cart[$position]})"] = [$model, $storefront, $cart[$position]];
                }
            }
        }

        return $cases;
    }

    /** The platform of the money world converts from a base currency (the riyal at 3.75), where an amount that was converted by mistake shows as another number. */
    private function runThePlatformAs(string $model): void
    {
        if ($model === 'multi_currency') {
            foreach (self::MONEY_CURRENCIES as $code => $currency) {
                DB::table('currencies')->updateOrInsert(
                    attributes: ['code' => $code],
                    values: ['name' => $code, 'symbol' => $currency['symbol'], 'exchange_rate' => $currency['rate'], 'status' => 1],
                );
            }
            $this->setSetting(type: 'currency_model', value: 'multi_currency');
            cache()->forget('active_currency_list');
        }

        $this->assertSame($model, getWebConfig('currency_model'), 'the platform runs the currency model of the case');
        $this->assertSame('single_currency', $this->inStorefront(country: 'KW', callback: fn (): string => getWebConfig('currency_model')), 'the Kuwaiti storefront trades in its own money whatever the platform does');
    }

    private function customerWithCartsIn(string ...$storefronts): User
    {
        $customer = $this->makeCustomer();
        foreach ($storefronts as $storefront) {
            $this->fillTheCartFrom(customer: $customer, country: $storefront, productId: self::CARTS[$storefront]['product']);
        }

        return $customer;
    }

    /** @return array<string, string> */
    private function storefrontHeader(?string $storefront): array
    {
        return $storefront === null ? [] : ['X-Storefront' => $storefront];
    }

    /**
     * A request no storefront middleware has reached binds nothing, so what an earlier request bound is forgotten and the storefront is the one the header names.
     * The header the cart was filled with stays on the test's requests until it is removed, and a request that names none must name none.
     */
    private function applyFrom(?string $storefront, User $customer, string $code): TestResponse
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        app()->forgetInstance(StorefrontContext::class);
        $this->serveAsHttpRequest(uri: self::APPLY);

        return $this->withoutHeader('X-Storefront')->asCustomer($customer)->getJson(uri: self::APPLY . "?code={$code}", headers: $this->storefrontHeader($storefront));
    }

    private function placeFrom(string $storefront, User $customer, ?string $code = null): TestResponse
    {
        $this->skipInStrictModeUntilTheCouponCountIsFixed();

        return $this->asCustomer($customer)->getJson(uri: self::COD . ($code === null ? '' : "?coupon_code={$code}"), headers: $this->storefrontHeader($storefront));
    }

    private function assertApplied(float $discount, TestResponse $response, string $message = ''): void
    {
        $response->assertOk();
        $this->assertEqualsWithDelta($discount, $response->json('coupon_discount'), 0.000001, $message);
        $this->assertSame('discount_on_purchase', $response->json('coupon_type'));
    }

    private function assertRefusedWith(string $message, TestResponse $response): void
    {
        $response->assertStatus(202);
        $this->assertSame($message, $response->json());
    }

    #[DataProvider('platforms')]
    public function test_a_kuwaiti_coupon_is_applied_in_dinars_as_it_was_typed(string $model): void
    {
        $this->runThePlatformAs($model);
        $customer = $this->customerWithCartsIn('KW');

        $this->assertApplied(
            discount: self::KUWAITI_DISCOUNT,
            response: $this->applyFrom(storefront: 'KW', customer: $customer, code: self::KUWAITI),
            message: 'the dinars as stored, not 1.505 / 3.75 = 0.401',
        );
    }

    #[DataProvider('platforms')]
    public function test_a_coupon_typed_for_kuwait_in_the_admin_form_is_redeemed_in_dinars_as_typed(string $model): void
    {
        $this->runThePlatformAs($model);
        $this->typeTheCouponInTheAdminForm(['code' => 'TYPED', 'storefront_country' => 'KW', 'discount' => 1.505, 'min_purchase' => 10.505]);
        $customer = $this->customerWithCartsIn('KW');

        $this->assertApplied(
            discount: 1.505,
            response: $this->applyFrom(storefront: 'KW', customer: $customer, code: 'TYPED'),
            message: 'typed as 1.505 dinars in the form, redeemed as 1.505 dinars',
        );

        $this->placeFrom(storefront: 'KW', customer: $customer, code: 'TYPED')->assertOk();
        $this->assertEqualsWithDelta(1.505, (float) DB::table('orders')->value('discount_amount'), 0.000001, 'and the order of the checkout is lowered by as much');
    }

    #[DataProvider('minimumsTheCartMeets')]
    public function test_a_cart_that_meets_the_minimum_of_its_coupon_in_its_own_money_may_use_it(string $model, string $storefront, string $minimum): void
    {
        $this->runThePlatformAs($model);
        DB::table('coupons')->where('code', self::CARTS[$storefront]['code'])->update(['min_purchase' => $minimum]);
        $customer = $this->customerWithCartsIn($storefront);

        $this->assertApplied(
            discount: self::CARTS[$storefront]['discount'],
            response: $this->applyFrom(storefront: $storefront, customer: $customer, code: self::CARTS[$storefront]['code']),
            message: 'the discount as stored',
        );
    }

    #[DataProvider('minimumsGreaterThanTheCart')]
    public function test_a_cart_below_the_minimum_of_its_coupon_in_its_own_money_is_refused(string $model, string $storefront, string $minimum): void
    {
        $this->runThePlatformAs($model);
        DB::table('coupons')->where('code', self::CARTS[$storefront]['code'])->update(['min_purchase' => $minimum]);
        $customer = $this->customerWithCartsIn($storefront);

        $this->assertRefusedWith(
            message: translate('minimum_purchase_amount_not_reached_for_this_coupon.'),
            response: $this->applyFrom(storefront: $storefront, customer: $customer, code: self::CARTS[$storefront]['code']),
        );
    }

    #[DataProvider('platforms')]
    public function test_the_kuwaiti_code_is_not_found_from_the_saudi_storefront(string $model): void
    {
        $this->runThePlatformAs($model);
        $customer = $this->customerWithCartsIn('SA', 'KW');

        $this->applyFrom(storefront: 'KW', customer: $customer, code: self::KUWAITI)->assertOk();

        foreach (['SA', null] as $storefront) {
            $this->assertRefusedWith(message: translate('invalid_coupon'), response: $this->applyFrom(storefront: $storefront, customer: $customer, code: self::KUWAITI));
        }
    }

    #[DataProvider('platforms')]
    public function test_the_saudi_code_is_not_found_from_the_kuwaiti_storefront(string $model): void
    {
        $this->runThePlatformAs($model);
        $customer = $this->customerWithCartsIn('SA', 'KW');

        $this->applyFrom(storefront: 'SA', customer: $customer, code: self::SAUDI)->assertOk();

        $this->assertRefusedWith(message: translate('invalid_coupon'), response: $this->applyFrom(storefront: 'KW', customer: $customer, code: self::SAUDI));
    }

    #[DataProvider('platforms')]
    public function test_a_saudi_coupon_is_applied_in_saudi_arabia_as_it_always_was(string $model): void
    {
        $this->runThePlatformAs($model);
        $customer = $this->customerWithCartsIn('SA');

        foreach (['SA', null] as $storefront) {
            $this->assertApplied(
                discount: self::SAUDI_DISCOUNT,
                response: $this->applyFrom(storefront: $storefront, customer: $customer, code: self::SAUDI),
                message: 'the amount as stored, from the default storefront and from a request that names none',
            );
        }
    }

    /** @return array<string, array{string, string}> the platform and the storefront of a checkout */
    public static function checkoutsOfEveryPlatform(): array
    {
        $cases = [];
        foreach (self::platforms() as $platform => [$model]) {
            foreach (array_keys(self::CARTS) as $storefront) {
                $cases["{$storefront}, {$platform}"] = [$model, $storefront];
            }
        }

        return $cases;
    }

    #[DataProvider('checkoutsOfEveryPlatform')]
    public function test_a_checkout_lowers_the_order_by_the_amount_of_its_own_coupon_as_stored(string $model, string $storefront): void
    {
        $this->runThePlatformAs($model);
        $discount = self::CARTS[$storefront]['discount'];

        $this->placeFrom(storefront: $storefront, customer: $this->customerWithCartsIn($storefront))->assertOk();
        $this->placeFrom(storefront: $storefront, customer: $this->customerWithCartsIn($storefront), code: self::CARTS[$storefront]['code'])->assertOk();

        [$without, $with] = DB::table('orders')->orderBy('id')->get()->all();
        $this->assertEqualsWithDelta(0.0, (float) $without->discount_amount, 0.000001);
        $this->assertSame(self::CARTS[$storefront]['code'], $with->coupon_code);
        $this->assertEqualsWithDelta($discount, (float) $with->discount_amount, 0.000001, 'the amount as stored');
        $this->assertEqualsWithDelta($discount, (float) $without->order_amount - (float) $with->order_amount, 0.000001, 'the same cart costs that much less');
        $this->assertEqualsWithDelta($discount, (float) DB::table('order_details_rewards')->value('reward_amount'), 0.000001, 'and the reward of the order records it');
    }

    #[DataProvider('checkoutsOfEveryPlatform')]
    public function test_a_checkout_takes_nothing_off_for_the_code_of_the_other_storefront(string $model, string $storefront): void
    {
        $this->runThePlatformAs($model);
        $codeOfTheOther = self::CARTS[$storefront === 'KW' ? 'SA' : 'KW']['code'];

        $this->placeFrom(storefront: $storefront, customer: $this->customerWithCartsIn($storefront), code: $codeOfTheOther)->assertOk();

        $this->assertEqualsWithDelta(0.0, (float) DB::table('orders')->value('discount_amount'), 0.000001, 'an amount typed in another currency is never deducted');
        $this->assertSame(0, DB::table('order_details_rewards')->count());
    }
}
