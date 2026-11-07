<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Models\SalesChannelAccount;
use App\Services\Fulfillment\Contracts\ReportsChannelHolds;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\Support\NoChannelHolds;
use App\Services\SalesChannels\Inventory\ChannelHoldsOnProviderStock;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolNet;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * ONE provider shelf, two sellers. Torod Point's warehouse and M5AZN's supplier stock are sold from by the storefront (the provider sync writes `products.current_stock`) and
 * published to Amazon FBM and Trendyol at the same time. A unit an open channel order holds has to come off BOTH sides, and off each of them once: that is what these tests pin.
 */
class SharedProviderStockTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private SalesChannelAccount $account;

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
        $this->storeProduct(self::PRODUCT);
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
    }

    private function storefrontStock(int $productId = self::PRODUCT): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** The figures the warehouse reports, applied the way the scheduled `fulfillment:sync --only=inventory` applies them. */
    private function providerSync(int $units, ?CarbonInterface $readStartedAt = null): void
    {
        app(InventorySyncService::class)->applyToAssignedProducts(ProviderCode::TorodPoint, ['ext-' . self::PRODUCT => $units], $readStartedAt ?? now());
    }

    private function channelOrder(int $units, ChannelOrderStatus $status = ChannelOrderStatus::New): void
    {
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', $units, status: $status);
    }

    // ---- the unit a channel sold is not offered to a customer as well -------------------------------------------------------

    public function test_a_channel_orders_units_come_off_the_stock_the_storefront_is_told_it_has(): void
    {
        $this->channelOrder(2);

        $this->providerSync(20);

        $this->assertSame(18, $this->storefrontStock(), '20 at the warehouse, 2 promised to a channel');
        $this->assertSame(18, app(PoolNet::class)->for($this->torodLocation(), self::PRODUCT)->net(), 'the channels see the same 18: one figure, one subtraction per promise');
    }

    public function test_store_orders_and_channel_orders_both_come_off_and_neither_covers_for_the_other(): void
    {
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->channelOrder(2);

        $this->providerSync(20);

        $this->assertSame(15, $this->storefrontStock(), '20 - 3 store units - 2 channel units');
    }

    public function test_a_channel_order_with_an_internal_order_of_its_own_is_counted_once_not_twice(): void
    {
        $this->channelOrder(2);
        $mapping = ExternalOrderMapping::firstOrFail();
        // What managed dispatch leaves behind: an internal store order whose provider lines count the units, and a hold released because of it.
        $this->storeOrder(77, self::PRODUCT, 2);
        $mapping->forceFill(['order_id' => 77])->save();
        app(ChannelOrderReservations::class)->sync($mapping->refresh());

        $this->providerSync(20);

        $this->assertSame(InventoryReservation::RELEASED, InventoryReservation::firstOrFail()->state);
        $this->assertSame(18, $this->storefrontStock(), 'the internal order counts the 2 units; the channel hold must not count them again');
    }

    public function test_units_a_shipped_channel_order_took_off_before_the_figure_was_read_are_not_taken_off_again(): void
    {
        $this->channelOrder(2);
        app(ReservationService::class)->commit(InventoryReservation::firstOrFail()->reservation_key, at: now()->subHour());

        $this->providerSync(20, readStartedAt: now());

        $this->assertSame(20, $this->storefrontStock(), 'the warehouse counted the parcel out before it gave this figure');
    }

    public function test_a_hold_committed_after_the_figure_was_read_still_counts(): void
    {
        $this->channelOrder(2);
        $readStartedAt = now();
        $this->travel(2)->minutes();
        app(ReservationService::class)->commit(InventoryReservation::firstOrFail()->reservation_key);

        $this->providerSync(20, readStartedAt: $readStartedAt);

        $this->assertSame(18, $this->storefrontStock(), 'the figure is older than the moment the units left the shelf');
    }

    public function test_more_promised_than_the_warehouse_holds_leaves_nothing_to_sell_and_never_a_negative_stock(): void
    {
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->channelOrder(4);

        $this->providerSync(5);

        $this->assertSame(0, $this->storefrontStock());
    }

    public function test_a_cancelled_channel_order_gives_its_units_back(): void
    {
        $this->channelOrder(2);
        $this->providerSync(20);
        $this->assertSame(18, $this->storefrontStock());

        $this->ingestOrder($this->account, 'ORD-1', 'BAR-1', 2, updatedAt: '2026-11-10 11:00:00', status: ChannelOrderStatus::Cancelled);
        $this->providerSync(20);

        $this->assertSame(20, $this->storefrontStock());
    }

    public function test_the_m5azn_shelf_is_shared_the_same_way(): void
    {
        $this->storeProduct(51, 'm5azn');
        $this->providerFigure(51, 20, now()->subHour(), provider: 'm5azn');
        $m5azn = app(InventoryLocationService::class)->ensureDefaults()[1];
        $this->stockListing($this->account, 51, $m5azn, 'BAR-51');
        $this->ingestOrder($this->account, 'ORD-51', 'BAR-51', 3);

        app(InventorySyncService::class)->apply(ProviderCode::M5azn, ['ext-51' => 20]);

        $this->assertSame(17, $this->storefrontStock(51), 'the legacy path writes the same shared figure');
    }

    public function test_a_hold_on_another_provider_s_shelf_is_not_taken_off_this_one(): void
    {
        $this->storeProduct(51, 'm5azn');
        $this->providerFigure(51, 20, now()->subHour(), provider: 'm5azn');
        $m5azn = app(InventoryLocationService::class)->ensureDefaults()[1];
        $this->stockListing($this->account, 51, $m5azn, 'BAR-51');
        $this->ingestOrder($this->account, 'ORD-51', 'BAR-51', 3);

        $this->providerSync(20);

        $this->assertSame(20, $this->storefrontStock(), 'the Torod shelf knows nothing of what M5AZN promised');
    }

    // ---- inert where it must be --------------------------------------------------------------------------------------------

    public function test_with_the_switch_off_the_storefront_figure_is_what_it_was_before(): void
    {
        config(['sales_channels.inventory.hold_channel_units_from_storefront' => false]);
        $this->channelOrder(2);

        $this->providerSync(20);

        $this->assertSame(20, $this->storefrontStock());
    }

    public function test_without_the_modules_tables_nothing_is_subtracted(): void
    {
        $this->channelOrder(2);
        Schema::drop('inventory_pools');

        $this->assertSame(0, app(ChannelHoldsOnProviderStock::class)->unitsHeld(ProviderCode::TorodPoint, self::PRODUCT, '', now()));
    }

    public function test_the_fulfilment_module_on_its_own_reports_no_channel_holds(): void
    {
        $this->assertSame(0, (new NoChannelHolds())->unitsHeld(ProviderCode::TorodPoint, self::PRODUCT, '', now()));
        $this->assertInstanceOf(ChannelHoldsOnProviderStock::class, app(ReportsChannelHolds::class), 'the sales-channel module answers once it is installed');
    }
}
