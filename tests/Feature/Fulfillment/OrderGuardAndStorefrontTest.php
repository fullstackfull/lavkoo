<?php

namespace Tests\Feature\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\CustomerFulfillmentPresenter;
use App\Services\Fulfillment\FulfillmentOrderGuard;
use App\Services\Fulfillment\MixedProviderNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * What stops an order being called delivered too early, and what a customer is shown about their shipments.
 */
class OrderGuardAndStorefrontTest extends AdminPagesTestCase
{
    private const NOTICE_AR = 'يحتوي طلبك على منتجات من مستودعات مختلفة، لذلك قد يصلك على شحنتين منفصلتين وفي أوقات مختلفة، دون الحاجة إلى إنشاء طلبين.';

    /** auth('api') builds Passport's token guard, which refuses to exist without an RSA key pair. */
    private function useThrowawayPassportKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    }

    private function guard(): FulfillmentOrderGuard
    {
        return app(FulfillmentOrderGuard::class);
    }

    public function test_an_order_with_no_provider_work_is_never_blocked(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1);

        $this->assertNull($this->guard()->deliveryBlockedReason(100));
    }

    public function test_an_order_cannot_be_delivered_while_a_provider_shipment_is_unfinished(): void
    {
        foreach ([FulfillmentStatus::Held, FulfillmentStatus::Submitted, FulfillmentStatus::Processing, FulfillmentStatus::InTransit, FulfillmentStatus::ReconcilePending, FulfillmentStatus::Failed] as $index => $status) {
            $orderId = 100 + $index;
            $this->shipment($orderId, ProviderCode::M5azn, $status, [], productId: 10 + $index);

            $this->assertSame('Provider shipments for this order are not all delivered yet.', $this->guard()->deliveryBlockedReason($orderId), $status->value);
        }
    }

    public function test_packing_in_the_warehouse_is_not_delivery(): void
    {
        $fulfillment = $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Processing, ['warehouse_status' => 'PACKED']);

        $this->assertNotNull($this->guard()->deliveryBlockedReason(100));
        $this->assertSame('PACKED', $fulfillment->refresh()->warehouse_status);
    }

    public function test_an_order_may_be_delivered_once_every_shipment_is_delivered_or_cancelled(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Delivered);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Cancelled, ['reference' => '100-TP1'], productId: 2);

        $this->assertNull($this->guard()->deliveryBlockedReason(100));
    }

    public function test_one_delivered_parcel_out_of_two_is_not_a_delivered_order(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Delivered);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::InTransit, ['reference' => '100-TP1'], productId: 2);

        $this->assertNotNull($this->guard()->deliveryBlockedReason(100));
    }

    public function test_a_line_that_was_never_handed_to_its_provider_also_blocks_delivery(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => 'pending', 'payment_status' => 'unpaid']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertSame(1, ProviderFulfillmentItem::where('order_id', 100)->count());
        $this->assertNotNull($this->guard()->deliveryBlockedReason(100), 'a provider-owned line nobody has shipped is not a delivered order');
    }

    public function test_an_order_with_provider_lines_cannot_be_edited_in_any_state(): void
    {
        foreach ([FulfillmentStatus::Held, FulfillmentStatus::Submitted, FulfillmentStatus::Delivered] as $index => $status) {
            $orderId = 300 + $index;
            $this->shipment($orderId, ProviderCode::M5azn, $status, [], productId: 20 + $index);

            $this->assertNotNull($this->guard()->editBlockedReason($orderId), $status->value);
        }
    }

    public function test_a_line_nobody_has_handed_over_yet_also_blocks_the_edit(): void
    {
        $this->makeProduct(1, ProviderCode::TorodPoint);
        $this->makeOrder(100, ['order_status' => 'pending']);
        $this->addLine(100, 1, 1, 50, ProviderCode::TorodPoint);

        $this->assertNotNull($this->guard()->editBlockedReason(100));
    }

    public function test_an_in_house_order_stays_editable(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100, ['order_status' => 'pending']);
        $this->addLine(100, 1);

        $this->assertNull($this->guard()->editBlockedReason(100, [['product_id' => 1, 'qty' => 3]]));
    }

    public function test_a_provider_product_cannot_be_added_to_an_in_house_order_by_an_edit(): void
    {
        $this->makeProduct(1);
        $this->makeProduct(2, ProviderCode::M5azn);
        $this->makeOrder(100, ['order_status' => 'confirmed']);
        $this->addLine(100, 1);

        $reason = $this->guard()->editBlockedReason(100, [['product_id' => 1], ['product_id' => 2]]);

        $this->assertSame('A product fulfilled by a delivery partner cannot be added to an existing order.', $reason);
    }

    public function test_the_shared_edit_funnel_refuses_before_writing_anything(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Submitted);
        $before = [DB::table('orders')->where('id', 100)->first(), DB::table('order_details')->where('order_id', 100)->get()->all()];
        $editor = new class {
            use \App\Traits\OrderEditManager;
        };

        $result = $editor->generateEditOrder(order: \App\Models\Order::findOrFail(100), editedOrder: [['product_id' => 1, 'qty' => 99, 'price' => 1]]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('cannot be edited', $result['message']);
        $this->assertEquals($before, [DB::table('orders')->where('id', 100)->first(), DB::table('order_details')->where('order_id', 100)->get()->all()], 'nothing was written');
    }

    public function test_the_editability_check_the_panels_use_refuses_a_provider_order(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Held);
        DB::table('orders')->where('id', 100)->update(['order_status' => 'confirmed']);
        DB::table('order_details')->where('order_id', 100)->update(['product_details' => json_encode(['id' => 1, 'product_type' => 'physical'])]);

        $verdict = app(\App\Services\OrderEditService::class)->checkIsOrderEditable(\App\Models\Order::with('details')->findOrFail(100), 'admin');

        $this->assertFalse($verdict['status']);
        $this->assertStringContainsString('cannot be edited', $verdict['message']);
    }

    public function test_the_admin_status_endpoint_asks_the_guard_before_touching_stock_or_status(): void
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\Admin\Order\OrderController::class, 'updateStatus');
        $body = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('FulfillmentOrderGuard::class)->statusChangeBlockedReason', $body);
        $this->assertLessThan(strpos($body, 'updateStockOnOrderStatusChange'), strpos($body, 'statusChangeBlockedReason'), 'the guard runs before stock or status are touched');
    }

    public function test_the_notice_appears_only_when_the_cart_comes_from_more_than_one_source(): void
    {
        session(['local' => 'sa']);
        $this->makeProduct(1);
        $this->makeProduct(2, ProviderCode::TorodPoint);
        $this->makeProduct(3, ProviderCode::TorodPoint);
        $this->makeProduct(4, ProviderCode::M5azn);
        $notice = app(MixedProviderNotice::class);

        $this->assertNull($notice->forProducts([]), 'empty cart');
        $this->assertNull($notice->forProducts([1]), 'one product');
        $this->assertNull($notice->forProducts([2, 3]), 'two products, one source');
        $this->assertNull($notice->forProducts([1, 1]), 'the same product twice');

        foreach ([[1, 2], [2, 4], [1, 2, 3, 4]] as $cart) {
            $result = $notice->forProducts($cart);
            $this->assertSame(self::NOTICE_AR, $result['message'], json_encode($cart));
            $this->assertGreaterThanOrEqual(2, $result['sources']);
        }
    }

    public function test_the_notice_partial_renders_the_arabic_text_for_a_mixed_cart_and_nothing_otherwise(): void
    {
        session(['local' => 'sa']);
        $this->makeProduct(1);
        $this->makeProduct(2, ProviderCode::TorodPoint);

        $mixed = View::make('web-views.partials._mixed-provider-notice', ['cartProductIds' => [1, 2]])->render();
        $this->assertStringContainsString('data-mixed-provider-notice', $mixed);
        $this->assertStringContainsString(self::NOTICE_AR, $mixed);

        $this->assertSame('', trim(View::make('web-views.partials._mixed-provider-notice', ['cartProductIds' => [2]])->render()));
        $this->assertSame('', trim(View::make('web-views.partials._mixed-provider-notice')->render()));
    }

    public function test_a_customer_sees_shipments_and_tracking_but_never_a_provider_name_or_error(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::InTransit, [
            'tracking_number' => 'TRK-CUSTOMER', 'tracking_url' => 'https://track.example.test/TRK-CUSTOMER', 'provider_reference' => 'WH-INTERNAL-9', 'provider_status' => 'SECRET_PROVIDER_STATUS',
        ]);
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Failed, [
            'reference' => '100-MZ1', 'last_error_message' => 'Gateway timeout talking to supplier', 'tracking_number' => 'TRK-HIDDEN',
        ], productId: 2);

        $view = app(CustomerFulfillmentPresenter::class)->forOrder(100);

        $this->assertCount(2, $view['shipments']);
        $this->assertNotNull($view['notice'], 'two shipments of one order carry the explanation');
        $this->assertSame('TRK-CUSTOMER', $view['shipments'][0]['tracking_number']);
        $this->assertNull($view['shipments'][1]['tracking_number'], 'a shipment the provider never accepted has no tracking to show');

        $payload = json_encode($view, JSON_UNESCAPED_UNICODE);
        foreach (['M5AZN', 'Torod', 'تورود', 'LogesTechs', '100-TP1', '100-MZ1', 'WH-INTERNAL-9', 'SECRET_PROVIDER_STATUS', 'Gateway timeout', 'provider', 'torod_point', 'm5azn'] as $internal) {
            $this->assertStringNotContainsString($internal, $payload, "the customer must not see: {$internal}");
        }
    }

    public function test_cancelled_shipments_are_not_shown_to_the_customer(): void
    {
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Delivered);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::Cancelled, ['reference' => '100-TP1'], productId: 2);

        $view = app(CustomerFulfillmentPresenter::class)->forOrder(100);

        $this->assertCount(1, $view['shipments']);
        $this->assertSame('delivered', $view['status']);
    }

    public function test_an_order_without_provider_shipments_renders_exactly_as_before(): void
    {
        $this->makeProduct(1);
        $this->makeOrder(100);
        $this->addLine(100, 1);

        $this->assertNull(app(CustomerFulfillmentPresenter::class)->forOrder(100));
        $this->assertSame('', trim(View::make('web-views.partials._fulfillment-shipments', ['orderId' => 100])->render()));
    }

    public function test_the_shipments_partial_shows_each_parcel_with_a_tracking_link(): void
    {
        session(['local' => 'sa']);
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::OutForDelivery, ['tracking_number' => 'TRK-LINK', 'tracking_url' => 'https://track.example.test/TRK-LINK']);
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::Submitted, ['reference' => '100-MZ1'], productId: 2);

        $html = View::make('web-views.partials._fulfillment-shipments', ['orderId' => 100])->render();

        $this->assertStringContainsString('شحناتك', $html);
        $this->assertStringContainsString('href="https://track.example.test/TRK-LINK"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString(self::NOTICE_AR, $html);
        $this->assertStringNotContainsString('M5AZN', $html);
        $this->assertStringNotContainsString('Torod', $html);
    }

    public function test_the_order_endpoint_returns_only_the_callers_own_shipments(): void
    {
        $this->makeProduct(1, ProviderCode::M5azn);
        $this->makeOrder(100, ['customer_id' => 'guest-A', 'is_guest' => 1]);
        $this->addLine(100, 1, 1, 50, ProviderCode::M5azn);
        $this->shipment(100, ProviderCode::M5azn, FulfillmentStatus::InTransit, ['tracking_number' => 'TRK-OWN']);
        $this->withoutMiddleware();
        $this->useThrowawayPassportKeys();

        $own = $this->getJson('/api/v1/customer/order/fulfillments?order_id=100&guest_id=guest-A');
        $own->assertOk()->assertJsonPath('fulfillments.shipments.0.tracking_number', 'TRK-OWN');
        $this->assertStringNotContainsString('m5azn', strtolower((string) $own->getContent()));

        $this->getJson('/api/v1/customer/order/fulfillments?order_id=100&guest_id=guest-B')->assertNotFound();
        $this->getJson('/api/v1/customer/order/fulfillments?order_id=100')->assertNotFound();
        $this->getJson('/api/v1/customer/order/fulfillments?guest_id=guest-A')->assertStatus(403);
    }

    public function test_provider_rows_stay_out_of_every_table_the_storefront_already_reads(): void
    {
        $this->shipment(100, ProviderCode::TorodPoint, FulfillmentStatus::InTransit);

        $this->assertSame('confirmed', DB::table('orders')->where('id', 100)->value('order_status'), 'a provider status never writes the parent order status');
    }
}
