<?php

namespace Tests\Feature\Catalog;

use App\Services\Catalog\ProductVariantSku;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\TestCase;

/**
 * B-06: a variant's SKU names one variant inside its product's country. Two variants of one product may not share a SKU, and a SKU a product
 * of the same country already holds is refused — while the same SKU in another country is free, as a product's own code is.
 *
 * Variants live as a JSON list in `products.variation`, so this is the one half of product identity no index can hold; it is a gate, and it is
 * dormant with the country-scoped codes switch.
 */
class ProductVariantSkuTest extends TestCase
{
    use BuildsCodeScopeCatalog;

    private ProductVariantSku $variantSku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->variantSku = app(ProductVariantSku::class);
    }

    protected function tearDown(): void
    {
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- dormant while the switch is off -----------------------------------------------------------------------------------------

    public function test_nothing_is_refused_while_the_switch_is_off(): void
    {
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['RED' => 'V-1']);

        $refusals = $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V-1', 'sku_Green' => 'V-1']), country: 'SA');

        $this->assertSame([], $refusals);
        $this->assertFalse($this->variantSku->applies());
    }

    public function test_nothing_is_refused_while_the_country_column_is_missing(): void
    {
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_A' => 'V-1', 'sku_B' => 'V-1']), country: 'SA'));
    }

    // ---- two variants of one product ---------------------------------------------------------------------------------------------

    public function test_two_variants_of_a_product_may_not_share_a_sku(): void
    {
        $this->scopeCodesByCountry();

        $refusals = $this->variantSku->refusals(request: $this->submission(['sku_Red' => 'V-1', 'sku_Blue' => 'V-1']), country: 'SA');

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('V-1', $refusals[0]);
    }

    public function test_variants_of_a_product_with_different_skus_are_accepted(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Red' => 'V-1', 'sku_Blue' => 'V-2']), country: 'SA'));
    }

    public function test_two_variant_skus_that_differ_only_in_letter_case_are_two_skus(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Red' => 'V-1', 'sku_Blue' => 'v-1']), country: 'SA'));
    }

    public function test_variants_with_no_sku_typed_do_not_count_as_a_repeat(): void
    {
        $this->scopeCodesByCountry();

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Red' => '', 'sku_Blue' => '   ']), country: 'SA'));
    }

    // ---- a SKU another product of the country holds ------------------------------------------------------------------------------

    public function test_a_variant_sku_another_product_of_the_same_country_holds_is_refused(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V-1']);

        $refusals = $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V-1']), country: 'SA');

        $this->assertCount(1, $refusals);
        $this->assertStringContainsString('Product P-1', $refusals[0]);
    }

    public function test_the_same_variant_sku_in_another_country_is_free(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V-1']);

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V-1']), country: 'KW'));
    }

    public function test_a_product_being_edited_does_not_hold_its_own_variant_sku_against_itself(): void
    {
        $this->scopeCodesByCountry();
        $productId = $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V-1']);

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Red' => 'V-1']), country: 'SA', ignoreProductId: $productId));
    }

    public function test_a_variant_sku_equal_to_another_products_own_code_is_not_a_variant_clash(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'V-1', country: 'SA', variants: []);

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V-1']), country: 'SA'));
    }

    public function test_a_sku_with_characters_the_text_filter_cannot_carry_is_still_found(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V/1%_x']);

        $refusals = $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V/1%_x']), country: 'SA');

        $this->assertCount(1, $refusals);
    }

    public function test_a_sku_whose_text_looks_like_a_wildcard_does_not_match_another_sku(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'VX1']);

        $this->assertSame([], $this->variantSku->refusals(request: $this->submission(['sku_Blue' => 'V_1']), country: 'SA'));
    }

    // ---- the gate the four forms share -------------------------------------------------------------------------------------------

    public function test_the_gate_adds_its_refusals_under_the_field_the_forms_already_show(): void
    {
        $this->scopeCodesByCountry();
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V-1']);
        $validator = Validator::make([], []);

        $this->variantSku->guard(validator: $validator, request: $this->submission(['sku_Blue' => 'V-1']), country: 'SA');

        $this->assertTrue($validator->errors()->has('sku_error'));
    }

    public function test_the_gate_adds_nothing_while_the_switch_is_off(): void
    {
        $this->insertProduct(code: 'P-1', country: 'SA', variants: ['Red' => 'V-1']);
        $validator = Validator::make([], []);

        $this->variantSku->guard(validator: $validator, request: $this->submission(['sku_Blue' => 'V-1', 'sku_Green' => 'V-1']), country: 'SA');

        $this->assertFalse($validator->errors()->has('sku_error'));
    }

    // ---- reading the SKUs of a stored product ------------------------------------------------------------------------------------

    public function test_the_skus_of_a_product_whose_variation_is_not_a_list_are_none(): void
    {
        foreach ([null, '', 'not json', '{}', '[1, 2]'] as $stored) {
            $this->assertSame([], $this->variantSku->skusOf($stored)->all(), 'a variation column holding ' . var_export($stored, true));
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------------------------

    /** @param array<string, string> $fields */
    private function submission(array $fields): Request
    {
        return Request::create('/', 'POST', $fields);
    }

    /** @param array<string, string> $variants type => sku */
    private function insertProduct(string $code, string $country, array $variants): int
    {
        $variation = [];
        foreach ($variants as $type => $sku) {
            $variation[] = ['type' => $type, 'price' => 10, 'sku' => $sku, 'qty' => 3];
        }

        return (int) DB::table('products')->insertGetId([
            'name' => 'Product ' . $code,
            'code' => $code,
            'storefront_country' => $country,
            'variation' => json_encode($variation),
        ]);
    }
}
