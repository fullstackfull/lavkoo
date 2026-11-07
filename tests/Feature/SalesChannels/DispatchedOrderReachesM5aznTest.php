<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\StockSource;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\DispatchState;
use App\Models\ChannelOrderDispatch;
use App\Models\ExternalOrderMapping;
use App\Models\FulfillmentRoutingRule;
use App\Models\InventoryLocation;
use App\Models\ProviderFulfillment;
use App\Models\ProviderProductMapping;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\FulfillmentCancellation;
use App\Services\Fulfillment\FulfillmentRecovery;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\Fulfillment\ProviderOrderDispatcher;
use App\Services\SalesChannels\Dispatch\ManagedShipmentMirror;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fulfillment\M5aznTestCase;
use Tests\Support\BuildsDispatchWorld;

/** The REAL M5AZN adapter (against faked HTTP; M5AZN's answers are fixtures, nothing live was ever seen) given an order the dispatcher made. */
class DispatchedOrderReachesM5aznTest extends M5aznTestCase
{
    use BuildsDispatchWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDispatchWorld();
        FulfillmentRoutingRule::query()->delete();
        ProviderProductMapping::query()->delete();
        SalesChannelProductMapping::query()->update(['stock_location_id' => InventoryLocation::where('provider', 'm5azn')->firstOrFail()->id]);
        ProviderProductMapping::create([
            'provider' => 'm5azn', 'product_id' => 10, 'variant_key' => '', 'external_id' => '1947201', 'external_variant_id' => '', 'external_sku' => 'XSKU10', 'status' => MappingStatus::Approved,
            'stock_source' => StockSource::ProviderSync, 'provider_stock' => 50, 'provider_stock_synced_at' => now(),
        ]);
        FulfillmentRoutingRule::create(['name' => 'M5AZN for everything', 'priority' => 1, 'is_active' => true, 'action' => 'route_to_provider', 'target_provider' => 'm5azn']);
    }

    private function sent(): ProviderFulfillment
    {
        $orderId = (int) $this->dispatcher()->dispatch($this->placed())->internalOrderId;
        app(FulfillmentRouter::class)->route($orderId);
        $fulfillment = ProviderFulfillment::where('order_id', $orderId)->firstOrFail();
        Http::fake([self::BASE . '/api/v1/orders' => Http::response(['status' => 200, 'success' => true, 'data' => ['order_id' => 'M5-9001']]), self::BASE . '/*' => $this->loginResponse()]);
        app(ProviderOrderDispatcher::class)->dispatch($fulfillment->id, manual: true);

        return $fulfillment->refresh();
    }

    public function test_the_buyer_reaches_m5azn_as_customer_and_address_once_and_the_order_carries_the_stores_number(): void
    {
        $fulfillment = $this->sent();

        $creates = Http::recorded(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/orders'));
        $this->assertCount(1, $creates);
        $payload = $creates->first()[0]->data();
        $this->assertSame([(int) $fulfillment->order_id, self::STREET, 'SA', 'Riyadh'], [$payload['id'], $payload['shipping_info']['address'], $payload['shipping_info']['country_code'], $payload['shipping_info']['city']]);
        $this->assertSame(['Test', 'Buyer', self::PHONE_NATIONAL], [$payload['customer_info']['first_name'], $payload['customer_info']['last_name'], $payload['customer_info']['phone']]);
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->status);
    }

    public function test_a_channel_cancellation_of_an_order_m5azn_holds_is_not_taken_as_done_because_m5azn_cannot_be_asked_to_cancel(): void
    {
        $fulfillment = $this->sent();
        $mapping = ExternalOrderMapping::where('order_id', $fulfillment->order_id)->firstOrFail();
        $mirror = app(ManagedShipmentMirror::class);
        $this->assertSame(ManagedShipmentMirror::LINKED, $mirror->mirror($mapping));

        $mapping->refresh()->forceFill(['status' => ChannelOrderStatus::Cancelled->value])->save();
        $mirror->mirror($mapping);
        $this->assertSame(1, app(FulfillmentRecovery::class)->cancelForClosedOrders(), 'the pipeline picked the shipment up for cancellation');
        app(FulfillmentCancellation::class)->cancel($fulfillment->id);

        $fulfillment->refresh();
        $this->assertSame(FulfillmentStatus::Submitted, $fulfillment->status, 'M5AZN still holds the order: nothing stopped it');
        $this->assertNotNull($fulfillment->cancel_blocked_reason, 'the platform knows it cannot cancel there');
        $this->assertSame([DispatchState::Accepted->value, ManagedShipmentMirror::REASON_CANCEL_NOT_CONFIRMED], [ChannelOrderDispatch::firstOrFail()->state, $mapping->refresh()->review_reason], 'so the channel order waits for a person instead of looking cancelled');
    }
}
