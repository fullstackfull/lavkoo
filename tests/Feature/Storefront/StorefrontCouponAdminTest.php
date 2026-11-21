<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApplySeoRedirects;
use App\Http\Middleware\DetectMobile;
use App\Http\Middleware\Localization;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\MonitorRequest;
use App\Http\Middleware\NoIndexThemePreview;
use App\Http\Middleware\RecordAnalytics;
use App\Http\Middleware\RecordHttpTelemetry;
use App\Http\Middleware\ScopeMoneyToMarket;
use App\Http\Requests\Admin\CouponAddRequest;
use App\Services\CouponService;
use App\Services\Storefront\CouponCountry;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05, slice 5, the write side: the admin types a coupon for one storefront, in that storefront's currency, and the coupon records the storefront. A coupon
 * that exists keeps its own, whatever a form says, so its amounts can neither be read nor typed again in another currency. The platform of these tests converts
 * from a base currency (the Saudi riyal at 3.75), so an amount typed for Kuwait that was not kept as typed would show as a different number in the table.
 */
class StorefrontCouponAdminTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function probeRoutes(): void
    {
        Route::middleware('money.market:coupon')->get('/admin/__coupon-probe/{id}', fn (string $id) => response()->json([
            'model' => getWebConfig('currency_model'),
            'stored' => currencyConverter(amount: 3.505),
        ]));
        Route::middleware('money.market:coupon')->post('/admin/__coupon-probe-create', fn () => response()->json([
            'model' => getWebConfig('currency_model'),
            'stored' => currencyConverter(amount: 3.505),
        ]));
    }

    // ---- the market of a coupon route -------------------------------------------------------------------------------------------

    public function test_a_new_coupon_runs_in_the_money_of_the_storefront_the_form_names(): void
    {
        $this->probeRoutes();

        $this->postJson('/admin/__coupon-probe-create', ['storefront_country' => 'KW'])->assertOk()->assertJsonPath('model', 'single_currency')->assertJsonPath('stored', 3.505);
        $this->postJson('/admin/__coupon-probe-create', ['storefront_country' => 'SA'])->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->postJson('/admin/__coupon-probe-create', [])->assertOk()->assertJsonPath('model', 'multi_currency');
    }

    public function test_an_existing_coupon_runs_in_its_own_money_whatever_the_form_names(): void
    {
        $this->giveCoupon(code: 'SAUDI', country: null);
        $this->giveCoupon(code: 'DINAR', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__coupon-probe/2')->assertOk()->assertJsonPath('model', 'single_currency')->assertJsonPath('stored', 3.505);
        $this->getJson('/admin/__coupon-probe/1')->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->getJson('/admin/__coupon-probe/99')->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->getJson('/admin/__coupon-probe/1?storefront_country=KW')->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->getJson('/admin/__coupon-probe/2?storefront_country=SA')->assertOk()->assertJsonPath('model', 'single_currency');
    }

    public function test_nothing_is_scoped_while_storefront_currencies_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveCoupon(code: 'DINAR', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__coupon-probe/1')->assertJsonPath('model', 'multi_currency');
        $this->postJson('/admin/__coupon-probe-create', ['storefront_country' => 'KW'])->assertJsonPath('model', 'multi_currency');
    }

    public function test_the_money_of_one_coupon_request_never_reaches_the_next(): void
    {
        $this->giveCoupon(code: 'DINAR', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__coupon-probe/1')->assertJsonPath('model', 'single_currency');

        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    public function test_every_admin_coupon_route_runs_in_the_money_of_its_coupon(): void
    {
        $couponRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'admin/coupon/'));

        $this->assertGreaterThanOrEqual(10, $couponRoutes->count());
        foreach ($couponRoutes as $route) {
            $this->assertContains('money.market:coupon', $route->gatherMiddleware(), "{$route->uri()} must run in the money of its coupon");
        }
        $this->assertSame(ScopeMoneyToMarket::class, app('router')->getMiddleware()['money.market']);
    }

    // ---- what is saved ----------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    private function addRequest(array $input = []): Request
    {
        return Request::create('/admin/coupon/add', 'POST', $input + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Welcome', 'code' => 'WELCOME', 'start_date' => '2026-12-01', 'expire_date' => '2027-12-01',
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount', 'discount' => 1.505, 'min_purchase' => 10.505,
        ]);
    }

    /** The data the admin form saves, taken through the middleware the way the route does. */
    private function savedWith(Request $request): array
    {
        $data = [];
        (new ScopeMoneyToMarket())->handle($request, function (Request $scoped) use (&$data) {
            $data = app(CouponService::class)->getAddData(request: $scoped);

            return response()->json([]);
        }, 'coupon');

        return $data;
    }

    public function test_a_coupon_added_for_kuwait_is_saved_with_the_amounts_typed_for_it_and_the_storefront(): void
    {
        $data = $this->savedWith($this->addRequest(['storefront_country' => 'KW']));

        $this->assertSame('KW', $data['storefront_country']);
        $this->assertEqualsWithDelta(10.505, (float) $data['min_purchase'], 0.000001);
        $this->assertEqualsWithDelta(1.505, (float) $data['discount'], 0.000001);
        $this->assertEqualsWithDelta(1.505, (float) $data['max_discount'], 0.000001, 'the cap of an amount coupon is its discount');
    }

    public function test_a_coupon_added_for_the_default_storefront_is_converted_as_it_always_was_and_names_no_storefront(): void
    {
        $data = $this->savedWith($this->addRequest(['storefront_country' => 'SA']));

        $this->assertNull($data['storefront_country']);
        $this->assertEqualsWithDelta(10.505 / 3.75, (float) $data['min_purchase'], 0.000001);
        $this->assertEqualsWithDelta(1.505 / 3.75, (float) $data['discount'], 0.000001);
    }

    public function test_a_coupon_whose_form_names_no_storefront_or_one_that_is_not_configured_is_the_default_storefronts(): void
    {
        foreach ([[], ['storefront_country' => ''], ['storefront_country' => 'XX']] as $input) {
            $data = $this->savedWith($this->addRequest($input));

            $this->assertNull($data['storefront_country'], json_encode($input));
            $this->assertEqualsWithDelta(10.505 / 3.75, (float) $data['min_purchase'], 0.000001);
        }
    }

    public function test_a_percentage_coupon_keeps_its_percentage_and_converts_only_the_cap_and_the_minimum(): void
    {
        $request = $this->addRequest(['storefront_country' => 'AE', 'discount_type' => 'percentage', 'discount' => 15, 'max_discount' => 20.5, 'min_purchase' => 100]);

        $data = $this->savedWith($request);

        $this->assertSame('AE', $data['storefront_country']);
        $this->assertEqualsWithDelta(15.0, (float) $data['discount'], 0.000001);
        $this->assertEqualsWithDelta(20.5, (float) $data['max_discount'], 0.000001);
        $this->assertEqualsWithDelta(100.0, (float) $data['min_purchase'], 0.000001);
    }

    public function test_nothing_about_the_storefront_is_saved_while_the_switch_is_off_or_before_the_migration(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->assertArrayNotHasKey('storefront_country', $this->savedWith($this->addRequest(['storefront_country' => 'KW'])), 'the switch is off');

        config(['gcc.storefronts.currency.enabled' => true]);
        $this->dropStorefrontCouponWorld();
        $this->buildStorefrontCouponWorld(withCountryColumn: false);

        $this->assertArrayNotHasKey('storefront_country', $this->savedWith($this->addRequest(['storefront_country' => 'KW'])), 'the column is not there');
    }

    public function test_the_update_of_a_coupon_never_carries_a_storefront(): void
    {
        $request = $this->addRequest(['storefront_country' => 'KW']);

        $data = app(CouponService::class)->getUpdateData(request: $request);

        $this->assertArrayNotHasKey('storefront_country', $data);
    }

    // ---- the validation of the form ---------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    private function validated(array $input): ValidatorContract
    {
        $rules = array_intersect_key((new CouponAddRequest())->rules(), ['storefront_country' => 1]);

        return Validator::make($input, $rules);
    }

    public function test_a_configured_storefront_whose_currency_is_active_is_accepted_and_so_is_no_choice(): void
    {
        foreach (['SA', 'AE', 'KW', 'QA', 'BH', 'OM', null, ''] as $country) {
            $this->assertTrue($this->validated(['storefront_country' => $country])->passes(), (string) $country);
        }
    }

    public function test_a_country_that_is_not_configured_is_refused(): void
    {
        foreach (['XX', 'kw ', 'SAU', 5, ['KW']] as $country) {
            $this->assertTrue($this->validated(['storefront_country' => $country])->fails(), json_encode($country));
        }
    }

    public function test_a_storefront_whose_currency_is_not_active_is_refused_with_a_message_that_says_why(): void
    {
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');

        $validator = $this->validated(['storefront_country' => 'KW']);

        $this->assertTrue($validator->fails());
        $this->assertSame([translate('the_currency_of_this_storefront_is_not_active_so_a_coupon_cannot_be_typed_for_it')], $validator->errors()->get('storefront_country'));
        $this->assertTrue($this->validated(['storefront_country' => 'AE'])->passes(), 'the other storefronts are not affected');
    }

    public function test_the_field_is_not_validated_while_coupons_are_not_tracked(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertArrayNotHasKey('storefront_country', (new CouponAddRequest())->rules());
        $this->assertSame([], CouponCountry::newCouponRules());
    }

    // ---- the whole route --------------------------------------------------------------------------------------------------------

    private function throughTheRoute(): void
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function postedCoupon(array $input): TestResponse
    {
        return $this->postJson(route('admin.coupon.add'), $input + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Welcome', 'code' => 'WELCOME', 'start_date' => '2026-12-01', 'expire_date' => '2027-12-01',
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount', 'discount' => 1.505, 'min_purchase' => 10.505,
        ]);
    }

    public function test_the_route_stores_a_kuwaiti_coupon_as_typed_and_a_saudi_one_converted(): void
    {
        $this->throughTheRoute();

        $this->postedCoupon(['storefront_country' => 'KW', 'code' => 'DINAR'])->assertOk()->assertJsonPath('status', 1);
        $this->postedCoupon(['storefront_country' => 'SA', 'code' => 'RIYAL'])->assertOk()->assertJsonPath('status', 1);
        $this->postedCoupon(['code' => 'LEGACY'])->assertOk()->assertJsonPath('status', 1);

        $kuwait = DB::table('coupons')->where('code', 'DINAR')->first();
        $saudi = DB::table('coupons')->where('code', 'RIYAL')->first();
        $legacy = DB::table('coupons')->where('code', 'LEGACY')->first();

        $this->assertSame('KW', $kuwait->storefront_country);
        $this->assertEqualsWithDelta(10.505, (float) $kuwait->min_purchase, 0.000001);
        $this->assertEqualsWithDelta(1.505, (float) $kuwait->discount, 0.000001);
        $this->assertNull($saudi->storefront_country);
        $this->assertEqualsWithDelta(10.505 / 3.75, (float) $saudi->min_purchase, 0.000001);
        $this->assertNull($legacy->storefront_country);
        $this->assertEqualsWithDelta(10.505 / 3.75, (float) $legacy->min_purchase, 0.000001);
    }

    public function test_the_route_refuses_a_storefront_that_is_not_configured_and_one_with_no_active_currency_and_saves_nothing(): void
    {
        $this->throughTheRoute();
        DB::table('currencies')->where('code', 'QAR')->delete();
        Cache::forget('active_currency_list');

        $this->postedCoupon(['storefront_country' => 'XX', 'code' => 'ONE'])->assertStatus(422)->assertJsonValidationErrors('storefront_country');
        $this->postedCoupon(['storefront_country' => 'QA', 'code' => 'TWO'])->assertStatus(422)->assertJsonValidationErrors('storefront_country');

        $this->assertSame(0, DB::table('coupons')->count());
    }

    public function test_the_update_route_keeps_the_storefront_of_the_coupon_and_types_in_its_currency_whatever_the_form_names(): void
    {
        $this->throughTheRoute();
        $id = $this->giveCoupon(code: 'DINAR', country: 'KW', attributes: ['min_purchase' => 10.505, 'discount' => 1.505]);

        $this->postJson(route('admin.coupon.update', ['id' => $id]), [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Welcome', 'code' => 'DINAR', 'start_date' => '2026-12-01', 'expire_date' => '2027-12-01',
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount', 'discount' => 2.25, 'min_purchase' => 12.75,
            'storefront_country' => 'SA',
        ])->assertOk()->assertJsonPath('status', 1);

        $coupon = DB::table('coupons')->where('id', $id)->first();

        $this->assertSame('KW', $coupon->storefront_country, 'a form cannot move a coupon to another storefront');
        $this->assertEqualsWithDelta(12.75, (float) $coupon->min_purchase, 0.000001, 'it is still typed in dinars');
        $this->assertEqualsWithDelta(2.25, (float) $coupon->discount, 0.000001);
    }

    // ---- the amounts the edit form shows ----------------------------------------------------------------------------------------

    /** @return array<string, array{float}> fils amounts whose binary value is just below the typed one, so a plain floor at three decimals loses the last digit */
    public static function amountsThatFallJustBelowTheirDigits(): array
    {
        return ['1.001' => [1.001], '1.005' => [1.005], '1.011' => [1.011], '1.019' => [1.019], '1.023' => [1.023]];
    }

    #[DataProvider('amountsThatFallJustBelowTheirDigits')]
    public function test_the_edit_form_of_a_kuwaiti_coupon_shows_the_amount_that_was_typed(float $typed): void
    {
        $shown = $this->inStorefront('KW', fn () => usdToDefaultCurrency(amount: $typed));

        $this->assertSame($typed, $shown, 'one fils less on the form would be saved by the next edit');
    }

    public function test_the_edit_form_of_a_kuwaiti_coupon_still_floors_what_has_more_digits_than_the_currency(): void
    {
        $this->assertSame(2.675, $this->inStorefront('KW', fn () => usdToDefaultCurrency(amount: 2.6759)));
        $this->assertSame(2.99, $this->inStorefront('AE', fn () => usdToDefaultCurrency(amount: 2.999)), 'two decimals: the floor of 2.999 is 2.99, never rounded up');
    }

    public function test_the_arithmetic_of_the_platform_is_untouched_outside_a_storefront(): void
    {
        foreach (range(1, 60) as $step) {
            $amount = $step * 0.07;
            $rate = 3.75 / 1.0;
            $expected = floor($amount * $rate * 100) / 100;

            $this->assertSame($expected, usdToDefaultCurrency(amount: $amount), (string) $amount);
        }
    }
}
