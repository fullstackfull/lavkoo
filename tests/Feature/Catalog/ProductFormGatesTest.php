<?php

namespace Tests\Feature\Catalog;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Http\Requests\ProductAddRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * A1 to A4: the admin and vendor add and edit forms. A code or a barcode is refused when the country the product is saved in already holds
 * it; with the switch off it is refused when any country holds it, exactly as before.
 */
class ProductFormGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    private const BARCODE = '6291234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    /** @param array<string, mixed> $input */
    private function addErrors(array $input, string $panel = 'admin'): array
    {
        $request = ProductAddRequest::create("/{$panel}/products/add", 'POST', $input);

        return Validator::make($request->all(), $request->rules())->errors()->keys();
    }

    /** @param array<string, mixed> $input */
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

    // ---- A1, A2: the add form --------------------------------------------------------------------------------------------------

    public function test_the_add_form_refuses_a_code_and_a_barcode_held_by_any_country_while_the_switch_is_off(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: self::BARCODE);

        $errors = $this->addErrors(['code' => 'SKU100', 'barcode' => self::BARCODE]);

        $this->assertContains('code', $errors);
        $this->assertContains('barcode', $errors);
    }

    public function test_the_add_form_accepts_a_free_code_and_barcode(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: self::BARCODE);

        $errors = $this->addErrors(['code' => 'SKU999', 'barcode' => '6290000000002']);

        $this->assertNotContains('code', $errors);
        $this->assertNotContains('barcode', $errors);
    }

    public function test_the_admin_who_picks_a_country_is_checked_in_that_country(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: self::BARCODE);

        $inKuwait = $this->addErrors(['code' => 'SKU100', 'barcode' => self::BARCODE, 'storefront_country' => 'KW']);
        $inSaudi = $this->addErrors(['code' => 'SKU100', 'barcode' => self::BARCODE, 'storefront_country' => 'SA']);

        $this->assertContains('code', $inKuwait, 'a duplicate inside one country is refused');
        $this->assertContains('barcode', $inKuwait);
        $this->assertNotContains('code', $inSaudi, 'the same code in another country is allowed on create');
        $this->assertNotContains('barcode', $inSaudi);
    }

    public function test_the_admin_without_a_choice_is_checked_in_the_default_storefront(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');

        $this->assertContains('code', $this->addErrors(['code' => 'SKU100']));
        $this->assertNotContains('code', $this->addErrors(['code' => 'SKU200']));
    }

    public function test_while_countries_are_not_tracked_a_new_product_is_saved_in_the_table_default_and_checked_there(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');

        $this->assertContains('code', $this->addErrors(['code' => 'SKU100', 'storefront_country' => 'KW']), 'the choice is ignored, as the save ignores it');
        $this->assertNotContains('code', $this->addErrors(['code' => 'SKU200', 'storefront_country' => 'KW']));
    }

    public function test_a_vendor_is_checked_in_the_table_default_whatever_country_the_request_names(): void
    {
        config(['gcc.storefronts.enabled' => true]);
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');

        $crafted = $this->addErrors(['code' => 'SKU100', 'storefront_country' => 'KW'], panel: 'vendor');
        $other = $this->addErrors(['code' => 'SKU200', 'storefront_country' => 'KW'], panel: 'vendor');

        $this->assertContains('code', $crafted, 'the vendor save ignores the country, so a crafted request cannot slip a duplicate into the default country');
        $this->assertNotContains('code', $other);
    }

    public function test_the_add_form_is_global_again_when_the_column_is_missing(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'KW', barcode: self::BARCODE);
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $errors = $this->addErrors(['code' => 'SKU100', 'barcode' => self::BARCODE]);

        $this->assertContains('code', $errors);
        $this->assertContains('barcode', $errors);
    }

    // ---- A3, A4: the edit form -------------------------------------------------------------------------------------------------

    public function test_the_edit_form_refuses_a_code_and_a_barcode_held_by_another_product_in_any_country_while_the_switch_is_off(): void
    {
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: self::BARCODE);
        $this->insertCodedProduct(code: 'SKU200', country: 'KW', barcode: '6290000000002');

        $errors = $this->updateErrors($own, ['code' => 'SKU200', 'barcode' => '6290000000002']);

        $this->assertContains('code', $errors);
        $this->assertContains('barcode', $errors);
    }

    public function test_an_update_of_a_product_keeps_its_own_code_and_barcode_valid_in_both_modes(): void
    {
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: self::BARCODE);

        $this->assertSame([], array_intersect(['code', 'barcode'], $this->updateErrors($own, ['code' => 'SKU100', 'barcode' => self::BARCODE])));

        $this->scopeCodesByCountry();
        $this->assertSame([], array_intersect(['code', 'barcode'], $this->updateErrors($own, ['code' => 'SKU100', 'barcode' => self::BARCODE])));
    }

    public function test_the_edit_form_checks_the_country_the_product_is_in(): void
    {
        $this->scopeCodesByCountry();
        $saudi = $this->insertCodedProduct(code: 'SKU100', country: 'SA', barcode: self::BARCODE);
        $this->insertCodedProduct(code: 'SKU200', country: 'KW', barcode: '6290000000002');
        $this->insertCodedProduct(code: 'SKU300', country: 'SA', barcode: '6290000000003');

        $inAnotherCountry = $this->updateErrors($saudi, ['code' => 'SKU200', 'barcode' => '6290000000002']);
        $inTheSameCountry = $this->updateErrors($saudi, ['code' => 'SKU300', 'barcode' => '6290000000003']);

        $this->assertSame([], array_intersect(['code', 'barcode'], $inAnotherCountry), 'the same code in another country is allowed on update');
        $this->assertContains('code', $inTheSameCountry);
        $this->assertContains('barcode', $inTheSameCountry);
    }

    public function test_an_edit_of_a_non_saudi_product_is_checked_in_its_own_country(): void
    {
        $this->scopeCodesByCountry();
        $kuwaiti = $this->insertCodedProduct(code: 'SKU100', country: 'KW');
        $this->insertCodedProduct(code: 'SKU200', country: 'SA');
        $this->insertCodedProduct(code: 'SKU300', country: 'KW');

        $this->assertNotContains('code', $this->updateErrors($kuwaiti, ['code' => 'SKU200']));
        $this->assertContains('code', $this->updateErrors($kuwaiti, ['code' => 'SKU300']));
    }

    public function test_an_edit_ignores_a_country_in_the_request_because_the_save_ignores_it(): void
    {
        $this->scopeCodesByCountry();
        $saudi = $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU300', country: 'SA');

        $errors = $this->updateErrors($saudi, ['code' => 'SKU300', 'storefront_country' => 'KW']);

        $this->assertContains('code', $errors, 'a crafted country cannot move the check away from the product\'s own country');
    }

    public function test_the_edit_form_is_global_again_when_the_column_is_missing(): void
    {
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertContains('code', $this->updateErrors($own, ['code' => 'SKU200']));
    }

    /** @return array<string, array{0: string}> */
    public static function badValues(): array
    {
        return ['a code with a space' => ['SKU 100'], 'a code with a dash' => ['SKU-100']];
    }

    #[DataProvider('badValues')]
    public function test_the_other_rules_of_the_code_are_unchanged(string $code): void
    {
        $this->scopeCodesByCountry();
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA');

        $this->assertContains('code', $this->updateErrors($own, ['code' => $code]));
        $this->assertContains('code', $this->addErrors(['code' => $code]));
    }
}
