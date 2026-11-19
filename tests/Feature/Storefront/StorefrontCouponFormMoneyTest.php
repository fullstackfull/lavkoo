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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05, slice 5: the admin coupon form shows the amount that is stored, and the coupon list prints each coupon in its own money.
 *
 * An editable field is not a read-out. The form posts back the figure it shows, so a field filled one unit of the smallest denomination
 * below the stored amount saves that lower amount the next time an administrator presses Update, and the amount drops again on the edit
 * after that: 4.35 is 4.34999999999999964 in binary and the platform's display helper floors it to 4.34 at two decimals. These cases hold
 * the whole matrix the plan asks for (I1-12.1, I1-12.2): the single-currency model and the model that converts from a base currency, two
 * decimals and the three of the dinar, the platform's own money and the money of a storefront that trades in its own.
 */
class StorefrontCouponFormMoneyTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    private const KUWAITI_DINAR_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        $this->buildCouponScreenTables();
        DB::table('business_settings')->insert(['type' => 'pagination_limit', 'value' => '25']);
        Cache::flush();
        Cache::put(CACHE_IN_HOUSE_SHOP_TABLE, ['name' => 'In-house shop'], 3600);
        session()->flush();

        $this->withoutMiddleware([
            AdminMiddleware::class, ActivationCheckMiddleware::class, ModulePermissionMiddleware::class, MaintenanceModeMiddleware::class, Localization::class,
            DetectMobile::class, ApplySeoRedirects::class, NoIndexThemePreview::class, RecordHttpTelemetry::class, MonitorRequest::class, RecordAnalytics::class,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('users');
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The two tables the coupon screens read to fill their vendor and customer selects; both stay empty, so only the select headings are rendered. */
    private function buildCouponScreenTables(): void
    {
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('users');
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('approved');
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
        });
    }

    /**
     * The money the platform itself keeps. A storefront's own money is in force on top of this, from the coupon's country.
     *
     * @return array<string, array{0: string, 1: int, 2: string, 3: array{min_purchase: float, discount: float, max_discount: float}}>
     */
    public static function platformsAndTheAmountsTheyLose(): array
    {
        return [
            'one currency, two decimals' => ['single_currency', 2, 'SA', ['min_purchase' => 4.35, 'discount' => 1.13, 'max_discount' => 2.26]],
            'a base currency, two decimals' => ['multi_currency', 2, 'SA', ['min_purchase' => 4.35, 'discount' => 1.13, 'max_discount' => 2.26]],
            'one currency, three decimals' => ['single_currency', 3, 'SA', ['min_purchase' => 1.023, 'discount' => 1.005, 'max_discount' => 1.019]],
            'a base currency, three decimals' => ['multi_currency', 3, 'SA', ['min_purchase' => 10.505, 'discount' => 0.173, 'max_discount' => 0.116]],
            'a Kuwaiti coupon on a platform that converts from the riyal' => ['multi_currency', 2, 'KW', ['min_purchase' => 10.505, 'discount' => 1.005, 'max_discount' => 1.019]],
        ];
    }

    /** The platform's own currency settings. Three decimals mean the platform itself trades in dinars, so its rate is the dinar's. */
    private function usePlatform(string $currencyModel, int $decimals): void
    {
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => $currencyModel]);
        DB::table('business_settings')->where('type', 'decimal_point_settings')->update(['value' => (string) $decimals]);

        if ($decimals === 3) {
            DB::table('business_settings')->where('type', 'system_default_currency')->update(['value' => (string) self::KUWAITI_DINAR_ID]);
        }

        clearWebConfigCacheKeys();
        Cache::forget('active_currency_list');
        session()->flush();
    }

    /** @param array<string, mixed> $input */
    private function addCoupon(array $input): int
    {
        $this->postJson(route('admin.coupon.add'), $input + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Welcome', 'code' => 'WELCOME', 'start_date' => '2026-12-01', 'expire_date' => '2027-12-01',
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount',
        ])->assertOk()->assertJsonPath('status', 1);

        return (int) DB::table('coupons')->where('code', 'WELCOME')->value('id');
    }

    /** @return array<string, string> the three money fields of the edit form, exactly as the browser would post them back */
    private function fieldsOfTheEditForm(int $id): array
    {
        $html = $this->get(route('admin.coupon.update', ['id' => $id]))->assertOk()->getContent();

        preg_match_all('/name="(discount|min_purchase|max_discount)"[^>]* value="([^"]*)"/', $html, $fields, PREG_SET_ORDER);
        $shown = array_column($fields, 2, 1);
        ksort($shown);

        return $shown;
    }

    /** @param array<string, string> $fields */
    private function postTheEditFormBack(int $id, array $fields): void
    {
        $this->postJson(route('admin.coupon.update', ['id' => $id]), $fields + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Welcome', 'code' => 'WELCOME', 'start_date' => '2026-12-01', 'expire_date' => '2027-12-01',
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 2, 'discount_type' => 'amount',
        ])->assertOk()->assertJsonPath('status', 1);
    }

    /** @return array<string, float> the three amounts as the table holds them */
    private function storedAmounts(int $id): array
    {
        $row = DB::table('coupons')->where('id', $id)->first();

        return ['min_purchase' => (float) $row->min_purchase, 'discount' => (float) $row->discount, 'max_discount' => (float) $row->max_discount];
    }

    // ---- the form shows what is stored -------------------------------------------------------------------------------------------

    /** @param array{min_purchase: float, discount: float, max_discount: float} $typed */
    #[DataProvider('platformsAndTheAmountsTheyLose')]
    public function test_the_edit_form_shows_every_amount_as_the_administrator_typed_it(string $currencyModel, int $decimals, string $country, array $typed): void
    {
        $this->usePlatform(currencyModel: $currencyModel, decimals: $decimals);
        $id = $this->addCoupon(['storefront_country' => $country] + $typed);

        $shown = $this->fieldsOfTheEditForm($id);

        foreach ($typed as $field => $amount) {
            $this->assertSame((string) $amount, $shown[$field], "{$field} of a coupon typed as {$amount}");
        }
    }

    /**
     * The round trip the plan asks for: the figure the form was filled with is posted back untouched and the table holds exactly what it
     * held before. A field one unit short would be saved as the short amount here.
     *
     * @param array{min_purchase: float, discount: float, max_discount: float} $typed
     */
    #[DataProvider('platformsAndTheAmountsTheyLose')]
    public function test_posting_the_edit_form_back_unchanged_leaves_the_stored_amounts_identical(string $currencyModel, int $decimals, string $country, array $typed): void
    {
        $this->usePlatform(currencyModel: $currencyModel, decimals: $decimals);
        $id = $this->addCoupon(['storefront_country' => $country] + $typed);
        $before = $this->storedAmounts($id);

        $this->postTheEditFormBack($id, $this->fieldsOfTheEditForm($id));

        $this->assertSame($before, $this->storedAmounts($id));
        ksort($typed);
        $this->assertSame(array_map(static fn (float $amount): string => (string) $amount, $typed), $this->fieldsOfTheEditForm($id), 'and the form is stable: a second edit shows the same figures');
    }

    /** Two edits in a row take nothing: the amount a form loses once it loses again, so this is where a drip would show. */
    public function test_three_edits_in_a_row_take_nothing_from_a_coupon(): void
    {
        $this->usePlatform(currencyModel: 'multi_currency', decimals: 2);
        $id = $this->addCoupon(['storefront_country' => 'SA', 'min_purchase' => 4.35, 'discount' => 1.13, 'max_discount' => 2.26]);
        $before = $this->storedAmounts($id);

        foreach (range(1, 3) as $edit) {
            $this->postTheEditFormBack($id, $this->fieldsOfTheEditForm($id));
        }

        $this->assertSame($before, $this->storedAmounts($id));
        $this->assertSame(['discount' => '1.13', 'max_discount' => '2.26', 'min_purchase' => '4.35'], $this->fieldsOfTheEditForm($id));
    }

    // ---- the helpers, and what the platform still displays ------------------------------------------------------------------------

    /**
     * The defect as the audit of this area recorded it, read off the two helpers side by side. Every reader that only displays an amount
     * keeps the platform's own truncation; only a field that is posted back is repaired.
     *
     * @return array<string, array{0: string, 1: int, 2: float, 3: float, 4: float}>
     */
    public static function amountsTheDisplayHelperFloors(): array
    {
        return [
            '4.35 at two decimals, one currency' => ['single_currency', 2, 4.35, 4.34, 4.35],
            '4.35 at two decimals, a base currency' => ['multi_currency', 2, 4.35 / 3.75, 4.34, 4.35],
            '1.005 at three decimals, one currency' => ['single_currency', 3, 1.005, 1.004, 1.005],
            '10.505 at three decimals, a base currency' => ['multi_currency', 3, 10.505 / 0.307, 10.504, 10.505],
        ];
    }

    #[DataProvider('amountsTheDisplayHelperFloors')]
    public function test_the_display_helper_still_truncates_and_only_the_editable_field_is_repaired(string $currencyModel, int $decimals, float $stored, float $displayed, float $inTheField): void
    {
        $this->usePlatform(currencyModel: $currencyModel, decimals: $decimals);

        $this->assertSame($displayed, usdToDefaultCurrency(amount: $stored), 'the arithmetic every read-out of the platform has always shown');
        $this->assertSame($inTheField, usdToDefaultCurrencyForEditing(amount: $stored));
    }

    /** A stored amount with more digits than its currency holds is cut, not rounded up: the field shows no money the table does not hold. */
    public function test_a_stored_amount_with_more_digits_than_the_currency_holds_is_still_cut(): void
    {
        $this->usePlatform(currencyModel: 'single_currency', decimals: 2);

        $this->assertSame(2.67, usdToDefaultCurrencyForEditing(amount: 2.675));
        $this->assertSame(2.99, usdToDefaultCurrencyForEditing(amount: 2.999));
        $this->assertSame(1.0, usdToDefaultCurrencyForEditing(amount: 1.009));

        $this->usePlatform(currencyModel: 'single_currency', decimals: 3);

        $this->assertSame(2.675, usdToDefaultCurrencyForEditing(amount: 2.6759));
    }

    /** A nullable money column answers zero, as the display helper has always answered it, under both currency models. */
    public function test_an_amount_that_is_not_there_is_zero_and_never_an_error(): void
    {
        foreach (['single_currency', 'multi_currency'] as $currencyModel) {
            $this->usePlatform(currencyModel: $currencyModel, decimals: 2);

            $this->assertSame(0.0, usdToDefaultCurrency(amount: null), $currencyModel);
            $this->assertSame(0.0, usdToDefaultCurrencyForEditing(amount: null), $currencyModel);
        }
    }

    public function test_the_quick_view_of_a_saudi_coupon_prints_the_figure_it_always_printed(): void
    {
        $this->usePlatform(currencyModel: 'multi_currency', decimals: 2);
        $id = $this->giveCoupon(code: 'RIYAL', country: null, attributes: ['min_purchase' => 4.35 / 3.75, 'discount' => 1.13 / 3.75, 'discount_type' => 'amount']);

        $view = $this->getJson(route('admin.coupon.quick-view-details', ['id' => $id]))->assertOk()->json('view');

        $this->assertStringContainsString('4.34SAR', $view, 'the read-out keeps the platform arithmetic');
        $this->assertStringContainsString('1.12SAR', $view);
    }

    // ---- the coupon list ---------------------------------------------------------------------------------------------------------

    private function listPage(): string
    {
        return $this->get(route('admin.coupon.add'))->assertOk()->getContent();
    }

    /** The rows of the list table alone: the add form above it prints the platform's own mark, which is not what these cases are about. */
    private function listRows(string $html): string
    {
        return $this->collapsed((string) preg_replace('/.*<tbody>(.*?)<\/tbody>.*/s', '$1', $html));
    }

    private function collapsed(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    public function test_the_list_prints_each_coupon_in_the_money_of_its_own_storefront(): void
    {
        $this->giveCoupon(code: 'RIYAL', country: null, attributes: ['min_purchase' => 4, 'discount' => 2, 'discount_type' => 'amount']);
        $this->giveCoupon(code: 'DINAR', country: 'KW', attributes: ['min_purchase' => 10.505, 'discount' => 1.505, 'discount_type' => 'amount']);
        $this->giveCoupon(code: 'PERCENT', country: 'AE', attributes: ['min_purchase' => 40.5, 'discount' => 10, 'discount_type' => 'percentage']);

        $html = $this->listRows($this->listPage());

        $this->assertStringContainsString('7.50SAR', $html, 'the stored 2 is 7.50 riyals at 3.75');
        $this->assertStringContainsString('15.00SAR', $html);
        $this->assertStringContainsString('1.505KD', $html, 'three decimals, and not the riyal figure of 5.64');
        $this->assertStringContainsString('10.505KD', $html);
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('40.50AED', $html);
        $this->assertStringNotContainsString('5.64SAR', $html, 'no coupon is shown in another storefront\'s money');
    }

    public function test_a_free_delivery_coupon_shows_no_discount_and_still_shows_its_minimum(): void
    {
        $this->giveCoupon(code: 'FREE', country: 'KW', attributes: ['coupon_type' => 'free_delivery', 'discount' => 0, 'discount_type' => 'percentage', 'min_purchase' => 10.505]);

        $html = $this->listRows($this->listPage());

        $this->assertStringContainsString('—', $html);
        $this->assertStringContainsString('10.505KD', $html);
    }

    public function test_the_list_has_no_amount_column_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveCoupon(code: 'DINAR', country: 'KW', attributes: ['min_purchase' => 10.505, 'discount' => 1.505, 'discount_type' => 'amount']);

        $html = $this->listPage();
        $head = (string) preg_replace('/.*<thead>(.*?)<\/thead>.*/s', '$1', $html);
        $rows = $this->listRows($html);

        $this->assertSame(9, substr_count($head, '<th'), 'the columns the Saudi panel has today');
        $this->assertStringNotContainsString('10.505', $rows);
        $this->assertStringNotContainsString('KD', $rows);
        $this->assertStringNotContainsString('SAR', $rows, 'the list prints no amount at all, as before');
    }

    public function test_the_list_gains_one_column_while_the_switch_is_on(): void
    {
        $head = (string) preg_replace('/.*<thead>(.*?)<\/thead>.*/s', '$1', $this->listPage());

        $this->assertSame(10, substr_count($head, '<th'));
        $this->assertStringContainsString(translate('discount'), $head);
    }

    /** The money of one row never leaks into the next, nor out of the page. */
    public function test_the_money_of_a_coupon_row_is_put_back_after_the_page(): void
    {
        $this->giveCoupon(code: 'DINAR', country: 'KW', attributes: ['min_purchase' => 10.505, 'discount' => 1.505, 'discount_type' => 'amount']);
        $this->giveCoupon(code: 'RIYAL', country: null, attributes: ['min_purchase' => 4, 'discount' => 2, 'discount_type' => 'amount']);

        $this->listPage();

        $this->assertSame('multi_currency', getWebConfig('currency_model'));
        $this->assertSame('2', getWebConfig('decimal_point_settings'));
    }
}
