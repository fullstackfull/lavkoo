<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Http\Middleware\SellerMiddleware;
use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Models\Seller;
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
 * What a vendor can do with a coupon today, through the vendor panel (its own routes, under the seller guard) and the seller app (the v3 API, whose real bearer-token
 * middleware resolves the shop). Every case below is the answer the code gives, for the shop that is signed in, and a refused request changes nothing:
 *  - the panel's list without a search term shows the coupons of the shop (seller_id its own) and those the admin made for every vendor (seller_id 0, without an edit
 *    or a switch link) and no others; its edit page, its update and its switch answer for a coupon of the shop and refuse another vendor's coupon, a platform coupon
 *    (no vendor at all) and the admin's coupons for every vendor (not found, or back to the list with an error);
 *  - the seller app's list without a search term shows the same two sets and no others; its update and its switch answer for a coupon of the shop and refuse another
 *    vendor's coupon, a platform coupon and the admin's coupon for every vendor that the platform bears (403, coupon not found).
 *
 * I1-11.3 of the master plan ("only their own coupons") is therefore NOT proved. Four defects are OPEN: each was reproduced through the real route on this base, none
 * is pinned (a test that pinned it would call it right) and the code is not changed here.
 *  1. The seller app's list with a search term: GET /api/v3/seller/coupon/list?limit=50&offset=1&search=THEIRS (the code of another vendor's coupon) answers 200 with
 *     that coupon, and search=amount answers 200 with all five coupons of the fixture, the platform's included: the OR clauses of the search are not grouped with the
 *     shop's filter.
 *  2. The seller app's edit and switch of the coupon the admin made for every vendor and that vendors bear (added_by admin, coupon_bearer seller, seller_id 0): PUT
 *     /api/v3/seller/coupon/update/{id} and PUT /api/v3/seller/coupon/status-update/{id} answer 200 and change it (title rewritten, status 0), where its platform-borne
 *     twin answers 403 and the panel refuses it.
 *  3. The panel's quick view: GET /vendor/coupon/quick-view?id={id} answers 200 with the quick view of another vendor's coupon and of a platform coupon, naming their codes.
 *  4. The panel's delete: DELETE /vendor/coupon/delete/{id} of a platform coupon or of an admin coupon for every vendor answers 500 (ErrorException "Trying to access
 *     array offset on null", Vendor/Coupon/CouponController.php line 150) and deletes nothing; another vendor's coupon is refused with a warning and a redirect.
 */
class CouponOwnershipTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;
    use RestoresLanguageFiles;

    private const VENDOR = 5;

    private const OTHER_VENDOR = 6;

    private const VENDOR_TOKEN = 'vendor-five-token-vendor-five-token-vendor-five';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/panel-views'));
        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        config(['gcc.storefronts.enabled' => false, 'gcc.storefronts.currency.enabled' => false]);
        DB::table('business_settings')->where('type', 'currency_model')->update(['value' => 'single_currency']);
        DB::table('business_settings')->insert(['type' => 'pagination_limit', 'value' => '25']);
        Cache::flush();
        $this->createTheTablesOfThePanels();
        $this->withoutMiddleware([SellerMiddleware::class, SellerStaffAccessMiddleware::class, ActivationCheckMiddleware::class, MaintenanceModeMiddleware::class]);
    }

    protected function tearDown(): void
    {
        $this->dropTheTablesOfThePanels();
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function dropTheTablesOfThePanels(): void
    {
        foreach (['users', 'sellers', 'shops'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTheTablesOfThePanels(): void
    {
        $this->dropTheTablesOfThePanels();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('status')->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('author_type')->default('vendor');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('sellers')->insert([
            ['id' => self::VENDOR, 'f_name' => 'Vendor', 'status' => 'approved', 'auth_token' => self::VENDOR_TOKEN],
            ['id' => self::OTHER_VENDOR, 'f_name' => 'Other vendor', 'status' => 'approved', 'auth_token' => strrev(self::VENDOR_TOKEN)],
        ]);
    }

    /** @return array<string, int> the id of each coupon, by the name its code carries */
    private function giveTheCoupons(): array
    {
        $coupons = [
            'MINE' => ['added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::VENDOR],
            'THEIRS' => ['added_by' => 'seller', 'coupon_bearer' => 'seller', 'seller_id' => self::OTHER_VENDOR],
            'PLATFORM' => ['added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => null],
            'FORALL_SELLER' => ['added_by' => 'admin', 'coupon_bearer' => 'seller', 'seller_id' => 0],
            'FORALL_HOUSE' => ['added_by' => 'admin', 'coupon_bearer' => 'inhouse', 'seller_id' => 0],
        ];

        return array_map(fn (string $code): int => $this->giveCoupon(code: $code, country: null, attributes: $coupons[$code] + ['title' => $code]), array_combine(array_keys($coupons), array_keys($coupons)));
    }

    private function row(int $id): object
    {
        return DB::table('coupons')->where('id', $id)->first();
    }

    private function actingAsTheVendor(): void
    {
        $this->actingAs((new Seller())->forceFill(['id' => self::VENDOR, 'f_name' => 'Vendor']), 'seller');
    }

    /** @return list<string> the kinds of toast the last response asked the next page to show */
    private function toastTypes(): array
    {
        return array_column(session('laravel-toaster-magic::messages', []), 'type');
    }

    /** @return array<string, string> what a vendor sends to change a coupon, over the amounts it already holds */
    private function theChangedCoupon(string $code): array
    {
        return [
            'coupon_type' => 'discount_on_purchase', 'title' => "{$code} changed", 'code' => $code, 'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addDay()->toDateString(),
            'coupon_bearer' => 'seller', 'customer_id' => 0, 'limit' => 3, 'discount_type' => 'amount', 'discount' => 1, 'min_purchase' => 5,
        ];
    }

    /** @return array<string, array{string}> */
    public static function couponsThatAreNotTheVendors(): array
    {
        return [
            'the coupon of another vendor' => ['THEIRS'],
            'a platform coupon' => ['PLATFORM'],
            'a coupon of the admin for every vendor, borne by a vendor' => ['FORALL_SELLER'],
            'a coupon of the admin for every vendor, borne by the platform' => ['FORALL_HOUSE'],
        ];
    }

    // ---- the vendor panel -------------------------------------------------------------------------------------------------------------

    public function test_the_vendor_panel_lists_the_coupons_of_the_vendor_and_those_the_admin_made_for_every_vendor_and_no_others(): void
    {
        $ids = $this->giveTheCoupons();
        $this->actingAsTheVendor();

        $response = $this->get(route('vendor.coupon.index'))->assertOk();

        $this->assertSame(['FORALL_HOUSE', 'FORALL_SELLER', 'MINE'], $response->viewData('coupons')->pluck('code')->sort()->values()->all());
        $response->assertSee('href="' . route('vendor.coupon.update', ['id' => $ids['MINE']]) . '"', false);
        foreach (['FORALL_SELLER', 'FORALL_HOUSE'] as $adminCoupon) {
            $response->assertDontSee('href="' . route('vendor.coupon.update', ['id' => $ids[$adminCoupon]]) . '"', false);
            $response->assertDontSee(route('vendor.coupon.update-status', ['id' => $ids[$adminCoupon], 'status' => 0]), false);
        }
    }

    public function test_the_vendor_panel_opens_the_edit_page_of_a_coupon_of_the_vendor(): void
    {
        $ids = $this->giveTheCoupons();
        $this->actingAsTheVendor();

        $this->get(route('vendor.coupon.update', ['id' => $ids['MINE']]))->assertOk()->assertSee('value="MINE"', false);
    }

    #[DataProvider('couponsThatAreNotTheVendors')]
    public function test_the_vendor_panel_answers_not_found_to_the_edit_page_of_a_coupon_that_is_not_the_vendors(string $coupon): void
    {
        $ids = $this->giveTheCoupons();
        $this->actingAsTheVendor();

        $this->get(route('vendor.coupon.update', ['id' => $ids[$coupon]]))->assertNotFound();
    }

    public function test_the_vendor_panel_saves_the_changes_to_a_coupon_of_the_vendor(): void
    {
        $ids = $this->giveTheCoupons();
        $this->actingAsTheVendor();

        $this->post(uri: route('vendor.coupon.update', ['id' => $ids['MINE']]), data: $this->theChangedCoupon('MINE'))->assertRedirect(route('vendor.coupon.index'));

        $this->assertSame('MINE changed', $this->row($ids['MINE'])->title);
        $this->assertSame(['success'], $this->toastTypes());
    }

    #[DataProvider('couponsThatAreNotTheVendors')]
    public function test_the_vendor_panel_sends_the_vendor_back_to_the_list_with_an_error_and_changes_nothing_when_the_coupon_is_not_the_vendors(string $coupon): void
    {
        $ids = $this->giveTheCoupons();
        $before = $this->row($ids[$coupon]);
        $this->actingAsTheVendor();

        $this->post(uri: route('vendor.coupon.update', ['id' => $ids[$coupon]]), data: $this->theChangedCoupon($coupon))->assertRedirect(route('vendor.coupon.index'));

        $this->assertEquals($before, $this->row($ids[$coupon]));
        $this->assertSame(['error'], $this->toastTypes());
    }

    public function test_the_vendor_panel_switches_a_coupon_of_the_vendor_off_and_on(): void
    {
        $ids = $this->giveTheCoupons();
        $this->actingAsTheVendor();

        $this->get(route('vendor.coupon.update-status', ['id' => $ids['MINE'], 'status' => 0]))->assertOk()->assertJsonPath('status', 1);
        $this->assertSame(0, (int) $this->row($ids['MINE'])->status);

        $this->get(route('vendor.coupon.update-status', ['id' => $ids['MINE'], 'status' => 1]))->assertOk()->assertJsonPath('status', 1);
        $this->assertSame(1, (int) $this->row($ids['MINE'])->status);
    }

    #[DataProvider('couponsThatAreNotTheVendors')]
    public function test_the_vendor_panel_answers_not_found_to_the_switch_of_a_coupon_that_is_not_the_vendors_and_changes_nothing(string $coupon): void
    {
        $ids = $this->giveTheCoupons();
        $before = $this->row($ids[$coupon]);
        $this->actingAsTheVendor();

        $this->get(route('vendor.coupon.update-status', ['id' => $ids[$coupon], 'status' => 0]))
            ->assertNotFound()
            ->assertExactJson(['status' => 0, 'message' => translate('coupon_not_found')]);

        $this->assertEquals($before, $this->row($ids[$coupon]));
    }

    // ---- the seller app ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, string> the header of the seller app of the vendor: the token its shop was given at sign-in */
    private function theSellerApp(): array
    {
        return ['Authorization' => 'Bearer ' . self::VENDOR_TOKEN];
    }

    /** @return array<string, array{string}> the coupons that are not the vendor's and that the seller app refuses to change */
    public static function couponsTheSellerAppRefuses(): array
    {
        return [
            'the coupon of another vendor' => ['THEIRS'],
            'a platform coupon' => ['PLATFORM'],
            'a coupon of the admin for every vendor, borne by the platform' => ['FORALL_HOUSE'],
        ];
    }

    public function test_the_seller_app_lists_the_coupons_of_the_shop_and_those_the_admin_made_for_every_vendor_and_no_others(): void
    {
        $this->giveTheCoupons();

        $listed = $this->withHeaders($this->theSellerApp())->getJson('/api/v3/seller/coupon/list?limit=50&offset=1')->assertOk()->json('coupons');

        $codes = array_column($listed, 'code');
        sort($codes);
        $this->assertSame(['FORALL_HOUSE', 'FORALL_SELLER', 'MINE'], $codes);
    }

    public function test_the_seller_app_saves_the_changes_to_a_coupon_of_the_shop(): void
    {
        $ids = $this->giveTheCoupons();

        $this->withHeaders($this->theSellerApp())->putJson(uri: "/api/v3/seller/coupon/update/{$ids['MINE']}", data: $this->theChangedCoupon('MINE'))
            ->assertOk()
            ->assertExactJson(['message' => translate('coupon_updated_successfully')]);

        $this->assertSame('MINE changed', $this->row($ids['MINE'])->title);
    }

    #[DataProvider('couponsTheSellerAppRefuses')]
    public function test_the_seller_app_refuses_to_change_a_coupon_that_is_not_the_shops_and_changes_nothing(string $coupon): void
    {
        $ids = $this->giveTheCoupons();
        $before = $this->row($ids[$coupon]);

        $this->withHeaders($this->theSellerApp())->putJson(uri: "/api/v3/seller/coupon/update/{$ids[$coupon]}", data: $this->theChangedCoupon($coupon))
            ->assertForbidden()
            ->assertExactJson(['message' => translate('coupon_not_found')]);

        $this->assertEquals($before, $this->row($ids[$coupon]));
    }

    public function test_the_seller_app_switches_a_coupon_of_the_shop_off_and_on(): void
    {
        $ids = $this->giveTheCoupons();

        $this->withHeaders($this->theSellerApp())->putJson(uri: "/api/v3/seller/coupon/status-update/{$ids['MINE']}", data: ['status' => 0])->assertOk();
        $this->assertSame(0, (int) $this->row($ids['MINE'])->status);

        $this->withHeaders($this->theSellerApp())->putJson(uri: "/api/v3/seller/coupon/status-update/{$ids['MINE']}", data: ['status' => 1])->assertOk();
        $this->assertSame(1, (int) $this->row($ids['MINE'])->status);
    }

    #[DataProvider('couponsTheSellerAppRefuses')]
    public function test_the_seller_app_refuses_to_switch_a_coupon_that_is_not_the_shops_and_changes_nothing(string $coupon): void
    {
        $ids = $this->giveTheCoupons();
        $before = $this->row($ids[$coupon]);

        $this->withHeaders($this->theSellerApp())->putJson(uri: "/api/v3/seller/coupon/status-update/{$ids[$coupon]}", data: ['status' => 0])
            ->assertForbidden()
            ->assertExactJson(['message' => translate('coupon_not_found')]);

        $this->assertEquals($before, $this->row($ids[$coupon]));
    }
}
