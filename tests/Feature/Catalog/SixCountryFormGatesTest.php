<?php

namespace Tests\Feature\Catalog;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Requests\ProductAddRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Services\Storefront\ProductCountry;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\TestCase;

/**
 * A1 to A4 across the six countries: the admin and vendor add and edit forms. For every ordered pair of different countries the same SKU and the
 * same barcode can exist in both and be saved; inside one country they are refused; with the switch off every pair is refused, as today.
 */
class SixCountryFormGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        config(['gcc.storefronts.enabled' => true]);
        $this->insertHoldersOfEveryPair();
        $this->insertEditors();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @var array<string, array{first: int, second: int}> the ids of the two products of each country an edit is made on */
    private array $editors = [];

    /** Two products in every country with codes of their own: the ones an edit is made on. */
    private function insertEditors(): void
    {
        foreach ($this->matrixCountries() as $country) {
            foreach (['first' => ['9', 'First editor'], 'second' => ['6', 'Second editor']] as $which => [$series, $name]) {
                $this->editors[$country][$which] = (int) DB::table('products')->insertGetId([
                    'name' => "{$name} {$country}", 'code' => $this->codeOfCountry($country, $series), 'barcode' => $this->barcodeOfCountry($country, $series), 'storefront_country' => $country,
                ]);
            }
        }
    }

    private function editor(string $country, string $which = 'first'): int
    {
        return $this->editors[$country][$which];
    }

    /** @param array<string, mixed> $input @return list<string> the fields the form refuses */
    private function addErrors(array $input, string $panel = 'admin'): array
    {
        $request = ProductAddRequest::create("/{$panel}/products/add", 'POST', $input);

        return Validator::make($request->all(), $request->rules())->errors()->keys();
    }

    /** @param array<string, mixed> $input @return list<string> */
    private function updateErrors(int $productId, array $input): array
    {
        $base = Request::create("/admin/products/update/{$productId}", 'POST', $input);
        $request = ProductUpdateRequest::createFrom($base, new ProductUpdateRequest(app(ProductRepositoryInterface::class)));
        $request->setRouteResolver(static fn () => new class($productId) {
            public function __construct(private readonly int $id)
            {
            }

            public function parameter(string $name, mixed $default = null): int
            {
                return $this->id;
            }
        });

        return Validator::make($request->all(), $request->rules())->errors()->keys();
    }

    /** @return array{code: string, barcode: string} */
    private function pairInput(string $from, string $to, ?string $country = null): array
    {
        return ['code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to)] + ($country === null ? [] : ['storefront_country' => $country]);
    }

    private function refuses(array $errors): bool
    {
        return in_array('code', $errors, true) && in_array('barcode', $errors, true);
    }

    private function acceptsBoth(array $errors): bool
    {
        return !in_array('code', $errors, true) && !in_array('barcode', $errors, true);
    }

    public function test_the_matrix_is_built_from_the_configured_countries_and_covers_the_six_of_the_launch(): void
    {
        $countries = $this->matrixCountries();

        $this->assertEqualsCanonicalizing(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], array_intersect(['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], $countries));
        $this->assertSame($countries, ProductCountry::options());
        $this->assertCount(count($countries) * (count($countries) - 1), $this->orderedPairs());
        $this->assertCount(count($this->orderedPairs()), array_unique(array_map(fn (array $pair): string => $this->codeOfPair(...$pair), $this->orderedPairs())), 'every pair has a code of its own');
        $this->assertCount(count($this->orderedPairs()), array_unique(array_map(fn (array $pair): string => $this->barcodeOfPair(...$pair), $this->orderedPairs())), 'and a barcode of its own');
    }

    // ---- A1, A2: the admin add form, the admin choosing the country --------------------------------------------------------------

    public function test_with_the_switch_on_the_add_form_accepts_a_pair_in_the_other_country_saves_it_and_then_refuses_it_inside_each_country_that_holds_it(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $case = "held in {$from}, added in {$to}";
            $this->matrixCase($failures, true, $this->acceptsBoth($this->addErrors($this->pairInput($from, $to, $to))), "{$case}: the other country may hold the same code and barcode");
            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to, $from))), "{$case}: the country that holds them refuses them");

            $savedIn = $this->countrySavedForAdmin($to);
            $failures = [...$failures, ...($savedIn === $to ? [] : ["{$case}: the add path would save the product in {$savedIn}, not {$to}"])];
            DB::table('products')->insert(['name' => "Added in {$to}", 'code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to), 'storefront_country' => $savedIn]);

            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to, $to))), "{$case}: after the save, {$to} refuses them too");
            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to, $from))), "{$case}: after the save, {$from} still refuses them");
            foreach (array_diff($this->matrixCountries(), [$from, $to]) as $other) {
                $this->matrixCase($failures, true, $this->acceptsBoth($this->addErrors($this->pairInput($from, $to, $other))), "{$case}: a third country, {$other}, may still take them");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_the_add_form_refuses_every_pair_whatever_country_is_chosen(): void
    {
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to, $to))), "held in {$from}, added in {$to}: the global rule");
            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to))), "held in {$from}, added with no country: the global rule");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_an_add_without_a_choice_is_checked_in_the_default_storefront_only(): void
    {
        $this->scopeCodesByCountry();
        $default = ProductCountry::defaultCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, $from === $default, $this->refuses($this->addErrors($this->pairInput($from, $to))), "held in {$from}, added with no country (the default is {$default})");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_an_add_that_names_a_country_that_is_not_configured_is_checked_in_the_default_storefront(): void
    {
        $this->scopeCodesByCountry();
        $default = ProductCountry::defaultCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, $from === $default, $this->refuses($this->addErrors($this->pairInput($from, $to, 'XX'))), "held in {$from}, added in XX");
        }

        $this->assertMatrixHolds($failures);
    }

    // ---- A1, A2 for a vendor ---------------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_a_vendor_add_is_checked_in_the_table_default_country_whatever_country_the_request_names(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, $from === 'SA', $this->refuses($this->addErrors($this->pairInput($from, $to, $to), panel: 'vendor')), "held in {$from}, a vendor asks for {$to}");
            $this->matrixCase($failures, $from === 'SA', $this->refuses($this->addErrors($this->pairInput($from, $to), panel: 'vendor')), "held in {$from}, a vendor names no country");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_a_vendor_add_refuses_every_pair(): void
    {
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, true, $this->refuses($this->addErrors($this->pairInput($from, $to, $to), panel: 'vendor')), "held in {$from}, a vendor asks for {$to}");
        }

        $this->assertMatrixHolds($failures);
    }

    // ---- A3, A4: the edit form ---------------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_an_edit_may_take_the_code_and_barcode_another_country_holds_and_is_then_refused_to_a_neighbour_in_its_own_country(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $case = "held in {$from}, taken by a product of {$to}";
            $editor = $this->editor($to);
            $this->matrixCase($failures, true, $this->acceptsBoth($this->updateErrors($editor, $this->pairInput($from, $to))), "{$case}: the edit is accepted");
            $this->matrixCase($failures, true, $this->refuses($this->updateErrors($this->editor($from), $this->pairInput($from, $to))), "{$case}: a product of {$from} cannot take what its neighbour holds");

            DB::table('products')->where('id', $editor)->update(['code' => $this->codeOfPair($from, $to), 'barcode' => $this->barcodeOfPair($from, $to)]);

            $this->matrixCase($failures, true, $this->acceptsBoth($this->updateErrors($editor, $this->pairInput($from, $to))), "{$case}: after the save the product keeps its own values valid");
            $this->matrixCase($failures, true, $this->refuses($this->updateErrors($this->editor($to, 'second'), $this->pairInput($from, $to))), "{$case}: after the save a neighbour of {$to} is refused");
            foreach (array_diff($this->matrixCountries(), [$from, $to]) as $other) {
                $this->matrixCase($failures, true, $this->acceptsBoth($this->updateErrors($this->editor($other), $this->pairInput($from, $to))), "{$case}: a product of a third country, {$other}, may still take them");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_an_edit_is_refused_every_pair(): void
    {
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, true, $this->refuses($this->updateErrors($this->editor($to), $this->pairInput($from, $to))), "held in {$from}, taken by a product of {$to}: the global rule");
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_an_edit_is_checked_in_the_country_of_the_product_whatever_country_the_request_names(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->orderedPairs() as [$from, $to]) {
            $this->matrixCase($failures, true, $this->acceptsBoth($this->updateErrors($this->editor($to), $this->pairInput($from, $to, $from))), "held in {$from}, a product of {$to} asks for {$from}: its own country decides");
            $this->matrixCase($failures, true, $this->refuses($this->updateErrors($this->editor($from), $this->pairInput($from, $to, $to))), "held in {$from}, a product of {$from} asks for {$to}: its own country decides");
        }

        $this->assertMatrixHolds($failures);
    }
}
