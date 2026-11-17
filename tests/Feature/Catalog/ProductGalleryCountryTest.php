<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C3, G9: the admin product gallery lists the products of every country (a copy of one is how a product is made for another country today), so
 * while the switch is on each card and each details panel names the country of its product. The copy itself keeps blanking the code and the
 * barcode, whatever the switch says: the admin types the new ones, and they are checked in the country the copy is saved in. The seller's gallery
 * lists the seller's own products and is not touched.
 */
class ProductGalleryCountryTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsProductCatalog, RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        View::getFinder()->prependLocation(base_path('tests/Support/views'));
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
        Cache::flush();
        $this->insertProduct(id: 1, name: 'Serum', extra: ['code' => 'SKU100', 'barcode' => '6291234567890', 'storefront_country' => 'KW']);
        $this->insertProduct(id: 2, name: 'Serum', extra: ['code' => 'SKU100', 'barcode' => '6291234567890', 'storefront_country' => 'SA']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    private function gallery(): string
    {
        $loaded = Product::withoutGlobalScopes()->with(['tags'])->orderBy('id')->get()->each(fn (Product $product) => $product->setRelation('clearanceSale', null));
        $products = new LengthAwarePaginator($loaded, $loaded->count(), 25, 1, ['path' => '/admin/products/product-gallery']);

        return view('admin-views.product.product-gallery', [
            'products' => $products, 'vendors' => collect(), 'productBrands' => collect(), 'productCategories' => collect(), 'searchValue' => '', 'productStores' => collect(),
        ])->render();
    }

    public function test_with_the_switch_off_the_gallery_names_no_country(): void
    {
        $html = $this->gallery();

        $this->assertStringContainsString('Serum', $html);
        $this->assertStringNotContainsString(' · KW', $html);
        $this->assertStringNotContainsString(' · SA', $html);
        $this->assertSame(2, substr_count($html, '<h3 class="text-capitalize mb-3 line-1">Serum</h3>'), 'the card headings are byte for byte what they were');
        $this->assertSame(2, substr_count($html, '<h3 class="text-capitalize mb-0">Serum</h3>'), 'so are the headings of the details panels');
    }

    public function test_with_the_switch_on_each_card_and_each_details_panel_names_the_country_of_its_product(): void
    {
        $this->scopeCodesByCountry();

        $html = $this->gallery();

        $this->assertSame(2, substr_count($html, 'Serum · KW'), 'the card and the details panel of the Kuwaiti product');
        $this->assertSame(2, substr_count($html, 'Serum · SA'), 'the card and the details panel of the Saudi product');
    }

    public function test_the_switch_changes_nothing_but_the_country_text(): void
    {
        $off = $this->gallery();
        $this->scopeCodesByCountry();

        $this->assertSame($off, str_replace([' · KW', ' · SA'], '', $this->gallery()));
    }

    public function test_a_missing_column_gives_the_old_gallery(): void
    {
        $off = $this->gallery();
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame($off, $this->gallery());
    }

    // ---- G9: the copy -----------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string}> the forms that open a gallery product as the base of a new one */
    public static function copyForms(): array
    {
        return [
            'the admin copy form' => ['admin-views/product/update/_general-setup.blade.php'],
            'the seller copy form' => ['vendor-views/product/update/_general-setup.blade.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copyForms')]
    public function test_the_copy_of_a_gallery_product_blanks_the_code_and_the_barcode_in_the_form(string $form): void
    {
        $source = (string) file_get_contents(resource_path('views/' . $form));

        $this->assertMatchesRegularExpression("/request\\('product-gallery'\\)\\s*\\?\\s*' '\\s*:\\s*\\\$product->code/", $source, 'the code is blank (a space) in a copy');
        $this->assertMatchesRegularExpression("/request\\('product-gallery'\\)\\s*\\?\\s*''\\s*:\\s*old\\('barcode', \\\$product->barcode\\)/", $source, 'the barcode is blank in a copy');
    }

    public function test_the_copy_is_saved_by_the_add_action_so_it_is_checked_in_the_country_it_is_saved_in(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin-views/product/update/index.blade.php'));

        $this->assertStringContainsString("request('product-gallery') == 1 ? route('admin.products.add')", $source);
    }
}
