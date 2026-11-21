<?php

namespace Tests\Feature\Storefront;

use App\Models\Coupon;
use App\Services\Storefront\CouponCountry;
use App\Utils\OrderManager;
use ArrayObject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCouponWorld;
use Tests\Support\BuildsStorefrontMoneyWorld;
use Tests\TestCase;

/**
 * GCC-05, slice 5: a coupon is three numbers with no unit, typed in one currency, so only the storefront whose currency it was typed in may offer it.
 * A coupon with no country is the default storefront's (every coupon that exists today). Nothing here narrows anything while the currency switch is off.
 */
class StorefrontCouponEligibilityTest extends TestCase
{
    use BuildsStorefrontCouponWorld;
    use BuildsStorefrontMoneyWorld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontMoneyWorld();
        $this->buildStorefrontCouponWorld();
        $this->giveCoupon(code: 'RIYAL10', country: null);
        $this->giveCoupon(code: 'SAUDI5', country: 'SA');
        $this->giveCoupon(code: 'DINAR2', country: 'KW');
        $this->giveCoupon(code: 'DIRHAM3', country: 'AE');
    }

    protected function tearDown(): void
    {
        $this->dropStorefrontCouponWorld();
        $this->dropStorefrontMoneyWorld();

        parent::tearDown();
    }

    public function test_the_default_storefront_and_a_process_with_no_storefront_offer_the_coupons_with_no_country_and_the_saudi_ones(): void
    {
        $this->assertSame(['RIYAL10', 'SAUDI5'], $this->offeredCodes(), 'a panel, a queue and the console work in the platform\'s money');
        $this->assertSame(['RIYAL10', 'SAUDI5'], $this->inStorefront('SA', fn (): array => $this->offeredCodes()));
    }

    #[DataProvider('storefrontsWithTheirOwnCurrency')]
    public function test_a_storefront_with_its_own_currency_offers_only_its_own_coupons(string $country): void
    {
        $offered = $this->inStorefront($country, fn (): array => $this->offeredCodes());

        $this->assertSame(match ($country) { 'KW' => ['DINAR2'], 'AE' => ['DIRHAM3'], default => [] }, $offered);
    }

    public function test_a_storefront_meets_the_coupon_of_another_by_code_as_no_coupon(): void
    {
        $this->assertNull($this->inStorefront('KW', fn () => Coupon::query()->where('code', 'RIYAL10')->forStorefront()->first()));
        $this->assertNull($this->inStorefront('KW', fn () => Coupon::query()->where('code', 'DIRHAM3')->forStorefront()->first()));
        $this->assertNull($this->inStorefront('SA', fn () => Coupon::query()->where('code', 'DINAR2')->forStorefront()->first()));
        $this->assertSame('DINAR2', $this->inStorefront('KW', fn () => Coupon::query()->where('code', 'DINAR2')->forStorefront()->first()?->code));
    }

    public function test_an_explicit_country_overrides_the_request_s(): void
    {
        $this->assertSame(['DINAR2'], $this->inStorefront('SA', fn (): array => Coupon::query()->forStorefront('KW')->pluck('code')->all()));
        $this->assertSame(['RIYAL10', 'SAUDI5'], $this->inStorefront('KW', fn (): array => Coupon::query()->forStorefront('SA')->pluck('code')->all()));
    }

    public function test_nothing_is_narrowed_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertSame(['DINAR2', 'DIRHAM3', 'RIYAL10', 'SAUDI5'], $this->inStorefront('KW', fn (): array => $this->offeredCodes()));

        config(['gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.enabled' => false]);

        $this->assertSame(['DINAR2', 'DIRHAM3', 'RIYAL10', 'SAUDI5'], $this->offeredCodes());
    }

    public function test_before_the_migration_a_storefront_with_its_own_currency_meets_no_coupon_and_the_default_storefront_all(): void
    {
        $this->dropStorefrontCouponWorld();
        $this->buildStorefrontCouponWorld(withCountryColumn: false);
        $this->giveCoupon(code: 'RIYAL10', country: null);

        $this->assertSame(['RIYAL10'], $this->inStorefront('SA', fn (): array => $this->offeredCodes()));
        $this->assertSame(['RIYAL10'], $this->offeredCodes());
        $this->assertSame([], $this->inStorefront('KW', fn (): array => $this->offeredCodes()), 'a Saudi coupon never applies on a storefront before the column can tell');
        $this->assertFalse(CouponCountry::isTracked());
    }

    public function test_the_group_wise_coupon_of_a_checkout_finds_the_coupon_of_the_storefront_and_prices_it_as_typed(): void
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is still the bare GROUP BY that a strict server refuses (PR 89 fixes it).');
        }

        $request = Request::create('/checkout');
        $request->setUserResolver(fn (): ArrayObject => new ArrayObject(['id' => 7]));
        $cartListGroup = collect(['G1' => collect([['price' => 12.5, 'discount' => 0, 'quantity' => 2, 'seller_is' => 'admin', 'seller_id' => 1]])]);

        $coupon = $this->inStorefront('KW', fn (): array => OrderManager::getGroupWiseCouponArray($request, 'DINAR2', 25, 2, 'G1', $cartListGroup));

        $this->assertSame('DINAR2', $coupon['coupon_code']);
        $this->assertEqualsWithDelta(2.0, (float) $coupon['discount'], 0.000001, 'all of the 25 is eligible: 25 x 2 / 25');
    }

    public function test_a_new_coupon_records_the_storefront_it_is_typed_for(): void
    {
        $this->assertSame(['storefront_country' => 'KW'], CouponCountry::attributesOfNewCoupon('kw'));
        $this->assertSame(['storefront_country' => null], CouponCountry::attributesOfNewCoupon('SA'), 'the default storefront is never stamped');
        $this->assertSame(['storefront_country' => null], CouponCountry::attributesOfNewCoupon('XX'), 'a country that is not a storefront is the default one');
        $this->assertSame(['storefront_country' => null], CouponCountry::attributesOfNewCoupon(null));

        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertSame([], CouponCountry::attributesOfNewCoupon('KW'), 'nothing is recorded while the switch is off');
    }

    public function test_the_country_of_a_coupon_is_read_when_tracked(): void
    {
        $id = Coupon::query()->where('code', 'DINAR2')->value('id');

        $this->assertSame('KW', CouponCountry::countryOf($id));
        $this->assertNull(CouponCountry::countryOf(Coupon::query()->where('code', 'RIYAL10')->value('id')));

        config(['gcc.storefronts.currency.enabled' => false]);

        $this->assertNull(CouponCountry::countryOf($id));
    }

    public function test_a_coupon_is_serialised_for_the_apps_without_its_country_and_can_be_created_with_one(): void
    {
        $coupon = Coupon::query()->create([
            'added_by' => 'admin', 'coupon_type' => 'discount_on_purchase', 'coupon_bearer' => 'inhouse', 'customer_id' => 0, 'title' => 'T', 'code' => 'NEW5',
            'start_date' => now()->toDateString(), 'expire_date' => now()->addDay()->toDateString(), 'min_purchase' => 20, 'max_discount' => 0, 'discount' => 5,
            'discount_type' => 'amount', 'limit' => 1, 'storefront_country' => 'KW',
        ]);

        $this->assertSame('KW', Schema::getConnection()->table('coupons')->where('id', $coupon->id)->value('storefront_country'), 'the column is fillable, so create() keeps it');
        $this->assertArrayNotHasKey('storefront_country', $coupon->fresh()->toArray());
        $this->assertArrayNotHasKey('storefront_country', json_decode($coupon->fresh()->toJson(), true));
    }
}
