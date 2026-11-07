<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelApiLog;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\FulfillmentRouter;
use App\Services\SalesChannels\Dispatch\DispatchOutcome;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\Fixtures\AmazonFixtures as Amazon;

/** An Amazon merchant-fulfilled order dispatched end to end: the same chain as Trendyol's, with Amazon's own recipient reader (against faked HTTP). */
class AmazonDispatchTest extends DispatchTestCase
{
    private const LWA = 'https://api.amazon.com/auth/o2/token';
    private const SP = 'https://sellingpartnerapi-eu.amazon.com';
    private const ORDER = '171-0000001-0000001';

    private SalesChannelAccount $amazon;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $amazon = $this->amazonAccount();
        $amazon->forceFill([
            'settings' => ['shipment_creation' => 'app'] + (array) $amazon->settings,
            'capability_approvals' => ['buyer_data_transfer' => ['by' => 1, 'at' => now()->toIso8601String(), 'reason' => 'owner approved']] + (array) $amazon->capability_approvals,
        ])->save();
        $this->amazon = $amazon->fresh();
        SalesChannelProductMapping::create(['account_id' => $this->amazon->id, 'product_id' => 10, 'listing_key' => 'SKU-1', 'external_sku' => 'SKU-1', 'status' => 'mapped', 'stock_location_id' => $this->torodLocation->id]);
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([
            self::LWA => Http::response(['access_token' => 'Atza|access-token-123', 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'Atzr|refresh-token-abc']),
            self::SP . '/orders/*' => Http::response(['order' => Amazon::order(self::ORDER, 'MERCHANT')]),
        ]);
    }

    private function placedAtAmazon(array $overrides = []): ExternalOrderMapping
    {
        $order = $this->remoteOrder(self::ORDER, '', ChannelOrderStatus::New, [$this->remoteLine('44000000000001', 'SKU-1', null, 2, '200')], overrides: $overrides + [
            'fulfillmentMode' => FulfillmentMode::Fbm, 'fulfillmentChannelRaw' => 'MFN', 'paymentMethod' => 'CreditCard', 'isCod' => false,
        ]);
        $mapping = app(ChannelOrderIngestor::class)->ingest($this->amazon, $order)->mapping;
        app(DynamicFulfillmentRoutingEngine::class)->route($mapping->refresh());

        return $mapping->refresh();
    }

    public function test_an_amazon_order_becomes_one_internal_order_and_a_provider_fulfilment_with_the_buyer_read_once(): void
    {
        $mapping = $this->placedAtAmazon();

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertSame([DispatchOutcome::MATERIALIZED, 'torod_point'], [$outcome->state, $outcome->provider], (string) $outcome->reason . json_encode($outcome->blockers));
        $stored = json_decode((string) DB::table('orders')->where('id', $outcome->internalOrderId)->value('shipping_address_data'), true);
        $this->assertSame([self::NAME, self::STREET, 'SA'], [$stored['contact_person_name'], $stored['address'], $stored['country']]);
        $reads = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/orders/2026-01-01/orders/' . self::ORDER));
        $this->assertCount(1, $reads);
        $this->assertSame('RECIPIENT,FULFILLMENT', $reads->first()[0]->data()['includedData'] ?? null, 'it asks for the recipient and the fulfilment, nothing else');

        app(FulfillmentRouter::class)->route((int) $outcome->internalOrderId);
        $this->assertSame(1, ProviderFulfillment::where('order_id', $outcome->internalOrderId)->count());
        $this->assertSame($outcome->internalOrderId, $this->dispatcher()->dispatch($mapping->refresh())->internalOrderId, 'a second dispatch finds the order already made');
    }

    public function test_the_amazon_call_log_keeps_the_buyer_out(): void
    {
        $this->dispatcher()->dispatch($this->placedAtAmazon());

        $logged = json_encode(SalesChannelApiLog::where('operation', 'amazon.order_recipient')->get()->all());
        $this->assertNotSame('[]', $logged, 'the call is logged');
        foreach ([self::NAME, self::STREET, '5000000'] as $detail) {
            $this->assertStringNotContainsString($detail, $logged, "'{$detail}' reached the call log");
        }
    }

    public function test_an_amazon_order_with_no_payment_method_stated_is_not_dispatched(): void
    {
        $mapping = $this->placedAtAmazon(['paymentMethod' => null]);

        $outcome = $this->dispatcher()->dispatch($mapping);

        $this->assertContains('payment_method_unknown', $outcome->blockers);
        $this->assertSame(0, DB::table('orders')->count());
    }
}
