<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeScope;
use App\Services\ProductService;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\Support\RestoresLanguageFiles;
use Tests\Support\RunsBulkImport;
use Tests\TestCase;

/**
 * A9, B1, B2 across the six countries: the bulk import generates a code free in the country it imports into, which is the table default whatever
 * the default storefront is, and saves its rows there. A code another country holds may be generated and saved; a code the default country holds is
 * never generated twice there. With the switch off a code any country holds is skipped, as today.
 */
class SixCountryImportGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix, RestoresLanguageFiles, RunsBulkImport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->createImportTables();
        Cache::flush();
        foreach ($this->matrixCountries() as $country) {
            $this->insertCodedProduct(code: $this->generatedCodeOf($country), country: $country);
        }
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** The import generates codes of six capital letters and digits. */
    private function generatedCodeOf(string $country): string
    {
        return 'IMP' . str_pad((string) ((int) array_search($country, $this->matrixCountries(), true) + 1), 3, '0', STR_PAD_LEFT);
    }

    /** @return list<string> the codes the same country holds more than once: the one thing the switch must never allow */
    private function codesHeldTwiceInACountry(): array
    {
        return DB::table('products')->select('storefront_country', 'code')->groupBy('storefront_country', 'code')->havingRaw('count(*) > 1')->get()
            ->map(static fn (object $row): string => $row->storefront_country . ':' . $row->code)->all();
    }

    public function test_with_the_switch_on_the_generator_skips_a_code_only_when_the_default_country_holds_it(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            Str::createRandomStringsUsingSequence([strtolower($this->generatedCodeOf($country)), 'fresh1']);

            $generated = app(ProductService::class)->getUniqueProductSKUCode();

            $expected = $country === ProductCodeScope::TABLE_DEFAULT_COUNTRY ? 'FRESH1' : $this->generatedCodeOf($country);
            $this->matrixCase($failures, true, $generated === $expected, "held in {$country}: generated {$generated}, expected {$expected}");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_the_default_storefront_does_not_move_the_country_the_generator_checks(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.default_country' => 'KW']);
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            Str::createRandomStringsUsingSequence([strtolower($this->generatedCodeOf($country)), 'fresh1']);

            $generated = app(ProductService::class)->getUniqueProductSKUCode();

            $expected = $country === ProductCodeScope::TABLE_DEFAULT_COUNTRY ? 'FRESH1' : $this->generatedCodeOf($country);
            $this->matrixCase($failures, true, $generated === $expected, "held in {$country}, the default storefront is KW: generated {$generated}, expected {$expected}");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_the_generator_skips_a_code_any_country_holds(): void
    {
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            Str::createRandomStringsUsingSequence([strtolower($this->generatedCodeOf($country)), 'fresh1']);

            $generated = app(ProductService::class)->getUniqueProductSKUCode();

            $this->matrixCase($failures, true, $generated === 'FRESH1', "held in {$country}: generated {$generated}, expected FRESH1");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_an_import_saves_a_code_another_country_holds_in_the_default_country_and_never_twice_there(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            $code = $this->generatedCodeOf($country);
            $this->generateTheseCodes([$code]);

            $this->runImport(['1']);

            $savedInDefault = DB::table('products')->where('code', $code)->where('storefront_country', ProductCodeScope::TABLE_DEFAULT_COUNTRY)->count();
            $this->matrixCase($failures, true, $savedInDefault === 1, "held in {$country}: the default country holds the code {$savedInDefault} time(s) after the import, expected once");
            $this->matrixCase($failures, true, DB::table('products')->where('code', $code)->where('storefront_country', $country)->count() === 1, "held in {$country}: the original is still there, once");
            $this->matrixCase($failures, true, DB::table('products')->where('name', 'Imported 1')->where('storefront_country', '!=', ProductCodeScope::TABLE_DEFAULT_COUNTRY)->doesntExist(), "held in {$country}: an imported row is saved in no other country");
            DB::table('products')->where('name', 'Imported 1')->delete();
        }
        $this->matrixCase($failures, true, $this->codesHeldTwiceInACountry() === [], 'no country holds a code twice: ' . implode(', ', $this->codesHeldTwiceInACountry()));

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_an_import_never_saves_a_code_any_country_holds(): void
    {
        $failures = [];
        foreach ($this->matrixCountries() as $country) {
            $code = $this->generatedCodeOf($country);
            $this->generateTheseCodes([$code]);

            $this->runImport(['1']);

            $this->matrixCase($failures, true, DB::table('products')->where('code', $code)->count() === 1, "held in {$country}: the import did not repeat the code");
            DB::table('products')->where('name', 'Imported 1')->delete();
        }

        $this->assertMatrixHolds($failures);
    }
}
