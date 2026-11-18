<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCouponOwnershipWorld;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The seller app's coupon routes (/api/v3/seller/coupon/*), called with a bearer token through the real middleware (seller_api_auth, seller_can).
 *
 * list: a search term broke the shop filter, because the orWhere conditions of the search were not grouped with it, so a vendor who typed any
 * word saw every other vendor's coupons and the platform's.
 * update and status-update: a coupon addressed to every vendor (seller_id 0) was editable and switchable by any vendor, because the lookup took
 * seller_id 0 as "mine". The vendor panel never did (VendorPanelCouponOwnershipTest): the two surfaces now decide alike, so a coupon the admin
 * made is the admin's on both, even when it is addressed to this vendor alone.
 *
 * A staff member or an API key reaches these routes with the same shop id; only the owner's token is built here.
 */
class SellerAppCouponOwnershipTest extends TestCase
{
    use BuildsCouponOwnershipWorld;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCouponOwnershipWorld();
        $this->withoutTheShellMiddleware();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function asVendor(string $token = self::VENDOR_TOKEN): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function listCoupons(?string $search = null): TestResponse
    {
        return $this->asVendor()->getJson('/api/v3/seller/coupon/list?' . http_build_query(['limit' => 50, 'offset' => 1] + ($search === null ? [] : ['search' => $search])));
    }

    private function updateCoupon(string $code, string $token = self::VENDOR_TOKEN): TestResponse
    {
        return $this->asVendor($token)->putJson('/api/v3/seller/coupon/update/' . $this->couponId($code), $this->editOf($code));
    }

    private function switchCoupon(string $code, int $status, string $token = self::VENDOR_TOKEN): TestResponse
    {
        return $this->asVendor($token)->putJson('/api/v3/seller/coupon/status-update/' . $this->couponId($code), ['status' => $status]);
    }

    private function deleteCoupon(string $code): TestResponse
    {
        return $this->asVendor()->deleteJson('/api/v3/seller/coupon/delete/' . $this->couponId($code));
    }

    private function assertCouponNotFound(TestResponse $response): void
    {
        $response->assertStatus(403)->assertExactJson(['message' => translate('coupon_not_found')]);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function searchesThatReachForOtherShops(): array
    {
        return [
            'another vendor\'s code' => ['THEIRS', []],
            'the platform\'s code' => ['PLATFORM', []],
            'a word every coupon has (its discount type)' => ['amount', self::LISTED_TO_THE_VENDOR],
            'a word only titles have' => ['offer', self::LISTED_TO_THE_VENDOR],
            'the vendor\'s own code' => ['MINE', ['MINE']],
            'two codes, one of them another vendor\'s' => ['MINE THEIRS', ['MINE']],
            'two codes, both of other shops' => ['THEIRS PLATFORM', []],
        ];
    }

    #[DataProvider('searchesThatReachForOtherShops')]
    public function test_a_search_term_never_widens_the_list_beyond_the_vendors_shop(string $search, array $expectedCodes): void
    {
        $response = $this->listCoupons(search: $search)->assertOk();

        $this->assertSame(expected: $expectedCodes, actual: $this->codesOf($response->json('coupons')));
        $this->assertSame(expected: count($expectedCodes), actual: $response->json('total_size'));
    }

    public function test_the_list_without_a_search_term_shows_the_vendors_own_coupons_and_the_ones_made_for_every_vendor(): void
    {
        $response = $this->listCoupons()->assertOk();

        $this->assertSame(expected: self::LISTED_TO_THE_VENDOR, actual: $this->codesOf($response->json('coupons')));
        $this->assertSame(expected: count(self::LISTED_TO_THE_VENDOR), actual: $response->json('total_size'));
    }

    public function test_a_search_that_matches_the_vendors_own_coupons_keeps_the_answer_shape(): void
    {
        $this->listCoupons(search: 'MINE')->assertOk()
            ->assertJsonStructure(['total_size', 'limit', 'offset', 'coupons' => ['*' => ['id', 'code', 'title', 'seller_id', 'coupon_bearer', 'status', 'order_count']]])
            ->assertJsonPath('limit', '50')
            ->assertJsonPath('offset', '1')
            ->assertJsonPath('total_size', 1)
            ->assertJsonPath('coupons.0.code', 'MINE');
    }

    public function test_a_search_made_of_nothing_but_spaces_is_the_plain_list(): void
    {
        $this->assertSame(expected: self::LISTED_TO_THE_VENDOR, actual: $this->codesOf($this->listCoupons(search: '   ')->assertOk()->json('coupons')));
    }

    #[DataProvider('couponsTheVendorMayNotChange')]
    public function test_a_coupon_that_is_not_the_vendors_own_is_not_edited(string $code): void
    {
        $before = $this->storedCoupons();

        $this->assertCouponNotFound($this->updateCoupon(code: $code));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }

    #[DataProvider('couponsTheVendorMayNotChange')]
    public function test_a_coupon_that_is_not_the_vendors_own_is_not_switched(string $code): void
    {
        $before = $this->storedCoupons();

        $this->assertCouponNotFound($this->switchCoupon(code: $code, status: 0));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }

    #[DataProvider('couponsTheVendorMayNotDelete')]
    public function test_a_coupon_that_is_not_the_vendors_own_is_not_deleted(string $code): void
    {
        $before = $this->storedCoupons();

        $this->assertCouponNotFound($this->deleteCoupon(code: $code));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }

    public function test_the_refusal_is_the_answer_a_coupon_that_does_not_exist_gets(): void
    {
        $missing = $this->asVendor()->putJson('/api/v3/seller/coupon/update/' . self::MISSING_COUPON, $this->editOf('MISSING'));
        $missingSwitch = $this->asVendor()->putJson('/api/v3/seller/coupon/status-update/' . self::MISSING_COUPON, ['status' => 0]);

        $this->assertCouponNotFound($missing);
        $this->assertCouponNotFound($missingSwitch);
        foreach (self::NOT_THE_VENDORS_TO_CHANGE as $code) {
            $this->assertSame(expected: $missing->getContent(), actual: $this->updateCoupon(code: $code)->getContent(), message: "{$code}: edit");
            $this->assertSame(expected: $missingSwitch->getContent(), actual: $this->switchCoupon(code: $code, status: 0)->getContent(), message: "{$code}: switch");
        }
    }

    /**
     * The vendor panel is the reference: whatever it lets the vendor edit or switch, the app does, and nothing more. Each answer is read from a
     * real request of each surface for the same coupon, so a change of the panel's rule shows here as a difference between the two.
     */
    #[DataProvider('everyCoupon')]
    public function test_the_app_edits_and_switches_exactly_the_coupons_the_vendor_panel_does(string $code): void
    {
        $this->withoutMiddleware(MaintenanceModeMiddleware::class);
        $this->actingAs(Seller::findOrFail(self::VENDOR), 'seller');
        $couponId = $this->couponId($code);

        $this->post(route('vendor.coupon.update', ['id' => $couponId]), $this->editOf($code));
        $panelEdits = $this->toastTypes() === ['success'];
        $panelSwitches = $this->get(route('vendor.coupon.update-status', ['id' => $couponId, 'status' => 0]))->getStatusCode() === 200;
        $appEdits = $this->updateCoupon(code: $code)->getStatusCode() === 200;
        $appSwitches = $this->switchCoupon(code: $code, status: 0)->getStatusCode() === 200;

        $this->assertSame(expected: $code === 'MINE', actual: $panelEdits, message: "the panel's answer for {$code} is not the one this test is built on");
        $this->assertSame(expected: $panelEdits, actual: $appEdits, message: "{$code}: the app and the panel disagree on editing");
        $this->assertSame(expected: $panelSwitches, actual: $appSwitches, message: "{$code}: the app and the panel disagree on switching");
        $this->assertSame(expected: $panelEdits, actual: $panelSwitches, message: "{$code}: the panel edits and switches the same coupons");
    }

    public function test_the_vendor_still_edits_its_own_coupon_and_nothing_else_changes(): void
    {
        $before = $this->storedCoupons();

        $this->updateCoupon(code: 'MINE')->assertOk()->assertExactJson(['message' => translate('coupon_updated_successfully')]);

        $after = $this->storedCoupons();
        $this->assertSame(
            expected: [self::EDITED_TITLE, 7, 25.0, 90.0],
            actual: [$after['MINE']['title'], (int) $after['MINE']['limit'], (float) $after['MINE']['discount'], (float) $after['MINE']['min_purchase']],
        );
        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'MINE'), actual: $this->withoutCode(coupons: $after, code: 'MINE'), message: 'an edit of one coupon touched another');
    }

    public function test_the_vendor_still_switches_its_own_coupon_off_and_on(): void
    {
        $before = $this->storedCoupons();

        $this->switchCoupon(code: 'MINE', status: 0)->assertOk()->assertExactJson(['message' => translate('coupon_status_updated')]);
        $this->assertSame(expected: 0, actual: (int) DB::table('coupons')->where('code', 'MINE')->value('status'));

        $this->switchCoupon(code: 'MINE', status: 1)->assertOk();
        $this->assertSame(expected: 1, actual: (int) DB::table('coupons')->where('code', 'MINE')->value('status'));
        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'MINE'), actual: $this->withoutCode(coupons: $this->storedCoupons(), code: 'MINE'), message: 'a switch of one coupon touched another');
    }

    public function test_the_vendor_still_deletes_its_own_coupon_and_nothing_else(): void
    {
        $before = $this->storedCoupons();

        $this->deleteCoupon(code: 'MINE')->assertOk()->assertExactJson(['message' => translate('coupon_deleted_successfully')]);

        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'MINE'), actual: $this->storedCoupons());
    }

    public function test_another_vendors_token_does_not_reach_the_coupon_either(): void
    {
        $before = $this->storedCoupons();

        $this->assertCouponNotFound($this->updateCoupon(code: 'MINE', token: self::OTHER_VENDOR_TOKEN));
        $this->assertCouponNotFound($this->switchCoupon(code: 'MINE', status: 0, token: self::OTHER_VENDOR_TOKEN));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }
}
