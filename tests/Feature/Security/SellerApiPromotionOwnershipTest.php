<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSellerApiWorld;
use Tests\TestCase;

/**
 * A seller's promotions are the stock-clearance sale and the coupons. The permission middleware only says that the caller may run
 * promotions, so each handler must still act only on the caller's own rows: another seller's clearance entry or coupon, the in-house
 * shop's clearance entry for a product whose user_id equals this seller's id, and an admin coupon (a platform-wide one, or one set up
 * for this seller) are not the caller's to change, and a search must not step outside the caller's own coupons.
 */
class SellerApiPromotionOwnershipTest extends TestCase
{
    use BuildsSellerApiWorld;

    private const OWN_PRODUCT = 10;
    private const OWN_CLEARED_PRODUCT = 11;
    private const OTHER_SELLERS_PRODUCT = 20;
    private const OTHER_SELLERS_CLEARED_PRODUCT = 21;
    private const IN_HOUSE_PRODUCT = 30;
    private const IN_HOUSE_PRODUCT_NOT_ON_SALE = 31;

    private const OWN_CLEARANCE_ROW = 7;
    private const IN_HOUSE_CLEARANCE_ROW = 8;
    private const OTHER_SELLERS_CLEARANCE_ROW = 9;

    private const OWN_COUPON = 1;
    private const OTHER_SELLERS_COUPON = 2;
    private const PLATFORM_COUPON = 5;
    private const ADMIN_COUPON_FOR_THIS_SELLER = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSellerApiWorld();
        $this->createClearanceTables();
        $this->createCouponTables();
        $this->seedClearanceSale();
        $this->seedCoupons();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- clearance sale: adding ----

    public function test_another_sellers_product_cannot_be_put_on_the_callers_clearance_sale(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-add', ['products' => [['id' => self::OTHER_SELLERS_PRODUCT, 'discount_amount' => 90, 'discount_type' => 'percentage']]]);

        $this->assertSame(0, DB::table('stock_clearance_products')->where('product_id', self::OTHER_SELLERS_PRODUCT)->count());
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_in_house_product_sharing_the_sellers_id_cannot_be_put_on_the_callers_clearance_sale(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-add', ['products' => [['id' => self::IN_HOUSE_PRODUCT_NOT_ON_SALE, 'discount_amount' => 90, 'discount_type' => 'percentage']]]);

        $this->assertSame(0, DB::table('stock_clearance_products')->where('product_id', self::IN_HOUSE_PRODUCT_NOT_ON_SALE)->count());
        $this->assertNotFound($response, code: 'product');
    }

    public function test_a_request_naming_an_own_and_another_sellers_product_adds_neither(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-add', ['products' => [
            ['id' => self::OWN_PRODUCT, 'discount_amount' => 15, 'discount_type' => 'percentage'],
            ['id' => self::OTHER_SELLERS_PRODUCT, 'discount_amount' => 90, 'discount_type' => 'percentage'],
        ]]);

        $this->assertSame(0, DB::table('stock_clearance_products')->whereIn('product_id', [self::OWN_PRODUCT, self::OTHER_SELLERS_PRODUCT])->count());
        $this->assertNotFound($response, code: 'product');
    }

    public function test_the_seller_can_still_put_their_own_product_on_their_clearance_sale(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-add', ['products' => [['id' => self::OWN_PRODUCT, 'discount_amount' => 15, 'discount_type' => 'percentage']]]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->json('status'));
        $row = DB::table('stock_clearance_products')->where('product_id', self::OWN_PRODUCT)->first();
        $this->assertSame(['vendor', self::SELLER, 1, 'percentage', 15.0], [$row->added_by, (int) $row->user_id, (int) $row->setup_id, $row->discount_type, (float) $row->discount_amount]);
    }

    // ---- clearance sale: changing and removing ----

    public function test_another_sellers_clearance_entry_cannot_be_repriced(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-discount-update', ['product_id' => self::OTHER_SELLERS_CLEARED_PRODUCT, 'discount_amount' => 99, 'discount_type' => 'percentage']);

        $this->assertSame([10.0, 1], $this->clearanceRow(self::OTHER_SELLERS_CLEARANCE_ROW));
        $this->assertNotFound($response, code: 'clearance_product');
    }

    public function test_another_sellers_clearance_entry_cannot_be_switched_off(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-status-update', ['product_id' => self::OTHER_SELLERS_CLEARED_PRODUCT, 'is_active' => 0]);

        $this->assertSame([10.0, 1], $this->clearanceRow(self::OTHER_SELLERS_CLEARANCE_ROW));
        $this->assertNotFound($response, code: 'clearance_product');
    }

    public function test_another_sellers_clearance_entry_cannot_be_deleted(): void
    {
        $response = $this->sellerApi('POST', 'clearance-sale/product-delete', ['product_id' => self::OTHER_SELLERS_CLEARED_PRODUCT]);

        $this->assertTrue(DB::table('stock_clearance_products')->where('id', self::OTHER_SELLERS_CLEARANCE_ROW)->exists());
        $this->assertNotFound($response, code: 'clearance_product');
    }

    public function test_the_in_house_clearance_entry_of_a_product_sharing_the_sellers_id_cannot_be_changed_or_deleted(): void
    {
        $repriced = $this->sellerApi('POST', 'clearance-sale/product-discount-update', ['product_id' => self::IN_HOUSE_PRODUCT, 'discount_amount' => 99, 'discount_type' => 'percentage']);
        $switchedOff = $this->sellerApi('POST', 'clearance-sale/product-status-update', ['product_id' => self::IN_HOUSE_PRODUCT, 'is_active' => 0]);
        $deleted = $this->sellerApi('POST', 'clearance-sale/product-delete', ['product_id' => self::IN_HOUSE_PRODUCT]);

        $this->assertSame([20.0, 1], $this->clearanceRow(self::IN_HOUSE_CLEARANCE_ROW));
        $this->assertNotFound($repriced, code: 'clearance_product');
        $this->assertNotFound($switchedOff, code: 'clearance_product');
        $this->assertNotFound($deleted, code: 'clearance_product');
    }

    public function test_the_seller_can_still_reprice_switch_off_and_delete_their_own_clearance_entry(): void
    {
        $repriced = $this->sellerApi('POST', 'clearance-sale/product-discount-update', ['product_id' => self::OWN_CLEARED_PRODUCT, 'discount_amount' => 25, 'discount_type' => 'percentage']);
        $this->assertSame(200, $repriced->getStatusCode());
        $this->assertTrue($repriced->json('status'));
        $this->assertSame([25.0, 1], $this->clearanceRow(self::OWN_CLEARANCE_ROW));

        $switchedOff = $this->sellerApi('POST', 'clearance-sale/product-status-update', ['product_id' => self::OWN_CLEARED_PRODUCT, 'is_active' => 0]);
        $this->assertSame(200, $switchedOff->getStatusCode());
        $this->assertSame([25.0, 0], $this->clearanceRow(self::OWN_CLEARANCE_ROW));

        $deleted = $this->sellerApi('POST', 'clearance-sale/product-delete', ['product_id' => self::OWN_CLEARED_PRODUCT]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertFalse(DB::table('stock_clearance_products')->where('id', self::OWN_CLEARANCE_ROW)->exists());
        $this->assertTrue(DB::table('stock_clearance_products')->where('id', self::OTHER_SELLERS_CLEARANCE_ROW)->exists());
    }

    // ---- coupons: changing ----

    public function test_a_platform_wide_admin_coupon_cannot_be_rewritten_by_a_seller(): void
    {
        $response = $this->sellerApi('PUT', 'coupon/update/' . self::PLATFORM_COUPON, $this->couponUpdate(code: 'PLAT10', title: 'Everything free', discount: 100));

        $this->assertSame(['Platform 10', 10.0, 1], $this->couponTitleDiscountAndStatus(self::PLATFORM_COUPON));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_a_platform_wide_admin_coupon_cannot_be_switched_off_by_a_seller(): void
    {
        $response = $this->sellerApi('PUT', 'coupon/status-update/' . self::PLATFORM_COUPON, ['status' => 0]);

        $this->assertSame(['Platform 10', 10.0, 1], $this->couponTitleDiscountAndStatus(self::PLATFORM_COUPON));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_an_admin_coupon_set_up_for_this_seller_cannot_be_rewritten_or_switched_off_by_the_seller(): void
    {
        $rewritten = $this->sellerApi('PUT', 'coupon/update/' . self::ADMIN_COUPON_FOR_THIS_SELLER, $this->couponUpdate(code: 'ADMIN-FOR-1', title: 'Everything free', discount: 100));
        $switchedOff = $this->sellerApi('PUT', 'coupon/status-update/' . self::ADMIN_COUPON_FOR_THIS_SELLER, ['status' => 0]);

        $this->assertSame(['Admin coupon for seller 1', 5.0, 1], $this->couponTitleDiscountAndStatus(self::ADMIN_COUPON_FOR_THIS_SELLER));
        $this->assertSame(403, $rewritten->getStatusCode());
        $this->assertSame(403, $switchedOff->getStatusCode());
    }

    public function test_another_sellers_coupon_cannot_be_rewritten_or_switched_off(): void
    {
        $rewritten = $this->sellerApi('PUT', 'coupon/update/' . self::OTHER_SELLERS_COUPON, $this->couponUpdate(code: 'B-SECRET-VIP-50', title: 'Everything free', discount: 100));
        $switchedOff = $this->sellerApi('PUT', 'coupon/status-update/' . self::OTHER_SELLERS_COUPON, ['status' => 0]);

        $this->assertSame(['VIP customer only', 50.0, 1], $this->couponTitleDiscountAndStatus(self::OTHER_SELLERS_COUPON));
        $this->assertSame(403, $rewritten->getStatusCode());
        $this->assertSame(403, $switchedOff->getStatusCode());
    }

    public function test_the_seller_can_still_rewrite_and_switch_off_their_own_coupon(): void
    {
        $rewritten = $this->sellerApi('PUT', 'coupon/update/' . self::OWN_COUPON, $this->couponUpdate(code: 'A-OWN-CODE', title: 'A coupon renamed', discount: 15));
        $this->assertSame(200, $rewritten->getStatusCode());
        $this->assertSame(['A coupon renamed', 15.0, 1], $this->couponTitleDiscountAndStatus(self::OWN_COUPON));

        $switchedOff = $this->sellerApi('PUT', 'coupon/status-update/' . self::OWN_COUPON, ['status' => 0]);
        $this->assertSame(200, $switchedOff->getStatusCode());
        $this->assertSame(['A coupon renamed', 15.0, 0], $this->couponTitleDiscountAndStatus(self::OWN_COUPON));
    }

    // ---- coupons: listing ----

    public function test_a_search_cannot_list_the_coupons_of_other_sellers(): void
    {
        $this->assertSame([], $this->listedCodes(search: 'zzz-nothing'));
        $this->assertSame([], $this->listedCodes(search: 'VIP'));
        $this->assertSame(['PLAT10'], $this->listedCodes(search: 'percent'));
    }

    public function test_the_seller_still_finds_their_own_coupons_by_any_search_term(): void
    {
        $this->assertSame(['A-OWN-CODE'], $this->listedCodes(search: 'own'));
        $this->assertSame(['A-OWN-CODE'], $this->listedCodes(search: 'own zzz-nothing'));
    }

    public function test_the_seller_still_lists_their_own_and_the_platform_wide_coupons_without_a_search(): void
    {
        $this->assertEqualsCanonicalizing(['A-OWN-CODE', 'PLAT10', 'ADMIN-FOR-1'], $this->listedCodes());
    }

    // ---- helpers ----

    private function assertNotFound($response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    /** @return array{0: float, 1: int}|array{} discount amount and active flag, or nothing when the entry is gone */
    private function clearanceRow(int $rowId): array
    {
        $row = DB::table('stock_clearance_products')->where('id', $rowId)->first();

        return $row ? [(float) $row->discount_amount, (int) $row->is_active] : [];
    }

    /** @return array{0: string, 1: float, 2: int} */
    private function couponTitleDiscountAndStatus(int $couponId): array
    {
        $coupon = DB::table('coupons')->where('id', $couponId)->first();

        return [$coupon->title, (float) $coupon->discount, (int) $coupon->status];
    }

    /** @return list<string> */
    private function listedCodes(?string $search = null): array
    {
        $response = $this->sellerApi('GET', 'coupon/list?limit=50&offset=1' . ($search !== null ? '&search=' . urlencode($search) : ''));
        $this->assertSame(200, $response->getStatusCode());

        return array_column($response->json('coupons'), 'code');
    }

    /** @return array<string, mixed> */
    private function couponUpdate(string $code, string $title, int $discount): array
    {
        return [
            'coupon_type' => 'discount_on_purchase', 'customer_id' => 0, 'limit' => 5, 'discount_type' => 'percentage', 'discount' => $discount,
            'min_purchase' => 0, 'code' => $code, 'title' => $title, 'start_date' => '2020-01-01', 'expire_date' => '2099-01-01', 'max_discount' => 1000,
        ];
    }

    private function createClearanceTables(): void
    {
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
            $table->integer('current_stock')->default(0);
            $table->text('category_ids')->nullable();
            $table->text('colors')->nullable();
            $table->text('attributes')->nullable();
            $table->text('choice_options')->nullable();
            $table->text('variation')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
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
        $this->createSellerApiTable('stock_clearance_setups', function (Blueprint $table) {
            $table->string('setup_by');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('discount_type')->default('product_wise');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('offer_active_time')->nullable();
            $table->string('offer_active_range_start')->nullable();
            $table->string('offer_active_range_end')->nullable();
            $table->dateTime('duration_start_date')->nullable();
            $table->dateTime('duration_end_date')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_products', function (Blueprint $table) {
            $table->string('added_by');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->timestamps();
        });
    }

    private function createCouponTables(): void
    {
        $this->createSellerApiTable('coupons', function (Blueprint $table) {
            $table->string('coupon_type')->nullable();
            $table->string('coupon_bearer')->nullable();
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('title')->nullable();
            $table->string('code')->nullable();
            $table->date('start_date')->nullable();
            $table->date('expire_date')->nullable();
            $table->decimal('min_purchase', 16, 2)->default(0);
            $table->decimal('max_discount', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('limit')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('orders', function (Blueprint $table) {
            $table->string('coupon_code')->nullable();
            $table->timestamps();
        });
    }

    private function seedClearanceSale(): void
    {
        $product = fn (int $id, int $userId, string $addedBy, string $name) => DB::table('products')->insert([
            'id' => $id, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => $name, 'slug' => 'product-' . $id, 'code' => 'CODE' . $id,
            'images' => '[]', 'thumbnail' => 'thumb.png', 'unit_price' => 100, 'current_stock' => 50,
            'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
        ]);
        $product(self::OWN_PRODUCT, self::SELLER, 'seller', 'Own product');
        $product(self::OWN_CLEARED_PRODUCT, self::SELLER, 'seller', 'Own product on clearance');
        $product(self::OTHER_SELLERS_PRODUCT, self::OTHER_SELLER, 'seller', 'Other sellers product');
        $product(self::OTHER_SELLERS_CLEARED_PRODUCT, self::OTHER_SELLER, 'seller', 'Other sellers product on clearance');
        $product(self::IN_HOUSE_PRODUCT, self::IN_HOUSE_ID, 'admin', 'In-house product');
        $product(self::IN_HOUSE_PRODUCT_NOT_ON_SALE, self::IN_HOUSE_ID, 'admin', 'In-house product not on sale');

        DB::table('shops')->insert([['seller_id' => self::SELLER, 'name' => 'Shop 1'], ['seller_id' => self::OTHER_SELLER, 'name' => 'Shop 2']]);

        $setup = fn (int $id, string $setupBy, ?int $userId) => DB::table('stock_clearance_setups')->insert([
            'id' => $id, 'setup_by' => $setupBy, 'user_id' => $userId, 'shop_id' => $userId, 'discount_type' => 'product_wise', 'offer_active_time' => 'always',
            'duration_start_date' => '2020-01-01', 'duration_end_date' => '2099-01-01',
        ]);
        $setup(1, 'vendor', self::SELLER);
        $setup(2, 'vendor', self::OTHER_SELLER);
        $setup(3, 'admin', null);

        $row = fn (int $id, string $addedBy, ?int $userId, int $productId, int $setupId, int $percentage) => DB::table('stock_clearance_products')->insert([
            'id' => $id, 'added_by' => $addedBy, 'user_id' => $userId, 'is_active' => 1, 'discount_type' => 'percentage', 'discount_amount' => $percentage,
            'product_id' => $productId, 'setup_id' => $setupId,
        ]);
        $row(self::OWN_CLEARANCE_ROW, 'vendor', self::SELLER, self::OWN_CLEARED_PRODUCT, 1, 10);
        $row(self::IN_HOUSE_CLEARANCE_ROW, 'admin', null, self::IN_HOUSE_PRODUCT, 3, 20);
        $row(self::OTHER_SELLERS_CLEARANCE_ROW, 'vendor', self::OTHER_SELLER, self::OTHER_SELLERS_CLEARED_PRODUCT, 2, 10);
    }

    private function seedCoupons(): void
    {
        $coupon = fn (int $id, string $addedBy, ?int $sellerId, string $title, string $code, string $discountType, float $discount, int $customerId = 0) => DB::table('coupons')->insert([
            'id' => $id, 'coupon_type' => 'discount_on_purchase', 'coupon_bearer' => 'seller', 'added_by' => $addedBy, 'seller_id' => $sellerId, 'customer_id' => $customerId,
            'title' => $title, 'code' => $code, 'start_date' => '2020-01-01', 'expire_date' => '2099-01-01', 'min_purchase' => 100, 'max_discount' => 50,
            'discount' => $discount, 'discount_type' => $discountType, 'status' => 1, 'limit' => 5,
        ]);
        $coupon(self::OWN_COUPON, 'seller', self::SELLER, 'A coupon', 'A-OWN-CODE', 'amount', 5);
        $coupon(self::OTHER_SELLERS_COUPON, 'seller', self::OTHER_SELLER, 'VIP customer only', 'B-SECRET-VIP-50', 'percentage', 50, customerId: 77);
        $coupon(self::PLATFORM_COUPON, 'admin', 0, 'Platform 10', 'PLAT10', 'percentage', 10);
        $coupon(self::ADMIN_COUPON_FOR_THIS_SELLER, 'admin', self::SELLER, 'Admin coupon for seller 1', 'ADMIN-FOR-1', 'amount', 5);
    }
}
