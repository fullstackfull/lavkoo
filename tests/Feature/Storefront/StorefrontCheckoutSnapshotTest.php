<?php

namespace Tests\Feature\Storefront;

use App\Models\User;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Address\ApiAddressTestCase;
use Tests\Support\BuildsStorefrontCart;
use Tests\Support\BuildsStorefrontCatalog;

/**
 * GCC-05, end to end: a real cash-on-delivery checkout of the API, served from a storefront, saves its order with the storefront and the
 * currency it was sold in. The platform of this world runs one currency (the Saudi riyal), so an order of the default storefront is known to be
 * in it, and the Emirati storefront sells in dirhams at rate one: the amounts of its catalog are stored as they are.
 */
class StorefrontCheckoutSnapshotTest extends ApiAddressTestCase
{
    use BuildsStorefrontCart;
    use BuildsStorefrontCatalog;

    private const COD = '/api/v1/customer/order/place';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCartWorld();
        $this->seedTwoCountries();

        $migration = require database_path('migrations/2026_11_26_100000_add_storefront_snapshot_to_orders_table.php');
        $migration->up();

        foreach ([2 => ['AED', 'UAE Dirham'], 3 => ['KWD', 'Kuwaiti Dinar'], 4 => ['QAR', 'Qatari Riyal'], 5 => ['BHD', 'Bahraini Dinar'], 6 => ['OMR', 'Omani Rial']] as $id => [$code, $name]) {
            DB::table('currencies')->insert(['id' => $id, 'name' => $name, 'code' => $code, 'symbol' => $code, 'exchange_rate' => 1, 'status' => 1]);
        }
        cache()->forget('active_currency_list');
    }

    /** The catalog of each storefront: the one product it sells, priced in its own currency (the seeded two are the Saudi 100 and the Emirati 250). */
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

    /** @return array<string, array{0: string, 1: int, 2: string, 3: float}> */
    public static function theSixStorefronts(): array
    {
        return [
            'Saudi Arabia' => ['SA', 1, 'SAR', 100.0],
            'United Arab Emirates' => ['AE', 2, 'AED', 250.0],
            'Kuwait' => ['KW', 3, 'KWD', 30.0],
            'Qatar' => ['QA', 4, 'QAR', 45.0],
            'Bahrain' => ['BH', 5, 'BHD', 12.5],
            'Oman' => ['OM', 6, 'OMR', 14.0],
        ];
    }

    private function checkOutFrom(string $country, int $productId): User
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->addTheOtherCatalogs();
        config(['gcc.storefronts.currency.enabled' => true]);
        foreach (['QA', 'BH', 'OM', 'KW'] as $storefront) {
            config(["gcc.storefronts.countries.{$storefront}.active" => true]);
        }
        config(['gcc.storefronts.legal.grandfathered' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']]);
        StorefrontCatalog::flush();

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->getJson(self::COD)->assertOk();

        return $customer;
    }

    /** @return array{storefront_country: ?string, currency_code: ?string, currency_rate: ?string} */
    private function snapshotOfTheOrder(): array
    {
        $row = DB::table('orders')->first(['storefront_country', 'currency_code', 'currency_rate']);

        return [
            'storefront_country' => $row->storefront_country,
            'currency_code' => $row->currency_code,
            'currency_rate' => $row->currency_rate === null ? null : rtrim(rtrim((string) $row->currency_rate, '0'), '.'),
        ];
    }

    public function test_an_emirati_checkout_saves_its_order_in_dirhams_at_rate_one(): void
    {
        $this->checkOutFrom(country: 'AE', productId: 2);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(['storefront_country' => 'AE', 'currency_code' => 'AED', 'currency_rate' => '1'], $this->snapshotOfTheOrder());
        $this->assertSame([2], DB::table('order_details')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
    }

    #[DataProvider('theSixStorefronts')]
    public function test_each_of_the_six_storefronts_sells_one_order_in_its_own_currency(string $country, int $productId, string $currencyCode, float $price): void
    {
        $this->checkOutFrom(country: $country, productId: $productId);

        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(['storefront_country' => $country, 'currency_code' => $currencyCode, 'currency_rate' => '1'], $this->snapshotOfTheOrder());
        $this->assertEqualsWithDelta($price, (float) DB::table('order_details')->value('price'), 0.001, 'the line keeps the price of its own catalog, converted by no rate');
        $this->assertSame([$productId], DB::table('order_details')->pluck('product_id')->map(fn ($id) => (int) $id)->all());
        $this->assertGreaterThanOrEqual($price, (float) DB::table('orders')->value('order_amount'));
    }

    public function test_a_saudi_checkout_saves_its_order_in_the_platform_currency(): void
    {
        $this->checkOutFrom(country: 'SA', productId: 1);

        $this->assertSame(['storefront_country' => 'SA', 'currency_code' => 'SAR', 'currency_rate' => '1'], $this->snapshotOfTheOrder());
    }

    public function test_the_amounts_of_the_order_are_the_stored_amounts_of_the_catalog(): void
    {
        $this->checkOutFrom(country: 'AE', productId: 2);

        $order = DB::table('orders')->first();
        $this->assertEqualsWithDelta(250.0, (float) DB::table('order_details')->value('price'), 0.001, 'the line is the dirham price of the catalog, converted by no rate');
        $this->assertGreaterThanOrEqual(250.0, (float) $order->order_amount);
    }

    public function test_while_storefront_currencies_are_off_the_order_carries_no_record(): void
    {
        $customer = $this->makeCustomer();
        $this->switchStorefrontsOn();
        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->postJson('/api/v1/cart/add', ['id' => 2, 'quantity' => 1])->assertOk();
        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }

        $this->asCustomer($customer)->withHeader('X-Storefront', 'AE')->getJson(self::COD)->assertOk();

        $this->assertSame(['storefront_country' => null, 'currency_code' => null, 'currency_rate' => null], $this->snapshotOfTheOrder());
    }
}
