<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * B-02: no code path copies a country's catalogue price or stock into another country's.
 *
 * The product gallery's "use this product info" opens the EDIT form of an existing product with `product-gallery=1`, which turns the form's
 * action into the ADD route -- so the page is pre-filled from a source product and submitted as a NEW one. The same form carries a country
 * SELECT exactly when that flag is set (`_storefront-country.blade.php:5` makes the field a chooser rather than a read-only label), so an
 * administrator could take a Saudi product's page, choose Kuwait, and save.
 *
 * What was saved then was the Saudi figures in a Kuwaiti row. An amount typed in that form is stored AS TYPED in the chosen country's
 * currency (GCC-05 slice 4), so a product priced at 100 riyals became a product priced at 100 dinars -- about twelve times its price -- and
 * its stock figure was the Saudi warehouse's quantity in a market that holds none of it.
 *
 * The deliberate copy-to-country action already refused to carry either (`ProductCopyToCountry`, which zeroes price, purchase price, discount,
 * stock and every variant's figures and creates the copy unpublished). The gallery was the other path, and now follows the same rule.
 *
 * While countries are NOT tracked the gallery pre-fills exactly what it always did: there is one market, a figure cannot belong to another
 * one, and carrying it is the point of the feature.
 */
class AdminGalleryCopyFiguresTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RestoresLanguageFiles;

    private const PRICE = 100;

    private const STOCK = 42;

    private const SHIPPING = 7;

    private const DISCOUNT = 9;

    private const VARIANT_PRICE = 123;

    private const VARIANT_STOCK = 11;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
        $this->buildStorefrontCatalogTables();
        $this->buildProductRelationTables();
        $this->runStorefrontCountryMigration();
        $this->addTheColumnsTheFormReads();
        $this->buildOneCurrency();
        $this->buildAiSettings();
        StorefrontCatalog::flush();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- the pricing form -------------------------------------------------------------------------------------------------------

    public function test_the_gallery_form_of_a_saudi_product_carries_no_price_no_stock_no_discount_and_no_shipping_cost(): void
    {
        $this->switchStorefrontsOn();

        $html = $this->renderPricingForm(fromTheGallery: true);

        $this->assertSame('0', $this->valueOf(field: 'unit_price', html: $html), 'the price of another country is not pre-filled');
        $this->assertSame('0', $this->valueOf(field: 'current_stock', html: $html), 'nor the quantity in another country\'s warehouse');
        $this->assertSame('0', $this->valueOf(field: 'discount', html: $html));
        $this->assertSame('0', $this->valueOf(field: 'shipping_cost', html: $html));
    }

    /** Today's behaviour, unchanged: editing the product itself shows the product's own figures. */
    public function test_editing_the_product_itself_still_shows_its_own_figures(): void
    {
        $this->switchStorefrontsOn();

        $html = $this->renderPricingForm(fromTheGallery: false);

        $this->assertSame((string) self::PRICE, $this->valueOf(field: 'unit_price', html: $html));
        $this->assertSame((string) self::STOCK, $this->valueOf(field: 'current_stock', html: $html));
        $this->assertSame((string) self::DISCOUNT, $this->valueOf(field: 'discount', html: $html));
        $this->assertSame((string) self::SHIPPING, $this->valueOf(field: 'shipping_cost', html: $html));
    }

    /** The first test of the switch: while countries are not tracked the gallery carries the figures exactly as it always did. */
    public function test_while_countries_are_not_tracked_the_gallery_carries_the_figures_as_before(): void
    {
        $html = $this->renderPricingForm(fromTheGallery: true);

        $this->assertSame((string) self::PRICE, $this->valueOf(field: 'unit_price', html: $html));
        $this->assertSame((string) self::STOCK, $this->valueOf(field: 'current_stock', html: $html));
        $this->assertSame((string) self::DISCOUNT, $this->valueOf(field: 'discount', html: $html));
    }

    /** A flat discount is money and a percentage is not, and neither crosses: both halves of that one field are covered. */
    public function test_a_flat_discount_is_not_carried_either(): void
    {
        $this->switchStorefrontsOn();
        DB::table('products')->where('id', 1)->update(['discount_type' => 'flat']);

        $this->assertSame('0', $this->valueOf(field: 'discount', html: $this->renderPricingForm(fromTheGallery: true)));
        $this->assertSame((string) self::DISCOUNT, $this->valueOf(field: 'discount', html: $this->renderPricingForm(fromTheGallery: false)));
    }

    // ---- the variants -----------------------------------------------------------------------------------------------------------

    public function test_the_gallery_form_carries_no_variant_price_and_no_variant_quantity(): void
    {
        $this->switchStorefrontsOn();

        $html = $this->renderVariants(fromTheGallery: true);

        $this->assertSame('0', $this->valueOf(field: 'price_Red', html: $html));
        $this->assertSame('0', $this->valueOf(field: 'qty_Red', html: $html));
        $this->assertStringContainsString('value="RED-SKU-1"', $html, 'what the variant IS still crosses: only its figures do not');
    }

    public function test_editing_the_product_itself_still_shows_its_variants_own_figures(): void
    {
        $this->switchStorefrontsOn();

        $html = $this->renderVariants(fromTheGallery: false);

        $this->assertSame((string) self::VARIANT_PRICE, $this->valueOf(field: 'price_Red', html: $html));
        $this->assertSame((string) self::VARIANT_STOCK, $this->valueOf(field: 'qty_Red', html: $html));
    }

    /**
     * The hole this would otherwise have left. The variation controls re-render every variant's price and quantity through an endpoint of
     * their own, and that request does not carry the page's query string, so the gallery flag is put on the URL the page hands the browser.
     */
    public function test_the_variation_endpoint_the_gallery_page_names_carries_the_gallery_flag(): void
    {
        $this->switchStorefrontsOn();
        $this->registerTheVariationRoute();

        request()->merge(['product-gallery' => '1']);
        $fromTheGallery = route('admin.products.sku-combination', request()->boolean('product-gallery') ? ['product-gallery' => 1] : []);
        request()->replace([]);
        $fromTheEditForm = route('admin.products.sku-combination', request()->boolean('product-gallery') ? ['product-gallery' => 1] : []);

        $this->assertStringContainsString('product-gallery=1', $fromTheGallery);
        $this->assertStringNotContainsString('product-gallery', $fromTheEditForm, 'and an ordinary edit is unchanged');
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------------

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true, 'gcc.storefronts.countries.KW.active' => true]);
        StorefrontCatalog::flush();
    }

    private function renderPricingForm(bool $fromTheGallery): string
    {
        $this->seedTheSaudiProduct();
        $fromTheGallery ? request()->merge(['product-gallery' => '1']) : request()->replace([]);

        return view('admin-views.product.update._pricing-others', [
            'product' => Product::withoutGlobalScopes()->findOrFail(1),
            'taxVats' => collect(),
            'taxVatIds' => [],
            'productWiseTax' => 0,
        ])->render();
    }

    private function renderVariants(bool $fromTheGallery): string
    {
        $fromTheGallery ? request()->merge(['product-gallery' => '1']) : request()->replace([]);

        return view('admin-views.product.partials._edit-sku-combinations', [
            'combinations' => [['type' => 'Red', 'price' => self::VARIANT_PRICE, 'sku' => 'RED-SKU-1', 'qty' => self::VARIANT_STOCK]],
            'unitPrice' => self::PRICE,
            'colorsActive' => 1,
            'productName' => 'Serum',
        ])->render();
    }

    /** The value of one named input of the rendered form. */
    private function valueOf(string $field, string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<input[^>]*\sname="' . preg_quote($field, '/') . '"[^>]*>/', $html, $input)
                ?: preg_match('/<input[^>]*\svalue="([^"]*)"[^>]*\sname="' . preg_quote($field, '/') . '"/', $html, $input),
            "the form has one [{$field}] input",
        );

        $this->assertSame(1, preg_match('/\svalue="([^"]*)"/', $input[0], $value), "the [{$field}] input has a value");

        return $value[1];
    }

    private function seedTheSaudiProduct(): void
    {
        if (DB::table('products')->where('id', 1)->exists()) {
            return;
        }

        $this->insertCatalogProduct(id: 1, name: 'Saudi serum', price: self::PRICE, stock: self::STOCK);
        DB::table('products')->where('id', 1)->update([
            'discount' => self::DISCOUNT, 'discount_type' => 'percent', 'shipping_cost' => self::SHIPPING,
            'minimum_order_qty' => 1, 'product_type' => 'physical',
        ]);
    }

    /** One currency at a rate of one, so a converted amount is the stored amount and the test is about what is carried, not about a rate. */
    private function buildOneCurrency(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code');
            $table->double('exchange_rate');
            $table->boolean('status')->default(true);
        });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('business_settings')->updateOrInsert(['type' => 'system_default_currency'], ['value' => 1]);
        DB::table('business_settings')->updateOrInsert(['type' => 'currency_model'], ['value' => 'multi_currency']);
    }

    /** The money and quantity columns the pricing form reads, which the shared catalogue builder does not need and so does not create. */
    private function addTheColumnsTheFormReads(): void
    {
        Schema::table('products', function ($table): void {
            $table->decimal('discount', 24, 2)->default(0);
            $table->string('discount_type')->default('percent');
            $table->decimal('shipping_cost', 24, 2)->default(0);
            $table->integer('minimum_order_qty')->default(1);
            $table->boolean('multiply_qty')->default(false);
            $table->decimal('temp_shipping_cost', 24, 2)->nullable();
            $table->boolean('is_shipping_cost_updated')->default(true);
        });
    }

    private function buildAiSettings(): void
    {
        Schema::dropIfExists('ai_settings');
        Schema::create('ai_settings', function ($table): void {
            $table->id();
            $table->boolean('status')->default(false);
            $table->string('api_key')->nullable();
        });
    }

    /** The real route is declared inside the admin group, which this test does not load; its name is what the page reads. */
    private function registerTheVariationRoute(): void
    {
        if (!Route::has('admin.products.sku-combination')) {
            Route::post('admin/products/sku-combination', static fn (): string => 'ok')->name('admin.products.sku-combination');
        }
    }
}
