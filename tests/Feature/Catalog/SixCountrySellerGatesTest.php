<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\BuildsCountryMatrix;
use Tests\Support\CallsSellerProductApi;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A5 to A8 across the six countries: the seller apps (API v2 and v3) add and edit a product. A product a seller adds is saved in the table default
 * country whatever country is named, so the ordered pairs reduce for an add to the country that holds the code: it is refused when that country is the
 * default one and accepted for the other five. An edit is checked in the country of the product it edits, so it runs over every ordered pair. With the
 * switch off every country's code is refused, as today.
 */
class SixCountrySellerGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog, BuildsCountryMatrix, CallsSellerProductApi, RestoresLanguageFiles;

    /** @var array<string, int> the seller's own product in each country, the one an edit is made on */
    private array $editors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->createSellerTables();
        Cache::flush();
        $this->useTaxIncludedPrices();
        $this->insertHoldersOfEveryPair();
        foreach ($this->matrixCountries() as $country) {
            $this->editors[$country] = (int) DB::table('products')->insertGetId([
                'name' => "Seller product in {$country}", 'code' => $this->codeOfCountry($country, '9'), 'storefront_country' => $country, 'added_by' => 'seller', 'user_id' => 5,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @return array<string, callable(string): list<string>> */
    private function adds(): array
    {
        return ['v2' => fn (string $code): array => $this->v2Add($code), 'v3' => fn (string $code): array => $this->v3Add($code)];
    }

    /** @return array<string, callable(int, string): list<string>> */
    private function updates(): array
    {
        return ['v2' => fn (int $id, string $code): array => $this->v2Update($id, $code), 'v3' => fn (int $id, string $code): array => $this->v3Update($id, $code)];
    }

    /** @return list<array{0: string, 1: string}> a seller add is saved in one country whatever the pair names, so each country that holds a code is asked once, with the code of its first pair */
    private function holdersOnce(): array
    {
        return array_values(array_filter($this->orderedPairs(), fn (array $pair): bool => $pair[1] === (array_values(array_diff($this->matrixCountries(), [$pair[0]]))[0])));
    }

    // ---- A5, A7: add ---------------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_a_seller_add_is_refused_for_the_code_the_default_country_holds_and_accepted_for_the_other_five(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->adds() as $api => $add) {
            foreach ($this->holdersOnce() as [$from, $to]) {
                $this->matrixCase($failures, $from === ProductCodeScope::TABLE_DEFAULT_COUNTRY, in_array('code', $add($this->codeOfPair($from, $to)), true), "{$api}: held in {$from}, a seller adds (saved in the table default)");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_the_default_storefront_does_not_move_where_a_seller_product_is_saved(): void
    {
        $this->scopeCodesByCountry();
        config(['gcc.storefronts.countries.KW.active' => true, 'gcc.storefronts.default_country' => 'KW']);
        $failures = [];
        foreach ($this->adds() as $api => $add) {
            foreach ($this->holdersOnce() as [$from, $to]) {
                $this->matrixCase($failures, $from === ProductCodeScope::TABLE_DEFAULT_COUNTRY, in_array('code', $add($this->codeOfPair($from, $to)), true), "{$api}: held in {$from}, the default storefront is KW, a seller adds (still saved in the table default)");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_code_a_seller_saves_in_the_default_country_is_then_refused_to_the_next_seller_add_and_free_in_the_other_countries(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->holdersOnce() as [$from, $to]) {
            if ($from === ProductCodeScope::TABLE_DEFAULT_COUNTRY) {
                continue;
            }
            $code = $this->codeOfPair($from, $to);
            foreach ($this->adds() as $api => $add) {
                $this->matrixCase($failures, false, in_array('code', $add($code), true), "{$api}: held in {$from}, a seller may take it in the default country");
            }
            DB::table('products')->insert(['name' => 'Saved by a seller', 'code' => $code, 'storefront_country' => ProductCodeScope::TABLE_DEFAULT_COUNTRY, 'added_by' => 'seller', 'user_id' => 5]);
            foreach ($this->adds() as $api => $add) {
                $this->matrixCase($failures, true, in_array('code', $add($code), true), "{$api}: held in {$from} and now in the default country, the next seller add is refused");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_a_seller_add_is_refused_for_every_code_any_country_holds(): void
    {
        $failures = [];
        foreach ($this->adds() as $api => $add) {
            foreach ($this->holdersOnce() as [$from, $to]) {
                $this->matrixCase($failures, true, in_array('code', $add($this->codeOfPair($from, $to)), true), "{$api}: held in {$from}: the global rule");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    // ---- A6, A8: edit --------------------------------------------------------------------------------------------------------------

    public function test_with_the_switch_on_a_seller_edit_may_take_a_code_another_country_holds_and_is_refused_a_code_its_own_country_holds(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->updates() as $api => $update) {
            foreach ($this->orderedPairs() as [$from, $to]) {
                $code = $this->codeOfPair($from, $to);
                $this->matrixCase($failures, false, in_array('code', $update($this->editors[$to], $code), true), "{$api}: held in {$from}, taken by the seller's product of {$to}");
                $this->matrixCase($failures, true, in_array('code', $update($this->editors[$from], $code), true), "{$api}: held in {$from}, taken by the seller's product of {$from}");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_on_a_seller_edit_keeps_its_own_code_valid_in_every_country(): void
    {
        $this->scopeCodesByCountry();
        $failures = [];
        foreach ($this->updates() as $api => $update) {
            foreach ($this->matrixCountries() as $country) {
                $this->matrixCase($failures, false, in_array('code', $update($this->editors[$country], $this->codeOfCountry($country, '9')), true), "{$api}: the seller's product of {$country} keeps its own code");
            }
        }

        $this->assertMatrixHolds($failures);
    }

    public function test_with_the_switch_off_a_seller_edit_is_refused_every_code_another_product_holds(): void
    {
        $failures = [];
        foreach ($this->updates() as $api => $update) {
            foreach ($this->orderedPairs() as [$from, $to]) {
                $this->matrixCase($failures, true, in_array('code', $update($this->editors[$to], $this->codeOfPair($from, $to)), true), "{$api}: held in {$from}, taken by the seller's product of {$to}: the global rule");
            }
        }

        $this->assertMatrixHolds($failures);
    }
}
