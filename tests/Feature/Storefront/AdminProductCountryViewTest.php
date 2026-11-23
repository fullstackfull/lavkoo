<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Tests\Support\BuildsStorefrontCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * What the admin sees of a product's country. The form shows the choice for a new product and the stored country, read only, for an existing one; the list
 * shows the country and can be filtered by it. None of it is drawn while storefronts are off.
 */
class AdminProductCountryViewTest extends TestCase
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
        $this->runStorefrontCountryMigration();
        $this->buildDefaultCurrency();
        StorefrontCatalog::flush();
    }

    /** The one currency the admin pages price in. */
    private function buildDefaultCurrency(): void
    {
        Schema::dropIfExists('currencies');
        Schema::create('currencies', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('symbol');
            $table->string('code');
            $table->double('exchange_rate');
            $table->boolean('status')->default(true);
        });
        DB::table('currencies')->insert(['id' => 1, 'name' => 'Saudi riyal', 'symbol' => 'SAR', 'code' => 'SAR', 'exchange_rate' => 1]);
        DB::table('business_settings')->insert([
            ['type' => 'system_default_currency', 'value' => '1'],
            ['type' => 'decimal_point_settings', 'value' => '2'],
            ['type' => 'currency_symbol_position', 'value' => 'left'],
        ]);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('ai_settings');
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    private function switchStorefrontsOn(): void
    {
        config(['gcc.storefronts.enabled' => true]);
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

    private function renderCountryField(array $data = []): string
    {
        return trim(view('admin-views.product.partials._storefront-country', $data)->render());
    }

    private function seedProducts(): void
    {
        $this->insertCatalogProduct(id: 1, name: 'Saudi serum');
        $this->insertCatalogProduct(id: 2, name: 'Kuwaiti serum', country: 'KW');
    }

    /** The number of column headers in the list's table. */
    private function columnHeaders(string $html): int
    {
        return preg_match_all('/<th[\s>]/', $html);
    }

    // ---- the product form ---------------------------------------------------------------------------------------------------------

    public function test_a_new_product_form_offers_every_configured_country_and_selects_the_default_one(): void
    {
        $this->switchStorefrontsOn();

        $html = $this->renderCountryField();

        $this->assertStringContainsString('name="storefront_country"', $html);
        foreach (['SA · SAR', 'AE · AED', 'KW · KWD', 'QA · QAR', 'BH · BHD', 'OM · OMR'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertMatchesRegularExpression('/<option value="SA"\s+selected>/', $html);
        $this->assertSame(1, substr_count($html, 'selected'));
    }

    public function test_a_choice_that_failed_validation_is_selected_again(): void
    {
        $this->switchStorefrontsOn();

        $session = $this->app['session']->driver();
        $session->put('_old_input', ['storefront_country' => 'KW']);
        request()->setLaravelSession($session);

        $html = $this->renderCountryField();

        $this->assertMatchesRegularExpression('/<option value="KW"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="SA"\s+selected>/', $html);
    }

    public function test_an_existing_product_shows_its_country_and_offers_no_way_to_change_it(): void
    {
        $this->switchStorefrontsOn();
        $this->seedProducts();

        $html = $this->renderCountryField(['product' => Product::withoutGlobalScopes()->findOrFail(2)]);

        $this->assertStringContainsString('KW · KWD', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('name="storefront_country"', $html);
    }

    public function test_the_gallery_copy_of_a_product_is_a_new_product_that_starts_in_the_country_it_was_copied_from(): void
    {
        $this->switchStorefrontsOn();
        $this->seedProducts();
        request()->merge(['product-gallery' => '1']);

        $html = $this->renderCountryField(['product' => Product::withoutGlobalScopes()->findOrFail(2)]);

        $this->assertStringContainsString('name="storefront_country"', $html);
        $this->assertMatchesRegularExpression('/<option value="KW"\s+selected>/', $html);
    }

    public function test_a_product_in_a_country_that_is_no_longer_configured_is_still_shown(): void
    {
        $this->switchStorefrontsOn();
        $this->insertCatalogProduct(id: 3, name: 'Old import', country: 'ZZ');

        $html = $this->renderCountryField(['product' => Product::withoutGlobalScopes()->findOrFail(3)]);

        $this->assertStringContainsString('value="ZZ"', $html);
    }

    public function test_the_form_draws_nothing_while_countries_are_not_tracked(): void
    {
        $this->assertSame('', $this->renderCountryField());
        $this->assertSame('', $this->renderCountryField(['product' => new Product()]));
    }

    /** @return array<string, mixed> what the general setup of the product form reads from its page */
    private function productFormData(array $extra = []): array
    {
        return $extra + [
            'categories' => collect(), 'brands' => collect(), 'brandSetting' => 0, 'digitalProductSetting' => 0,
            'digitalProductAuthors' => collect(), 'publishingHouseList' => collect(),
            'productAuthorIds' => [], 'productPublishingHouseIds' => [],
        ];
    }

    private function buildAiSettings(): void
    {
        Schema::dropIfExists('ai_settings');
        Schema::create('ai_settings', function ($table) {
            $table->id();
            $table->boolean('status')->default(false);
            $table->string('api_key')->nullable();
        });
    }

    public function test_the_add_form_asks_for_the_country_while_countries_are_tracked_and_not_otherwise(): void
    {
        $this->buildAiSettings();

        $untracked = view('admin-views.product.add._general-setup', $this->productFormData())->render();

        $this->switchStorefrontsOn();
        $tracked = view('admin-views.product.add._general-setup', $this->productFormData())->render();

        $this->assertStringNotContainsString('storefront_country', $untracked);
        $this->assertStringContainsString('name="storefront_country"', $tracked);
    }

    public function test_the_edit_form_shows_the_country_of_the_product_while_countries_are_tracked_and_not_otherwise(): void
    {
        $this->buildAiSettings();
        $this->seedProducts();
        $product = Product::withoutGlobalScopes()->findOrFail(2)->setRelation('tags', collect());

        $untracked = view('admin-views.product.update._general-setup', $this->productFormData(['product' => $product]))->render();

        $this->switchStorefrontsOn();
        $tracked = view('admin-views.product.update._general-setup', $this->productFormData(['product' => $product]))->render();

        $this->assertStringNotContainsString('storefront-country', $untracked);
        $this->assertStringContainsString('KW · KWD', $tracked);
        $this->assertStringNotContainsString('name="storefront_country"', $tracked, 'an existing product cannot be sent to another country');
    }

    // ---- the list -----------------------------------------------------------------------------------------------------------------

    public function test_the_list_gets_a_country_column_while_countries_are_tracked_and_not_otherwise(): void
    {
        $this->seedProducts();

        $untracked = $this->renderList();

        $this->switchStorefrontsOn();
        $tracked = $this->renderList();

        $this->assertSame($this->columnHeaders($untracked) + 1, $this->columnHeaders($tracked));
        $this->assertDoesNotMatchRegularExpression('#>\s*KW\s*</td>#', $untracked);
        $this->assertMatchesRegularExpression('#>\s*KW\s*</td>#', $tracked);
        $this->assertMatchesRegularExpression('#>\s*SA\s*</td>#', $tracked);
    }

    public function test_the_list_filter_offers_the_configured_countries_and_keeps_the_ones_asked_for_checked(): void
    {
        $this->seedProducts();
        $this->switchStorefrontsOn();
        request()->merge(['filter_storefront_countries' => ['KW']]);

        $html = $this->renderList();

        $this->assertSame(6, substr_count($html, 'name="filter_storefront_countries[]"'));
        $this->assertMatchesRegularExpression('/value="KW"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="SA"\s+checked/', $html);
    }

    public function test_a_filter_written_as_text_in_the_address_does_not_break_the_list(): void
    {
        $this->seedProducts();
        $this->switchStorefrontsOn();
        request()->merge(['filter_storefront_countries' => 'KW']);

        $html = $this->renderList();

        $this->assertMatchesRegularExpression('/value="KW"\s+checked/', $html);
    }

    public function test_the_list_has_no_country_filter_while_countries_are_not_tracked(): void
    {
        $this->seedProducts();
        request()->merge(['filter_storefront_countries' => ['KW']]);

        $this->assertStringNotContainsString('filter_storefront_countries', $this->renderList());
    }

    public function test_the_words_the_admin_reads_are_in_the_language_files(): void
    {
        $this->switchStorefrontsOn();
        $before = array_map(fn (string $locale) => md5_file(base_path("resources/lang/{$locale}/new-messages.php")), ['en', 'sa', 'ae']);

        $this->seedProducts();
        $this->renderList();
        $this->renderCountryField();

        $after = array_map(fn (string $locale) => md5_file(base_path("resources/lang/{$locale}/new-messages.php")), ['en', 'sa', 'ae']);
        $this->assertSame($before, $after, 'rendering added an untranslated key to a language file');
    }
}
