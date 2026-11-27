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
use App\Services\Coupon\CouponAllowance;
use App\Services\Coupon\CouponRedemption;
use App\Services\Storefront\CouponCountry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * I1-14, the clause that was not whole: **the administrator sees what a coupon has given away, in the money of the store it belongs to.**
 *
 * The cap and the budget were built with the rest of I1-14 and `CouponAllowance::spendOf()` has answered this question since -- the spend,
 * the budget, the uses and the cap, each amount written in the money of the coupon's own storefront. Nothing read it. The method had no
 * caller anywhere in the application, so the figure existed and no screen showed it: an administrator could give a coupon a budget of
 * twenty thousand and had no way to see that nineteen of it were gone.
 *
 * It is wired into the one screen that shows a coupon's own terms, the quick view the coupon row opens, beside the minimum purchase and the
 * maximum discount it already prints.
 *
 * **The first test is the dormancy, and it asserts the SHAPE rather than the absence of a word.** It renders the view the way the controller
 * now renders it and the way the controller rendered it BEFORE this change -- with no `spend` at all -- and asserts the two are identical,
 * character for character. A block that appeared with a null inside it, or a stray space where an `@if` used to be, is a different answer
 * from a block that was never there, and "the HTML does not contain the word budget" would not have caught either.
 *
 * Three separate things each keep the block away, and each has a case: the switch (`gcc.coupons.limits.enabled`), a coupon with no budget --
 * where a spend figure is a number with nothing to read it against -- and the migrations not having run, which is every installation as this
 * ships.
 */
class CouponSpendOnTheAdminScreenTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    private const SPEND_ELEMENT = 'id="coupon_budget_spent"';

    private const USES_ELEMENT = 'id="coupon_uses"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        (require database_path('migrations/2027_01_08_100000_create_coupon_redemptions_table.php'))->up();
        (require database_path('migrations/2027_01_08_100100_add_cap_and_budget_to_coupons_table.php'))->up();
        CouponAllowance::forget();
        Cache::flush();
        Cache::put(CACHE_IN_HOUSE_SHOP_TABLE, ['name' => 'In-house shop'], 3600);
        session()->flush();

        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class,
            Localization::class, DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class,
            MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        // Built from its real migration, so it is put back: a table a class leaves behind is taken from every class after it.
        Schema::dropIfExists('coupon_redemptions');
        CouponAllowance::forget();
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------- dormancy */

    /**
     * Off, which is how it ships: the screen is what it was, character for character.
     *
     * The comparison is against the view rendered WITHOUT the `spend` variable -- the exact call the controller made before this change --
     * so this is a statement about the markup and not about a word being missing from it.
     */
    public function test_with_the_switch_off_the_screen_is_character_for_character_what_it_was(): void
    {
        config(['gcc.coupons.limits.enabled' => false]);
        $coupon = $this->couponWithABudget(code: 'DORMANT', country: null);
        $this->aRedemptionOf($coupon, discount: 5);

        $this->assertSame(
            $this->asTheControllerRenderedItBefore($coupon),
            $this->quickViewOf($coupon),
            'the quick view is no longer the markup it was while the limits are off',
        );
    }

    /** Off: and the two elements are simply not there, which is the same fact read from the other side. */
    public function test_with_the_switch_off_neither_element_is_rendered(): void
    {
        config(['gcc.coupons.limits.enabled' => false]);
        $coupon = $this->couponWithABudget(code: 'QUIET', country: null);
        $this->aRedemptionOf($coupon, discount: 5);

        $html = $this->quickViewOf($coupon);

        $this->assertStringNotContainsString(self::SPEND_ELEMENT, $html);
        $this->assertStringNotContainsString(self::USES_ELEMENT, $html);
    }

    /** Off: the answer the route gives has the shape it always had -- one key, `view`, and nothing beside it. */
    public function test_the_answer_of_the_route_keeps_its_shape(): void
    {
        config(['gcc.coupons.limits.enabled' => false]);
        $coupon = $this->couponWithABudget(code: 'SHAPE', country: null);

        $body = $this->getJson(route('admin.coupon.quick-view-details', ['id' => $coupon->id]))->assertOk()->json();

        $this->assertSame(['view'], array_keys($body), 'the quick view answers with keys it did not answer with before');
    }

    /** On, but the migrations have not run: nothing is read and nothing is shown, which is every installation as this ships. */
    public function test_with_the_columns_absent_nothing_is_shown(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        $coupon = $this->couponWithABudget(code: 'UNMIGRATED', country: null);
        $this->aRedemptionOf($coupon, discount: 5);

        Schema::dropIfExists('coupon_redemptions');
        CouponAllowance::forget();

        $this->assertStringNotContainsString(self::SPEND_ELEMENT, $this->quickViewOf($coupon));
    }

    /* ------------------------------------------------------------------- on it */

    /** On: the screen prints what the coupon has given away against what it was allowed to. */
    public function test_the_screen_prints_the_spend_against_the_budget(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        $coupon = $this->couponWithABudget(code: 'SPEND', country: null, attributes: ['budget' => 20, 'total_uses_limit' => 4]);
        $this->aRedemptionOf($coupon, discount: 5);

        $html = $this->collapsed($this->quickViewOf($coupon));

        $this->assertStringContainsString(self::SPEND_ELEMENT, $html);
        $this->assertStringContainsString('18.75SAR / 75.00SAR', $html, 'the stored 5 and 20 are 18.75 and 75.00 riyals at 3.75');
        $this->assertStringContainsString('1 / 4', $html, 'the redemptions are not counted against the cap');
    }

    /** On: and each figure is in the money of the coupon's OWN storefront, which is the clause the criterion names. */
    public function test_each_figure_is_in_the_money_of_the_coupons_own_storefront(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        $kuwaiti = $this->couponWithABudget(code: 'DINAR', country: 'KW', attributes: ['budget' => 10.505]);
        $this->aRedemptionOf($kuwaiti, discount: 1.505);

        $html = $this->collapsed($this->quickViewOf($kuwaiti));

        $this->assertStringContainsString('1.505KD / 10.505KD', $html, 'a Kuwaiti coupon is not read in dinars');
        $this->assertSame('multi_currency', getWebConfig('currency_model'), 'the money of the coupon is left in force after the screen');
    }

    /** On: a coupon with no budget shows nothing, because a spend beside no budget is a number with nothing to read it against. */
    public function test_a_coupon_with_no_budget_shows_no_spend(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        $coupon = $this->couponWithABudget(code: 'UNBUDGETED', country: null, attributes: ['budget' => null]);
        $this->aRedemptionOf($coupon, discount: 5);

        $this->assertStringNotContainsString(self::SPEND_ELEMENT, $this->quickViewOf($coupon));
    }

    /** On: a budget with no cap prints the money and not a count against nothing. */
    public function test_a_budget_with_no_cap_prints_the_money_and_no_count(): void
    {
        config(['gcc.coupons.limits.enabled' => true]);
        $coupon = $this->couponWithABudget(code: 'NOCAP', country: null, attributes: ['budget' => 20, 'total_uses_limit' => null]);
        $this->aRedemptionOf($coupon, discount: 5);

        $html = $this->quickViewOf($coupon);

        $this->assertStringContainsString(self::SPEND_ELEMENT, $html);
        $this->assertStringNotContainsString(self::USES_ELEMENT, $html, 'a count against no cap is a number with nothing to read it against');
    }

    /** On: and the two labels are DEFINED, in English and in Arabic, so the screen does not write a key into a tracked language file. */
    public function test_the_two_labels_are_defined_in_english_and_arabic(): void
    {
        foreach (['en' => 'Discount given so far', 'ae' => null, 'sa' => null] as $locale => $english) {
            $messages = require base_path("resources/lang/{$locale}/new-messages.php");

            foreach (['discount_given_so_far', 'times_redeemed'] as $key) {
                $this->assertArrayHasKey($key, $messages, "{$key} is not defined in {$locale}");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$key} is empty in {$locale}");
            }

            if ($english !== null) {
                $this->assertSame($english, $messages['discount_given_so_far']);
            }
        }
    }

    /* --------------------------------------------------------------------- world */

    /** @param array<string, mixed> $attributes */
    private function couponWithABudget(string $code, ?string $country, array $attributes = []): Coupon
    {
        $id = $this->giveCoupon(code: $code, country: $country, attributes: array_merge(
            ['discount' => 5, 'discount_type' => 'amount', 'min_purchase' => 4, 'budget' => 20, 'total_uses_limit' => 4],
            $attributes,
        ));

        return Coupon::query()->findOrFail($id);
    }

    private function aRedemptionOf(Coupon $coupon, float $discount): void
    {
        DB::table(CouponRedemption::TABLE)->insert([
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'order_group_id' => 'group-' . $coupon->id,
            'customer_id' => 1,
            'storefront_country' => null,
            'discount' => $discount,
            'currency_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The quick view as the screen really builds it: through the route, so the controller is what decides what the view is given. */
    private function quickViewOf(Coupon $coupon): string
    {
        return (string) $this->getJson(route('admin.coupon.quick-view-details', ['id' => $coupon->id]))->assertOk()->json('view');
    }

    /** The same view with no `spend` variable at all, which is the call the controller made before this change. */
    private function asTheControllerRenderedItBefore(Coupon $coupon): string
    {
        return (string) CouponCountry::inMoneyOf(
            coupon: $coupon,
            callback: static fn (): string => view('admin-views.coupon.details-quick-view', ['coupon' => $coupon])->render(),
        );
    }

    private function collapsed(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }
}
