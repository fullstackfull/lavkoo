<?php

namespace Tests\Support;

use App\Http\Controllers\RestAPI\v2\seller\ProductController as ProductControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\ProductController as ProductControllerV3;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller apps' add and edit of a product (API v2 and v3), driven so that nothing is ever created: each request is made to fail on a field that has
 * nothing to do with the code (no price), so what is read is only whether the code was one of the refusals. The seller is number 5, approved, with a shop.
 * The test class builds the product table first (BuildsCodeScopeCatalog) and calls createSellerTables() and useTaxIncludedPrices() in its set-up.
 */
trait CallsSellerProductApi
{
    private const SELLER_TOKEN = 'a-seller-token-that-is-longer-than-thirty-characters';

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
}
