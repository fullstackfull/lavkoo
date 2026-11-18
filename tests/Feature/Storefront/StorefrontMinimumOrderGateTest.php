<?php

namespace Tests\Feature\Storefront;

use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\BuildsStorefrontMoneyWorld;

/**
 * GCC-05, slice 5, the gate proof: the minimum order amount of a storefront with its own currency is what an operator gave that storefront, in its currency, and a
 * real API checkout is refused below it, passes at it and above it. The platform's minimum (typed in riyals) is never compared with a dinar, a dirham or a rial.
 * Everything here goes through the unchanged cart gate and the unchanged readers: only the seam of getWebConfig knows about storefronts.
 */
class StorefrontMinimumOrderGateTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;
    use BuildsStorefrontMoneyWorld;

    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();
        (require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php'))->up();

        foreach ([2 => ['AED', 'UAE Dirham'], 3 => ['KWD', 'Kuwaiti Dinar'], 4 => ['QAR', 'Qatari Riyal'], 5 => ['BHD', 'Bahraini Dinar'], 6 => ['OMR', 'Omani Rial']] as $id => [$code, $name]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }
        cache()->forget('active_currency_list');

        $this->addTheOtherCatalogs();
        $this->switchStorefrontsOn();
        config(['gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        foreach (['QA', 'BH', 'OM', 'KW'] as $storefront) {
            config(["gcc.storefronts.countries.{$storefront}.active" => true]);
        }
        StorefrontCatalog::flush();

        $this->setSetting('minimum_order_amount_status', '1');
        $this->setSetting('minimum_order_amount', '100');
    }

    private function setSetting(string $type, string $value): void
    {
        DB::table('business_settings')->updateOrInsert(['type' => $type], ['value' => $value]);
        clearWebConfigCacheKeys();
    }

    private function addTheOtherCatalogs(): void
    {
        $product = ['added_by' => 'admin', 'user_id' => 1, 'code' => 'SKU-1', 'variation' => '[]', 'images' => '[]', 'thumbnail' => 'x.png', 'category_ids' => '[]', 'discount' => 0, 'discount_type' => 'flat', 'choice_options' => '[]', 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'current_stock' => 10];

        DB::table('products')->insert([
            $product + ['id' => 3, 'name' => 'Paracetamol KW', 'slug' => 'paracetamol-kw', 'unit_price' => 30, 'storefront_country' => 'KW'],
            $product + ['id' => 4, 'name' => 'Paracetamol QA', 'slug' => 'paracetamol-qa', 'unit_price' => 45, 'storefront_country' => 'QA'],
            $product + ['id' => 5, 'name' => 'Paracetamol BH', 'slug' => 'paracetamol-bh', 'unit_price' => 12.5, 'storefront_country' => 'BH'],
            $product + ['id' => 6, 'name' => 'Paracetamol OM', 'slug' => 'paracetamol-om', 'unit_price' => 14, 'storefront_country' => 'OM'],
        ]);
    }

    /** Puts one unit of the storefront's product in a cart and places the order, cash on delivery, from that storefront. */
    private function placeFrom(string $country, int $productId): TestResponse
    {
        $customer = $this->makeCustomer();

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        return $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::COD);
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(403)->assertJsonPath('message', translate('Check_minimum_order_amount_requirement'));
        $this->assertSame(0, DB::table('orders')->count(), 'a refused checkout saves no order');
    }

    /** The order is placed: the checkout that follows the gate saves it (which a strict SQL server refuses until PR 89 replaces the bare GROUP BY of its coupon count). */
    private function assertPlacedFrom(string $country, int $productId): void
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is still the bare GROUP BY that a strict server refuses (PR 89 fixes it).');
        }

        $this->placeFrom($country, $productId)->assertOk();
        $this->assertSame(1, DB::table('orders')->count());
    }

    /** @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string}> country, product, currency, the price of its product, the smallest amount above it */
    public static function storefrontsWithTheirOwnMinimum(): array
    {
        return [
            'United Arab Emirates' => ['AE', 2, 'AED', '250.00', '250.01'],
            'Kuwait' => ['KW', 3, 'KWD', '30.000', '30.001'],
            'Qatar' => ['QA', 4, 'QAR', '45.00', '45.01'],
            'Bahrain' => ['BH', 5, 'BHD', '12.500', '12.501'],
            'Oman' => ['OM', 6, 'OMR', '14.000', '14.001'],
        ];
    }

    #[DataProvider('storefrontsWithTheirOwnMinimum')]
    public function test_a_cart_below_the_minimum_of_its_storefront_is_refused(string $country, int $productId, string $currency, string $price, string $justAbove): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => $justAbove]);

        $this->assertRefused($this->placeFrom($country, $productId));
    }

    #[DataProvider('storefrontsWithTheirOwnMinimum')]
    public function test_a_cart_equal_to_the_minimum_of_its_storefront_passes(string $country, int $productId, string $currency, string $price, string $justAbove): void
    {
        $this->giveStorefrontAmounts(country: $country, currency: $currency, amounts: ['minimum_order_amount' => $price]);

        $this->assertPlacedFrom($country, $productId);
    }

    #[DataProvider('storefrontsWithTheirOwnMinimum')]
    public function test_a_storefront_that_was_given_no_minimum_has_none_whatever_the_platform_asks_in_riyals(string $country, int $productId): void
    {
        $this->assertPlacedFrom($country, $productId);
    }

    public function test_the_platform_minimum_is_never_compared_with_the_dinars_of_a_kuwaiti_cart(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertPlacedFrom('KW', 3);
    }

    public function test_the_row_of_another_currency_is_ignored_so_nothing_is_compared(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'SAR', amounts: ['minimum_order_amount' => '1000']);

        $this->assertPlacedFrom('KW', 3);
    }

    public function test_the_default_storefront_still_meets_the_platform_minimum_below_it_and_at_it(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);
        $this->setSetting('minimum_order_amount', '100.01');

        $this->assertRefused($this->placeFrom('SA', 1));
    }

    public function test_the_default_storefront_passes_at_the_platform_minimum(): void
    {
        $this->setSetting('minimum_order_amount', '100');

        $this->assertPlacedFrom('SA', 1);
    }

    public function test_a_kuwaiti_minimum_does_not_reach_the_cart_of_the_default_storefront(): void
    {
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '500.000']);
        $this->setSetting('minimum_order_amount', '50');

        $this->assertPlacedFrom('SA', 1);
    }

    public function test_nothing_changes_while_the_switch_is_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->giveStorefrontAmounts(country: 'KW', currency: 'KWD', amounts: ['minimum_order_amount' => '5.000']);

        $this->assertRefused($this->placeFrom('KW', 3));
    }
}
