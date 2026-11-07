<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderApiLog;
use App\Models\ProviderFulfillment;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fulfillment\TorodTestCase;
use Tests\Support\BuildsDispatchWorld;

/** The REAL Torod Point adapter (against faked HTTP) given an order the dispatcher made: the buyer reaches Torod in the form its documents ask for, once, and leaves no trace in the logs. */
class DispatchedOrderReachesTorodTest extends TorodTestCase
{
    use BuildsDispatchWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDispatchWorld();
    }

    private function dispatchedFulfillment(): ProviderFulfillment
    {
        $orderId = (int) $this->dispatcher()->dispatch($this->placed())->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);

        return ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
    }

    private function realSends(): void
    {
        $this->enableProvider(ProviderCode::TorodPoint, ['company_id' => '714', 'dry_run' => false, 'dispatch_enabled' => true, 'auto_dispatch' => true], [
            'fulfillment_username' => 'wh-user', 'fulfillment_password' => 'wh-pass-secret-9', 'lastmile_email' => 'lm@example.test', 'lastmile_password' => 'lm-pass-secret-9', 'webhook_secret' => 'hook-secret-abcdef',
        ]);
    }

    public function test_the_buyer_reaches_torod_as_receiver_in_the_form_it_asks_for_and_the_shipment_is_held_once(): void
    {
        $fulfillment = $this->dispatchedFulfillment();
        $this->realSends();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['id' => 4455, 'barcode' => 'O0015030400001', 'invoiceNumber' => $fulfillment->reference])]);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/public/fulfillment/order')
            && $request['receiverName'] === self::NAME && $request['receiverPhone'] === '00966500000000' && $request['receiverAddress']['addressLine1'] === self::STREET
            && (string) $request['receiverAddress']['cityId'] === '41125' && $request['invoiceNumber'] === $fulfillment->reference && $request['shipmentType'] === 'REGULAR');
        $fulfillment->refresh();
        $this->assertSame([FulfillmentStatus::Submitted, 'O0015030400001'], [$fulfillment->status, $fulfillment->provider_reference]);
        $this->assertSame(1, count(Http::recorded(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/public/fulfillment/order'))), 'one create request, once');
    }

    public function test_a_second_dispatch_of_the_same_order_never_makes_a_second_shipment_or_a_second_request(): void
    {
        $fulfillment = $this->dispatchedFulfillment();
        $this->realSends();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['id' => 4455, 'barcode' => 'O0015030400001'])]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->dispatcher()->dispatch($this->placed());
        app(FulfillmentRouter::class)->route((int) $fulfillment->order_id);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertSame([1, 1], [ProviderFulfillment::count(), count(Http::recorded(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/public/fulfillment/order')))]);
    }

    public function test_the_provider_log_the_error_text_and_everything_else_stored_keep_the_buyer_out_after_a_send(): void
    {
        $fulfillment = $this->dispatchedFulfillment();
        $this->realSends();
        Http::fake([self::V5 . '/public/fulfillment/order' => Http::response(['message' => 'Invalid receiver ' . self::NAME . ' at ' . self::STREET], 422)]);

        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id);

        $this->assertStringContainsString(self::NAME, (string) $fulfillment->refresh()->last_error_message, 'the platform keeps the provider\'s words as they came: this is the leak the sweep closes');
        app(ManagedShipmentMirror::class)->sweep();

        $this->assertGreaterThan(0, ProviderApiLog::count(), 'the call was logged: the log is where a leak would show');
        $stored = $this->storedOutsideTheOrder();
        foreach ([self::NAME, self::STREET, self::PHONE_NATIONAL, '500000000'] as $detail) {
            $this->assertFalse(str_contains($stored, $detail), "'{$detail}' is still in a stored text");
        }
        $this->assertStringContainsString('Invalid receiver', (string) $fulfillment->refresh()->last_error_message, 'the words around the buyer stay, so the message is still readable');
    }
}
