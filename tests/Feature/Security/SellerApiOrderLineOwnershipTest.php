<?php

namespace Tests\Feature\Security;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsSellerApiOrders;
use Tests\TestCase;

/**
 * The seller apps read an order's lines, upload the file a digital line delivers, read a line's refund and print a point-of-sale invoice by
 * an id the app sends. A line carries the seller's id, and the in-house shop's lines carry id 1 as well, so each lookup must also say that
 * the order is a seller's order: another seller's order and the in-house order whose lines carry the caller's id are not found, nothing is
 * read from them and the file they delivered is not replaced.
 */
class SellerApiOrderLineOwnershipTest extends TestCase
{
    use BuildsSellerApiOrders;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->buildSellerApiWorld();
        $this->createSellerApiOrderTables();
        $this->createRefundAndInvoiceTables();
        $this->createTablesTheOrderDetailsRelationsReadFrom();
        $this->seedSellerApiOrders();
        $this->seedDeliveredFiles();
    }

    protected function tearDown(): void
    {
        $this->tearDownSellerApiWorld();

        parent::tearDown();
    }

    // ---- order lines: API v2 ----

    public function test_v2_the_lines_of_the_in_house_order_sharing_the_sellers_id_are_not_returned(): void
    {
        $response = $this->sellerApiV2('GET', 'orders/' . self::IN_HOUSE_ORDER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('In-house secret item', (string) $response->getContent());
        $this->assertSame([], $response->json());
    }

    public function test_v2_the_lines_of_another_sellers_order_are_not_returned(): void
    {
        $response = $this->sellerApiV2('GET', 'orders/' . self::OTHER_SELLERS_ORDER);

        $this->assertStringNotContainsString('Other sellers item', (string) $response->getContent());
        $this->assertSame([], $response->json());
    }

    public function test_v2_the_seller_can_still_read_the_lines_of_their_own_order(): void
    {
        $response = $this->sellerApiV2('GET', 'orders/' . self::OWN_ORDER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_LINE], array_column($response->json(), 'id'));
        $this->assertSame('Own item', $response->json('0.product_details.name'));
        $this->assertSame(self::OWN_ORDER, $response->json('0.order.id'));
    }

    // ---- order lines: API v3 ----

    public function test_v3_the_lines_of_the_in_house_order_sharing_the_sellers_id_are_not_found(): void
    {
        $response = $this->sellerApi('GET', 'orders/' . self::IN_HOUSE_ORDER);

        $this->assertStringNotContainsString('In-house secret item', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order');
    }

    public function test_v3_the_lines_of_another_sellers_order_are_not_found(): void
    {
        $response = $this->sellerApi('GET', 'orders/' . self::OTHER_SELLERS_ORDER);

        $this->assertStringNotContainsString('Other sellers item', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order');
    }

    public function test_v3_the_seller_can_still_read_the_lines_of_their_own_order(): void
    {
        $response = $this->sellerApi('GET', 'orders/' . self::OWN_ORDER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([self::OWN_LINE], array_column($response->json(), 'id'));
        $this->assertSame('Own item', $response->json('0.product_details.name'));
    }

    // ---- the file a digital line delivers ----

    public function test_v2_the_file_delivered_by_the_in_house_line_sharing_the_sellers_id_is_not_replaced(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/order-wise-product-upload', ['order_id' => self::IN_HOUSE_LINE, 'digital_file_after_sell' => $this->pdf()]);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertDeliveredFileKept(self::IN_HOUSE_LINE, 'in-house-delivered.pdf');
    }

    public function test_v2_the_file_delivered_by_another_sellers_line_is_not_replaced(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/order-wise-product-upload', ['order_id' => self::OTHER_SELLERS_LINE, 'digital_file_after_sell' => $this->pdf()]);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertDeliveredFileKept(self::OTHER_SELLERS_LINE, 'other-sellers-delivered.pdf');
    }

    public function test_v2_the_seller_can_still_replace_the_file_delivered_by_their_own_line(): void
    {
        $response = $this->sellerApiV2('PUT', 'orders/order-wise-product-upload', ['order_id' => self::OWN_LINE, 'digital_file_after_sell' => $this->pdf()]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDeliveredFileReplaced(self::OWN_LINE, 'own-delivered.pdf');
        $this->assertDeliveredFileKept(self::IN_HOUSE_LINE, 'in-house-delivered.pdf');
    }

    public function test_v3_the_file_delivered_by_the_in_house_line_sharing_the_sellers_id_is_not_replaced(): void
    {
        $response = $this->sellerApi('PUT', 'orders/order-wise-product-upload', $this->v3Upload(self::IN_HOUSE_LINE));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertDeliveredFileKept(self::IN_HOUSE_LINE, 'in-house-delivered.pdf');
    }

    public function test_v3_the_file_delivered_by_another_sellers_line_is_not_replaced(): void
    {
        $response = $this->sellerApi('PUT', 'orders/order-wise-product-upload', $this->v3Upload(self::OTHER_SELLERS_LINE));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertDeliveredFileKept(self::OTHER_SELLERS_LINE, 'other-sellers-delivered.pdf');
    }

    public function test_v3_the_seller_can_still_replace_the_file_delivered_by_their_own_line(): void
    {
        $response = $this->sellerApi('PUT', 'orders/order-wise-product-upload', $this->v3Upload(self::OWN_LINE));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDeliveredFileReplaced(self::OWN_LINE, 'own-delivered.pdf');
        $this->assertDeliveredFileKept(self::IN_HOUSE_LINE, 'in-house-delivered.pdf');
    }

    // ---- the refund of a line ----

    public function test_v2_the_refund_of_the_in_house_line_sharing_the_sellers_id_is_not_found_and_discloses_no_delivery_man(): void
    {
        $response = $this->sellerApiV2('GET', 'refund/refund-details?order_details_id=' . self::IN_HOUSE_LINE);

        $this->assertStringNotContainsString('InHouseDriver', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order-details');
    }

    public function test_v2_the_refund_of_another_sellers_line_is_not_found_and_discloses_no_delivery_man(): void
    {
        $response = $this->sellerApiV2('GET', 'refund/refund-details?order_details_id=' . self::OTHER_SELLERS_LINE);

        $this->assertStringNotContainsString('OtherSellersDriver', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order-details');
    }

    public function test_v2_the_seller_can_still_read_the_refund_of_their_own_line(): void
    {
        $response = $this->sellerApiV2('GET', 'refund/refund-details?order_details_id=' . self::OWN_LINE);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(100.0, (float) $response->json('product_price'), 0.001);
        $this->assertSame(2, $response->json('quntity'));
        $this->assertEqualsWithDelta(200.0, (float) $response->json('subtotal'), 0.001);
        $this->assertSame(self::OWN_DELIVERY_MAN, $response->json('deliveryman_details.id'));
    }

    public function test_v3_the_refund_of_the_in_house_line_sharing_the_sellers_id_is_not_found_and_discloses_no_delivery_man(): void
    {
        $response = $this->sellerApi('GET', 'refund/refund-details?order_details_id=' . self::IN_HOUSE_LINE);

        $this->assertStringNotContainsString('InHouseDriver', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order-details');
    }

    public function test_v3_the_refund_of_another_sellers_line_is_not_found_and_discloses_no_delivery_man(): void
    {
        $response = $this->sellerApi('GET', 'refund/refund-details?order_details_id=' . self::OTHER_SELLERS_LINE);

        $this->assertStringNotContainsString('OtherSellersDriver', (string) $response->getContent());
        $this->assertNotFound($response, code: 'order-details');
    }

    public function test_v3_the_seller_can_still_read_the_refund_of_their_own_line(): void
    {
        $response = $this->sellerApi('GET', 'refund/refund-details?order_details_id=' . self::OWN_LINE);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(100.0, (float) $response->json('product_price'), 0.001);
        $this->assertSame(2, $response->json('quntity'));
        $this->assertEqualsWithDelta(200.0, (float) $response->json('refund_amount'), 0.001);
        $this->assertSame(self::OWN_DELIVERY_MAN, $response->json('deliveryman_details.id'));
    }

    // ---- the point-of-sale invoice ----

    public function test_the_invoice_of_the_in_house_order_sharing_the_sellers_id_is_not_returned(): void
    {
        $response = $this->sellerApi('GET', 'pos/get-invoice?id=' . self::IN_HOUSE_ORDER);

        $this->assertStringNotContainsString('In-house secret item', (string) $response->getContent());
        $this->assertSame([], $response->json());
    }

    public function test_the_invoice_of_another_sellers_order_is_not_returned(): void
    {
        $response = $this->sellerApi('GET', 'pos/get-invoice?id=' . self::OTHER_SELLERS_ORDER);

        $this->assertStringNotContainsString('Other sellers item', (string) $response->getContent());
        $this->assertSame([], $response->json());
    }

    public function test_the_seller_can_still_read_the_invoice_of_their_own_order(): void
    {
        $response = $this->sellerApi('GET', 'pos/get-invoice?id=' . self::OWN_ORDER);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::OWN_ORDER, $response->json('id'));
        $this->assertSame(['Own item'], array_column(array_column($response->json('details'), 'product_details'), 'name'));
    }

    // ---- helpers ----

    private function assertNotFound(TestResponse $response, string $code): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($code, $response->json('errors.0.code'));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');
    }

    /** The v3 form request validates digital_file_ready, while the handler and the app read digital_file_after_sell. */
    private function v3Upload(int $lineId): array
    {
        return ['order_id' => $lineId, 'digital_file_ready' => $this->pdf(), 'digital_file_after_sell' => $this->pdf()];
    }

    private function deliveredFileOf(int $lineId): ?string
    {
        return DB::table('order_details')->where('id', $lineId)->value('digital_file_after_sell');
    }

    private function assertDeliveredFileKept(int $lineId, string $file): void
    {
        $this->assertSame($file, $this->deliveredFileOf($lineId));
        Storage::disk('public')->assertExists('product/digital-product/' . $file);
    }

    private function assertDeliveredFileReplaced(int $lineId, string $previousFile): void
    {
        $this->assertNotSame($previousFile, $this->deliveredFileOf($lineId));
        $this->assertNotNull($this->deliveredFileOf($lineId));
        Storage::disk('public')->assertExists('product/digital-product/' . $this->deliveredFileOf($lineId));
        Storage::disk('public')->assertMissing('product/digital-product/' . $previousFile);
    }

    private function seedDeliveredFiles(): void
    {
        foreach ([self::OWN_LINE => 'own-delivered.pdf', self::IN_HOUSE_LINE => 'in-house-delivered.pdf', self::OTHER_SELLERS_LINE => 'other-sellers-delivered.pdf'] as $lineId => $file) {
            DB::table('order_details')->where('id', $lineId)->update(['digital_file_after_sell' => $file]);
            Storage::disk('public')->put('product/digital-product/' . $file, 'delivered ' . $file);
        }
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['delivery_man_id' => self::OWN_DELIVERY_MAN]);
    }

    private function createRefundAndInvoiceTables(): void
    {
        $this->createSellerApiTable('refund_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('order_details_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->nullable();
            $table->text('images')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('shipping_methods', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->timestamps();
        });
        Schema::table('sellers', fn (Blueprint $table) => $table->boolean('pos_status')->default(true));
        DB::table('business_settings')->insert(['type' => 'seller_pos', 'value' => '1']);
        Cache::flush();
    }

    private function createTablesTheOrderDetailsRelationsReadFrom(): void
    {
        $this->createSellerApiTable('users', fn (Blueprint $table) => $table->string('f_name')->nullable());
        $this->createSellerApiTable('offline_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });
        $this->createSellerApiTable('order_delivery_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->string('image')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('order_edit_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->timestamps();
        });
        $this->createSellerApiTable('products', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->nullable();
            $table->string('name')->nullable();
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
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        $this->createSellerApiTable('digital_product_variations', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->string('variant_key')->nullable();
            $table->string('file')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('product_seos', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_products', function (Blueprint $table) {
            $table->string('added_by')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('setup_id')->nullable();
            $table->integer('is_active')->default(1);
            $table->timestamps();
        });
        $this->createSellerApiTable('stock_clearance_setups', function (Blueprint $table) {
            $table->integer('is_active')->default(1);
            $table->dateTime('duration_start_date')->nullable();
            $table->dateTime('duration_end_date')->nullable();
            $table->string('offer_active_time')->nullable();
            $table->string('offer_active_range_start')->nullable();
            $table->string('offer_active_range_end')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('translations', function (Blueprint $table) {
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $this->createSellerApiTable('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        $this->createSellerApiTable('categories', fn (Blueprint $table) => $table->string('name')->nullable());
        $this->createSellerApiTable('brands', fn (Blueprint $table) => $table->string('name')->nullable());

        foreach ([[10, self::SELLER, 'seller'], [20, self::OTHER_SELLER, 'seller'], [30, self::IN_HOUSE_ID, 'admin']] as [$productId, $userId, $addedBy]) {
            DB::table('products')->insert([
                'id' => $productId, 'user_id' => $userId, 'added_by' => $addedBy, 'name' => 'Product ' . $productId, 'images' => '[]', 'thumbnail' => 'thumb.png', 'unit_price' => 100,
                'current_stock' => 50, 'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
            ]);
        }
        Cache::put('tax_system_type_vendor_with_vat', [
            'SystemTaxVat' => null, 'SystemTaxVatType' => 'order_wise', 'is_included' => 1, 'productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => collect([]),
        ], 3600);
    }
}
