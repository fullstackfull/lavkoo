<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeReadiness;
use App\Services\Catalog\ProductCodeSites;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * `php artisan gcc:catalog:code-readiness`: what an operator reads before GCC_COUNTRY_SCOPED_CODES is turned on. It changes nothing.
 */
class ProductCodeReadinessTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return array{0: int, 1: string} */
    private function run_command(): array
    {
        $exit = Artisan::call('gcc:catalog:code-readiness');

        return [$exit, Artisan::output()];
    }

    private function cleanCatalog(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $this->insertCodedProduct(code: 'SKU200', country: 'SA');
        $this->insertCodedProduct(code: 'SKU300', country: 'KW', barcode: '6290000000001');
    }

    public function test_a_clean_catalog_is_ready_and_the_counts_are_per_country(): void
    {
        $this->cleanCatalog();

        [$exit, $output] = $this->run_command();

        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/SA\s+2/', $output);
        $this->assertMatchesRegularExpression('/KW\s+1/', $output);
        $this->assertStringContainsString('No product has an empty country', $output);
        $this->assertStringContainsString('No code or barcode is held twice inside one country', $output);
    }

    public function test_it_reports_the_state_of_the_switch_and_changes_nothing(): void
    {
        $this->cleanCatalog();
        $before = DB::table('products')->orderBy('id')->get()->all();

        $off = $this->run_command()[1];
        $this->scopeCodesByCountry();
        $on = $this->run_command()[1];

        $this->assertStringContainsString('Country-scoped codes: off', $off);
        $this->assertStringContainsString('Country-scoped codes: on', $on);
        $this->assertEquals($before, DB::table('products')->orderBy('id')->get()->all());
    }

    public function test_every_site_that_still_treats_a_code_as_one_product_is_listed_with_its_id(): void
    {
        $this->cleanCatalog();

        $output = $this->run_command()[1];

        $this->assertNotEmpty(ProductCodeSites::notCovered(), 'the registry keeps the open sites');
        foreach (ProductCodeSites::notCovered() as $site) {
            $this->assertStringContainsString($site['id'], $output);
            $this->assertStringContainsString($site['where'], $output);
        }
    }

    public function test_a_product_without_a_country_makes_the_catalog_not_ready(): void
    {
        $this->cleanCatalog();
        $this->insertCodedProduct(code: 'SKU400', country: '');

        [$exit, $output] = $this->run_command();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('1 product has an empty country', $output);
    }

    public function test_the_report_counts_the_products_without_a_country_and_is_not_ready(): void
    {
        $this->cleanCatalog();
        DB::table('products')->where('code', 'SKU200')->update(['storefront_country' => '']);

        $report = app(ProductCodeReadiness::class)->report();

        $this->assertSame(1, $report['empty_country']);
        $this->assertFalse($report['ready']);
    }

    public function test_a_code_held_twice_inside_one_country_makes_the_catalog_not_ready(): void
    {
        $this->cleanCatalog();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');

        [$exit, $output] = $this->run_command();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('SKU100', $output);
        $this->assertStringContainsString('code', $output);
    }

    public function test_a_barcode_held_twice_inside_one_country_makes_the_catalog_not_ready(): void
    {
        $this->cleanCatalog();
        $this->insertCodedProduct(code: 'SKU500', country: 'SA', barcode: '6291234567890');

        [$exit, $output] = $this->run_command();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('6291234567890', $output);
    }

    public function test_a_code_shared_by_two_countries_is_listed_but_does_not_block(): void
    {
        $this->cleanCatalog();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        [$exit, $output] = $this->run_command();

        $this->assertSame(0, $exit, 'a shared code is what the switch allows');
        $this->assertStringContainsString('SKU100', $output);
        $this->assertStringContainsString('SA', $output);
        $this->assertStringContainsString('KW', $output);
    }

    public function test_a_country_that_is_not_configured_is_listed(): void
    {
        $this->cleanCatalog();
        $this->insertCodedProduct(code: 'SKU600', country: 'XX');

        $report = app(ProductCodeReadiness::class)->report();

        $this->assertSame([['country' => 'XX', 'products' => 1]], $report['unconfigured_countries']);
    }

    public function test_before_the_migration_it_says_so_and_is_not_ready(): void
    {
        $this->cleanCatalog();
        $this->dropStorefrontCountryColumn();

        [$exit, $output] = $this->run_command();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('storefront_country', $output);
        $this->assertStringContainsString('migration', $output);
    }

    public function test_a_table_without_a_barcode_column_is_still_checked_by_code(): void
    {
        $this->cleanCatalog();
        Schema::table('products', fn ($table) => $table->dropColumn('barcode'));

        [$exit] = $this->run_command();

        $this->assertSame(0, $exit);
    }

    public function test_a_long_list_of_duplicates_is_cut_and_says_how_many_more(): void
    {
        for ($number = 1; $number <= 60; $number++) {
            $this->insertCodedProduct(code: 'DUP' . $number, country: 'SA');
            $this->insertCodedProduct(code: 'DUP' . $number, country: 'SA');
        }

        $output = $this->run_command()[1];

        $this->assertStringContainsString('and 10 more', $output);
    }
}
