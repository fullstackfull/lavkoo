<?php

namespace Tests\Feature\Catalog;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Controllers\Admin\Order\OrderEditController as AdminOrderEditController;
use App\Http\Controllers\Vendor\Order\OrderEditController as VendorOrderEditController;
use App\Models\Seller;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C4: an order is one storefront's: its country follows from its lines. The picker that adds a product to an existing order offers the products
 * of that country, so another country's product cannot be added by picking the wrong twin. With the switch off, or when the order's lines do not
 * name one country, the picker offers what it always offered.
 */
class OrderEditPickerCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsProductCatalog, RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCatalogTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
        });
        $this->addStorefrontCountryColumn();
        $this->createOrderTables();
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        Cache::flush();

        $this->insertProduct(id: 1, name: 'Saudi serum', extra: ['code' => 'SKU100', 'storefront_country' => 'SA']);
        $this->insertProduct(id: 2, name: 'Kuwaiti serum', extra: ['code' => 'SKU100', 'storefront_country' => 'KW']);
        $this->insertProduct(id: 3, name: 'Other serum', extra: ['code' => 'SKU300', 'storefront_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function createOrderTables(): void
    {
        foreach (['orders', 'order_details'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('seller_is')->default('admin');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->integer('qty')->default(1);
        });
    }

    /** @param list<int> $productIds */
    private function orderOf(int $orderId, array $productIds, string $sellerIs = 'admin', ?int $sellerId = null): void
    {
        DB::table('orders')->insert(['id' => $orderId, 'seller_is' => $sellerIs, 'seller_id' => $sellerId]);
        foreach ($productIds as $productId) {
            DB::table('order_details')->insert(['order_id' => $orderId, 'product_id' => $productId]);
        }
    }

    /** @return list<int> */
    private function ids(iterable $products): array
    {
        return $this->idsOf($products);
    }

    // ---- the order's country ---------------------------------------------------------------------------------------------------

    public function test_the_country_of_an_order_follows_from_its_lines(): void
    {
        $this->scopeCodesByCountry();
        $this->orderOf(10, [2]);
        $this->orderOf(11, [1, 3]);

        $scope = app(ProductCodeScope::class);

        $this->assertSame('KW', $scope->countryOfOrder(10));
        $this->assertSame('SA', $scope->countryOfOrder(11));
    }

    public function test_an_order_whose_lines_name_two_countries_or_none_has_no_country(): void
    {
        $this->scopeCodesByCountry();
        $this->orderOf(10, [1, 2]);
        $this->orderOf(11, []);
        $this->orderOf(12, [1]);
        DB::table('order_details')->insert(['order_id' => 12, 'product_id' => 999]);

        $scope = app(ProductCodeScope::class);

        $this->assertNull($scope->countryOfOrder(10), 'two countries');
        $this->assertNull($scope->countryOfOrder(11), 'no lines');
        $this->assertNull($scope->countryOfOrder(12), 'a line whose product is gone says nothing, and the other one is not enough to decide');
        $this->assertNull($scope->countryOfOrder(404), 'no such order');
        $this->assertNull($scope->countryOfOrder(null));
    }

    public function test_a_product_without_a_country_leaves_the_order_without_one(): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->where('id', 3)->update(['storefront_country' => '']);
        $this->orderOf(10, [1, 3]);

        $this->assertNull(app(ProductCodeScope::class)->countryOfOrder(10));
    }

    public function test_with_the_switch_off_an_order_has_no_country(): void
    {
        $this->orderOf(10, [2]);

        $this->assertNull(app(ProductCodeScope::class)->countryOfOrder(10));
    }

    public function test_a_missing_column_gives_an_order_no_country(): void
    {
        $this->orderOf(10, [2]);
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertNull(app(ProductCodeScope::class)->countryOfOrder(10));
    }

    // ---- the repository --------------------------------------------------------------------------------------------------------

    public function test_the_list_keeps_to_a_country_while_the_switch_is_on_and_ignores_it_otherwise(): void
    {
        $repository = app(ProductRepositoryInterface::class);

        $off = $repository->getListWithScope(filters: ['storefront_country' => 'KW'], dataLimit: 'all');
        $this->scopeCodesByCountry();
        $on = $repository->getListWithScope(filters: ['storefront_country' => 'KW'], dataLimit: 'all');
        $every = $repository->getListWithScope(dataLimit: 'all');

        $this->assertSame([1, 2, 3], $this->ids($off));
        $this->assertSame([2], $this->ids($on));
        $this->assertSame([1, 2, 3], $this->ids($every));
    }

    public function test_a_search_by_code_keeps_to_the_country_too(): void
    {
        $this->scopeCodesByCountry();
        $repository = app(ProductRepositoryInterface::class);

        $found = $repository->getListWithScope(searchValue: 'SKU100', filters: ['storefront_country' => 'SA'], dataLimit: 'all');

        $this->assertSame([1], $this->ids($found));
    }

    // ---- the pickers -----------------------------------------------------------------------------------------------------------

    private function adminPicker(int $orderId): array
    {
        $request = Request::create('/admin/orders/edit/search-product', 'GET', ['order_id' => $orderId, 'searchValue' => 'serum']);

        return app(AdminOrderEditController::class)->getSearchEditOrderProductsView($request)->getData(true);
    }

    public function test_the_admin_picker_offers_the_products_of_the_orders_country(): void
    {
        $this->scopeCodesByCountry();
        $this->orderOf(10, [2]);

        $this->assertSame(1, $this->adminPicker(10)['count']);
        $this->assertStringContainsString('Kuwaiti serum', $this->adminPicker(10)['result']);
        $this->assertStringNotContainsString('Saudi serum', $this->adminPicker(10)['result']);
    }

    public function test_the_admin_picker_offers_every_country_with_the_switch_off(): void
    {
        $this->orderOf(10, [2]);

        $this->assertSame(3, $this->adminPicker(10)['count']);
    }

    public function test_the_admin_picker_offers_every_country_for_an_order_of_no_one_country(): void
    {
        $this->scopeCodesByCountry();
        $this->orderOf(10, [1, 2]);

        $this->assertSame(3, $this->adminPicker(10)['count']);
    }

    public function test_the_vendor_picker_offers_the_products_of_the_orders_country(): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->update(['added_by' => 'seller', 'user_id' => 5]);
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 2, 'seller_id' => 5, 'author_type' => 'vendor', 'name' => 'Vendor shop']);
        $this->orderOf(10, [2], sellerIs: 'seller', sellerId: 5);
        auth('seller')->setUser((new Seller())->forceFill(['id' => 5]));
        $request = Request::create('/vendor/orders/edit/search-product', 'GET', ['order_id' => 10, 'searchValue' => 'serum']);

        $answer = app(VendorOrderEditController::class)->getSearchEditOrderProductsView($request)->getData(true);

        $this->assertSame(1, $answer['count']);
    }
}
