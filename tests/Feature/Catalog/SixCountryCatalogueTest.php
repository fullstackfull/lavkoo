<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeReadiness;
use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\TestCase;

/**
 * The six-country catalogue itself. The matrix tests build their cases from the configured countries; these tests pin that the matrix is not vacuous
 * (every launch country is configured, every ordered pair has a code and a barcode of its own), what the readiness command says about a catalogue in
 * which every ordered pair shares a code, and the label a picker draws for each country.
 */
class SixCountryCatalogueTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix;

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

    /** Every ordered pair shares its code and its barcode: one product in each of its two countries. */
    private function shareEveryPair(): void
    {
        foreach ($this->orderedPairs() as [$from, $to]) {
            foreach ([$from, $to] as $country) {
                $this->insertCodedProduct(code: $this->codeOfPair($from, $to), country: $country, barcode: $this->barcodeOfPair($from, $to));
            }
        }
    }

    public function test_the_matrix_covers_the_six_countries_of_the_launch_and_every_ordered_pair_once(): void
    {
        $countries = $this->matrixCountries();

        $this->assertSame([], array_diff(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $countries), 'a launch country is not configured');
        $this->assertSame($countries, ProductCountry::options());
        $this->assertCount(count($countries) * (count($countries) - 1), $this->orderedPairs());
        $this->assertCount(count($this->orderedPairs()), array_unique(array_map(fn (array $pair): string => implode('>', $pair), $this->orderedPairs())));
        $this->assertCount(count($countries), array_unique(array_map(fn (string $country): string => $this->codeOfCountry($country), $countries)));
        $this->assertSame([], array_intersect(array_map(fn (array $pair): string => $this->codeOfPair(...$pair), $this->orderedPairs()), array_map(fn (string $country): string => $this->codeOfCountry($country), $countries)), 'a code of a pair is never the code of a country');
    }

    public function test_the_readiness_report_of_a_catalogue_in_which_every_pair_shares_a_code_lists_them_and_does_not_block(): void
    {
        $this->shareEveryPair();

        $report = app(ProductCodeReadiness::class)->report();

        $pairs = count($this->orderedPairs());
        $this->assertTrue($report['ready']);
        $this->assertSame([], $report['within_country']);
        $this->assertCount(2 * $pairs, $report['across_countries'], 'a code and a barcode of every pair');
        $this->assertSame(array_fill_keys($this->matrixCountries(), 2 * (count($this->matrixCountries()) - 1)), array_column($report['countries'], 'products', 'country'), 'each country holds the two products of every pair it is in');
        foreach ($report['across_countries'] as $shared) {
            $this->assertCount(2, $shared['countries']);
        }
        $this->assertSame(0, Artisan::call('gcc:catalog:code-readiness'));
    }

    public function test_the_readiness_report_names_a_code_held_twice_inside_one_of_the_six_countries(): void
    {
        foreach ($this->matrixCountries() as $country) {
            DB::table('products')->delete();
            $this->shareEveryPair();
            $this->insertCodedProduct(code: $this->codeOfCountry($country, '4'), country: $country);
            $this->insertCodedProduct(code: $this->codeOfCountry($country, '4'), country: $country);

            $report = app(ProductCodeReadiness::class)->report();

            $this->assertFalse($report['ready'], $country);
            $this->assertSame([['kind' => 'code', 'country' => $country, 'value' => $this->codeOfCountry($country, '4'), 'products' => 2]], $report['within_country'], $country);
            $this->assertSame(1, Artisan::call('gcc:catalog:code-readiness'), $country);
        }
    }

    public function test_an_order_belongs_to_the_country_of_its_lines_and_a_picker_narrowed_to_it_offers_that_countrys_twin_only(): void
    {
        $this->scopeCodesByCountry();
        Schema::dropIfExists('order_details');
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
        });
        $twins = [];
        foreach ($this->matrixCountries() as $country) {
            $twins[$country] = $this->insertCodedProduct(code: 'SHARED-CODE', country: $country);
        }
        $scope = app(ProductCodeScope::class);
        $failures = [];
        foreach ($this->matrixCountries() as $number => $country) {
            DB::table('order_details')->insert(['order_id' => $number + 1, 'product_id' => $twins[$country]]);

            $this->matrixCase($failures, true, $scope->countryOfOrder($number + 1) === $country, "an order of {$country}: its country");
            $offered = $scope->restrictToCountry(DB::table('products')->where('code', 'SHARED-CODE'), $scope->countryOfOrder($number + 1))->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $this->matrixCase($failures, true, $offered === [$twins[$country]], "an order of {$country}: the picker offers the twin of {$country} only");
        }
        foreach ($this->orderedPairs() as $index => [$from, $to]) {
            DB::table('order_details')->insert([['order_id' => 100 + $index, 'product_id' => $twins[$from]], ['order_id' => 100 + $index, 'product_id' => $twins[$to]]]);
            $this->matrixCase($failures, true, $scope->countryOfOrder(100 + $index) === null, "an order with lines of {$from} and {$to}: no country, the picker is not narrowed");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_a_picker_draws_the_code_of_each_country_after_a_name(): void
    {
        $scope = app(ProductCodeScope::class);
        $this->assertSame('', $scope->countryTag((object) ['storefront_country' => 'KW']), 'nothing while the switch is off');
        $this->scopeCodesByCountry();

        foreach ($this->matrixCountries() as $country) {
            $this->assertSame(" · {$country}", $scope->countryTag((object) ['storefront_country' => $country]));
        }
        $this->assertSame('', $scope->countryTag((object) ['storefront_country' => '']));
        $this->assertSame('', $scope->countryTag((object) ['storefront_country' => null]));
    }
}
