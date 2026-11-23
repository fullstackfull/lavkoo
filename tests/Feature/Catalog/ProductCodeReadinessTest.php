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
        $this->assertStringContainsString('No code or barcode is shared by two countries', $output);
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

        // No assertion that the registry is non-empty: the branch at the end of this method already covers the case where every
        // site has been reached, and the two together contradicted each other once that became true. The command must name every
        // site the registry still lists, and say so plainly when the list is empty -- which is what it now is, since the
        // country-scoping lanes covered the last of them.
        foreach (ProductCodeSites::notCovered() as $site) {
            $this->assertStringContainsString($site['id'], $output);
            $this->assertStringContainsString($site['where'], $output);
        }
        if (ProductCodeSites::notCovered() === []) {
            $this->assertStringContainsString('Every site of the survey is covered, labelled or id-keyed', $output);
        }
    }

    public function test_the_output_counts_the_sites_of_the_survey_by_status(): void
    {
        $this->cleanCatalog();
        $counts = ProductCodeSites::counts();

        $output = $this->run_command()[1];

        $this->assertSame(count(ProductCodeSites::all()), array_sum($counts), 'every site is in exactly one count');
        $this->assertStringContainsString(
            sprintf('Survey of %d lookup sites: %d covered, %d labelled (a picker names the country), %d not needed (id-keyed), %d still open.', count(ProductCodeSites::all()), $counts['covered'], $counts['labelled'], $counts['id_keyed'], $counts['open']),
            $output,
        );
    }

    public function test_the_counts_agree_with_the_status_of_each_site(): void
    {
        $byStatus = array_count_values(array_column(ProductCodeSites::all(), 'status'));
        $counts = ProductCodeSites::counts();

        $this->assertSame($byStatus[ProductCodeSites::COVERED] ?? 0, $counts['covered']);
        $this->assertSame($byStatus[ProductCodeSites::LABELLED] ?? 0, $counts['labelled']);
        $this->assertSame($byStatus[ProductCodeSites::ID_KEYED] ?? 0, $counts['id_keyed']);
        $this->assertSame(($byStatus[ProductCodeSites::PARTIAL] ?? 0) + ($byStatus[ProductCodeSites::NOT_COVERED] ?? 0) + ($byStatus[ProductCodeSites::DEFERRED] ?? 0), $counts['open']);
    }

    public function test_an_open_site_is_a_partial_a_not_covered_or_a_deferred_one_and_slice_3_left_none(): void
    {
        $open = array_column(ProductCodeSites::notCovered(), 'status');

        $this->assertSame([], array_diff($open, [ProductCodeSites::PARTIAL, ProductCodeSites::NOT_COVERED, ProductCodeSites::DEFERRED]));
        $this->assertSame([], $open, 'slice 3 closed the channel matcher, the provider connections and the copy to another country');
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
        $this->assertSame([['kind' => 'code', 'value' => 'SKU100', 'countries' => ['KW', 'SA']]], app(ProductCodeReadiness::class)->report()['across_countries'], 'only what two countries share is listed');
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
