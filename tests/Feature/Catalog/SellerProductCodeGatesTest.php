<?php

namespace Tests\Feature\Catalog;

use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\CallsSellerProductApi;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A5 to A8: the seller apps (API v2 and v3) add and edit a product. Each request here is made to fail on a field that has nothing to do with
 * the code (no price), so what is read is only whether the code was one of the refusals: nothing is ever created.
 */
class SellerProductCodeGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog;
    use CallsSellerProductApi;
    use RestoresLanguageFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        $this->buildCodeScopeProducts();
        $this->buildCodeScopeRelationTables();
        $this->createSellerTables();
        Cache::flush();
        $this->useTaxIncludedPrices();
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();
        StorefrontCatalog::flush();

        parent::tearDown();
    }

    // ---- A5, A6: the seller app v2 ---------------------------------------------------------------------------------------------

    public function test_v2_add_refuses_a_code_held_by_any_country_while_the_switch_is_off(): void
    {
        $this->insertCodedProduct(code: '100001', country: 'KW');

        $this->assertContains('code', $this->v2Add('100001'));
    }

    public function test_v2_add_is_checked_in_the_table_default_country_with_the_switch_on(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: '100001', country: 'SA');
        $this->insertCodedProduct(code: '100002', country: 'KW');

        $this->assertContains('code', $this->v2Add('100001'));
        $this->assertNotContains('code', $this->v2Add('100002'));
    }

    public function test_v2_edit_keeps_its_own_code_and_is_checked_in_the_products_country(): void
    {
        $this->scopeCodesByCountry();
        $own = $this->insertCodedProduct(code: '100001', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: '100002', country: 'KW');
        $this->insertCodedProduct(code: '100003', country: 'SA');

        $this->assertNotContains('code', $this->v2Update($own, '100001'));
        $this->assertNotContains('code', $this->v2Update($own, '100002'));
        $this->assertContains('code', $this->v2Update($own, '100003'));
    }

    public function test_v2_edit_refuses_a_code_held_by_another_product_in_any_country_while_the_switch_is_off(): void
    {
        $own = $this->insertCodedProduct(code: '100001', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: '100002', country: 'KW');

        $this->assertContains('code', $this->v2Update($own, '100002'));
        $this->assertNotContains('code', $this->v2Update($own, '100001'));
    }

    public function test_v2_is_global_again_when_the_column_is_missing(): void
    {
        $own = $this->insertCodedProduct(code: '100001', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: '100002', country: 'KW');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertContains('code', $this->v2Add('100002'));
        $this->assertContains('code', $this->v2Update($own, '100002'));
    }

    // ---- A7, A8: the seller app v3 ---------------------------------------------------------------------------------------------

    public function test_v3_add_refuses_a_code_held_by_any_country_while_the_switch_is_off(): void
    {
        $this->insertCodedProduct(code: 'SKU100', country: 'KW');

        $this->assertContains('code', $this->v3Add('SKU100'));
    }

    public function test_v3_add_is_checked_in_the_table_default_country_with_the_switch_on(): void
    {
        $this->scopeCodesByCountry();
        $this->insertCodedProduct(code: 'SKU100', country: 'SA');
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');

        $this->assertContains('code', $this->v3Add('SKU100'), 'a duplicate inside the country the seller product is saved in');
        $this->assertNotContains('code', $this->v3Add('SKU200'), 'the same code in another country is allowed on create');
    }

    public function test_v3_edit_keeps_its_own_code_and_is_checked_in_the_products_country(): void
    {
        $this->scopeCodesByCountry();
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');
        $this->insertCodedProduct(code: 'SKU300', country: 'SA');

        $this->assertNotContains('code', $this->v3Update($own, 'SKU100'), 'an update keeps its own code valid');
        $this->assertNotContains('code', $this->v3Update($own, 'SKU200'), 'the same code in another country is allowed on update');
        $this->assertContains('code', $this->v3Update($own, 'SKU300'));
    }

    public function test_v3_edit_refuses_a_code_held_by_another_product_in_any_country_while_the_switch_is_off(): void
    {
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');

        $this->assertContains('code', $this->v3Update($own, 'SKU200'));
        $this->assertNotContains('code', $this->v3Update($own, 'SKU100'));
    }

    public function test_v3_is_global_again_when_the_column_is_missing(): void
    {
        $own = $this->insertCodedProduct(code: 'SKU100', country: 'SA', extra: ['added_by' => 'seller', 'user_id' => 5]);
        $this->insertCodedProduct(code: 'SKU200', country: 'KW');
        $this->scopeCodesByCountry();
        $this->dropStorefrontCountryColumn();

        $this->assertContains('code', $this->v3Add('SKU200'));
        $this->assertContains('code', $this->v3Update($own, 'SKU200'));
    }
}
