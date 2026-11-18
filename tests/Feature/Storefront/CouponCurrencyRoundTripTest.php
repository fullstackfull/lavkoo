<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Services\Storefront\CouponCountry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsCouponCheckoutWorld;
use Tests\Support\BuildsSixStorefrontCheckoutWorld;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontCouponWorld;

/**
 * The round trip of a coupon through the two currency models of the platform, with the storefront switches off. The admin types min_purchase, a flat discount and
 * max_discount in the default currency of the platform, through the real form; the tests read the stored columns, the edit form, the coupon list of the customer
 * API, the apply route and the checkout.
 *
 * Under the single-currency model the platform has no other unit, so a coupon is stored as typed (even with a rate in the currency rows, which a conversion would use). Under the multi-currency model every amount is stored in dollars: the amount typed is divided by the rate of the default currency, and everything
 * the customer's cart holds is in dollars too, so a cart of 100 is 375 riyals, 30.700 dinars, 37.600 dinars of Bahrain or 38.500 Omani rials at the rates of the
 * cases. The stored amounts are written by hand in the cases (50, 5 and 10 for the minimum, the discount and the cap), so the division is checked against a number
 * and not against the code's own arithmetic.
 *
 * What the admin is shown back is the stored amount floored to the decimals setting of the platform (usdToDefaultCurrency), so an amount whose binary value falls just
 * below its digits can come back one minor unit low: the cases use amounts that come back as typed, and the same test pins the bound of the rounding over a range of
 * amounts (never above what was typed, never more than one minor unit below it) instead of a value of it.
 */
class CouponCurrencyRoundTripTest extends ApiAddressTestCase
{
    use BuildsCouponCheckoutWorld;
    use BuildsSixStorefrontCheckoutWorld;
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontCouponWorld;

    private const PERCENTAGE_OVER_THE_CAP = 20;

    private const PERCENTAGE_UNDER_THE_CAP = 3;

    protected function setUp(): void
    {
        parent::setUp();

        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildCouponCheckoutWorld();
        $this->withoutMiddleware([AdminMiddleware::class, ModulePermissionMiddleware::class]);
    }

    protected function tearDown(): void
    {
        CouponCountry::flush();

        parent::tearDown();
    }

    /**
     * @return array<string, array{array<string, mixed>}> the model, the default currency, its rate to the dollar and the decimals of the platform; what the admin types
     *     in that currency; what is stored (in the unit of the cart); and the cart of 100 as the admin would type it in that currency
     */
    public static function platforms(): array
    {
        $multi = ['model' => 'multi_currency', 'stored' => ['min' => 50.0, 'discount' => 5.0, 'cap' => 10.0]];

        return [
            'single currency, riyal, two decimals' => [[
                'model' => 'single_currency', 'currency' => 'SAR', 'rate' => 3.75, 'decimals' => 2,
                'typed' => ['min' => 50.0, 'discount' => 5.0, 'cap' => 10.0], 'stored' => ['min' => 50.0, 'discount' => 5.0, 'cap' => 10.0], 'cart' => 100.0,
            ]],
            'single currency, dinar, three decimals' => [[
                'model' => 'single_currency', 'currency' => 'KWD', 'rate' => 0.307, 'decimals' => 3,
                'typed' => ['min' => 50.505, 'discount' => 5.505, 'cap' => 10.505], 'stored' => ['min' => 50.505, 'discount' => 5.505, 'cap' => 10.505], 'cart' => 100.0,
            ]],
            'multi currency, riyal at 3.75, two decimals' => [$multi + [
                'currency' => 'SAR', 'rate' => 3.75, 'decimals' => 2, 'typed' => ['min' => 187.5, 'discount' => 18.75, 'cap' => 37.5], 'cart' => 375.0,
            ]],
            'multi currency, Kuwaiti dinar at 0.307, three decimals' => [$multi + [
                'currency' => 'KWD', 'rate' => 0.307, 'decimals' => 3, 'typed' => ['min' => 15.35, 'discount' => 1.535, 'cap' => 3.07], 'cart' => 30.7,
            ]],
            'multi currency, Bahraini dinar at 0.376, three decimals' => [$multi + [
                'currency' => 'BHD', 'rate' => 0.376, 'decimals' => 3, 'typed' => ['min' => 18.8, 'discount' => 1.88, 'cap' => 3.76], 'cart' => 37.6,
            ]],
            'multi currency, Omani rial at 0.385, three decimals' => [$multi + [
                'currency' => 'OMR', 'rate' => 0.385, 'decimals' => 3, 'typed' => ['min' => 19.25, 'discount' => 1.925, 'cap' => 3.85], 'cart' => 38.5,
            ]],
        ];
    }

    /** @param array<string, mixed> $platform */
    private function runThePlatformAs(array $platform): void
    {
        DB::table('currencies')->updateOrInsert(attributes: ['code' => 'USD'], values: ['name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 1, 'status' => 1]);
        DB::table('currencies')->where('code', $platform['currency'])->update(['exchange_rate' => $platform['rate']]);
        $this->setSetting(type: 'currency_model', value: $platform['model']);
        $this->setSetting(type: 'system_default_currency', value: (string) DB::table('currencies')->where('code', $platform['currency'])->value('id'));
        $this->setSetting(type: 'decimal_point_settings', value: (string) $platform['decimals']);
        Cache::forget('active_currency_list');
        session()->flush();

        $this->assertSame($platform['model'], getWebConfig('currency_model'));
        $this->assertSame((string) $platform['decimals'], (string) getWebConfig('decimal_point_settings'));
    }

    /** One unit of what the cart holds is this much of the default currency: the rate under the multi-currency model and one under the single-currency one. */
    private function defaultCurrencyPerCartUnit(array $platform): float
    {
        return $platform['model'] === 'multi_currency' ? $platform['rate'] : 1.0;
    }

    /** @param array<string, mixed> $form what the admin types, over the fields every coupon of these tests shares */
    private function typeTheCouponInTheAdminForm(array $form): void
    {
        $this->postJson(uri: route('admin.coupon.add'), data: $form + [
            'coupon_type' => 'discount_on_purchase', 'title' => 'Typed in the form', 'start_date' => now()->subDay()->toDateString(), 'expire_date' => now()->addDay()->toDateString(),
            'coupon_bearer' => 'inhouse', 'seller_id' => 'inhouse', 'customer_id' => 0, 'limit' => 5, 'discount_type' => 'amount',
        ])->assertOk()->assertJsonPath('status', 1);
    }

    /** @param array<string, mixed> $platform */
    private function typeTheThreeCoupons(array $platform): void
    {
        ['min' => $min, 'discount' => $discount, 'cap' => $cap] = $platform['typed'];

        $this->typeTheCouponInTheAdminForm(['code' => 'FLAT', 'min_purchase' => $min, 'discount' => $discount]);
        $this->typeTheCouponInTheAdminForm(['code' => 'CAPPED', 'min_purchase' => $min, 'discount_type' => 'percentage', 'discount' => self::PERCENTAGE_OVER_THE_CAP, 'max_discount' => $cap]);
        $this->typeTheCouponInTheAdminForm(['code' => 'BELOWCAP', 'min_purchase' => $min, 'discount_type' => 'percentage', 'discount' => self::PERCENTAGE_UNDER_THE_CAP, 'max_discount' => $cap]);
    }

    private function storedRow(string $code): object
    {
        return DB::table('coupons')->where('code', $code)->first();
    }

    /** @return array{min: float, discount: float, cap: float} what the edit form of the coupon shows in its three fields */
    private function theEditFormShows(string $code): array
    {
        $html = $this->get(route('admin.coupon.update', ['id' => $this->storedRow($code)->id]))->assertOk()->getContent();
        $field = function (string $id) use ($html): float {
            $this->assertSame(1, preg_match('/id="' . $id . '" value="([^"]*)"/', $html, $match), "the edit form has the field {$id}");

            return (float) $match[1];
        };

        return ['min' => $field('minimum purchase'), 'discount' => $field('discount'), 'cap' => $field('maximum discount')];
    }

    /** @param array<string, mixed> $platform */
    #[DataProvider('platforms')]
    public function test_a_coupon_typed_in_the_default_currency_is_stored_as_the_model_stores_it(array $platform): void
    {
        $this->runThePlatformAs($platform);
        $this->typeTheThreeCoupons($platform);
        ['min' => $min, 'discount' => $discount, 'cap' => $cap] = $platform['stored'];

        $flat = $this->storedRow('FLAT');
        $this->assertEqualsWithDelta($min, (float) $flat->min_purchase, 0.000000001, 'the minimum purchase');
        $this->assertEqualsWithDelta($discount, (float) $flat->discount, 0.000000001, 'the flat discount');
        $this->assertEqualsWithDelta($discount, (float) $flat->max_discount, 0.000000001, 'the cap of a flat coupon is its discount');
        foreach ([['CAPPED', self::PERCENTAGE_OVER_THE_CAP], ['BELOWCAP', self::PERCENTAGE_UNDER_THE_CAP]] as [$code, $percentage]) {
            $percentageCoupon = $this->storedRow($code);
            $this->assertEqualsWithDelta($min, (float) $percentageCoupon->min_purchase, 0.000000001, "{$code}: the minimum purchase");
            $this->assertEqualsWithDelta($percentage, (float) $percentageCoupon->discount, 0.000000001, "{$code}: a percentage is stored as typed, whatever the model");
            $this->assertEqualsWithDelta($cap, (float) $percentageCoupon->max_discount, 0.000000001, "{$code}: the cap");
        }
    }

    /** @param array<string, mixed> $platform */
    #[DataProvider('platforms')]
    public function test_the_edit_form_shows_back_the_amounts_that_were_typed_within_the_rounding_of_the_decimals_setting(array $platform): void
    {
        $this->runThePlatformAs($platform);
        $this->typeTheThreeCoupons($platform);
        ['min' => $min, 'discount' => $discount, 'cap' => $cap] = $platform['typed'];

        $this->assertEqualsWithDelta(['min' => $min, 'discount' => $discount, 'cap' => $discount], $this->theEditFormShows('FLAT'), 0.000000001);
        $this->assertEqualsWithDelta(['min' => $min, 'discount' => self::PERCENTAGE_OVER_THE_CAP, 'cap' => $cap], $this->theEditFormShows('CAPPED'), 0.000000001);

        $minorUnit = 1 / 10 ** $platform['decimals'];
        $outOfBounds = [];
        foreach (range(1, 1200) as $minorUnits) {
            $amount = $minorUnits / 10 ** $platform['decimals'];
            $shown = usdToDefaultCurrency(amount: currencyConverter(amount: $amount));
            if ($shown > $amount + 0.000000001 || $amount - $shown >= $minorUnit + 0.000000001) {
                $outOfBounds[] = "{$amount} is shown as {$shown}";
            }
        }
        $this->assertSame([], $outOfBounds, 'the form floors to the decimals of the platform: it may show one minor unit less than an amount that was typed, never more, never two less');
    }

    /** @return array{discount: float, listed: float, order: object, withoutCoupon: object} */
    private function redeem(string $code): array
    {
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);
        $listed = collect($this->asCustomer($customer)->getJson('/api/v1/coupon/list')->assertOk()->json('coupons'))->firstWhere('code', $code);
        $applied = $this->applyTheCoupon(customer: $customer, code: $code)->assertOk();
        $this->placeTheOrder(customer: $customer, code: $code)->assertOk();

        $control = $this->makeCustomer();
        $this->fillTheCart($control, self::IN_HOUSE_PRODUCT);
        $this->placeTheOrder(customer: $control)->assertOk();

        return ['discount' => (float) $applied->json('coupon_discount'), 'listed' => (float) $listed['discount'], 'order' => $this->ordersOf($customer)[0], 'withoutCoupon' => $this->ordersOf($control)[0]];
    }

    /** @param array<string, mixed> $platform */
    #[DataProvider('platforms')]
    public function test_the_customer_is_shown_and_redeems_the_amount_that_was_typed_at_the_rate_it_was_converted_at(array $platform): void
    {
        $this->runThePlatformAs($platform);
        $this->typeTheThreeCoupons($platform);
        $perUnit = $this->defaultCurrencyPerCartUnit($platform);
        $cartInTheCartUnit = (float) self::IN_HOUSE_PRICE;
        $expected = [
            'FLAT' => ['stored' => $platform['stored']['discount'], 'typed' => $platform['typed']['discount']],
            'CAPPED' => ['stored' => $platform['stored']['cap'], 'typed' => $platform['typed']['cap']],
            'BELOWCAP' => ['stored' => self::PERCENTAGE_UNDER_THE_CAP, 'typed' => self::PERCENTAGE_UNDER_THE_CAP / 100 * $cartInTheCartUnit * $perUnit],
        ];

        foreach ($expected as $code => ['stored' => $stored, 'typed' => $typed]) {
            ['discount' => $discount, 'listed' => $listed, 'order' => $order, 'withoutCoupon' => $withoutCoupon] = $this->redeem($code);

            $this->assertEqualsWithDelta($stored, $discount, 0.000000001, "{$code}: the apply route takes off the stored amount of the cart's unit");
            $this->assertEqualsWithDelta($typed, $discount * $perUnit, 0.000000001, "{$code}: which is the amount typed, in the default currency, at the rate it was converted at");
            $this->assertEqualsWithDelta((float) $order->discount_amount, $discount, 0.000000001, "{$code}: the order records what the route said");
            $this->assertEqualsWithDelta($discount, (float) $withoutCoupon->order_amount - (float) $order->order_amount, 0.000000001, "{$code}: and costs that much less than the same cart");
            if ($code === 'FLAT') {
                $this->assertEqualsWithDelta($discount, $listed, 0.000000001, 'the list of coupons shows the customer the amount the route takes off');
            }
        }
    }

    /** @param array<string, mixed> $platform */
    #[DataProvider('platforms')]
    public function test_a_cart_is_refused_a_minimum_one_minor_unit_above_its_value_and_accepted_at_and_below_it(array $platform): void
    {
        $this->runThePlatformAs($platform);
        $minorUnit = 1 / 10 ** $platform['decimals'];
        foreach (['BELOW' => $platform['cart'] - $minorUnit, 'EQUAL' => $platform['cart'], 'ABOVE' => $platform['cart'] + $minorUnit] as $code => $minimum) {
            $this->typeTheCouponInTheAdminForm(['code' => $code, 'min_purchase' => $minimum, 'discount' => $platform['typed']['discount']]);
        }
        $customer = $this->makeCustomer();
        $this->fillTheCart($customer, self::IN_HOUSE_PRODUCT);

        $this->assertCouponApplied(discount: $platform['stored']['discount'], response: $this->applyTheCoupon(customer: $customer, code: 'BELOW'));
        $this->assertCouponApplied(discount: $platform['stored']['discount'], response: $this->applyTheCoupon(customer: $customer, code: 'EQUAL'));
        $this->assertCouponRefused(message: translate('minimum_purchase_amount_not_reached_for_this_coupon.'), response: $this->applyTheCoupon(customer: $customer, code: 'ABOVE'));
    }
}
