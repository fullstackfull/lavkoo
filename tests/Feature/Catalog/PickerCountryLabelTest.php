<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\Admin\Product\ProductController;
use App\Http\Controllers\Admin\Product\ReviewController;
use App\Http\Controllers\Admin\Promotion\ClearanceSaleController;
use App\Http\Controllers\Admin\Promotion\FlashDealController;
use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C4, C5, C6: the pickers of the admin panel (the clearance sale, the flash deal and the deal of the day, the reviews filter, the product chips
 * they fill, and the order-edit picker) offer the products of every country, because a promotion has no country to narrow to. Two countries may
 * now hold products of one name and one SKU, so each row names its country in the text it already has, only while the switch is on. The layout
 * never changes, and the seller's own pickers (a seller's products are all in the table default country) are not touched.
 */
class PickerCountryLabelTest extends TestCase
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
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        DB::table('shops')->insert(['id' => 1, 'author_type' => 'admin', 'name' => 'In-house']);
        Cache::flush();
        $this->insertProduct(id: 1, name: 'Serum', extra: ['code' => 'SKU100', 'storefront_country' => 'KW']);
        $this->insertProduct(id: 2, name: 'Serum', extra: ['code' => 'SKU100', 'storefront_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> the view, the variable that carries its products, and the element of a name exactly as it was before the label */
    public static function adminPickerViews(): array
    {
        return [
            'the promotion and reviews picker rows' => ['admin-views.partials._search-product', 'products', '<h5 class="text-capitalize mb-1 product-name">Serum</h5>'],
            'the chips of the promotion pickers' => ['admin-views.partials._select-product', 'selectedProducts', '<h5 class="mb-1 product-name">Serum</h5>'],
            'the clearance sale picker rows' => ['admin-views.deal.clearance-sale.partials._search-product', 'products', "<h4 class=\"mb-1 product-name line-1 text-start\">\n                                Serum\n                            </h4>"],
            'the chips of the clearance sale picker' => ['admin-views.deal.clearance-sale.partials._select-product', 'selectedProducts', "<h4 class=\"mb-1 product-name line-1\">\n                                Serum\n\n                            </h4>"],
            'the order-edit picker rows' => ['admin-views.order.partials._search-product', 'products', "<h4 class=\"mb-0 product-name line-1 text-start\">\n                                    Serum\n                                </h4>"],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> the seller's own pickers: the rows of a seller are all in the table default country */
    public static function vendorPickerViews(): array
    {
        return [
            'the vendor reviews picker rows' => ['vendor-views.partials._search-product', 'products'],
            'the vendor clearance sale picker rows' => ['vendor-views.promotion.clearance-sale.partials._search-product', 'products'],
            'the vendor order-edit picker rows' => ['vendor-views.order.partials._search-product', 'products'],
        ];
    }

    private function render(string $view, string $variable): string
    {
        $products = Product::withoutGlobalScopes()->orderBy('id')->get();

        return view($view, [$variable => $products, 'orderId' => 7, 'clearanceConfig' => null])->render();
    }

    #[DataProvider('adminPickerViews')]
    public function test_with_the_switch_off_no_row_names_a_country_and_the_markup_is_the_old_one(string $view, string $variable, string $oldName): void
    {
        $off = $this->render($view, $variable);
        $this->assertSame(2, substr_count($off, $oldName), 'the element of the name is byte for byte what it was');

        $this->scopeCodesByCountry();
        $on = $this->render($view, $variable);

        $this->assertStringNotContainsString(' · KW', $off);
        $this->assertStringNotContainsString(' · SA', $off);
        $this->assertSame($off, str_replace([' · KW', ' · SA'], '', $on), 'the only difference the switch makes is the country text');
    }

    #[DataProvider('adminPickerViews')]
    public function test_with_the_switch_on_each_product_names_its_country_after_its_name(string $view, string $variable, string $oldName): void
    {
        $this->scopeCodesByCountry();

        $html = $this->render($view, $variable);

        $this->assertMatchesRegularExpression('/Serum · KW\s*</', $html, 'the Kuwaiti product');
        $this->assertMatchesRegularExpression('/Serum · SA\s*</', $html, 'the Saudi product');
        $this->assertSame(1, substr_count($html, 'Serum · KW'));
        $this->assertSame(1, substr_count($html, 'Serum · SA'));
    }

    #[DataProvider('adminPickerViews')]
    public function test_a_missing_column_draws_nothing(string $view, string $variable, string $oldName): void
    {
        $off = $this->render($view, $variable);
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame($off, $this->render($view, $variable));
    }

    #[DataProvider('adminPickerViews')]
    public function test_a_product_whose_country_is_unknown_is_not_tagged(string $view, string $variable, string $oldName): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->where('id', 1)->update(['storefront_country' => '']);

        $html = $this->render($view, $variable);

        $this->assertStringNotContainsString('Serum · KW', $html);
        $this->assertStringContainsString('Serum · SA', $html);
    }

    #[DataProvider('vendorPickerViews')]
    public function test_a_sellers_own_pickers_are_the_same_with_the_switch_on_and_off(string $view, string $variable): void
    {
        $off = $this->render($view, $variable);
        $this->scopeCodesByCountry();

        $this->assertSame($off, $this->render($view, $variable));
        $this->assertStringContainsString('Serum', $off, 'the view did list the products');
    }

    // ---- the endpoints that fill the pickers -----------------------------------------------------------------------------------

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function adminPickerEndpoints(): array
    {
        return [
            'the clearance sale search' => [ClearanceSaleController::class, 'getSearchedProductsView'],
            'the flash deal search' => [FlashDealController::class, 'search'],
            'the reviews product search' => [ReviewController::class, 'search'],
            'the promotion code search' => [ProductController::class, 'getSearchedAllProductsView'],
        ];
    }

    private function search(string $controller, string $method): string
    {
        $request = Request::create('/admin/search', 'GET', ['searchValue' => 'Serum', 'name' => 'SKU100']);
        $this->app->instance('request', $request);

        /** @var JsonResponse $response */
        $response = app($controller)->{$method}($request);

        return $response->getData(true)['result'];
    }

    #[DataProvider('adminPickerEndpoints')]
    public function test_the_rows_an_endpoint_answers_with_name_the_country_only_while_the_switch_is_on(string $controller, string $method): void
    {
        $off = $this->search($controller, $method);
        $this->scopeCodesByCountry();
        $on = $this->search($controller, $method);

        $this->assertStringContainsString('Serum', $off);
        $this->assertStringNotContainsString(' · ', $off);
        $this->assertStringContainsString('Serum · KW', $on);
        $this->assertStringContainsString('Serum · SA', $on);
    }
}
