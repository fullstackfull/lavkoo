<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\SalesChannels\Shipments\ChannelShipmentNotifier;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use App\Services\SalesChannels\Shipments\ExternalShipmentLookup;
use App\Services\SalesChannels\Shipments\ShipmentCandidate;
use App\Services\SalesChannels\Shipments\ShipmentLinkRefused;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\Support\FakeCurrencyRates;
use Tests\Support\Fixtures\TrendyolFixtures as Trendyol;
use Tests\TestCase;

/** An order someone else ships is tied to the shipment that already exists, never given a second one, and the channel is told once. */
class ShipmentLinkingTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const TY = 'https://apigw.trendyol.com/integration';
    private const AMAZON = 'https://sellingpartnerapi-eu.amazon.com';

    private SalesChannelAccount $account;

    private ExternalShipmentLinker $linker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00 UTC'));
        $this->account = $this->trendyolAccount();
        $this->linker = app(ExternalShipmentLinker::class);
    }

    private function order(string $orderId = '111', string $packageId = '900', array $overrides = []): ExternalOrderMapping
    {
        $remote = $this->remoteOrder($orderId, $packageId, ChannelOrderStatus::New, null, overrides: $overrides + ['fulfillmentMode' => FulfillmentMode::MerchantManaged, 'fulfillmentChannelRaw' => 'seller_pays']);

        return app(ChannelOrderIngestor::class)->ingest($this->account, $remote)->mapping;
    }

    private function lookup(array $candidates, bool $available = true): void
    {
        $this->app->bind(ExternalShipmentLookup::class, fn () => new class($candidates, $available) implements ExternalShipmentLookup {
            public function __construct(private array $found, private bool $can)
            {
            }

            public function available(): bool
            {
                return $this->can;
            }

            public function candidates(ExternalOrderMapping $mapping): array
            {
                return $this->found;
            }
        });
        $this->linker = app(ExternalShipmentLinker::class);
    }

    // ---- linking ----------------------------------------------------------------------------------------

    public function test_a_person_links_an_order_to_its_existing_shipment_and_nothing_is_created(): void
    {
        $mapping = $this->order();
        $mapping->forceFill(['review_reason' => 'no_rule_matched', 'execution_policy' => 'manual_review'])->save();

        $linked = $this->linker->link($mapping, 'torod_point', ' TOR-123 ', adminId: 1, trackingNumber: 'TRK-9', carrier: 'SMSA');

        $this->assertSame(['externally_managed', 'torod_point', 'linked', 'TOR-123', 'TRK-9', 'SMSA', null], [
            $linked->execution_policy, $linked->provider, $linked->shipment_link_state, $linked->shipment_reference, $linked->tracking_number, $linked->carrier, $linked->review_reason,
        ]);
        $this->assertNull($linked->order_id, 'No internal order, no provider fulfilment.');
        $this->assertSame(0, ProviderFulfillment::count());
    }

    public function test_one_shipment_cannot_belong_to_two_orders(): void
    {
        $first = $this->order('111', '900');
        $second = $this->order('112', '901');
        $this->linker->link($first, 'torod_point', 'TOR-123', 1);

        $this->expectException(ShipmentLinkRefused::class);
        $this->linker->link($second, 'torod_point', 'TOR-123', 1);
    }

    public function test_the_database_refuses_the_same_shipment_twice_even_if_the_check_is_bypassed(): void
    {
        $first = $this->order('111', '900');
        $second = $this->order('112', '901');
        $first->forceFill(['provider' => 'torod_point', 'shipment_reference' => 'TOR-123'])->save();

        $this->expectException(UniqueConstraintViolationException::class);
        $second->forceFill(['provider' => 'torod_point', 'shipment_reference' => 'TOR-123'])->save();
    }

    public function test_an_order_already_linked_to_one_shipment_needs_an_unlink_before_another(): void
    {
        $mapping = $this->order();
        $this->linker->link($mapping, 'torod_point', 'TOR-1', 1);

        try {
            $this->linker->link($mapping->refresh(), 'torod_point', 'TOR-2', 1);
            $this->fail('A second shipment was linked.');
        } catch (ShipmentLinkRefused) {
            $this->addToAssertionCount(1);
        }

        $this->linker->unlink($mapping->refresh(), 1, 'wrong shipment');
        $this->linker->link($mapping->refresh(), 'torod_point', 'TOR-2', 1);
        $this->assertSame('TOR-2', $mapping->refresh()->shipment_reference);
    }

    public function test_unlinking_puts_back_the_tracking_the_channel_reported_before_the_link(): void
    {
        $mapping = $this->order('111', '900', ['carrier' => 'Trendyol Express', 'trackingNumber' => 'CHANNEL-1', 'trackingUrl' => 'https://track.example/CHANNEL-1']);
        $this->assertSame(['CHANNEL-1', 'Trendyol Express'], [$mapping->tracking_number, $mapping->carrier]);
        $snapshotBefore = (array) $mapping->snapshot;

        $linked = $this->linker->link($mapping, 'torod_point', 'TOR-123', 1, trackingNumber: 'TRK-9', carrier: 'SMSA');
        $this->assertSame(['TRK-9', 'SMSA'], [$linked->tracking_number, $linked->carrier]);

        $unlinked = $this->linker->unlink($linked->refresh(), 1, 'linked by mistake');

        $this->assertSame([ExternalOrderMapping::LINK_UNLINKED, null, 'CHANNEL-1', 'Trendyol Express', 'https://track.example/CHANNEL-1'], [
            $unlinked->shipment_link_state, $unlinked->shipment_reference, $unlinked->tracking_number, $unlinked->carrier, $unlinked->tracking_url,
        ]);
        $this->assertSame($snapshotBefore, (array) $unlinked->snapshot, 'The snapshot is what it was before the link.');
    }

    public function test_the_link_resolves_only_its_own_review_reasons(): void
    {
        $ambiguous = $this->order('111', '900');
        $ambiguous->forceFill(['shipment_link_state' => ExternalOrderMapping::LINK_NEEDS_REVIEW, 'review_reason' => 'ambiguous_shipment'])->save();
        $this->assertNull($this->linker->link($ambiguous->refresh(), 'torod_point', 'TOR-1', 1)->review_reason);

        $unrelated = $this->order('112', '901');
        $unrelated->forceFill(['review_reason' => 'modified_after_routing'])->save();
        $linked = $this->linker->link($unrelated->refresh(), 'torod_point', 'TOR-2', 1);
        $this->assertSame('modified_after_routing', $linked->review_reason, 'A person still has to look at the change after routing.');

        $this->assertSame('modified_after_routing', $this->linker->unlink($linked->refresh(), 1, 'undo')->review_reason);
    }

    public function test_orders_that_cannot_have_a_provider_shipment_are_refused(): void
    {
        $fba = $this->order('F', '', ['fulfillmentMode' => FulfillmentMode::Fba]);
        $unknown = $this->order('U', '', ['fulfillmentMode' => FulfillmentMode::Unknown]);
        $dispatched = $this->order('D', '');
        $dispatched->forceFill(['order_id' => 55])->save();

        foreach ([$fba, $unknown, $dispatched] as $mapping) {
            try {
                $this->linker->link($mapping->refresh(), 'torod_point', 'TOR-' . $mapping->id, 1);
                $this->fail("Order {$mapping->external_order_id} was linked.");
            } catch (ShipmentLinkRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_blank_reference_is_refused(): void
    {
        $this->expectException(ShipmentLinkRefused::class);
        $this->linker->link($this->order('X'), 'torod_point', '   ', 1);
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->expectException(ShipmentLinkRefused::class);
        $this->linker->link($this->order(), 'some_courier', 'REF', 1);
    }

    // ---- looking for the shipment -----------------------------------------------------------------------

    public function test_without_a_provider_search_nothing_is_linked_on_a_guess(): void
    {
        $mapping = $this->order();

        $this->assertSame(ExternalShipmentLinker::UNAVAILABLE, $this->linker->autoLink($mapping));
        $this->assertNotSame(ExternalOrderMapping::LINK_LINKED, $mapping->refresh()->shipment_link_state);
    }

    public function test_exactly_one_candidate_is_linked_none_stays_unlinked_and_several_wait_for_a_person(): void
    {
        $this->lookup([new ShipmentCandidate('torod_point', 'TOR-7', 'TRK-7', 'Aramex', 'created', 'order_number')]);
        $one = $this->order('111', '900');
        $this->assertSame(ExternalShipmentLinker::LINKED, $this->linker->autoLink($one));
        $this->assertSame(['TOR-7', 'TRK-7'], [$one->refresh()->shipment_reference, $one->tracking_number]);

        $this->lookup([]);
        $none = $this->order('112', '901');
        $this->assertSame(ExternalShipmentLinker::NOT_FOUND, $this->linker->autoLink($none));
        $this->assertNotNull($none->refresh()->shipment_checked_at);

        $this->lookup([new ShipmentCandidate('torod_point', 'A'), new ShipmentCandidate('torod_point', 'B')]);
        $many = $this->order('113', '902');
        $this->assertSame(ExternalShipmentLinker::NEEDS_REVIEW, $this->linker->autoLink($many));
        $this->assertSame([ExternalOrderMapping::LINK_NEEDS_REVIEW, 'ambiguous_shipment'], [$many->refresh()->shipment_link_state, $many->review_reason]);
    }

    public function test_a_candidate_already_linked_to_another_order_is_a_conflict_not_a_second_link(): void
    {
        $this->lookup([new ShipmentCandidate('torod_point', 'TOR-7')]);
        $first = $this->order('111', '900');
        $second = $this->order('112', '901');
        $this->linker->autoLink($first);

        $this->assertSame(ExternalShipmentLinker::NEEDS_REVIEW, $this->linker->autoLink($second));
        $this->assertSame(ExternalOrderMapping::LINK_CONFLICT, $second->refresh()->shipment_link_state);
        $this->assertNull($second->shipment_reference);
    }

    // ---- telling the channel ----------------------------------------------------------------------------

    private function linkedWithTracking(array $package = []): ExternalOrderMapping
    {
        $mapping = $this->order('10234567890', '3012345678');
        $mapping->forceFill(['shipped_at' => now()])->save();

        return $this->linker->link($mapping->refresh(), 'torod_point', 'TOR-1', 1, trackingNumber: 'TRK-9', carrier: 'SMSA')->load('lines', 'account');
    }

    private function fakeTrendyol(array $package): void
    {
        Http::fake([
            self::TY . '/order/sellers/1234567/v2/orders*' => Http::response(Trendyol::stream([Trendyol::package($package)])),
            self::TY . '/order/sellers/*' => Http::response('', 200),
        ]);
    }

    /** @return list<Request> */
    private function puts(): array
    {
        return array_values(array_filter(Http::recorded()->map(fn (array $pair): Request => $pair[0])->all(), fn (Request $request): bool => $request->method() === 'PUT'));
    }

    public function test_a_linked_shipment_is_reported_to_trendyol_once(): void
    {
        $this->fakeTrendyol(['whoPays' => 1]);
        $mapping = $this->linkedWithTracking();
        $notifier = app(ChannelShipmentNotifier::class);

        $first = $notifier->notify($mapping);
        $second = $notifier->notify($mapping->refresh()->load('lines', 'account'));

        $this->assertSame(['sent', null], [$first['outcome'], $first['reason']]);
        $this->assertSame(['skipped', 'already_notified'], [$second['outcome'], $second['reason']]);
        $this->assertCount(2, $this->puts(), 'Picking and the tracking number, once.');
        $this->assertSame(['cargoSenderNumber' => 'TRK-9', 'providerCode' => 'SMSA'], $this->puts()[1]->data());
    }

    public function test_a_carrier_trendyol_has_no_code_for_is_reported_as_a_failure_a_person_can_read(): void
    {
        $this->fakeTrendyol(['whoPays' => 1]);
        $mapping = $this->linkedWithTracking();
        $mapping->forceFill(['carrier' => 'Torod Point'])->save();

        $result = app(ChannelShipmentNotifier::class)->notify($mapping->refresh()->load('lines', 'account'));

        $this->assertSame(['failed', 'channel_notify_error'], [$result['outcome'], $result['reason']]);
        $this->assertStringContainsString('torod point', $mapping->refresh()->snapshot['channel_notify_error']['error']);
        $this->assertCount(0, $this->puts(), 'Nothing was written before the carrier code was known.');
    }

    public function test_nothing_is_reported_without_a_linked_shipment_the_capability_or_outside_a_dry_run(): void
    {
        Http::fake();
        $notifier = app(ChannelShipmentNotifier::class);
        $unlinked = $this->order('111', '900');
        $this->assertSame('no_linked_shipment_with_tracking', $notifier->notify($unlinked->load('lines', 'account'))['reason']);

        $this->fakeTrendyol(['whoPays' => 1]);
        $linked = $this->linkedWithTracking();
        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'order_import']])->save();
        $this->assertSame('fulfillment_dispatch_off', $notifier->notify($linked->refresh()->load('lines', 'account'))['reason']);

        $this->account->forceFill(['capabilities' => ['connection_test', 'read_only_sync', 'order_import', 'fulfillment_dispatch'], 'dry_run' => true])->save();
        $this->assertSame('dry_run', $notifier->notify($linked->refresh()->load('lines', 'account'))['reason']);
        $this->assertCount(0, $this->puts());
    }

    public function test_a_shipment_of_a_cancelled_order_is_not_reported(): void
    {
        $this->fakeTrendyol(['whoPays' => 1]);
        $mapping = $this->linkedWithTracking();
        $mapping->forceFill(['status' => 'cancelled'])->save();

        $this->assertSame('order_cancelled', app(ChannelShipmentNotifier::class)->notify($mapping->refresh()->load('lines', 'account'))['reason']);
    }

    public function test_an_amazon_merchant_fulfilled_order_is_confirmed_with_amazon(): void
    {
        $amazon = $this->amazonAccount();
        $remote = $this->remoteOrder('171-0000001-0000001', '', ChannelOrderStatus::New, [$this->remoteLine('44000000000001', 'SKU-1', null, 2, '200')], overrides: ['fulfillmentMode' => FulfillmentMode::Fbm]);
        $mapping = app(ChannelOrderIngestor::class)->ingest($amazon, $remote)->mapping;
        $mapping->forceFill(['shipped_at' => now()])->save();
        $linked = $this->linker->link($mapping->refresh(), 'torod_point', 'TOR-5', 1, trackingNumber: 'TRK-5', carrier: 'SMSA Express')->load('lines', 'account');
        Http::fake(['https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|t', 'expires_in' => 3600]), self::AMAZON . '/*' => Http::response('', 204)]);

        $result = app(ChannelShipmentNotifier::class)->notify($linked);

        $this->assertSame('sent', $result['outcome']);
        $request = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'shipmentConfirmation'))->first()[0];
        $this->assertSame(['Other', 'SMSA Express', 'TRK-5'], [$request->data()['packageDetail']['carrierCode'], $request->data()['packageDetail']['carrierName'], $request->data()['packageDetail']['trackingNumber']]);
        $this->assertSame([['orderItemId' => '44000000000001', 'quantity' => 2]], $request->data()['packageDetail']['orderItems']);
    }

    public function test_a_later_sync_does_not_overwrite_the_tracking_of_a_linked_shipment(): void
    {
        $mapping = $this->order('111', '900', ['carrier' => 'Trendyol Express', 'trackingNumber' => 'CHANNEL-1']);
        $this->linker->link($mapping, 'torod_point', 'TOR-123', 1, trackingNumber: 'TRK-9', carrier: 'SMSA');

        $refreshed = $this->remoteOrder('111', '900', ChannelOrderStatus::New, null, '2026-11-10 12:00:00', ['fulfillmentMode' => FulfillmentMode::MerchantManaged, 'fulfillmentChannelRaw' => 'seller_pays', 'carrier' => 'Trendyol Express', 'trackingNumber' => 'CHANNEL-2']);
        $result = app(ChannelOrderIngestor::class)->ingest($this->account, $refreshed);

        $this->assertSame(['TRK-9', 'SMSA', 'TOR-123'], [$result->mapping->tracking_number, $result->mapping->carrier, $result->mapping->shipment_reference]);
    }
}
