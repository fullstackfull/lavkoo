<?php

namespace Tests\Feature\Security;

use App\Events\OrderStatusEvent;
use App\Http\Controllers\RestAPI\v2\seller\RefundController as RefundControllerV2;
use App\Http\Controllers\RestAPI\v3\seller\OrderController;
use App\Http\Controllers\RestAPI\v3\seller\POSController;
use App\Http\Controllers\RestAPI\v3\seller\RefundController as RefundControllerV3;
use App\Http\Requests\API\v3\DigitalProductFileUploadAfterSell;
use App\Models\Seller;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RestoresLanguageFiles;
use Tests\TestCase;
use Throwable;

/**
 * The seller app reaches orders, order lines and refunds by an id it sends. Each lookup must answer only for the seller who asks:
 * an order of another seller, or an in-house order whose seller_id happens to equal this seller's id (in-house carts are stored
 * with seller_id 1 and seller_is admin), is not found.
 */
class SellerApiOrderScopingTest extends TestCase
{
    use RestoresLanguageFiles;

    private const SELLER_A = 1;
    private const SELLER_B = 2;
    private const SELLER_A_TOKEN = 'seller-a-token-that-is-longer-than-thirty-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberLanguageFiles();
        Event::fake([OrderStatusEvent::class]);
        Storage::fake('public');
        $this->createOrderTables();

        DB::table('sellers')->insert([
            ['id' => self::SELLER_A, 'f_name' => 'Seller A', 'auth_token' => self::SELLER_A_TOKEN, 'pos_status' => 1],
            ['id' => self::SELLER_B, 'f_name' => 'Seller B', 'auth_token' => null, 'pos_status' => 1],
        ]);
        DB::table('business_settings')->insert(['type' => 'seller_pos', 'value' => '1']);
    }

    protected function tearDown(): void
    {
        $this->restoreLanguageFiles();

        parent::tearDown();
    }

    public function test_v2_refund_details_of_another_sellers_order_line_without_a_refund_request_is_not_found(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_B, sellerIs: 'seller');

        $response = $this->v2RefundDetails(orderDetailsId: 500);

        $this->assertArrayNotHasKey('refund_amount', (array) json_decode((string) $response->getContent(), true));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('order-details', json_decode((string) $response->getContent(), true)['errors'][0]['code']);
    }

    public function test_v2_refund_details_of_an_in_house_order_line_sharing_the_sellers_id_is_not_found(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');

        $response = $this->v2RefundDetails(orderDetailsId: 500);

        $this->assertArrayNotHasKey('refund_amount', (array) json_decode((string) $response->getContent(), true));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_v2_refund_details_of_the_sellers_own_order_line_still_returns_the_refund_amount(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'seller');

        $response = $this->v2RefundDetails(orderDetailsId: 500);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertEquals(100, $body['refund_amount']);
        $this->assertSame([], $body['refund_request']);
    }

    public function test_v2_refund_status_update_of_another_sellers_refund_request_is_not_found_and_changes_nothing(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_B, sellerIs: 'seller');
        $refundRequestId = $this->insertRefundRequest(orderId: 100, orderDetailsId: 500);

        $response = $this->v2RefundStatusUpdate(refundRequestId: $refundRequestId);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('refund', json_decode((string) $response->getContent(), true)['errors'][0]['code']);
        $this->assertSame('pending', DB::table('refund_requests')->where('id', $refundRequestId)->value('status'));
        $this->assertSame(0, DB::table('refund_statuses')->count());
    }

    public function test_a_seller_updates_their_own_refund_request_with_valid_input(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'seller');
        $refundRequestId = $this->insertRefundRequest(orderId: 100, orderDetailsId: 500);

        $response = $this->v2RefundStatusUpdate(refundRequestId: $refundRequestId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('refund status updated successfully!', json_decode((string) $response->getContent(), true)['message']);

        $refundRequest = DB::table('refund_requests')->where('id', $refundRequestId)->first();
        $this->assertSame('rejected', $refundRequest->status);
        $this->assertSame('seller', $refundRequest->change_by);
        $this->assertSame('not eligible', $refundRequest->rejected_note);
        $this->assertNull($refundRequest->approved_note);

        $this->assertSame(3, (int) DB::table('order_details')->where('id', 500)->value('refund_request'));

        $refundStatuses = DB::table('refund_statuses')->where('refund_request_id', $refundRequestId)->get();
        $this->assertCount(1, $refundStatuses);
        $this->assertSame('seller', $refundStatuses[0]->change_by);
        $this->assertSame(self::SELLER_A, (int) $refundStatuses[0]->change_by_id);
        $this->assertSame('rejected', $refundStatuses[0]->status);
        $this->assertSame('not eligible', $refundStatuses[0]->message);

        Event::assertDispatched(OrderStatusEvent::class, fn (OrderStatusEvent $event) => $event->key === 'refund_request_canceled_message'
            && $event->type === 'customer' && $event->order?->id === 100);
    }

    public function test_v2_refund_status_update_of_an_unknown_refund_request_is_not_found(): void
    {
        $response = $this->v2RefundStatusUpdate(refundRequestId: 999);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('refund', json_decode((string) $response->getContent(), true)['errors'][0]['code']);
    }

    public function test_v3_refund_details_of_an_in_house_order_line_sharing_the_sellers_id_is_not_found(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');
        $this->insertRefundRequest(orderId: 100, orderDetailsId: 500);

        $response = $this->callEndpoint(fn (Request $request) => app(RefundControllerV3::class)->refund_details($request),
            $this->sellerRequest(uri: '/api/v3/seller/refund/refund-details', input: ['order_details_id' => 500]));

        $this->assertArrayNotHasKey('refund_amount', (array) json_decode((string) $response->getContent(), true));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_v3_refund_details_of_the_sellers_own_order_line_still_returns_the_refund_amount(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'seller');
        $this->insertRefundRequest(orderId: 100, orderDetailsId: 500);

        $response = $this->callEndpoint(fn (Request $request) => app(RefundControllerV3::class)->refund_details($request),
            $this->sellerRequest(uri: '/api/v3/seller/refund/refund-details', input: ['order_details_id' => 500]));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertEquals(100, $body['refund_amount']);
        $this->assertCount(1, $body['refund_request']);
    }

    public function test_assigning_a_delivery_man_to_another_sellers_order_is_not_found_and_changes_nothing(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_B, sellerIs: 'seller');

        $response = $this->assignDeliveryMan(orderId: 100);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull(DB::table('orders')->where('id', 100)->value('delivery_man_id'));
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_assigning_a_delivery_man_to_an_in_house_order_sharing_the_sellers_id_is_not_found_and_changes_nothing(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');

        $response = $this->assignDeliveryMan(orderId: 100);

        $this->assertNull(DB::table('orders')->where('id', 100)->value('delivery_man_id'));
        $this->assertSame(404, $response->getStatusCode());
        Event::assertNotDispatched(OrderStatusEvent::class);
    }

    public function test_the_seller_can_still_assign_a_delivery_man_to_their_own_order(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'seller');

        $response = $this->assignDeliveryMan(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(9, (int) DB::table('orders')->where('id', 100)->value('delivery_man_id'));
        Event::assertDispatched(OrderStatusEvent::class);
    }

    public function test_the_order_details_of_an_in_house_order_sharing_the_sellers_id_are_not_found(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');

        $response = $this->callEndpoint(fn (Request $request) => app(OrderController::class)->details($request, 100),
            $this->sellerRequest(uri: '/api/v3/seller/orders/100', input: []));

        $this->assertStringNotContainsString('"order_id":100', (string) $response->getContent());
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_a_digital_file_cannot_be_uploaded_to_an_in_house_order_line_sharing_the_sellers_id(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');
        $request = DigitalProductFileUploadAfterSell::create('/api/v3/seller/orders/order-wise-product-upload', 'POST', ['order_id' => 500], [], [
            'digital_file_after_sell' => UploadedFile::fake()->create('replacement.pdf', 1, 'application/pdf'),
        ]);
        $request->headers->set('Accept', 'application/json');
        $request['seller'] = $this->sellerA();

        $response = $this->callEndpoint(fn (Request $request) => app(OrderController::class)->digital_file_upload_after_sell($request), $request);

        $this->assertSame('original.pdf', DB::table('order_details')->where('id', 500)->value('digital_file_after_sell'));
        $this->assertSame(202, $response->getStatusCode());
    }

    public function test_the_pos_invoice_of_an_in_house_order_sharing_the_sellers_id_is_not_returned(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'admin');

        $response = $this->posInvoice(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], (array) json_decode((string) $response->getContent(), true));
    }

    public function test_the_sellers_own_pos_invoice_is_still_returned(): void
    {
        $this->insertOrderWithLine(orderId: 100, orderDetailsId: 500, sellerId: self::SELLER_A, sellerIs: 'seller');

        $response = $this->posInvoice(orderId: 100);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(100, json_decode((string) $response->getContent(), true)['id']);
    }

    /**
     * Calls the endpoint and, if it throws, renders the exception the way the HTTP kernel would, so a test sees the status a
     * seller app would receive (a crash on a missing row is a 500, not a passed guard).
     */
    private function callEndpoint(Closure $endpoint, Request $request): Response
    {
        try {
            return $endpoint($request);
        } catch (Throwable $exception) {
            return $this->app->make(ExceptionHandler::class)->render($request, $exception);
        }
    }

    private function v2RefundDetails(int $orderDetailsId): Response
    {
        $request = $this->bearerRequest(uri: '/api/v2/seller/refund/refund-details', method: 'GET', input: ['order_details_id' => $orderDetailsId]);

        return $this->callEndpoint(fn (Request $request) => app(RefundControllerV2::class)->refund_details($request), $request);
    }

    private function v2RefundStatusUpdate(int $refundRequestId): Response
    {
        $request = $this->bearerRequest(uri: '/api/v2/seller/refund/refund-status-update', method: 'POST', input: [
            'refund_request_id' => $refundRequestId,
            'refund_status' => 'rejected',
            'note' => 'not eligible',
        ]);

        return $this->callEndpoint(fn (Request $request) => app(RefundControllerV2::class)->refund_status_update($request), $request);
    }

    private function posInvoice(int $orderId): Response
    {
        return $this->callEndpoint(fn (Request $request) => app(POSController::class)->get_invoice($request),
            $this->sellerRequest(uri: '/api/v3/seller/pos/get-invoice', input: ['id' => $orderId]));
    }

    private function bearerRequest(string $uri, string $method, array $input): Request
    {
        $request = Request::create($uri, $method, $input);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer ' . self::SELLER_A_TOKEN);

        return $request;
    }

    private function assignDeliveryMan(int $orderId): Response
    {
        return $this->callEndpoint(fn (Request $request) => app(OrderController::class)->assign_delivery_man($request),
            $this->sellerRequest(uri: '/api/v3/seller/orders/assign-delivery-man', input: [
                'order_id' => $orderId,
                'delivery_man_id' => 9,
            ]));
    }

    private function sellerA(): Seller
    {
        return Seller::findOrFail(self::SELLER_A);
    }

    private function sellerRequest(string $uri, array $input): Request
    {
        $request = Request::create($uri, 'GET', $input);
        $request->headers->set('Accept', 'application/json');
        $request['seller'] = $this->sellerA();

        return $request;
    }

    private function insertOrderWithLine(int $orderId, int $orderDetailsId, int $sellerId, string $sellerIs): void
    {
        DB::table('orders')->insert([
            'id' => $orderId, 'customer_id' => 7, 'seller_id' => $sellerId, 'seller_is' => $sellerIs,
            'order_amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_details')->insert([
            'id' => $orderDetailsId, 'order_id' => $orderId, 'product_id' => 1, 'seller_id' => $sellerId,
            'product_details' => json_encode([
                'id' => 1, 'name' => 'Item', 'product_type' => 'physical', 'unit_price' => 100, 'current_stock' => 5,
                'category_ids' => '[]', 'colors' => '[]', 'attributes' => '[]', 'choice_options' => '[]', 'variation' => '[]',
            ]),
            'variation' => '[]', 'qty' => 1, 'price' => 100,
            'digital_file_after_sell' => 'original.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertRefundRequest(int $orderId, int $orderDetailsId): int
    {
        return DB::table('refund_requests')->insertGetId([
            'order_details_id' => $orderDetailsId, 'order_id' => $orderId, 'customer_id' => 7,
            'status' => 'pending', 'amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createOrderTables(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('auth_token')->nullable();
            $table->boolean('pos_status')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('seller_is')->default('admin');
            $table->decimal('order_amount', 16, 2)->default(0);
            $table->decimal('init_order_amount', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->dateTime('deliveryman_assigned_at')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_service_name')->nullable();
            $table->string('third_party_delivery_tracking_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->longText('product_details')->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('discount', 16, 2)->default(0);
            $table->decimal('tax', 16, 2)->default(0);
            $table->string('digital_file_after_sell')->nullable();
            $table->string('variant')->nullable();
            $table->text('variation')->nullable();
            $table->boolean('is_stock_decreased')->default(true);
            $table->integer('refund_request')->default(0);
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_details_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 16, 2)->default(0);
            $table->text('images')->nullable();
            $table->string('change_by')->nullable();
            $table->text('approved_note')->nullable();
            $table->text('rejected_note')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_request_id');
            $table->string('change_by')->nullable();
            $table->unsignedBigInteger('change_by_id')->nullable();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });

        Schema::create('offline_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->text('payment_info')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->timestamps();
        });

        Schema::create('order_delivery_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('image')->nullable();
            $table->timestamps();
        });

        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_status')->nullable();
            $table->string('order_return_payment_status')->nullable();
            $table->decimal('order_due_amount', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('system_tax_setups', function (Blueprint $table) {
            $table->id();
            $table->string('tax_payer')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_included')->default(false);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type');
            $table->unsignedBigInteger('data_id');
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });
    }
}
