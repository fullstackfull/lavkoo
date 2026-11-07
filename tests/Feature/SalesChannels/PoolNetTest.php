<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\StockSource;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Inventory\PoolFigures;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Inventory\StockAvailability;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Routing\RoutingContextFactory;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\ChannelOrderIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * One Net for every reader: the figure, less the units on open channel orders, less the store orders the provider has not counted yet, less the buffer. Each unit is taken off
 * once, a store order is never forgotten, and what the module cannot attribute is said so.
 */
class PoolNetTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private SalesChannelAccount $account;

    private PoolNet $net;

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
        $this->net = app(PoolNet::class);
        $this->storeProduct(self::PRODUCT);
    }

    private function pool(): PoolFigures
    {
        return $this->net->for($this->torodLocation(), self::PRODUCT);
    }

    // ---- the formula --------------------------------------------------------------------------------------------------------

    public function test_the_figure_less_store_orders_channel_orders_and_the_buffer(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);

        $pool = $this->net->for($this->torodLocation(), self::PRODUCT, buffer: 1);

        $this->assertSame([20, 3, 2, 1], [$pool->available, $pool->storeHolds, $pool->channelHolds, $pool->buffer]);
        $this->assertSame(14, $pool->net(), '20 - 3 store units - 2 channel units - 1 buffer');
        $this->assertSame(0, $pool->deficit());
        $this->assertTrue($pool->isPublishable());
    }

    public function test_the_raw_figure_the_cost_module_reads_is_left_alone(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 3);

        $raw = app(LocationStockReader::class)->availability($this->torodLocation(), self::PRODUCT);

        $this->assertSame(20, $raw->available, 'availability() stays raw: only PoolNet takes store orders off.');
        $this->assertSame(17, $this->pool()->net());
    }

    public function test_more_promised_than_there_is_offers_nothing_and_reports_the_deficit(): void
    {
        $this->providerFigure(self::PRODUCT, 3, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 2);
        app(ReservationService::class)->reserve('channel-order:1:1', $this->torodLocation()->id, self::PRODUCT, '', 2);

        $pool = $this->pool();

        $this->assertSame([0, 1], [$pool->net(), $pool->deficit()]);
    }

    // ---- where a store order stops being the store's to subtract ----------------------------------------------------------------

    public function test_a_store_order_the_provider_accepted_before_the_figure_is_in_it_and_one_accepted_at_or_after_is_not(): void
    {
        $figureAt = now()->subHour();
        $this->providerFigure(self::PRODUCT, 20, $figureAt);
        $this->storeOrder(1, self::PRODUCT, 1, FulfillmentStatus::Submitted, $figureAt->copy()->subSecond());
        $this->storeOrder(2, self::PRODUCT, 1, FulfillmentStatus::Processing, $figureAt->copy()->subHours(3));
        $this->storeOrder(3, self::PRODUCT, 1, FulfillmentStatus::Submitted, $figureAt->copy());
        $this->storeOrder(4, self::PRODUCT, 1, FulfillmentStatus::Submitted, $figureAt->copy()->addSecond());
        $this->storeOrder(5, self::PRODUCT, 1);

        $pool = $this->pool();

        $this->assertSame(3, $pool->storeHolds, 'the order accepted at the figure\'s own instant, the one accepted after it, and the one the provider has not got: 3; the two accepted before are in the figure.');
        $this->assertSame(17, $pool->net());
    }

    public function test_a_figure_a_person_typed_counts_store_orders_from_the_time_it_was_typed(): void
    {
        $typedAt = now()->subHour();
        $mapping = $this->providerFigure(self::PRODUCT, 20, $typedAt, StockSource::Manual);
        $this->storeOrder(1, self::PRODUCT, 2, FulfillmentStatus::Submitted, $typedAt->copy()->subMinutes(30));
        $this->storeOrder(2, self::PRODUCT, 3, FulfillmentStatus::Submitted, $typedAt->copy()->addMinutes(10));
        $this->storeOrder(3, self::PRODUCT, 4);

        $pool = $this->pool();

        $this->assertSame(StockAvailability::MANUAL, $pool->quality);
        $this->assertSame(7, $pool->storeHolds, 'the order accepted before it was typed is assumed to be in the typed count; the other two are not');
        $this->assertSame(13, $pool->net());
        $this->assertTrue($pool->isPublishable());
        $this->assertSame(20, $mapping->refresh()->provider_stock, 'the reader never writes the figure');
    }

    public function test_a_cancelled_line_and_a_finished_order_hold_nothing(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 2);
        $this->storeOrder(2, self::PRODUCT, 3);
        $this->storeOrder(3, self::PRODUCT, 4, orderStatus: 'delivered');
        DB::table('provider_fulfillment_items')->where('order_id', 2)->update(['status' => 'cancelled']);

        $this->assertSame(2, $this->pool()->storeHolds);
    }

    public function test_store_orders_of_another_provider_and_of_another_variant_are_not_this_pools(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 2);
        DB::table('provider_fulfillment_items')->where('order_id', 1)->update(['provider' => 'm5azn']);
        $this->storeOrder(2, self::PRODUCT, 3, variantKey: 'Red');

        $this->assertSame(0, $this->pool()->storeHolds);
    }

    public function test_a_location_no_provider_keeps_counts_no_store_orders_because_none_can_be_attributed_to_it(): void
    {
        $own = $this->ownLocation();
        InventoryLocationBalance::create(['location_id' => $own->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'sku' => 'S', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()]);
        $this->storeOrder(1, self::PRODUCT, 3);

        $pool = $this->net->for($own, self::PRODUCT);

        $this->assertSame([0, 20], [$pool->storeHolds, $pool->net()]);
    }

    // ---- a unit is counted exactly once ----------------------------------------------------------------------------------------

    public function test_a_unit_of_a_channel_order_that_became_an_internal_order_is_subtracted_once(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $this->assertSame([0, 2, 18], [$this->pool()->storeHolds, $this->pool()->channelHolds, $this->pool()->net()], 'before: the channel hold counts it');

        $this->storeOrder(100, self::PRODUCT, 2);
        $mapping->forceFill(['order_id' => 100])->save();
        app(ChannelOrderReservations::class)->sync($mapping->refresh());

        $pool = $this->pool();
        $this->assertSame([2, 0, 18], [$pool->storeHolds, $pool->channelHolds, $pool->net()], 'after: the provider item counts it, and nothing else does: 18, not 16');
        $hold = InventoryReservation::firstOrFail();
        $this->assertSame([InventoryReservation::RELEASED, ChannelOrderReservations::INTERNAL_ORDER_COUNTS_IT], [$hold->state, $hold->release_reason]);
    }

    public function test_the_hold_of_an_order_with_an_internal_order_never_comes_back_however_often_the_order_is_read(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $this->storeOrder(100, self::PRODUCT, 2);
        $mapping->forceFill(['order_id' => 100])->save();

        foreach (['2026-11-10 10:05:00', '2026-11-10 10:10:00'] as $updatedAt) {
            $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2, $updatedAt, ['status' => ChannelOrderStatus::Processing]);
        }
        app(ChannelOrderReservations::class)->sync($mapping->refresh());

        $this->assertSame(1, InventoryReservation::count());
        $this->assertSame(InventoryReservation::RELEASED, InventoryReservation::firstOrFail()->state);
        $this->assertSame(18, $this->pool()->net());
    }

    public function test_an_order_with_an_internal_order_is_never_reserved_in_the_first_place(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->storeOrder(100, self::PRODUCT, 2);
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        InventoryReservation::query()->delete();
        $mapping->forceFill(['order_id' => 100])->save();

        app(ChannelOrderReservations::class)->sync($mapping->refresh());

        $this->assertSame(0, InventoryReservation::where('state', '!=', InventoryReservation::RELEASED)->count());
        $this->assertSame(18, $this->pool()->net());
    }

    public function test_the_internal_order_gives_its_units_back_when_it_is_cancelled(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $this->storeOrder(100, self::PRODUCT, 2);
        $mapping->forceFill(['order_id' => 100])->save();
        app(ChannelOrderReservations::class)->sync($mapping->refresh());

        DB::table('orders')->where('id', 100)->update(['order_status' => 'canceled']);

        $this->assertSame(20, $this->pool()->net());
    }

    public function test_once_the_provider_has_the_internal_order_and_the_figure_is_newer_the_unit_is_in_the_figure(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $this->storeOrder(100, self::PRODUCT, 2, FulfillmentStatus::Submitted, now()->subMinutes(30));
        $mapping->forceFill(['order_id' => 100])->save();
        app(ChannelOrderReservations::class)->sync($mapping->refresh());
        $this->assertSame(18, $this->pool()->net(), 'accepted after the figure was taken: not in it yet');

        $this->providerFigure(self::PRODUCT, 18, now());

        $this->assertSame(18, $this->pool()->net(), 'the new figure shows the 2 units gone: nothing more is taken off');
    }

    // ---- what no pool is told about -----------------------------------------------------------------------------------------------

    public function test_a_line_linked_to_nothing_or_to_no_location_holds_nothing_and_is_reported_as_unattributed(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'NO-PLACE', ['stock_location_id' => null]);
        $this->ingestOrder($this->account, 'ORD-1', 'NOT-A-LISTING', 4);
        $placeless = $this->ingestOrder($this->account, 'ORD-2', 'NO-PLACE', 3);

        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(20, $this->pool()->net(), 'the units are on no pool: every pool still offers them');
        $this->assertSame([$this->account->id => 7], app(ChannelOrderReservations::class)->unattributedUnits());

        SalesChannelProductMapping::where('listing_key', 'NO-PLACE')->update(['stock_location_id' => $this->torodLocation()->id]);
        app(ChannelOrderReservations::class)->sync($placeless->refresh());

        $this->assertSame([$this->account->id => 4], app(ChannelOrderReservations::class)->unattributedUnits(), 'once the listing names a location its order holds its units');
        $this->assertSame(17, $this->pool()->net());
    }

    public function test_an_order_the_channel_ships_or_one_that_is_finished_is_not_unattributed(): void
    {
        $this->ingestOrder($this->account, 'ORD-1', 'NOT-A-LISTING', 4, overrides: ['fulfillmentMode' => FulfillmentMode::Fba]);
        $this->ingestOrder($this->account, 'ORD-2', 'NOT-A-LISTING', 3, overrides: ['status' => ChannelOrderStatus::Delivered]);
        $this->ingestOrder($this->account, 'ORD-3', 'NOT-A-LISTING', 5, overrides: ['status' => ChannelOrderStatus::Cancelled]);

        $this->assertSame([], app(ChannelOrderReservations::class)->unattributedUnits());
    }

    // ---- who reads it -------------------------------------------------------------------------------------------------------------

    private function sentQuantities(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))->map(fn (array $pair): int => $pair[0]->data()['items'][0]['quantity'])->values()->all();
    }

    public function test_the_publisher_offers_net_not_the_raw_figure(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1', ['safety_buffer' => 1]);
        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'b-1'])]);

        app(StockPricePublisher::class)->publishStock($this->account);

        $this->assertSame([14], $this->sentQuantities(), '20 - 3 store units - 2 channel units - the listing\'s buffer of 1');
    }

    public function test_a_deficit_is_published_as_zero_never_as_a_negative_quantity(): void
    {
        $this->providerFigure(self::PRODUCT, 3, now()->subHour());
        $listing = $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'b-1'])]);
        app(StockPricePublisher::class)->publishStock($this->account);
        $this->storeOrder(1, self::PRODUCT, 2);
        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $listing->refresh()->forceFill(['last_published_at' => now()->subHour()])->save();
        $this->travel(2)->hours();
        $this->providerFigure(self::PRODUCT, 3, now());

        app(StockPricePublisher::class)->publishStock($this->account);

        $this->assertSame([3, 0], $this->sentQuantities());
        $this->assertSame(1, $this->pool()->deficit());
    }

    public function test_a_product_sold_per_variant_is_not_offered_from_a_listing_that_names_no_variant(): void
    {
        $this->storeProduct(60, 'torod_point', 10, json_encode([['type' => 'Red', 'price' => 10, 'qty' => 5], ['type' => 'Blue', 'price' => 10, 'qty' => 5]]));
        $this->providerFigure(60, 8, now()->subHour());
        $this->providerFigure(60, 8, now()->subHour(), variantKey: 'Red');
        $this->stockListing($this->account, 60, $this->torodLocation(), 'WHOLE');
        $this->stockListing($this->account, 60, $this->torodLocation(), 'RED', ['variant_key' => 'Red']);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'b-1'])]);

        $result = app(StockPricePublisher::class)->publishStock($this->account);

        $this->assertSame([PoolFigures::VARIANT_KEY_REQUIRED => 1], $result['stats']['skipped']);
        $this->assertSame([8], $this->sentQuantities(), 'only the listing that names its variant is offered');
        $this->assertSame(PoolFigures::VARIANT_KEY_REQUIRED, $this->net->for($this->torodLocation(), 60)->refusal);
        $this->assertNull($this->net->for($this->torodLocation(), 60, 'Red')->refusal);
    }

    public function test_stock_the_store_owns_is_not_offered_until_the_owner_decides_and_nothing_is_decremented(): void
    {
        $own = $this->ownLocation();
        $own->forceFill(['source_of_truth' => 'platform'])->save();
        InventoryLocationBalance::create(['location_id' => $own->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'sku' => 'S', 'available' => 20, 'quality' => 'manual', 'source_synced_at' => now()]);
        $this->stockListing($this->account, self::PRODUCT, $own, 'BAR-1');
        Http::fake();

        $result = app(StockPricePublisher::class)->publishStock($this->account);

        $this->assertSame([PoolFigures::STOREFRONT_OWNED => 1], $result['stats']['skipped']);
        Http::assertNothingSent();
        $this->assertSame(50, (int) DB::table('products')->where('id', self::PRODUCT)->value('current_stock'), 'the module never writes the store\'s stock');
        $this->assertSame(PoolFigures::STOREFRONT_OWNED, $this->net->for($own, self::PRODUCT)->refusal);
    }

    public function test_the_routing_check_asks_whether_the_net_of_the_pool_covers_the_line(): void
    {
        $this->providerFigure(self::PRODUCT, 2, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        $factory = app(RoutingContextFactory::class);
        $this->assertTrue($factory->for($mapping->refresh())->lines[0]->stockConfirmed, 'two units on the shelf cover the order\'s two');

        $this->storeOrder(1, self::PRODUCT, 1);

        $this->assertFalse($factory->for($mapping->refresh())->lines[0]->stockConfirmed, 'a store order the provider has not counted yet takes a unit: one is left');
    }

    public function test_the_ingestor_still_records_holds_through_the_same_reservations(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');

        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2);
        app(ChannelOrderIngestor::class)->relinkLines(ExternalOrderMapping::firstOrFail());

        $this->assertSame(1, InventoryReservation::count());
        $this->assertSame(18, $this->pool()->net());
    }
}
