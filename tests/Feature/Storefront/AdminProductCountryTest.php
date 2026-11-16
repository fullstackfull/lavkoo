<?php

namespace Tests\Feature\Storefront;

use App\Http\Requests\ProductAddRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use App\Utils\ProductManager;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * The admin decides which country a new product belongs to, and every other tool leaves a product in the country it was added for.
 *
 * Nothing here is reachable while storefronts are off or before the products migration has run: the form, the validation, the data a
 * new product is saved with and the filters of the lists are exactly what they were.
 */
class AdminProductCountryTest extends TestCase
{
    use BuildsStorefrontCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        $this->runStorefrontCountryMigration();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);
    }

    private function adminAddRequest(array $input = []): Request
    {
        return Request::create('/admin/products/add', 'POST', $input + [
            'name' => ['Serum', 'سيروم'],
            'lang' => ['en', 'ar'],
            'description' => ['A serum', 'سيروم'],
            'code' => 'SER12345',
            'barcode' => '6291234567890',
            'category_id' => 1,
            'sub_category_id' => null,
            'sub_sub_category_id' => null,
            'product_type' => 'physical',
            'unit' => 'pc',
            'unit_price' => 10,
            'tax' => 0,
            'tax_type' => 'percent',
            'tax_model' => 'exclude',
            'discount' => 0,
            'discount_type' => 'percent',
            'current_stock' => 5,
            'minimum_order_qty' => 1,
            'shipping_cost' => 0,
            'existing_thumbnail' => 'existing.webp',
        ]);
    }

    // ---- when the tools know about countries ----------------------------------------------------------------------------------

    public function test_the_tools_track_a_products_country_only_while_storefronts_are_on_and_the_migration_has_run(): void
    {
        $this->assertFalse(ProductCountry::isTracked(), 'storefronts are off');

        $this->switchStorefrontsOn();
        $this->assertTrue(ProductCountry::isTracked());

        $this->removeStorefrontCountryColumn();
        StorefrontCatalog::flush();
        $this->assertFalse(ProductCountry::isTracked(), 'the column does not exist yet');
    }

    public function test_every_configured_country_can_be_chosen_for_a_product_even_before_it_opens(): void
    {
        $this->assertFalse(config('gcc.storefronts.countries.KW.active'));

        $this->assertSame(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], ProductCountry::options());
    }

    // ---- what a new product is saved with --------------------------------------------------------------------------------------

    public function test_a_new_product_gets_the_country_the_admin_chose(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['storefront_country' => 'KW'], ProductCountry::newProductAttributes(requestedCountry: 'KW'));
        $this->assertSame(['storefront_country' => 'SA'], ProductCountry::newProductAttributes(requestedCountry: 'SA'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function noUsableCountry(): array
    {
        return [
            'nothing' => [null],
            'an empty text' => [''],
            'a country that is not configured' => ['XX'],
            'a country in the wrong letter case' => ['kw'],
            'a list' => [['KW']],
            'a number' => [0],
        ];
    }

    #[DataProvider('noUsableCountry')]
    public function test_a_new_product_without_a_usable_country_belongs_to_the_default_storefront(mixed $requested): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['storefront_country' => 'SA'], ProductCountry::newProductAttributes(requestedCountry: $requested));
    }

    public function test_while_the_country_is_not_tracked_a_new_product_is_saved_exactly_as_before(): void
    {
        $this->assertSame([], ProductCountry::newProductAttributes(requestedCountry: 'KW'));
        $this->assertSame([], ProductCountry::newProductRules());
    }

    public function test_the_admin_add_data_carries_the_chosen_country_and_the_seller_add_data_never_does(): void
    {
        $this->switchStorefrontsOn();
        $service = app(ProductService::class);

        $adminData = $service->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'KW']), addedBy: 'admin', shopId: 1);
        $sellerData = $service->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'KW']), addedBy: 'seller', shopId: 7);

        $this->assertSame('KW', $adminData['storefront_country']);
        $this->assertArrayNotHasKey('storefront_country', $sellerData, 'a seller product takes the default of the table until sellers have markets');
    }

    public function test_the_add_data_is_what_it_was_while_the_country_is_not_tracked(): void
    {
        $service = app(ProductService::class);

        $adminData = $service->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'KW']), addedBy: 'admin', shopId: 1);

        $this->assertArrayNotHasKey('storefront_country', $adminData);
    }

    public function test_the_saved_product_keeps_the_chosen_country_and_reads_back_as_that_countrys_product(): void
    {
        $this->switchStorefrontsOn();
        $data = app(ProductService::class)->getAddProductData(request: $this->adminAddRequest(['storefront_country' => 'KW']), addedBy: 'admin', shopId: 1);

        $saved = Product::create(Arr::only($data, ['storefront_country', 'added_by', 'name', 'code', 'barcode', 'status', 'request_status', 'unit_price', 'current_stock']));

        $this->assertSame('KW', DB::table('products')->where('id', $saved->id)->value('storefront_country'));
        $this->assertArrayNotHasKey('storefront_country', $saved->fresh()->toArray(), 'the apps still never see the column');
    }

    public function test_a_product_is_never_moved_to_another_country_by_an_update(): void
    {
        $this->switchStorefrontsOn();
        $this->insertCatalogProduct(id: 7, name: 'Kuwaiti serum', country: 'KW');
        $product = Product::withoutGlobalScopes()->findOrFail(7);
        $request = $this->adminAddRequest(['storefront_country' => 'SA']);

        $data = app(ProductService::class)->getUpdateProductData(request: $request, product: $product, updateBy: 'admin');

        $this->assertArrayNotHasKey('storefront_country', $data);
    }

    // ---- validation -------------------------------------------------------------------------------------------------------------

    public function test_the_add_form_accepts_a_configured_country_a_missing_one_and_refuses_the_rest_while_countries_are_tracked(): void
    {
        $this->switchStorefrontsOn();
        $rules = (new ProductAddRequest())->rules();

        $this->assertArrayHasKey('storefront_country', $rules);
        $this->assertFalse(Validator::make(['storefront_country' => 'KW'], ['storefront_country' => $rules['storefront_country']])->fails());
        $this->assertFalse(Validator::make([], ['storefront_country' => $rules['storefront_country']])->fails());
        $this->assertTrue(Validator::make(['storefront_country' => 'XX'], ['storefront_country' => $rules['storefront_country']])->fails());
        $this->assertTrue(Validator::make(['storefront_country' => ['KW']], ['storefront_country' => $rules['storefront_country']])->fails());
    }

    public function test_the_add_form_does_not_know_the_field_while_countries_are_not_tracked(): void
    {
        $this->assertArrayNotHasKey('storefront_country', (new ProductAddRequest())->rules());
    }

    // ---- lists ------------------------------------------------------------------------------------------------------------------

    public function test_a_list_filter_keeps_the_configured_countries_the_admin_asked_for(): void
    {
        $this->switchStorefrontsOn();

        $this->assertSame(['KW', 'AE'], ProductCountry::filterOf(['KW', 'AE']));
        $this->assertSame(['KW'], ProductCountry::filterOf('KW'));
        $this->assertSame(['KW'], ProductCountry::filterOf(['KW', 'XX', ['SA'], null, 5, 'kw']));
        $this->assertSame([], ProductCountry::filterOf(['XX']));
        $this->assertSame([], ProductCountry::filterOf(null));
        $this->assertSame([], ProductCountry::filterOf([]));
    }

    public function test_a_list_is_never_filtered_by_country_while_countries_are_not_tracked(): void
    {
        $this->assertSame([], ProductCountry::filterOf(['KW']));
        $this->assertSame([], ProductManager::getSortFilterWhereInArrays(request: ['filter_storefront_countries' => ['KW']]));
    }

    public function test_the_shared_list_filters_narrow_to_the_countries_asked_for(): void
    {
        $this->switchStorefrontsOn();

        $where = ProductManager::getSortFilterWhereInArrays(request: ['filter_storefront_countries' => ['KW', 'XX'], 'filter_product_types' => ['physical']]);

        $this->assertSame(['product_type' => ['physical'], 'storefront_country' => ['KW']], $where);
        $this->assertSame([], ProductManager::getSortFilterWhereInArrays(request: ['filter_storefront_countries' => ['XX']]), 'a filter with nothing valid left is no filter at all');
    }
}
