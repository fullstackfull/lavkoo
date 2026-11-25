<?php

namespace Tests\Feature\Security;

use App\Services\Marketplace\SellerOrderTimelineService;
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
 * The seller apps read an order's lines, what it earns and how it progressed, upload the file a digital line delivers, read a line's refund
 * and print a point-of-sale invoice by an id the app sends. A line carries the seller's id, and the in-house shop's lines carry id 1 as well, so each lookup must also say that
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

    // ---- what an order earns and how it progressed ----

    public function test_v3_the_breakdown_of_the_in_house_order_sharing_the_sellers_id_is_not_found(): void
    {
        $response = $this->sellerApi('GET', 'orders/' . self::IN_HOUSE_ORDER . '/breakdown');

        $this->assertNull($response->json('fees'), 'the in-house shop\'s totals were returned');
        $this->assertNotFound($response, code: 'order');
    }

    public function test_v3_the_breakdown_of_another_sellers_order_is_not_found(): void
    {
        $response = $this->sellerApi('GET', 'orders/' . self::OTHER_SELLERS_ORDER . '/breakdown');

        $this->assertNull($response->json('fees'));
        $this->assertNotFound($response, code: 'order');
    }

    public function test_v3_the_seller_can_still_read_the_breakdown_and_timeline_of_their_own_order(): void
    {
        DB::table('orders')->where('id', self::OWN_ORDER)->update(['created_at' => now()->subHour()]);

        $response = $this->sellerApi('GET', 'orders/' . self::OWN_ORDER . '/breakdown');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::OWN_ORDER, $response->json('order_id'));
        $this->assertSame(1, $response->json('fees.items.count'));
        $this->assertSame(2, $response->json('fees.items.quantity'));
        $this->assertEqualsWithDelta(200.0, (float) $response->json('fees.items.price'), 0.001);
        $this->assertSame(['order_placed'], array_column($response->json('timeline'), 'key'));
    }

    public function test_the_timeline_of_the_in_house_order_sharing_the_sellers_id_is_not_found(): void
    {
        DB::table('orders')->where('id', self::IN_HOUSE_ORDER)->update(['created_at' => now()->subHour()]);

        $this->assertNull(app(SellerOrderTimelineService::class)->timelineFor(orderId: self::IN_HOUSE_ORDER, sellerId: self::SELLER));
    }

    public function test_the_timeline_of_another_sellers_order_is_not_found(): void
    {
        DB::table('orders')->where('id', self::OTHER_SELLERS_ORDER)->update(['created_at' => now()->subHour()]);

        $this->assertNull(app(SellerOrderTimelineService::class)->timelineFor(orderId: self::OTHER_SELLERS_ORDER, sellerId: self::SELLER));
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

    // ---- why a shipment has not left (H1-04, H1-06) ----

    /**
     * A shipment can be held for something the seller can act on -- a product with no permission to reach the destination, a regulated
     * product with no licence on file -- and the reason was written by the dispatcher and read only by the admin panel. Now the seller
     * reads it, in their own language, and reads nothing else: not the carrier, which is the platform's arrangement, and not the
     * provider's own error text, which can carry a carrier's API message.
     */
    public function test_the_seller_reads_why_their_own_shipment_is_held_and_nothing_else(): void
    {
        $this->createProviderFulfillmentsTable();
        $this->aHeldShipment(orderId: self::OWN_ORDER, reason: 'hold_route_unsupported', detail: 'Torod said: ERR_UNSUPPORTED_ROUTE');

        $response = $this->sellerApi('GET', 'orders/' . self::OWN_ORDER . '/shipment-holds');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $response->json('holds'));
        $this->assertSame('hold_route_unsupported', $response->json('holds.0.reason_code'));
        $this->assertSame(translate('hold_route_unsupported'), $response->json('holds.0.reason'));
        $this->assertNotSame('hold_route_unsupported', $response->json('holds.0.reason'), 'the key itself is not an answer');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('ERR_UNSUPPORTED_ROUTE', $body, "the carrier's own error text is not the seller's to read");
        $this->assertStringNotContainsString('torod', strtolower($body), 'nor is which carrier the platform uses');
    }

    public function test_an_order_with_nothing_held_answers_an_empty_list(): void
    {
        $this->createProviderFulfillmentsTable();

        $response = $this->sellerApi('GET', 'orders/' . self::OWN_ORDER . '/shipment-holds');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json('holds'));
    }

    public function test_the_holds_of_another_sellers_order_are_not_found(): void
    {
        $this->createProviderFulfillmentsTable();
        $this->aHeldShipment(orderId: self::OTHER_SELLERS_ORDER, reason: 'hold_route_unsupported');

        $response = $this->sellerApi('GET', 'orders/' . self::OTHER_SELLERS_ORDER . '/shipment-holds');

        $this->assertNull($response->json('holds'));
        $this->assertNotFound($response, code: 'order');
    }

    /** The in-house shop's lines carry id 1 as well, so a seller whose id is 1 must still not read its order. */
    public function test_the_holds_of_the_in_house_order_sharing_the_sellers_id_are_not_found(): void
    {
        $this->createProviderFulfillmentsTable();
        $this->aHeldShipment(orderId: self::IN_HOUSE_ORDER, reason: 'hold_route_unsupported');

        $response = $this->sellerApi('GET', 'orders/' . self::IN_HOUSE_ORDER . '/shipment-holds');

        $this->assertNull($response->json('holds'));
        $this->assertNotFound($response, code: 'order');
    }

    /** A shipment that is not held is not a reason to show the seller anything. */
    public function test_only_held_shipments_are_listed(): void
    {
        $this->createProviderFulfillmentsTable();
        $this->aHeldShipment(orderId: self::OWN_ORDER, reason: 'hold_route_unsupported');
        $this->aHeldShipment(orderId: self::OWN_ORDER, reason: null, status: 'dispatched');

        $this->assertCount(1, $this->sellerApi('GET', 'orders/' . self::OWN_ORDER . '/shipment-holds')->json('holds'));
    }

    /** A shop whose fulfilment tables have never been created answers an empty list rather than failing. */
    public function test_a_shop_without_the_fulfillment_table_answers_an_empty_list(): void
    {
        Schema::dropIfExists('provider_fulfillments');

        $response = $this->sellerApi('GET', 'orders/' . self::OWN_ORDER . '/shipment-holds');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->json('holds'));
    }

    private function createProviderFulfillmentsTable(): void
    {
        Schema::dropIfExists('provider_fulfillments');
        Schema::create('provider_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('provider')->nullable();
            $table->string('status')->default('pending');
            $table->string('hold_reason')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });
    }

    private function aHeldShipment(int $orderId, ?string $reason, ?string $detail = null, string $status = 'held'): void
    {
        DB::table('provider_fulfillments')->insert([
            'reference' => 'PF-' . $orderId . '-' . $status . '-' . ($reason ?? 'none'),
            'order_id' => $orderId,
            'provider' => 'torod_point',
            'status' => $status,
            'hold_reason' => $reason,
            'last_error_message' => $detail,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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
}
