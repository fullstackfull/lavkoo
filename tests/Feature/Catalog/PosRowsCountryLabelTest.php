<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * C2, C3: two countries may now hold products of one name and one SKU, so the admin point of sale shows the country of a product in the text it
 * already has (the SKU line of a search result, the title of a grid card). Nothing is drawn while the switch is off, and the layout never changes.
 */
class PosRowsCountryLabelTest extends TestCase
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

    private function searchRows(): string
    {
        $products = Product::withoutGlobalScopes()->orderBy('id')->get();

        return view('admin-views.pos.partials._search-product', compact('products'))->render();
    }

    private function gridCard(int $id): string
    {
        $product = Product::withoutGlobalScopes()->findOrFail($id)->setRelation('clearanceSale', null);

        return view('admin-views.pos.partials._single-product', compact('product'))->render();
    }

    public function test_with_the_switch_off_no_row_names_a_country(): void
    {
        $rows = $this->searchRows();

        $this->assertStringContainsString('SKU100</div>', $rows);
        $this->assertStringNotContainsString(' · ', $rows);
        $this->assertStringNotContainsString(' · ', $this->gridCard(1));
    }

    public function test_with_the_switch_on_the_sku_line_of_each_search_row_names_its_country(): void
    {
        $this->scopeCodesByCountry();

        $rows = $this->searchRows();

        $this->assertStringContainsString('SKU100 · KW</div>', $rows);
        $this->assertStringContainsString('SKU100 · SA</div>', $rows);
    }

    public function test_with_the_switch_on_a_grid_card_names_the_country_after_the_title(): void
    {
        $this->scopeCodesByCountry();

        $this->assertStringContainsString('Serum · KW', $this->gridCard(1));
        $this->assertStringContainsString('Serum · SA', $this->gridCard(2));
    }

    public function test_the_only_difference_the_switch_makes_to_a_row_is_the_country_text(): void
    {
        $off = [$this->searchRows(), $this->gridCard(1)];
        $this->scopeCodesByCountry();
        $on = [$this->searchRows(), $this->gridCard(1)];

        $this->assertSame($off[0], str_replace([' · KW', ' · SA'], '', $on[0]));
        $this->assertSame($off[1], str_replace(' · KW', '', $on[1]));
    }

    public function test_a_missing_column_draws_nothing(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertStringNotContainsString(' · ', $this->searchRows());
    }

    public function test_the_tag_is_empty_for_a_product_whose_country_is_unknown(): void
    {
        $this->scopeCodesByCountry();
        DB::table('products')->where('id', 1)->update(['storefront_country' => '']);

        $this->assertSame('', app(ProductCodeScope::class)->countryTag(Product::withoutGlobalScopes()->findOrFail(1)));
        $this->assertSame(' · SA', app(ProductCodeScope::class)->countryTag(Product::withoutGlobalScopes()->findOrFail(2)));
    }
}
