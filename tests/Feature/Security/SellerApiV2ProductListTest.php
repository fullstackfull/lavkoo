<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * The older seller app lists the seller's products. A product row has its own id and a user_id that says whose it is, and the two are easy
 * to take for each other: the list must hold the products added by this seller (added_by 'seller', user_id the seller's id), not the one
 * product whose own id happens to equal the seller's id, and never the in-house shop's products whose user_id equals the seller's id.
 */
class SellerApiV2ProductListTest extends TestCase
{
    use BuildsSellerApiWorld;

    /** Owned by seller 1, and its own id is seller 2's id. */
    private const SELLER_ONES_PRODUCT_WITH_SELLER_TWOS_ID = 2;
    private const SELLER_ONES_OTHER_PRODUCT = 10;
    /** Owned by seller 2, and its own id is seller 1's id. */
    private const SELLER_TWOS_PRODUCT_WITH_SELLER_ONES_ID = 1;
    private const SELLER_TWOS_OTHER_PRODUCT = 20;
    private const IN_HOUSE_PRODUCT = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('request_status')->default(true);
            $table->string('product_type')->default('physical');
            $table->text('images')->nullable();
            $table->string('thumbnail')->nullable();
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->integer('current_stock')->default(0);
            $table->text('category_ids')->nullable();
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('storages', function (Blueprint $table) {
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });

        $product = fn (int $id, int $userId, string $addedBy, float $purchasePrice) => DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => 'Product ' . $id, 'slug' => 'product-' . $id, 'code' => 'CODE' . $id, 'images' => '[]',
            'thumbnail' => 'thumb.png', 'unit_price' => 100, 'purchase_price' => $purchasePrice, 'current_stock' => 5, 'category_ids' => '[]', 'colors' => '[]',
            'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
        ]);
        $product(self::SELLER_TWOS_PRODUCT_WITH_SELLER_ONES_ID, self::OTHER_SELLER, 'seller', 33);
        $product(self::SELLER_ONES_PRODUCT_WITH_SELLER_TWOS_ID, self::SELLER, 'seller', 77);
        $product(self::SELLER_ONES_OTHER_PRODUCT, self::SELLER, 'seller', 11);
        $product(self::SELLER_TWOS_OTHER_PRODUCT, self::OTHER_SELLER, 'seller', 22);
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin', 44);
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    public function test_the_list_holds_the_products_the_seller_added_and_no_other_product(): void
    {
        $response = $this->sellerApiV2('GET', 'products/list');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::SELLER_ONES_OTHER_PRODUCT, self::SELLER_ONES_PRODUCT_WITH_SELLER_TWOS_ID], array_column($response->json(), 'id'));
    }

    public function test_the_list_of_the_other_seller_holds_that_sellers_products_and_not_the_product_that_shares_their_id(): void
    {
        $response = $this->sellerApiV2('GET', 'products/list', as: self::OTHER_SELLER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::SELLER_TWOS_OTHER_PRODUCT, self::SELLER_TWOS_PRODUCT_WITH_SELLER_ONES_ID], array_column($response->json(), 'id'));
        $this->assertStringNotContainsString('"purchase_price":77', (string) $response->getContent());
    }

    public function test_the_list_does_not_hold_the_in_house_shops_product_whose_user_id_is_the_sellers_id(): void
    {
        $response = $this->sellerApiV2('GET', 'products/list');

        $this->assertNotContains(self::IN_HOUSE_PRODUCT, array_column($response->json(), 'id'));
    }
}
