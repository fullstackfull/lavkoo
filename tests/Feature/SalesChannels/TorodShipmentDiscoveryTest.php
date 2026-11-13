<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\ProviderFulfillment;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\Providers\TorodPoint\TorodFieldMaps;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use App\Services\SalesChannels\Shipments\ExternalShipmentLookup;
use App\Services\SalesChannels\Shipments\NullShipmentLookup;
use App\Services\SalesChannels\Shipments\ShipmentDiscovery;
use App\Services\SalesChannels\Shipments\TorodShipmentLookup;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Fulfillment\TorodTestCase;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;

/** A Trendyol account whose shipments Torod creates itself: the one proof accepted is the channel's order number, exactly, in the invoice number of exactly one Torod order. */
class TorodShipmentDiscoveryTest extends TorodTestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const ORDER = '10234567890';

    private SalesChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runSalesChannelMigrations();
        config(['sales_channels.enabled' => true, 'sales_channels.shipment_discovery.enabled' => true, 'sales_channels.shipment_discovery.window_hours' => 36]);
        $this->account = $this->trendyolAccount();
        $this->makeProduct(10);
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status'], null);
        $this->assertSame([], app(FieldMapStore::class)->verify(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, [['invoiceNumber' => 'A-1', 'barcode' => 'O1', 'status' => 'NEW']], ['invoice_number', 'reference']));
    }

    private function followed(string $orderId = self::ORDER, string $package = '3012345678'): ExternalOrderMapping
    {
        $order = $this->remoteOrder($orderId, $package, ChannelOrderStatus::New, [$this->remoteLine('L1', 'SKU-1', 'SKU-1', 1, '100')], overrides: ['fulfillmentChannelRaw' => 'seller_pays', 'fulfillmentMode' => FulfillmentMode::MerchantManaged]);
        $mapping = app(ChannelOrderIngestor::class)->ingest($this->account, $order)->mapping;
        $mapping->forceFill(['execution_policy' => 'externally_managed', 'shipment_link_state' => ExternalOrderMapping::LINK_UNLINKED])->save();

        return $mapping->refresh();
    }

    /** @param list<array<string, mixed>> $orders */
    private function torodList(array $orders, int $status = 200): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake([self::V5 . '/public/fulfillment/order*' => Http::response(['data' => $orders], $status)]);
    }

    private function torodOrder(string $invoice, string $barcode, string $status = 'NEW'): array
    {
        return ['id' => random_int(1000, 9999), 'barcode' => $barcode, 'invoiceNumber' => $invoice, 'status' => $status];
    }

    private function lookup(): TorodShipmentLookup
    {
        return app(TorodShipmentLookup::class);
    }

    private function fresh(ExternalOrderMapping $mapping): ExternalOrderMapping
    {
        return $mapping->refresh();
    }

    public function test_discovery_is_off_by_default_and_the_default_lookup_finds_nothing_and_guesses_nothing(): void
    {
        config(['sales_channels.shipment_discovery.enabled' => false]);
        $this->torodList([$this->torodOrder(self::ORDER, 'O001')]);

        $this->assertInstanceOf(NullShipmentLookup::class, app(ExternalShipmentLookup::class));
        $this->assertFalse($this->lookup()->available());
        $this->artisan('channels:find-shipments')->expectsOutputToContain('switched off')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_when_enabled_the_torod_lookup_replaces_the_null_one(): void
    {
        $this->assertInstanceOf(TorodShipmentLookup::class, app(ExternalShipmentLookup::class));
    }

    public function test_exactly_one_torod_order_carrying_the_channels_order_number_is_linked_without_sending_anything(): void
    {
        $mapping = $this->followed();
        $this->torodList([$this->torodOrder('SOMETHING-ELSE', 'O009'), $this->torodOrder(self::ORDER, 'O001', 'PICKED_UP')]);

        $outcome = app(ExternalShipmentLinker::class)->autoLink($mapping);

        $this->assertSame(ExternalShipmentLinker::LINKED, $outcome);
        $mapping->refresh();
        $this->assertSame([ExternalOrderMapping::LINK_LINKED, 'O001', 'PICKED_UP', ProviderCode::TorodPoint->value, 'externally_managed'], [$mapping->shipment_link_state, $mapping->shipment_reference, $mapping->shipment_status, $mapping->provider, $mapping->execution_policy]);
        Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(static fn ($request): bool => $request->method() !== 'GET');
        $this->assertSame(0, ProviderFulfillment::count(), 'nothing was created at the provider');
    }

    public function test_the_accounts_own_setting_says_which_channel_value_torod_holds_as_the_reference(): void
    {
        $this->account->forceFill(['settings' => ['torod_reference_field' => 'package_id'] + (array) $this->account->settings])->save();
        $mapping = $this->followed()->refresh();
        $this->torodList([$this->torodOrder(self::ORDER, 'O001'), $this->torodOrder('3012345678', 'O002')]);

        $candidates = $this->lookup()->candidates($mapping);

        $this->assertSame(['O002'], array_map(static fn ($candidate): string => $candidate->reference, $candidates));
        $this->assertSame('invoice_number = package_id', $candidates[0]->matchedOn);
    }

    public function test_nothing_but_the_identifier_is_compared_the_buyers_own_details_prove_nothing(): void
    {
        $mapping = $this->followed();
        $lookalike = $this->torodOrder('other-1', 'O002') + ['receiverName' => 'Test Buyer', 'receiverPhone' => '0500000000', 'city' => 'Riyadh'];
        $this->torodList([$lookalike]);

        $this->assertSame([], $this->lookup()->candidates($mapping));
        $this->assertSame(ExternalShipmentLinker::NOT_FOUND, app(ExternalShipmentLinker::class)->autoLink($mapping));
        $this->assertSame(ExternalOrderMapping::LINK_UNLINKED, $this->fresh($mapping)->shipment_link_state);
    }

    public function test_two_torod_orders_with_the_same_number_are_not_guessed_between_and_wait_for_a_person(): void
    {
        $mapping = $this->followed();
        $this->torodList([$this->torodOrder(self::ORDER, 'O001'), $this->torodOrder(self::ORDER, 'O002')]);

        $this->assertSame(ExternalShipmentLinker::NEEDS_REVIEW, app(ExternalShipmentLinker::class)->autoLink($mapping));
        $mapping->refresh();
        $this->assertSame([ExternalOrderMapping::LINK_NEEDS_REVIEW, 'ambiguous_shipment', null], [$mapping->shipment_link_state, $mapping->review_reason, $mapping->shipment_reference]);
    }

    public function test_orders_with_no_invoice_number_never_match_and_our_own_shipments_are_never_taken_for_the_channels(): void
    {
        $mapping = $this->followed();
        ProviderFulfillment::create(['reference' => '100-TP1', 'order_id' => 100, 'provider' => 'torod_point', 'generation' => 1, 'idempotency_key' => 'k1', 'status' => 'submitted', 'provider_reference' => 'O777']);
        $this->torodList([$this->torodOrder('', 'O003'), ['id' => 5, 'barcode' => 'O004', 'invoiceNumber' => null, 'status' => 'NEW'], $this->torodOrder(self::ORDER, 'O777')]);

        $this->assertSame([], $this->lookup()->candidates($mapping));
    }

    public function test_an_unreadable_list_or_an_unverified_orders_map_makes_the_lookup_unavailable_instead_of_finding_nothing(): void
    {
        $mapping = $this->followed();
        $this->torodList([], 500);
        $this->assertFalse($this->lookup()->available());
        $this->assertSame(ExternalShipmentLinker::UNAVAILABLE, app(ExternalShipmentLinker::class)->autoLink($mapping));

        $this->torodList([$this->torodOrder(self::ORDER, 'O001')]);
        app(FieldMapStore::class)->save(ProviderCode::TorodPoint, TorodFieldMaps::ORDERS, ['invoice_number' => 'invoiceNumber', 'reference' => 'barcode', 'status' => 'status'], 'data.items');
        $this->assertFalse(app(TorodShipmentLookup::class)->available());
        $this->assertSame(ExternalOrderMapping::LINK_UNLINKED, $this->fresh($mapping)->shipment_link_state);
    }

    public function test_only_the_pages_it_is_allowed_to_read_are_searched_and_an_order_further_back_is_not_found(): void
    {
        $mapping = $this->followed();
        config(['sales_channels.shipment_discovery.torod.max_pages' => 1]);
        $page = array_map(fn (int $number): array => $this->torodOrder('X' . $number, 'B' . $number), range(1, 50));
        $this->torodList($page);

        $this->assertSame([], $this->lookup()->candidates($mapping));
        Http::assertSentCount(1);
    }

    public function test_an_order_still_without_a_shipment_after_the_waiting_time_is_handed_to_a_person_not_waited_for_for_ever(): void
    {
        $mapping = $this->followed();
        $mapping->forceFill(['purchased_at' => now()->subHours(10)])->save();
        $this->torodList([]);

        $first = app(ShipmentDiscovery::class)->run();
        $this->assertSame([0, ExternalOrderMapping::LINK_UNLINKED], [$first['handed_to_a_person'], $this->fresh($mapping)->shipment_link_state]);

        $mapping->forceFill(['purchased_at' => now()->subHours(40)])->save();
        $second = app(ShipmentDiscovery::class)->run();

        $this->assertSame(1, $second['handed_to_a_person']);
        $this->assertSame([ExternalOrderMapping::LINK_NEEDS_REVIEW, ShipmentDiscovery::REASON_NOT_FOUND], [$this->fresh($mapping)->shipment_link_state, $mapping->review_reason]);
    }

    public function test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current(): void
    {
        $mapping = $this->followed();
        $mapping->forceFill(['purchased_at' => now()->subHours(10)])->save();
        $this->torodList([]);
        app(ShipmentDiscovery::class)->run();
        $this->assertSame(ExternalOrderMapping::LINK_UNLINKED, $this->fresh($mapping)->shipment_link_state);

        $this->travel(3)->minutes();
        $this->torodList([$this->torodOrder(self::ORDER, 'O001', 'NEW')]);
        $this->assertSame(1, app(ShipmentDiscovery::class)->run()[ExternalShipmentLinker::LINKED]);

        $this->travel(3)->minutes();
        $this->torodList([$this->torodOrder(self::ORDER, 'O001', 'DELIVERED')]);
        $counts = app(ShipmentDiscovery::class)->run();

        $this->assertSame([1, 'DELIVERED'], [$counts['status_updated'], $this->fresh($mapping)->shipment_status]);
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->followed();
        $this->torodList([]);

        $this->artisan('channels:find-shipments')->expectsOutputToContain('Shipment discovery:')->assertSuccessful();
    }
}
