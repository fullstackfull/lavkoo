<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * Three catalogue handlers of the seller app work on a row that is not a product itself: a restock request, a digital product's
 * downloadable file and a category's shipping cost. The permission middleware only says that the caller may manage products or the
 * shop, so each must still act only on a row that belongs to the caller: the restock request and the file are reached through the
 * product, which must be the caller's own (not another seller's, and not the in-house shop's whose user_id equals this seller's id),
 * and the shipping cost through its seller_id.
 */
class SellerApiCatalogueOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_PRODUCT = 10;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    private const OWN_RESTOCK_REQUEST = 4;
    private const OTHER_SELLERS_RESTOCK_REQUEST = 5;
    private const IN_HOUSE_RESTOCK_REQUEST = 6;

    private const OWN_COST = 6;
    private const OTHER_SELLERS_COST = 7;
    private const OWN_SECOND_COST = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createCatalogueTables();
        $this->seedProducts();
        $this->seedRestockRequests();
        $this->seedDigitalVariations();
        $this->seedCategoryShippingCosts();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- restock requests ----

    public function test_a_restock_request_of_another_sellers_product_cannot_be_deleted(): void
    {
        $response = $this->sellerApi('GET', 'products/restock-request-delete?id=' . self::OTHER_SELLERS_RESTOCK_REQUEST);

        $this->assertSame([1, 1], $this->restockRequestAndCustomerCounts(self::OTHER_SELLERS_RESTOCK_REQUEST));
        $this->assertNotFound($response, code: 'restock_request');
    }

    public function test_a_restock_request_of_the_in_house_product_sharing_the_sellers_id_cannot_be_deleted(): void
    {
        $response = $this->sellerApi('GET', 'products/restock-request-delete?id=' . self::IN_HOUSE_RESTOCK_REQUEST);

        $this->assertSame([1, 1], $this->restockRequestAndCustomerCounts(self::IN_HOUSE_RESTOCK_REQUEST));
        $this->assertNotFound($response, code: 'restock_request');
    }

    public function test_a_restock_request_that_no_longer_exists_is_not_found(): void
    {
        $response = $this->sellerApi('GET', 'products/restock-request-delete?id=999');

        $this->assertNotFound($response, code: 'restock_request');
    }

    public function test_the_seller_can_still_delete_the_restock_request_of_their_own_product(): void
    {
        $response = $this->sellerApi('GET', 'products/restock-request-delete?id=' . self::OWN_RESTOCK_REQUEST);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([0, 0], $this->restockRequestAndCustomerCounts(self::OWN_RESTOCK_REQUEST));
        $this->assertSame([1, 1], $this->restockRequestAndCustomerCounts(self::OTHER_SELLERS_RESTOCK_REQUEST));
        $this->assertSame([1, 1], $this->restockRequestAndCustomerCounts(self::IN_HOUSE_RESTOCK_REQUEST));
    }

    // ---- digital product files ----

    public function test_the_file_of_another_sellers_digital_product_cannot_be_detached(): void
    {
        $response = $this->sellerApi('POST', 'products/delete-digital-product', ['product_id' => self::OTHER_SELLERS_PRODUCT, 'variant_key' => 'pdf']);

        $this->assertSame('other-sellers-ebook.pdf', $this->variationFile(self::OTHER_SELLERS_PRODUCT));
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_file_of_the_in_house_digital_product_sharing_the_sellers_id_cannot_be_detached(): void
    {
        $response = $this->sellerApi('POST', 'products/delete-digital-product', ['product_id' => self::IN_HOUSE_PRODUCT, 'variant_key' => 'pdf']);

        $this->assertSame('in-house-ebook.pdf', $this->variationFile(self::IN_HOUSE_PRODUCT));
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_seller_can_still_detach_the_file_of_their_own_digital_product(): void
    {
        $response = $this->sellerApi('POST', 'products/delete-digital-product', ['product_id' => self::OWN_PRODUCT, 'variant_key' => 'pdf']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->json('status'));
        $this->assertNull($this->variationFile(self::OWN_PRODUCT));
        $this->assertSame('other-sellers-ebook.pdf', $this->variationFile(self::OTHER_SELLERS_PRODUCT));
        $this->assertSame('in-house-ebook.pdf', $this->variationFile(self::IN_HOUSE_PRODUCT));
    }

    public function test_detaching_a_variation_the_own_product_does_not_have_is_still_reported_as_unsuccessful(): void
    {
        $response = $this->sellerApi('POST', 'products/delete-digital-product', ['product_id' => self::OWN_PRODUCT, 'variant_key' => 'epub']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $response->json('status'));
    }

    // ---- category shipping costs ----

    public function test_the_shipping_cost_of_another_sellers_category_cannot_be_rewritten(): void
    {
        $response = $this->sellerApi('POST', 'shipping/set-category-cost', ['ids' => [self::OTHER_SELLERS_COST], 'cost' => [999], 'multiply_qty' => [1]]);

        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_a_request_naming_an_own_and_another_sellers_cost_rewrites_neither(): void
    {
        $response = $this->sellerApi('POST', 'shipping/set-category-cost', ['ids' => [self::OWN_COST, self::OTHER_SELLERS_COST], 'cost' => [10, 999], 'multiply_qty' => [1, 1]]);

        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
        $this->assertSame([3.0, 0], $this->categoryCost(self::OWN_COST));
        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_a_shipping_cost_that_does_not_exist_is_not_found_instead_of_crashing(): void
    {
        $response = $this->sellerApi('POST', 'shipping/set-category-cost', ['ids' => [999], 'cost' => [10], 'multiply_qty' => [1]]);

        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_the_seller_can_still_set_the_shipping_cost_of_their_own_categories(): void
    {
        $response = $this->sellerApi('POST', 'shipping/set-category-cost', ['ids' => [self::OWN_COST, self::OWN_SECOND_COST], 'cost' => [10, 20], 'multiply_qty' => [1, 0]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([10.0, 1], $this->categoryCost(self::OWN_COST));
        $this->assertSame([20.0, 0], $this->categoryCost(self::OWN_SECOND_COST));
        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
    }

    // ---- category shipping costs: API v2 ----

    public function test_v2_the_shipping_cost_of_another_sellers_category_cannot_be_rewritten(): void
    {
        $response = $this->sellerApiV2('POST', 'shipping/set-category-cost', ['ids' => [self::OTHER_SELLERS_COST], 'cost' => [999], 'multiply_qty' => [1]]);

        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_v2_a_request_naming_an_own_and_another_sellers_cost_rewrites_neither(): void
    {
        $response = $this->sellerApiV2('POST', 'shipping/set-category-cost', ['ids' => [self::OWN_COST, self::OTHER_SELLERS_COST], 'cost' => [10, 999], 'multiply_qty' => [1, 1]]);

        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
        $this->assertSame([3.0, 0], $this->categoryCost(self::OWN_COST));
        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_v2_a_shipping_cost_that_does_not_exist_is_not_found_instead_of_crashing(): void
    {
        $response = $this->sellerApiV2('POST', 'shipping/set-category-cost', ['ids' => [999], 'cost' => [10], 'multiply_qty' => [1]]);

        $this->assertNotFound($response, code: 'category_shipping_cost');
    }

    public function test_v2_the_seller_can_still_set_the_shipping_cost_of_their_own_categories(): void
    {
        $response = $this->sellerApiV2('POST', 'shipping/set-category-cost', ['ids' => [self::OWN_COST, self::OWN_SECOND_COST], 'cost' => [10, 20], 'multiply_qty' => [1, 0]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([10.0, 1], $this->categoryCost(self::OWN_COST));
        $this->assertSame([20.0, 0], $this->categoryCost(self::OWN_SECOND_COST));
        $this->assertSame([5.0, 0], $this->categoryCost(self::OTHER_SELLERS_COST));
    }

    // ---- helpers ----

    private function assertNotFound($response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    /** @return array{0: int, 1: int} the request's own row and its customers' rows */
    private function restockRequestAndCustomerCounts(int $restockRequestId): array
    {
        return [
            DB::table('restock_products')->where('id', $restockRequestId)->count(),
            DB::table('restock_product_customers')->where('restock_product_id', $restockRequestId)->count(),
        ];
    }

    private function variationFile(int $productId): ?string
    {
        return DB::table('digital_product_variations')->where(['product_id' => $productId, 'variant_key' => 'pdf'])->value('file');
    }

    /** @return array{0: float, 1: int} cost and whether it is multiplied by the quantity */
    private function categoryCost(int $rowId): array
    {
        $row = DB::table('category_shipping_costs')->where('id', $rowId)->first();

        return [(float) $row->cost, (int) $row->multiply_qty];
    }

    private function createCatalogueTables(): void
    {
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('product_type')->default('physical');
            $table->timestamps();
        });
        $this->createSellerApiTable('restock_products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('restock_product_customers', function (Blueprint $table) {
            $table->unsignedBigInteger('restock_product_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_variations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key');
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('file')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('category_shipping_costs', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('cost', 10, 2)->default(0);
            $table->boolean('multiply_qty')->default(false);
            $table->timestamps();
        });
    }

    private function seedProducts(): void
    {
        $product = fn (int $id, int $userId, string $addedBy) => DB::table('products')->insert(['id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => 'Product ' . $id, 'product_type' => 'digital']);
        $product(self::OWN_PRODUCT, self::SELLER, 'seller');
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'seller');
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin');
    }

    private function seedRestockRequests(): void
    {
        foreach ([
            self::OWN_RESTOCK_REQUEST => self::OWN_PRODUCT,
            self::OTHER_SELLERS_RESTOCK_REQUEST => self::OTHER_SELLERS_PRODUCT,
            self::IN_HOUSE_RESTOCK_REQUEST => self::IN_HOUSE_PRODUCT,
        ] as $restockRequestId => $productId) {
            DB::table('restock_products')->insert(['id' => $restockRequestId, 'product_id' => $productId, 'variant' => 'red']);
            DB::table('restock_product_customers')->insert(['restock_product_id' => $restockRequestId, 'customer_id' => 7]);
        }
    }

    private function seedDigitalVariations(): void
    {
        foreach ([
            self::OWN_PRODUCT => 'own-ebook.pdf',
            self::OTHER_SELLERS_PRODUCT => 'other-sellers-ebook.pdf',
            self::IN_HOUSE_PRODUCT => 'in-house-ebook.pdf',
        ] as $productId => $file) {
            DB::table('digital_product_variations')->insert(['product_id' => $productId, 'variant_key' => 'pdf', 'file' => $file]);
        }
    }

    private function seedCategoryShippingCosts(): void
    {
        $cost = fn (int $id, int $sellerId, int $categoryId, float $amount) => DB::table('category_shipping_costs')->insert([
            'id' => $id, 'seller_id' => $sellerId, 'category_id' => $categoryId, 'cost' => $amount, 'multiply_qty' => 0,
        ]);
        $cost(self::OWN_COST, self::SELLER, 1, 3);
        $cost(self::OTHER_SELLERS_COST, self::OTHER_SELLER, 1, 5);
        $cost(self::OWN_SECOND_COST, self::SELLER, 2, 4);
    }
}
