<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\OrderManager;
use Illuminate\Support\Facades\DB;

/**
 * The checkout world of ApiAddressTestCase with the five storefronts other than the Saudi one open: a currency row and one in-house product for each, priced in its
 * own currency (Saudi 100, Emirati 250, Kuwaiti 30, Qatari 45, Bahraini 12.5, Omani 14), the storefront currency switch on, and a way to fill a customer's cart from a
 * storefront. For a test case that extends ApiAddressTestCase and uses BuildsStorefrontCart, BuildsStorefrontCatalog and BuildsStorefrontMoneyWorld.
 */
trait BuildsSixStorefrontCheckoutWorld
{
    protected function buildSixStorefrontCheckoutWorld(): void
    {
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
    }

    protected function setSetting(string $type, string $value): void
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

    /** @return array<string, string> the header with which a request names its storefront, and no header for a request that names none */
    protected function storefrontHeader(?string $storefront): array
    {
        return $storefront === null ? [] : ['X-Storefront' => $storefront];
    }

    /** A checkout that prices the cart reads the use count of a coupon, which a strict SQL server refuses until PR 89 replaces its bare GROUP BY. */
    protected function skipInStrictModeUntilTheCouponCountIsFixed(): void
    {
        if (getenv('DB_STRICT_TESTS') === '1' && !method_exists(OrderManager::class, 'countCouponUses')) {
            $this->markTestSkipped('The use count of a coupon is still the bare GROUP BY that a strict server refuses (PR 89 fixes it).');
        }
    }

    /** Puts one unit of the storefront's product in the customer's cart, with a shipping method of 25, as the customer of that storefront does. */
    protected function fillTheCartFrom(User $customer, string $country, int $productId): void
    {
        $this->asCustomer($customer)->withHeader('X-Storefront', $country)->postJson('/api/v1/cart/add', ['id' => $productId, 'quantity' => 1])->assertOk()->assertJsonPath('status', 1);

        foreach (DB::table('carts')->pluck('cart_group_id') as $group) {
            DB::table('cart_shippings')->insert(['cart_group_id' => $group, 'shipping_method_id' => 1, 'shipping_cost' => 25]);
        }
    }
}
