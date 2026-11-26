<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsProductCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\PlatformWriteScanner;
use Tests\TestCase;

/**
 * C2, C3 (the seller's side): a seller's panel lists the seller's own products, and every path that creates a seller product saves it in the table
 * default country, so two countries cannot hold a product of one code in a seller's list and nothing there needs a country label. These tests pin
 * the reason (the only place a request can choose a product's country is the admin add) and the result (no seller view draws a country, and the
 * point of sale rows and cards of a seller are the same with the switch on and off). The day a seller can hold products in two countries (a
 * copy-to-country tool, GCC-14b slice 3) the pins fail and name what has to be labelled.
 */
class SellerSurfacesCountryTest extends TestCase
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
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved']);
        DB::table('shops')->insert(['id' => 5, 'seller_id' => 5, 'author_type' => 'vendor', 'name' => 'Seller shop']);
        Cache::flush();
        $this->insertProduct(id: 1, name: 'Serum', userId: 5, addedBy: 'seller', extra: ['code' => 'SKU100', 'storefront_country' => 'SA']);
        $this->insertProduct(id: 2, name: 'Serum', userId: 5, addedBy: 'seller', extra: ['code' => 'SKU100', 'storefront_country' => 'KW']);
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

        return view('vendor-views.pos.partials._search-product', compact('products'))->render();
    }

    private function gridCard(int $id): string
    {
        $product = Product::withoutGlobalScopes()->findOrFail($id)->setRelation('clearanceSale', null);

        return view('vendor-views.pos.partials._single-product', compact('product'))->render();
    }

    public function test_the_point_of_sale_rows_and_cards_of_a_seller_are_the_same_with_the_switch_on_and_off(): void
    {
        $off = [$this->searchRows(), $this->gridCard(1), $this->gridCard(2)];
        $this->scopeCodesByCountry();

        $this->assertSame($off, [$this->searchRows(), $this->gridCard(1), $this->gridCard(2)]);
        $this->assertStringContainsString('Serum', $off[0]);
    }

    public function test_no_view_of_the_seller_panel_draws_a_country(): void
    {
        $offenders = [];
        $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/vendor-views'), FilesystemIterator::SKIP_DOTS));
        foreach ($views as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php') && preg_match('/countryTag\(|storefront_country|ProductCodeScope/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), strlen(resource_path('views/')));
            }
        }

        $this->assertSame([], $offenders, 'a seller\'s list cannot hold two countries\' products; the day it can, these views need the label (and this test the new list)');
    }

    // ---- why a seller's list holds one country -----------------------------------------------------------------------------------

    public function test_a_product_a_seller_adds_is_saved_in_the_table_default_country_whatever_country_the_request_names(): void
    {
        $this->assertSame('SA', ProductCodeScope::TABLE_DEFAULT_COUNTRY);
        $scope = app(ProductCodeScope::class);

        foreach (ProductCountry::options() as $country) {
            $this->assertSame(ProductCodeScope::TABLE_DEFAULT_COUNTRY, $scope->countryOfNewProduct(addedBy: 'seller', requestedCountry: $country), "a request for {$country}");
        }
        $this->assertSame(ProductCodeScope::TABLE_DEFAULT_COUNTRY, $scope->countryOfNewProduct(addedBy: 'seller'));
    }

    /**
     * Who may turn a REQUEST into the country of a new product: only ProductCountry::newProductAttributes(), and only the two places that
     * ask it. The call is matched with its class, not by the bare method name, because two other services answer to that name and neither
     * reads a request -- SellerMarketScope::newProductAttributes() gives the market the signed-in seller was GRANTED, which the middleware
     * checked before the controller ran (K-04), and LegalEntityRegistry::newProductAttributes() gives the company registered for a country
     * somebody has already chosen. A file that calls the qualified one and is not listed here is a new way for a request to name a country.
     */
    public function test_only_the_admin_add_and_the_check_that_mirrors_it_let_a_request_choose_the_country_of_a_product(): void
    {
        $callers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
        foreach ([...iterator_to_array($files, false), ...iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS)), false)] as $file) {
            // Comments STRIPPED, and it is load-bearing: App\Services\Catalog\BulkImportCountry's docblock NAMES
            // ProductCountry::newProductAttributes() in order to say that it deliberately does NOT reuse that fallback -- a code nobody
            // sells in is refused rather than corrected. A scan reading its own explanation filed it as a surface that lets a request
            // choose a country, which is the opposite of what the file says and does, and adding it to the list below would have recorded
            // that falsehood in the one place somebody looks to find out which surfaces may choose a country.
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/ProductCountry::newProductAttributes\(|public static function newProductAttributes\(/', PlatformWriteScanner::codeOf((string) file_get_contents($file->getPathname()))) === 1) {
                $callers[] = substr($file->getPathname(), strlen(base_path()) + 1);
            }
        }
        sort($callers);
        $callers = array_values(array_diff($callers, ['app/Rules/NewProductCountryInStaffScope.php']));

        // Unchanged at three, which is the point: the set of surfaces that may choose a product's country did NOT grow when the bulk
        // import gained its country row, because that service asks StaffCountryScope and refuses, and never reaches this fallback.
        $this->assertSame(['app/Services/Catalog/ProductCodeScope.php', 'app/Services/ProductService.php', 'app/Services/Storefront/ProductCountry.php'], $callers);
        $this->assertStringContainsString("\$addedBy == 'admin'\n            ? ProductCountry::newProductAttributes(", (string) file_get_contents(app_path('Services/ProductService.php')), 'only an admin add takes the country of the request');
    }

    /**
     * The other half of that sentence, which a merge had thrown away: a seller's add is the market they were granted, and the panel and the
     * seller app agree about it. ProductService held both lanes' version of the one assignment, the second overwriting the first, so the
     * panel saved the table's default country for every seller while the seller app saved their market -- the same seller, the same product,
     * two countries depending on which screen they used, and the company that follows from the country gone with it.
     */
    public function test_a_sellers_add_saves_the_market_they_were_granted_on_the_panel_as_it_does_in_the_seller_app(): void
    {
        $panel = (string) file_get_contents(app_path('Services/ProductService.php'));
        $sellerApp = (string) file_get_contents(app_path('Http/Controllers/RestAPI/v3/seller/ProductController.php'));

        $this->assertStringContainsString(': $this->sellerMarkets->newProductAttributes();', $panel, 'the panel saves the seller\'s granted market');
        $this->assertStringContainsString('$this->sellerMarkets->newProductAttributes()', $sellerApp, 'and so does the seller app');
        $this->assertSame(
            1,
            preg_match_all('/\$countryAttributes\s*=/', $panel),
            'the country of a new product is worked out once: a second assignment of it is a merge that kept both lanes\' version',
        );
    }
}
