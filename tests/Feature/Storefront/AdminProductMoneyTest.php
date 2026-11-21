<?php

namespace Tests\Feature\Storefront;

use App\Http\Middleware\ScopeMoneyToMarket;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontCurrency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * GCC-05: an admin screen that works on the products of one country reads and writes their amounts in that country's currency. The platform of
 * this test converts from a base currency (the multi-currency model, the Saudi riyal as its default): without the market in force an amount typed
 * for Kuwait would be divided by the rate of the riyal on its way into the table, and an amount read from it would be multiplied.
 */
class AdminProductMoneyTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        Schema::table('products', function ($table) {
            $table->string('code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        $this->runStorefrontCountryMigration();
        $this->useMultiCurrencyPlatform();

        config([
            'gcc.storefronts.enabled' => true,
            'gcc.storefronts.currency.enabled' => true,
            'gcc.storefronts.countries.KW.active' => true,
            'gcc.storefronts.countries.AE.active' => true,
        ]);
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('ai_settings');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function useMultiCurrencyPlatform(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('symbol')->nullable();
            $table->string('code')->nullable();
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        foreach (['USD' => ['$', 1.0], 'SAR' => ['SAR', 3.75], 'AED' => ['AED', 3.6725], 'KWD' => ['KD', 0.307]] as $code => [$symbol, $rate]) {
            DB::table('currencies')->insert(['name' => $code, 'symbol' => $symbol, 'code' => $code, 'exchange_rate' => $rate, 'status' => 1]);
        }

        DB::table('business_settings')->whereIn('type', ['currency_model', 'system_default_currency', 'decimal_point_settings', 'currency_symbol_position'])->delete();
        DB::table('business_settings')->insert([
            ['type' => 'currency_model', 'value' => 'multi_currency'],
            ['type' => 'system_default_currency', 'value' => (string) DB::table('currencies')->where('code', 'SAR')->value('id')],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        Cache::flush();
    }

    private function market(): StorefrontCurrency
    {
        return app(StorefrontCurrency::class);
    }

    private function renderList(string $type = 'in_house'): string
    {
        URL::defaults(['type' => $type]);
        $loaded = Product::withoutGlobalScopes()->orderBy('id')->get()->each(fn (Product $product) => $product->setRelation('clearanceSale', null));
        $products = new LengthAwarePaginator($loaded, $loaded->count(), 25, 1, ['path' => '/admin/products/list/' . $type]);

        return view('admin-views.product.list', [
            'products' => $products, 'sellers' => collect(), 'brands' => collect(), 'categories' => collect(),
            'subCategories' => collect(), 'subSubCategories' => collect(), 'filters' => [], 'type' => $type, 'productWiseTax' => false,
        ])->render();
    }

    // ---- the market in force ----------------------------------------------------------------------------------------------------

    public function test_an_amount_typed_for_kuwait_is_stored_as_typed_and_one_typed_for_the_default_storefront_is_converted_as_before(): void
    {
        $this->assertEqualsWithDelta(3.505 / 3.75, currencyConverter(amount: 3.505), 0.000001, 'the platform converts it to the base currency');

        $stored = $this->market()->within(country: 'KW', callback: fn () => currencyConverter(amount: 3.505));

        $this->assertSame(3.505, $stored);
    }

    public function test_an_amount_read_from_a_kuwaiti_product_is_shown_in_dinars_with_three_decimals(): void
    {
        $shown = $this->market()->within(country: 'KW', callback: fn () => setCurrencySymbol(amount: usdToDefaultCurrency(amount: 3.5), currencyCode: getCurrencyCode()));

        $this->assertSame('KD3.500', $shown);
        $this->assertSame('SAR13.12', setCurrencySymbol(amount: usdToDefaultCurrency(amount: 3.5), currencyCode: getCurrencyCode()), 'outside the market the platform reads it as before');
    }

    public function test_the_market_in_force_before_is_put_back_even_when_the_callback_throws(): void
    {
        try {
            $this->market()->within(country: 'KW', callback: function () {
                throw new RuntimeException('a failing save');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('multi_currency', getWebConfig('currency_model'));

        $this->market()->within(country: 'KW', callback: function () {
            $this->market()->within(country: 'AE', callback: fn () => $this->assertSame('2', getWebConfig('decimal_point_settings')));
            $this->assertSame('3', getWebConfig('decimal_point_settings'), 'back in Kuwait after the inner market');
        });

        $this->assertSame('2', getWebConfig('decimal_point_settings'));
    }

    public function test_nothing_changes_for_the_default_market_an_unknown_country_no_country_or_while_the_switch_is_off(): void
    {
        foreach (['SA', 'XX', '', null] as $country) {
            $this->assertSame('multi_currency', $this->market()->within(country: $country, callback: fn () => getWebConfig('currency_model')));
        }

        config(['gcc.storefronts.currency.enabled' => false]);
        $this->assertSame('multi_currency', $this->market()->within(country: 'KW', callback: fn () => getWebConfig('currency_model')));

        config(['gcc.storefronts.currency.enabled' => true, 'gcc.storefronts.enabled' => false]);
        $this->assertSame('multi_currency', $this->market()->within(country: 'KW', callback: fn () => getWebConfig('currency_model')));
    }

    public function test_a_market_without_its_currency_keeps_the_platform_settings(): void
    {
        DB::table('currencies')->where('code', 'KWD')->delete();
        Cache::forget('active_currency_list');

        $this->assertSame('multi_currency', $this->market()->within(country: 'KW', callback: fn () => getWebConfig('currency_model')));
    }

    // ---- the middleware ---------------------------------------------------------------------------------------------------------

    private function probeRoutes(): void
    {
        Route::middleware(ScopeMoneyToMarket::class)->get('/admin/__money-probe/{id}', fn (string $id) => response()->json([
            'model' => getWebConfig('currency_model'),
            'stored' => currencyConverter(amount: 3.505),
        ]));
        Route::middleware(ScopeMoneyToMarket::class)->post('/admin/__money-probe-create', fn () => response()->json([
            'model' => getWebConfig('currency_model'),
            'stored' => currencyConverter(amount: 3.505),
        ]));
    }

    public function test_a_route_that_names_a_kuwaiti_product_runs_in_dinars_and_one_that_names_a_saudi_product_does_not(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__money-probe/2')->assertOk()->assertJsonPath('model', 'single_currency')->assertJsonPath('stored', 3.505);
        $this->getJson('/admin/__money-probe/1')->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->getJson('/admin/__money-probe/99')->assertOk()->assertJsonPath('model', 'multi_currency');
    }

    public function test_a_form_that_names_a_country_runs_in_that_country(): void
    {
        $this->probeRoutes();

        $this->postJson('/admin/__money-probe-create', ['storefront_country' => 'KW'])->assertOk()->assertJsonPath('model', 'single_currency')->assertJsonPath('stored', 3.505);
        $this->postJson('/admin/__money-probe-create', ['storefront_country' => 'SA'])->assertOk()->assertJsonPath('model', 'multi_currency');
        $this->postJson('/admin/__money-probe-create', [])->assertOk()->assertJsonPath('model', 'multi_currency');
    }

    public function test_the_money_of_one_request_never_reaches_the_next(): void
    {
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__money-probe/2')->assertJsonPath('model', 'single_currency');

        $this->assertSame('multi_currency', getWebConfig('currency_model'));
    }

    public function test_nothing_is_scoped_while_storefront_currencies_are_off(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
        $this->probeRoutes();

        $this->getJson('/admin/__money-probe/2')->assertJsonPath('model', 'multi_currency');
        $this->postJson('/admin/__money-probe-create', ['storefront_country' => 'KW'])->assertJsonPath('model', 'multi_currency');
    }

    /**
     * The five pickers and searches that span every country (multiple-product-details, search, search-all-product, picker-options, picker-labels)
     * name no one market, so none of them runs in a country's money, whichever permission guards them: the two pickers of the product form sit
     * under module:catalog on their own, outside the group of the screens that work on one product.
     */
    public function test_every_admin_product_route_of_the_catalog_group_carries_the_middleware(): void
    {
        $pickerUris = ['admin/products/picker-options', 'admin/products/picker-labels'];
        [$pickers, $productRoutes] = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin/products/') && in_array('module:catalog', $route->gatherMiddleware(), true))
            ->partition(fn ($route) => in_array($route->uri(), $pickerUris, true));

        $this->assertGreaterThan(30, $productRoutes->count());
        foreach ($productRoutes as $route) {
            $this->assertContains('money.market', $route->gatherMiddleware(), "{$route->uri()} must run in the money of its product's country");
        }
        foreach ($pickers as $route) {
            $this->assertNotContains('money.market', $route->gatherMiddleware(), "{$route->uri()} spans every country and names no market");
        }
        $this->assertSame(ScopeMoneyToMarket::class, app('router')->getMiddleware()['money.market']);
    }

    private function adminAddRequest(array $input = []): Request
    {
        return Request::create('/admin/products/add', 'POST', $input + [
            'name' => ['Serum', 'سيروم'], 'lang' => ['en', 'ar'], 'description' => ['A serum', 'سيروم'], 'code' => 'SER12345', 'barcode' => '6291234567890',
            'category_id' => 1, 'sub_category_id' => null, 'sub_sub_category_id' => null, 'product_type' => 'physical', 'unit' => 'pc',
            'unit_price' => 3.505, 'tax' => 0, 'tax_type' => 'percent', 'tax_model' => 'exclude', 'discount' => 1.25, 'discount_type' => 'flat',
            'current_stock' => 5, 'minimum_order_qty' => 1, 'shipping_cost' => 0.5, 'existing_thumbnail' => 'existing.webp',
        ]);
    }

    /** The data the admin form saves, taken through the middleware the way the route does. */
    private function savedWith(Request $request): array
    {
        $data = [];
        (new ScopeMoneyToMarket())->handle($request, function (Request $scoped) use (&$data) {
            $data = app(ProductService::class)->getAddProductData(request: $scoped, addedBy: 'admin', shopId: 1);

            return response()->json([]);
        });

        return $data;
    }

    public function test_a_product_added_for_kuwait_is_saved_with_the_amounts_typed_for_it(): void
    {
        $data = $this->savedWith($this->adminAddRequest(['storefront_country' => 'KW']));

        $this->assertSame('KW', $data['storefront_country']);
        $this->assertEqualsWithDelta(3.505, (float) $data['unit_price'], 0.000001);
        $this->assertEqualsWithDelta(1.25, (float) $data['discount'], 0.000001);
        $this->assertEqualsWithDelta(0.5, (float) $data['shipping_cost'], 0.000001);
    }

    public function test_a_product_added_for_the_default_storefront_is_converted_as_it_always_was(): void
    {
        $data = $this->savedWith($this->adminAddRequest(['storefront_country' => 'SA']));

        $this->assertEqualsWithDelta(3.505 / 3.75, (float) $data['unit_price'], 0.000001);
        $this->assertEqualsWithDelta(1.25 / 3.75, (float) $data['discount'], 0.000001);
    }

    // ---- the list ---------------------------------------------------------------------------------------------------------------

    public function test_the_admin_list_shows_the_price_of_each_product_in_the_currency_of_its_own_country(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 100);
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', price: 3.5, country: 'KW');

        $html = $this->renderList();

        $this->assertStringContainsString('SAR375.00', $html, 'the Saudi product is converted as before');
        $this->assertStringContainsString('KD3.500', $html, 'the Kuwaiti product is shown in dinars, as typed');
        $this->assertStringNotContainsString('SAR13.12', $html);
    }

    public function test_while_the_switch_is_off_the_list_shows_every_product_as_before(): void
    {
        config(['gcc.storefronts.currency.enabled' => false]);
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: 100);
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', price: 3.5, country: 'KW');

        $html = $this->renderList();

        $this->assertStringContainsString('SAR375.00', $html);
        $this->assertStringContainsString('SAR13.12', $html);
        $this->assertStringNotContainsString('KD3.500', $html);
    }
}
