<?php

namespace Tests\Feature\Catalog;

use App\Http\Controllers\RestAPI\v2\seller\ProductController as ProductControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as ProductControllerV3;
use App\Models\Seller;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCodeScopeCatalog;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;

/**
 * A5 to A8: the seller apps (API v2 and v3) add and edit a product. Each request here is made to fail on a field that has nothing to do with
 * the code (no price), so what is read is only whether the code was one of the refusals: nothing is ever created.
 */
class SellerProductCodeGatesTest extends TestCase
{
    use BuildsCodeScopeCatalog;
    use RestoresLanguageFiles;

    private const SELLER_TOKEN = 'a-seller-token-that-is-longer-than-thirty-characters';

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

    private function createSellerTables(): void
    {
        Schema::dropIfExists('sellers');
        Schema::create('sellers', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('auth_token')->nullable();
            $table->softDeletes();
        });
        DB::table('sellers')->insert(['id' => 5, 'status' => 'approved', 'auth_token' => self::SELLER_TOKEN]);

        Schema::dropIfExists('shops');
        Schema::create('shops', function ($table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
        });
        DB::table('shops')->insert(['id' => 1, 'seller_id' => 5]);

        foreach (['digital_product_variations', 'product_seos'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($definition) {
                $definition->id();
                $definition->unsignedBigInteger('product_id');
            });
        }
    }

    /** A store whose prices already include tax: the tax set-up the validators ask for is answered from the cache instead of the tax module's tables. */
    private function useTaxIncludedPrices(): void
    {
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => ['tax_ids' => [], 'additionalData' => [], 'tax_type' => 'order_wise'],
            'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }

    /** @return array<string, mixed> everything the add and edit validators ask for, except the price */
    private function fields(string $code): array
    {
        return [
            'name' => json_encode(['Serum']), 'lang' => json_encode(['en']), 'description' => json_encode(['A serum']),
            'colors' => '[]', 'images' => '[]', 'color_image' => '[]', 'tags' => '[]', 'choice' => '[]', 'choice_no' => '[]', 'choice_attributes' => '[]',
            'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc', 'thumbnail' => 'thumbnail.webp', 'discount_type' => 'flat',
            'discount' => 0, 'shipping_cost' => 0, 'minimum_order_qty' => 1, 'code' => $code,
        ];
    }

    /** @param array<string, mixed> $input */
    private function sellerRequest(string $uri, array $input): Request
    {
        $request = Request::create($uri, 'POST', $input, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::SELLER_TOKEN]);
        $request['seller'] = (new Seller())->forceFill(['id' => 5]);
        $this->app->instance('request', $request);

        return $request;
    }

    /** @return list<string> the fields the answer refuses */
    private function refusals(JsonResponse $response): array
    {
        return array_map(static fn (array $error): string => $error['code'], $response->getData(true)['errors'] ?? []);
    }

    private function v3Add(string $code): array
    {
        return $this->refusals(app(ProductControllerV3::class)->add_new($this->sellerRequest('/api/v3/seller/products/add', $this->fields($code))));
    }

    private function v3Update(int $productId, string $code): array
    {
        return $this->refusals(app(ProductControllerV3::class)->updateProduct($this->sellerRequest("/api/v3/seller/products/update/{$productId}", $this->fields($code)), $productId));
    }

    /** @return array<string, mixed> what the v2 add and edit validators ask for, except the price */
    private function v2Fields(string $code): array
    {
        return [
            'name' => ['Serum'], 'lang' => ['en'], 'description' => ['A serum'], 'category_id' => 1, 'product_type' => 'physical', 'unit' => 'pc',
            'images' => ['image.webp'], 'thumbnail' => 'thumbnail.webp', 'discount_type' => 'flat', 'tax' => 0, 'purchase_price' => 5, 'discount' => 0,
            'shipping_cost' => 0, 'minimum_order_qty' => 1, 'code' => $code,
        ];
    }

    private function v2Add(string $code): array
    {
        return $this->refusals(app(ProductControllerV2::class)->add_new($this->sellerRequest('/api/v2/seller/products/add', $this->v2Fields($code))));
    }

    private function v2Update(int $productId, string $code): array
    {
        return $this->refusals(app(ProductControllerV2::class)->update($this->sellerRequest("/api/v2/seller/products/update/{$productId}", $this->v2Fields($code)), $productId));
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
