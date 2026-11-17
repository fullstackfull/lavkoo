<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductCodeScope;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * GCC-14b: the same SKU or barcode may exist in two countries, never twice in one. The scope is the only place that knows the flag and the
 * column. With the flag off (the default), or before the products migration has run, every answer is the global one of today.
 */
class ProductCodeScopeTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    private ProductCodeScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->scope = app(ProductCodeScope::class);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- when the scope applies ------------------------------------------------------------------------------------------------

    public function test_the_flag_ships_off(): void
    {
        $this->assertFalse(config('gcc.catalog.country_scoped_codes'));
        $this->assertFalse($this->scope->enabled());
    }

    public function test_the_scope_applies_when_the_flag_is_on_and_the_column_exists(): void
    {
        $this->scopeCodesByCountry();

        $this->assertTrue($this->scope->enabled());
    }

    public function test_a_missing_column_acts_as_off_even_when_the_flag_is_on(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertFalse($this->scope->enabled());
    }

    public function test_the_scope_does_not_depend_on_the_storefront_switch(): void
    {
        $this->scopeCodesByCountry();

        $this->assertFalse(config('gcc.storefronts.enabled'));
        $this->assertTrue($this->scope->enabled());
    }

    // ---- is a code or a barcode taken ------------------------------------------------------------------------------------------

    public function test_with_the_flag_off_a_code_is_taken_whatever_country_holds_it(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');

        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'SA'));
        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'KW'), 'today a code is unique across the whole table');
        $this->assertFalse($this->scope->isCodeTaken(code: 'SKU200', country: 'KW'));
    }

    public function test_with_the_flag_on_a_code_is_taken_only_in_the_country_that_holds_it(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');

        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'SA'));
        $this->assertFalse($this->scope->isCodeTaken(code: 'SKU100', country: 'KW'));
    }

    public function test_a_missing_column_makes_a_code_global_again_without_failing(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'KW'));
    }

    public function test_the_product_being_saved_does_not_count_against_itself(): void
    {
        $this->scopeCodesByCountry();
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA');

        $this->assertFalse($this->scope->isCodeTaken(code: 'SKU100', country: 'SA', ignoreProductId: $own));
        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: 'SA', ignoreProductId: $own + 1));
    }

    public function test_a_product_whose_country_is_unknown_is_checked_against_every_country(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: null));
        $this->assertTrue($this->scope->isCodeTaken(code: 'SKU100', country: ''));
    }

    public function test_barcodes_follow_the_same_rule(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');

        $this->assertTrue($this->scope->isBarcodeTaken(barcode: '6291234567890', country: 'KW'), 'flag off: global');

        $this->scopeCodesByCountry();
        $this->assertTrue($this->scope->isBarcodeTaken(barcode: '6291234567890', country: 'SA'));
        $this->assertFalse($this->scope->isBarcodeTaken(barcode: '6291234567890', country: 'KW'));
        $this->assertFalse($this->scope->isBarcodeTaken(barcode: '6291234567890', country: 'SA', ignoreProductId: 1));
    }

    public function test_a_generated_code_is_free_where_the_product_will_be_created(): void
    {
        $this->insertCodedProduct(code: 'ABC123', country: 'KW');

        $this->assertFalse($this->scope->generatedCodeIsFree(code: 'ABC123', country: 'SA'), 'flag off: a code held anywhere is not free');

        $this->scopeCodesByCountry();
        $this->assertTrue($this->scope->generatedCodeIsFree(code: 'ABC123', country: 'SA'));
        $this->assertFalse($this->scope->generatedCodeIsFree(code: 'ABC123', country: 'KW'));
    }

    // ---- the validation rules --------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: ?int}> */
    public static function ruleKinds(): array
    {
        return ['code' => ['code', null], 'barcode' => ['barcode', null]];
    }

    private function passes(string $field, string $value, ?string $country, ?int $ignore = null): bool
    {
        $rule = $field === 'code'
            ? $this->scope->uniqueRuleForCode(country: $country, ignoreProductId: $ignore)
            : $this->scope->uniqueRuleForBarcode(country: $country, ignoreProductId: $ignore);

        return Validator::make([$field => $value], [$field => [$rule]])->passes();
    }

    #[DataProvider('ruleKinds')]
    public function test_the_rule_is_global_while_the_flag_is_off(string $field): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertFalse($this->passes($field, $value, 'SA'));
        $this->assertFalse($this->passes($field, $value, 'KW'));
    }

    #[DataProvider('ruleKinds')]
    public function test_the_rule_is_per_country_while_the_flag_is_on(string $field): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertFalse($this->passes($field, $value, 'SA'), 'a duplicate inside one country is refused');
        $this->assertTrue($this->passes($field, $value, 'KW'), 'the same value in another country is allowed');
    }

    #[DataProvider('ruleKinds')]
    public function test_the_rule_keeps_an_update_of_the_product_itself_valid(string $field): void
    {
        $this->scopeCodesByCountry();
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertTrue($this->passes($field, $value, 'SA', ignore: $own));
        $this->assertFalse($this->passes($field, $value, 'SA', ignore: $own + 1));
    }

    #[DataProvider('ruleKinds')]
    public function test_the_rule_refuses_a_move_into_a_country_that_already_holds_the_value(string $field): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: '6291234567890');
        $moving = $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertFalse($this->passes($field, $value, 'KW', ignore: $moving), 'checked against the NEW country');
        $this->assertTrue($this->passes($field, $value, 'AE', ignore: $moving));
    }

    #[DataProvider('ruleKinds')]
    public function test_the_rule_never_mentions_the_column_when_it_does_not_exist(string $field): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: '6291234567890');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertFalse($this->passes($field, $value, 'KW'), 'the flag acts as off: the global rule, and no query on a missing column');
    }

    #[DataProvider('ruleKinds')]
    public function test_a_rule_without_a_country_is_checked_against_every_country(string $field): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: '6291234567890');
        $value = $field === 'code' ? 'SKU100' : '6291234567890';

        $this->assertFalse($this->passes($field, $value, null));
    }

    // ---- candidates ------------------------------------------------------------------------------------------------------------

    public function test_the_candidates_of_a_code_are_every_product_that_answers_to_it_by_code_or_barcode(): void
    {
        $sa = $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $kw = $this->insertCodedProduct(code: 'SKU900', country: 'KW', barcode: 'SKU100');
        $this->insertCodedProduct(code: 'SKU300', country: 'SA');

        $candidates = $this->scope->candidatesForCode(code: 'SKU100');

        $this->assertSame([$sa, $kw], $candidates->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_with_the_flag_off_a_country_never_narrows_the_candidates(): void
    {
        $sa = $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $kw = $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $this->assertSame([$sa, $kw], $this->scope->candidatesForCode(code: 'SKU100', country: 'KW')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_with_the_flag_on_a_country_narrows_the_candidates_and_each_one_names_its_country(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $kw = $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $candidates = $this->scope->candidatesForCode(code: 'SKU100', country: 'KW');

        $this->assertSame([$kw], $candidates->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('KW', $candidates->first()->storefront_country);
        $this->assertCount(2, $this->scope->candidatesForCode(code: 'SKU100'), 'no country: every country answers');
    }

    // ---- the country of a product ----------------------------------------------------------------------------------------------

    public function test_the_default_country_of_a_row_is_the_one_the_migration_gives_it(): void
    {
        $id = (int) DB::table('products')->insertGetId(['name' => 'No country given', 'code' => 'SKU100']);

        $this->assertSame(ProductCodeScope::TABLE_DEFAULT_COUNTRY, DB::table('products')->where('id', $id)->value('storefront_country'));
    }

    public function test_a_new_admin_product_is_checked_in_the_country_the_create_path_will_write(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame('KW', $this->scope->countryOfNewProduct(addedBy: 'admin', requestedCountry: 'KW'));
        $this->assertSame('SA', $this->scope->countryOfNewProduct(addedBy: 'admin', requestedCountry: 'XX'), 'an unusable choice falls back to the default storefront');
        $this->assertSame('SA', $this->scope->countryOfNewProduct(addedBy: 'admin'));
    }

    public function test_a_seller_product_always_takes_the_table_default_whatever_it_asks_for(): void
    {
        config(['gcc.storefronts.enabled' => true]);

        $this->assertSame('SA', $this->scope->countryOfNewProduct(addedBy: 'seller', requestedCountry: 'KW'));
    }

    public function test_while_countries_are_not_tracked_every_new_product_takes_the_table_default(): void
    {
        $this->assertSame('SA', $this->scope->countryOfNewProduct(addedBy: 'admin', requestedCountry: 'KW'));
    }

    public function test_the_country_of_a_stored_product_is_read_from_its_row(): void
    {
        $id = $this->insertCodedProduct(code: 'SKU100', country: 'KW');
        $product = \App\Models\Product::withoutGlobalScopes()->findOrFail($id);

        $this->assertSame('KW', $this->scope->countryOfProduct($product));
        $this->assertNull($this->scope->countryOfProduct(null));
    }
}
