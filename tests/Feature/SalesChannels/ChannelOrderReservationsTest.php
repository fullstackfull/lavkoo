<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocation;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/** What open channel orders promise of our stock stays out of what is offered, once, and only for as long as the location's own count does not show it. */
class ChannelOrderReservationsTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private SalesChannelAccount $account;

    private InventoryLocation $own;

    private ChannelOrderIngestor $ingestor;

    private LocationStockReader $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        $this->account = $this->trendyolAccount();
        $this->own = app(InventoryLocationService::class)->ensureDefaults()[2];
        InventoryLocationBalance::create(['location_id' => $this->own->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'sku' => 'SKU-1', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()->subHour()]);
        $this->ingestor = app(ChannelOrderIngestor::class);
        $this->stock = app(LocationStockReader::class);
    }

    private function listing(): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::create([
            'account_id' => $this->account->id, 'listing_key' => 'BAR-1', 'external_sku' => 'SKU-1', 'external_barcode' => 'BAR-1', 'product_id' => self::PRODUCT, 'status' => 'mapped',
            'stock_location_id' => $this->own->id, 'inventory_publish' => true,
        ]);
    }

    private function offered(): int
    {
        return $this->stock->availability($this->own, self::PRODUCT)->sellable();
    }

    private function order(ChannelOrderStatus $status = ChannelOrderStatus::New, int $cancelled = 0, string $mode = 'fbm', string $updatedAt = '2026-11-10 10:00:00'): ExternalOrderMapping
    {
        $this->ingestor->ingest($this->account, $this->remoteOrder(status: $status, lines: [$this->remoteLine('L1', 'SKU-1', 'BAR-1', 2, '100', cancelled: $cancelled)], updatedAt: $updatedAt, overrides: ['fulfillmentMode' => FulfillmentMode::from($mode)]));

        return ExternalOrderMapping::firstOrFail();
    }

    public function test_an_open_order_keeps_its_units_out_of_what_is_offered_once_however_often_it_is_read(): void
    {
        $this->listing();

        $this->order();
        $this->order(updatedAt: '2026-11-10 10:05:00');

        $this->assertSame(1, InventoryReservation::count());
        $this->assertSame(18, $this->offered(), '20 on the shelf less the 2 units the order promised.');
    }

    public function test_a_unit_cancelled_gives_its_place_back_and_a_cancelled_order_gives_everything_back(): void
    {
        $this->listing();
        $this->order();

        $this->order(cancelled: 1, updatedAt: '2026-11-10 10:05:00');
        $this->assertSame(19, $this->offered());

        $this->order(status: ChannelOrderStatus::Cancelled, updatedAt: '2026-11-10 10:10:00');
        $this->assertSame(20, $this->offered());
        $this->assertSame(InventoryReservation::RELEASED, InventoryReservation::firstOrFail()->state);
    }

    public function test_once_a_shipment_is_linked_the_units_count_only_until_the_location_counts_them_itself(): void
    {
        $this->listing();
        $mapping = $this->order();

        app(ExternalShipmentLinker::class)->link($mapping->refresh(), 'torod_point', 'TOR-1', 1, trackingNumber: 'TRK-1', carrier: 'SMSA');

        $this->assertSame(InventoryReservation::COMMITTED, InventoryReservation::firstOrFail()->state);
        $this->assertSame(18, $this->offered(), 'The count of the shelf has not moved yet.');

        InventoryLocationBalance::query()->update(['available' => 18, 'source_synced_at' => now()->addMinute()]);
        $this->assertSame(18, $this->offered(), 'The count now shows the 2 units gone: they are not taken off again.');
    }

    public function test_unlinking_the_shipment_makes_an_order_that_has_not_shipped_hold_its_units_again(): void
    {
        $this->listing();
        $linker = app(ExternalShipmentLinker::class);
        $linked = $linker->link($this->order()->refresh(), 'torod_point', 'TOR-1', 1, trackingNumber: 'TRK-1', carrier: 'SMSA');
        InventoryLocationBalance::query()->update(['available' => 18, 'source_synced_at' => now()->addMinute()]);
        $this->assertSame(18, $this->offered(), 'The count shows the 2 units gone: nothing more is taken off.');

        $linker->unlink($linked->refresh(), 1, 'linked by mistake');

        $this->assertSame(InventoryReservation::RESERVED, InventoryReservation::firstOrFail()->state);
        $this->assertSame(16, $this->offered(), 'The order is open again and waits for a person: its units are promised again, which can only under-offer.');
    }

    public function test_unlinking_the_shipment_of_an_order_that_has_shipped_changes_nothing_about_its_units(): void
    {
        $this->listing();
        $linker = app(ExternalShipmentLinker::class);
        $linker->link($this->order()->refresh(), 'torod_point', 'TOR-1', 1, trackingNumber: 'TRK-1', carrier: 'SMSA');
        $this->order(status: ChannelOrderStatus::Shipped, updatedAt: '2026-11-10 10:05:00');

        $linker->unlink(ExternalOrderMapping::firstOrFail(), 1, 'undo');

        $this->assertSame(InventoryReservation::COMMITTED, InventoryReservation::firstOrFail()->state);
    }

    public function test_an_order_the_channel_ships_itself_holds_none_of_our_stock(): void
    {
        $this->listing();

        $this->order(mode: 'fba');

        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(20, $this->offered());
    }

    public function test_an_order_first_seen_after_it_shipped_does_not_count_against_a_figure_taken_after_the_shipment(): void
    {
        $this->listing();
        $shippedAt = now()->subHours(2);

        $this->order(status: ChannelOrderStatus::Delivered, updatedAt: $shippedAt->toDateTimeString());

        $hold = InventoryReservation::firstOrFail();
        $this->assertSame(InventoryReservation::COMMITTED, $hold->state);
        $this->assertTrue($hold->committed_at->eq($shippedAt), 'the units left the shelf when the channel says the order was last changed, not when we heard of it');
        $this->assertSame(20, $this->offered(), 'the count was typed an hour ago, after that: it shows the 2 units gone');
    }

    public function test_an_order_first_seen_after_it_shipped_counts_against_a_figure_taken_before_the_shipment_until_a_newer_figure_shows_it(): void
    {
        $this->listing();

        $this->order(status: ChannelOrderStatus::Delivered, updatedAt: now()->subMinutes(30)->toDateTimeString());

        $this->assertSame(18, $this->offered(), 'the figure was typed an hour ago and does not show the 2 units that left half an hour ago');

        InventoryLocationBalance::query()->update(['available' => 18, 'source_synced_at' => now()->subMinutes(10)]);
        $this->assertSame(18, $this->offered(), 'a figure typed after the shipment shows them gone: nothing more is taken off');
    }

    public function test_an_order_first_seen_after_it_shipped_with_a_time_in_the_future_counts_from_now(): void
    {
        $this->listing();

        $this->order(status: ChannelOrderStatus::Shipped, updatedAt: now()->addHour()->toDateTimeString());

        $this->assertTrue(InventoryReservation::firstOrFail()->committed_at->eq(now()), 'a channel clock that runs ahead cannot make a shipment look older than the figure it is not in');
        $this->assertSame(18, $this->offered());
    }

    public function test_an_order_whose_listing_was_linked_later_starts_holding_its_units_then(): void
    {
        $mapping = $this->order();
        $this->assertSame(0, InventoryReservation::count(), 'No listing, no product, no location: nothing to hold yet.');

        $this->listing();
        $this->ingestor->relinkLines($mapping);

        $this->assertSame(18, $this->offered());
    }

    public function test_what_is_offered_to_the_channel_already_has_the_open_order_taken_off(): void
    {
        $listing = $this->listing();
        $this->order();
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);

        app(StockPricePublisher::class)->publishStock($this->account);

        $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))->first()[0];
        $this->assertSame(['items' => [['barcode' => 'BAR-1', 'quantity' => 18]]], $sent->data(), 'Not 20: the channel would have been told the two units were still for sale.');
        $this->assertSame(18, $listing->refresh()->last_published_quantity);
    }
}
