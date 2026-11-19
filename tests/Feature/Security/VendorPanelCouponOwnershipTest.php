<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\MaintenanceModeMiddleware;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCouponOwnershipWorld;
use Tests\Support\BuildsFulfillmentSchema;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * The vendor panel's coupon routes (/vendor/coupon/*) for a signed-in vendor, through the real `seller` guard and its staff gate.
 *
 * quick-view: it read any coupon by its number, so a vendor saw the code, discount, minimum purchase and bearer of another vendor's coupons and of
 * the platform's. It now opens what the vendor's own list shows and nothing else, and answers anything else as it answers a coupon that is not there.
 * delete: a coupon the admin made was not found by its lookup, and reading the seller_id of nothing was a PHP warning that Laravel turns into a 500;
 * with the warning silenced, null == 0 passed the loose in_array and the coupon was deleted by its number alone. The same loose comparison deleted a
 * coupon a vendor had added once its seller_id was NULL. REPOINTED, a vendor-added coupon the admin addressed to every vendor, is still deletable by
 * any vendor, as before: pinned here so that tightening it is a decision of the owner and not an accident.
 * The edit page, the update and the switch already refused everything but the vendor's own coupon: their tests here are the reference the seller
 * app is held to (SellerAppCouponOwnershipTest). OWNHOUSE, a vendor-added coupon the admin made the platform bear, is the one kind they treat
 * differently, and always did: the panel edits it (and hands the bearer back to the vendor) but neither switches nor deletes it.
 */
class VendorPanelCouponOwnershipTest extends TestCase
{
    use BuildsCouponOwnershipWorld;
    use BuildsFulfillmentSchema;
    use RestoresLanguageFiles;

    private ?int $errorReportingBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCouponOwnershipWorld();
        View::getFinder()->prependLocation(base_path('tests/Support/panel-views'));
        $this->withoutTheShellMiddleware(also: [MaintenanceModeMiddleware::class]);
        $this->actingAs(Seller::findOrFail(self::VENDOR), 'seller');
    }

    protected function tearDown(): void
    {
        if ($this->errorReportingBefore !== null) {
            error_reporting(error_level: $this->errorReportingBefore);
        }
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    /** The request the eye button of the coupon list sends. */
    private function quickView(?int $couponId): TestResponse
    {
        return $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('vendor.coupon.quick-view', $couponId === null ? [] : ['id' => $couponId]));
    }

    /** The request the bin button of the coupon list sends: the redirect back goes to the list it came from. */
    private function deleteFromTheList(int $couponId): TestResponse
    {
        return $this->from(route('vendor.coupon.index'))->delete(route('vendor.coupon.delete', ['id' => $couponId]));
    }

    /** @return list<string> the codes, in alphabetical order, that the list page of the panel was given for this vendor */
    private function listedCodes(): array
    {
        return $this->codesOf($this->get(route('vendor.coupon.index'))->assertOk()->viewData('coupons'));
    }

    /** @return array<string, array{string}> the coupons the vendor's list does not show */
    public static function couponsOutsideTheVendorsList(): array
    {
        return self::datasetOf(array_values(array: array_filter(
            array: self::NOT_THE_VENDORS_TO_CHANGE,
            callback: static fn (string $code): bool => !in_array(needle: $code, haystack: self::LISTED_TO_THE_VENDOR, strict: true),
        )));
    }

    public function test_the_list_shows_the_vendors_own_coupons_and_the_ones_made_for_every_vendor(): void
    {
        $this->assertSame(expected: self::LISTED_TO_THE_VENDOR, actual: $this->listedCodes());
    }

    #[DataProvider('everyCoupon')]
    public function test_the_quick_view_opens_the_coupons_the_list_shows_and_no_other(string $code): void
    {
        $listed = in_array(needle: $code, haystack: $this->listedCodes(), strict: true);
        $title = (string) DB::table('coupons')->where('code', $code)->value('title');

        $response = $this->quickView($this->couponId($code));

        if ($listed) {
            $response->assertOk()->assertJsonStructure(['view']);
            $this->assertStringContainsString(needle: $code, haystack: $response->json('view'));
            $this->assertStringContainsString(needle: $title, haystack: $response->json('view'));

            return;
        }
        $response->assertNotFound();
        $this->assertArrayNotHasKey(key: 'view', array: (array) $response->json());
        $this->assertStringNotContainsString(needle: $code, haystack: (string) $response->getContent());
        $this->assertStringNotContainsString(needle: $title, haystack: (string) $response->getContent());
    }

    #[DataProvider('couponsOutsideTheVendorsList')]
    public function test_a_coupon_outside_the_list_is_answered_by_the_quick_view_like_one_that_does_not_exist(string $code): void
    {
        $missing = $this->quickView(self::MISSING_COUPON);
        $answer = $this->quickView($this->couponId($code));

        $this->assertSame(expected: $missing->getStatusCode(), actual: $answer->getStatusCode());
        $this->assertSame(expected: $missing->getContent(), actual: $answer->getContent());
    }

    public function test_a_coupon_that_does_not_exist_is_a_clean_not_found_in_the_quick_view(): void
    {
        $response = $this->quickView(self::MISSING_COUPON)->assertNotFound();

        $this->assertArrayNotHasKey(key: 'view', array: (array) $response->json());
    }

    public function test_a_quick_view_that_names_no_coupon_opens_nothing(): void
    {
        $this->quickView(null)->assertNotFound();
    }

    #[DataProvider('couponsTheVendorMayNotDelete')]
    public function test_a_coupon_that_is_not_the_vendors_own_is_not_deleted(string $code): void
    {
        $before = $this->storedCoupons();

        $this->deleteFromTheList($this->couponId($code))->assertRedirect(route('vendor.coupon.index'));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
        $this->assertSame(expected: ['warning'], actual: $this->toastTypes());
    }

    public function test_deleting_a_coupon_that_does_not_exist_answers_like_deleting_one_that_is_not_the_vendors(): void
    {
        $before = $this->storedCoupons();

        $this->deleteFromTheList(self::MISSING_COUPON)->assertRedirect(route('vendor.coupon.index'));

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
        $this->assertSame(expected: ['warning'], actual: $this->toastTypes());
    }

    /**
     * What stopped the delete of a coupon the admin made was a PHP warning that Laravel turns into an exception (a 500), not a decision of the
     * code: with the warning silenced, null == 0 made the old check pass and the coupon was deleted by its number alone.
     */
    public function test_a_coupon_that_is_not_the_vendors_survives_even_when_php_warnings_are_not_turned_into_exceptions(): void
    {
        $before = $this->storedCoupons();
        $this->errorReportingBefore = error_reporting(error_level: E_ALL & ~E_WARNING);

        foreach ([...array_map(callback: $this->couponId(...), array: self::NOT_THE_VENDORS_TO_DELETE), self::MISSING_COUPON] as $couponId) {
            $this->delete(route('vendor.coupon.delete', ['id' => $couponId]));

            $this->assertSame(expected: $before, actual: $this->storedCoupons(), message: "deleting coupon {$couponId} with warnings silenced changed the stored coupons");
        }
    }

    public function test_the_vendor_still_deletes_its_own_coupon_and_nothing_else(): void
    {
        $before = $this->storedCoupons();

        $this->deleteFromTheList($this->couponId('MINE'))->assertRedirect(route('vendor.coupon.index'));

        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'MINE'), actual: $this->storedCoupons());
        $this->assertSame(expected: ['success'], actual: $this->toastTypes());
    }

    public function test_a_coupon_a_vendor_added_and_the_admin_addressed_to_every_vendor_stays_deletable_as_before(): void
    {
        $before = $this->storedCoupons();

        $this->deleteFromTheList($this->couponId('REPOINTED'))->assertRedirect(route('vendor.coupon.index'));

        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'REPOINTED'), actual: $this->storedCoupons());
        $this->assertSame(expected: ['success'], actual: $this->toastTypes());
    }

    #[DataProvider('couponsTheVendorMayNotChange')]
    public function test_the_edit_page_the_update_and_the_switch_refuse_a_coupon_that_is_not_the_vendors_own(string $code): void
    {
        $before = $this->storedCoupons();
        $couponId = $this->couponId($code);

        $this->get(route('vendor.coupon.update', ['id' => $couponId]))->assertNotFound();
        $this->post(route('vendor.coupon.update', ['id' => $couponId]), $this->editOf($code))->assertRedirect(route('vendor.coupon.index'));
        $this->assertSame(expected: ['error'], actual: $this->toastTypes());
        $this->get(route('vendor.coupon.update-status', ['id' => $couponId, 'status' => 0]))
            ->assertNotFound()->assertExactJson(['status' => 0, 'message' => translate('coupon_not_found')]);

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }

    public function test_a_coupon_the_vendor_added_and_the_platform_bears_is_not_switched(): void
    {
        $before = $this->storedCoupons();

        $this->get(route('vendor.coupon.update-status', ['id' => $this->couponId('OWNHOUSE'), 'status' => 0]))
            ->assertNotFound()->assertExactJson(['status' => 0, 'message' => translate('coupon_not_found')]);

        $this->assertSame(expected: $before, actual: $this->storedCoupons());
    }

    public function test_a_coupon_the_vendor_added_and_the_platform_bears_is_still_edited_and_the_bearer_goes_back_to_the_vendor(): void
    {
        $couponId = $this->couponId('OWNHOUSE');
        $before = $this->storedCoupons();

        $this->get(route('vendor.coupon.update', ['id' => $couponId]))->assertOk();
        $this->post(route('vendor.coupon.update', ['id' => $couponId]), $this->editOf('OWNHOUSE'))->assertRedirect(route('vendor.coupon.index'));

        $after = $this->storedCoupons();
        $this->assertSame(expected: ['success'], actual: $this->toastTypes());
        $this->assertSame(expected: [self::EDITED_TITLE, 'seller'], actual: [$after['OWNHOUSE']['title'], $after['OWNHOUSE']['coupon_bearer']]);
        $this->assertSame(expected: $this->withoutCode(coupons: $before, code: 'OWNHOUSE'), actual: $this->withoutCode(coupons: $after, code: 'OWNHOUSE'));
    }

    public function test_the_vendor_still_opens_edits_and_switches_its_own_coupon(): void
    {
        $couponId = $this->couponId('MINE');

        $this->get(route('vendor.coupon.update', ['id' => $couponId]))->assertOk();
        $this->post(route('vendor.coupon.update', ['id' => $couponId]), $this->editOf('MINE'))->assertRedirect(route('vendor.coupon.index'));
        $this->assertSame(expected: ['success'], actual: $this->toastTypes());
        $this->assertSame(expected: [self::EDITED_TITLE, 7], actual: [DB::table('coupons')->where('code', 'MINE')->value('title'), (int) DB::table('coupons')->where('code', 'MINE')->value('limit')]);

        $this->get(route('vendor.coupon.update-status', ['id' => $couponId, 'status' => 0]))->assertOk()->assertJsonPath('status', 1);
        $this->assertSame(expected: 0, actual: (int) DB::table('coupons')->where('code', 'MINE')->value('status'));
    }
}
