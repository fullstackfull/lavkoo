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
use App\Models\Coupon;
use App\Services\Storefront\CouponCountry;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05, slice 5, the admin coupon screens: a coupon is typed for one storefront, so the form offers the storefronts that can take one and its labels follow the
 * choice, an existing coupon shows its own storefront and its amounts as they were typed, and the quick view and the export print each coupon in its own money.
 * With the switch off none of it appears and the markup is what it was.
 */
class StorefrontCouponAdminViewTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        Cache::flush();
        Cache::put(CACHE_IN_HOUSE_SHOP_TABLE, ['name' => 'In-house shop'], 3600);
        session()->flush();
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function partial(?Coupon $coupon = null): string
    {
        return view('admin-views.coupon.partials._storefront-country', $coupon === null ? [] : ['coupon' => $coupon])->render();
    }

    private function collapsed(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    private function coupon(string $code, ?string $country, array $attributes = []): Coupon
    {
        return Coupon::query()->findOrFail($this->giveCoupon(code: $code, country: $country, attributes: $attributes));
    }

    // ---- the country select -------------------------------------------------------------------------------------------------------

    public function test_a_new_coupon_offers_every_storefront_that_can_take_one_with_the_mark_of_its_currency(): void
    {
        $html = $this->partial();

        preg_match_all('/<option value="([A-Z]{2})" data-currency-symbol="([^"]*)"\s*(selected)?/', $html, $options, PREG_SET_ORDER);
        $offered = array_column($options, 2, 1);

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_keys($offered));
        $this->assertSame(['SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KD', 'QA' => 'QR', 'BH' => 'BD', 'OM' => 'RO'], $offered);
        $this->assertStringContainsString('name="storefront_country"', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertSame(['SA'], array_values(array_column(array_filter($options, static fn (array $option): bool => ($option[3] ?? '') === 'selected'), 1)), 'the default storefront is chosen');
    }

    public function test_a_storefront_with_no_active_currency_is_not_offered(): void
    {
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');

        $this->assertSame(['SA', 'AE', 'QA', 'BH', 'OM'], CouponCountry::options());
        $this->assertStringNotContainsString('value="KW"', $this->partial());
    }

    public function test_the_choice_made_before_a_failed_submit_is_kept(): void
    {
        session()->flashInput(['storefront_country' => 'KW']);
        $this->app['request']->setLaravelSession(session()->driver());

        $this->assertMatchesRegularExpression('/<option value="KW"[^>]*selected/', $this->partial());
    }

    public function test_an_existing_coupon_shows_its_storefront_and_cannot_change_it(): void
    {
        $html = $this->collapsed($this->partial($this->coupon('DINAR', 'KW')));

        $this->assertStringContainsString('value="KW · KWD" disabled', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('name="storefront_country"', $html, 'a disabled field is not even submitted');
        $this->assertStringContainsString('value="SA · SAR" disabled', $this->collapsed($this->partial($this->coupon('RIYAL', null))), 'a coupon with no storefront is the default one\'s');
    }

    public function test_nothing_is_rendered_while_the_switch_is_off_or_before_the_migration(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->assertSame('', $this->collapsed($this->partial()));
        $this->assertSame('', $this->collapsed($this->partial($this->coupon('DINAR', 'KW'))));

        config(['gcc.storefronts.currency.enabled' => true]);
        $this->dropStorefrontCouponWorld();
        $this->buildStorefrontCouponWorld(withCountryColumn: false);

        $this->assertSame('', $this->collapsed($this->partial()));
    }

    // ---- the add and edit pages ---------------------------------------------------------------------------------------------------

    private function addPage(): string
    {
        $coupons = new LengthAwarePaginator(new Collection(), 0, 25, 1, ['path' => '/admin/coupon/add']);

        return view('admin-views.coupon.add-new', ['coupons' => $coupons, 'customers' => new Collection()])->render();
    }

    public function test_the_add_page_has_the_select_and_three_marks_that_it_changes(): void
    {
        $html = $this->addPage();

        $this->assertSame(1, substr_count($html, 'id="storefront-country"'));
        $this->assertSame(3, substr_count($html, 'class="coupon-currency">SAR</span>'), 'the discount, the minimum purchase and the maximum discount');
        $this->assertStringContainsString("$('.coupon-currency').text(", $html);
    }

    public function test_the_add_page_has_no_select_while_the_switch_is_off_and_keeps_its_marks(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $html = $this->addPage();

        $this->assertStringNotContainsString('storefront-country', $html);
        $this->assertSame(3, substr_count($html, 'class="coupon-currency">SAR</span>'));
    }

    public function test_the_edit_page_of_a_kuwaiti_coupon_shows_its_storefront_and_its_amounts_as_typed(): void
    {
        $coupon = $this->coupon('DINAR', 'KW', ['min_purchase' => 10.505, 'discount' => 1.005, 'discount_type' => 'amount', 'max_discount' => 1.005]);

        $html = app(StorefrontCurrency::class)->within(country: 'KW', callback: fn (): string => view('admin-views.coupon.edit', [
            'coupon' => $coupon, 'customers' => new Collection(), 'sellers' => new Collection(),
        ])->render());

        $this->assertStringContainsString('value="KW · KWD" disabled', $this->collapsed($html));
        $this->assertStringContainsString('name="min_purchase"', $html);
        $this->assertMatchesRegularExpression('/id="minimum purchase" value="10\.505"/', $html);
        $this->assertMatchesRegularExpression('/id="discount" value="1\.005"/', $html, 'the fils is not lost on the way to the form');
        $this->assertStringContainsString('(KD)', $html);
        $this->assertStringNotContainsString('(SAR)', $html);
        $this->assertStringNotContainsString("$('.coupon-currency').text(", $html, 'a coupon cannot change its storefront, so nothing follows the choice');
    }

    // ---- the quick view and the export ----------------------------------------------------------------------------------------------

    private function throughTheRoute(): void
    {
        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    public function test_the_quick_view_prints_a_coupon_in_its_own_money(): void
    {
        $this->throughTheRoute();
        $saudi = $this->coupon('RIYAL', null, ['min_purchase' => 4, 'discount' => 2, 'discount_type' => 'amount']);
        $kuwaiti = $this->coupon('DINAR', 'KW', ['min_purchase' => 10.505, 'discount' => 1.505, 'discount_type' => 'amount']);

        $saudiView = $this->getJson(route('admin.coupon.quick-view-details', ['id' => $saudi->id]))->assertOk()->json('view');
        $kuwaitiView = $this->getJson(route('admin.coupon.quick-view-details', ['id' => $kuwaiti->id]))->assertOk()->json('view');

        $this->assertStringContainsString('15.00SAR', $this->collapsed($saudiView), 'the stored 4 is 15.00 riyals at 3.75');
        $this->assertStringContainsString('10.505KD', $this->collapsed($kuwaitiView));
        $this->assertStringContainsString('1.505KD', $this->collapsed($kuwaitiView));
        $this->assertSame('multi_currency', getWebConfig('currency_model'), 'the money of the coupon is not left in force');
    }

    public function test_the_export_prints_each_coupon_in_its_own_money_and_the_platforms_for_the_rest(): void
    {
        $this->coupon('RIYAL', null, ['min_purchase' => 4, 'discount' => 2, 'discount_type' => 'amount']);
        $this->coupon('DINAR', 'KW', ['min_purchase' => 10.505, 'discount' => 1.505, 'discount_type' => 'amount']);
        $this->coupon('DIRHAM', 'AE', ['min_purchase' => 40.5, 'discount' => 10, 'discount_type' => 'percentage', 'max_discount' => 25.25]);
        $coupons = Coupon::query()->orderBy('id')->get()->each(fn (Coupon $coupon) => $coupon->setAttribute('order_count', 0));

        $html = $this->collapsed(view('file-exports.coupon-list', ['data' => ['coupon' => $coupons, 'search' => null]])->render());

        $this->assertStringContainsString('<td> 15.00SAR </td>', $html);
        $this->assertStringContainsString('<td> 10.505KD </td>', $html);
        $this->assertStringContainsString('<td> 1.505KD </td>', $html);
        $this->assertStringContainsString('<td> 40.50AED </td> <td> 25.25AED </td>', $html, 'the minimum purchase and the cap of a percentage coupon');
        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    public function test_nothing_is_scoped_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $coupons = Coupon::query()->where('id', $this->coupon('DINAR', 'KW', ['min_purchase' => 7.5, 'discount' => 1, 'discount_type' => 'amount'])->id)->get()
            ->each(fn (Coupon $coupon) => $coupon->setAttribute('order_count', 0));

        $html = $this->collapsed(view('file-exports.coupon-list', ['data' => ['coupon' => $coupons, 'search' => null]])->render());

        $this->assertStringContainsString('<td> 28.12SAR </td>', $html, 'the platform reads the stored number as it always did');
    }
}
