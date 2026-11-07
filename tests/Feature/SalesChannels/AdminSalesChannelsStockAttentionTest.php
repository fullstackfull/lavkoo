<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryPool;
use App\Models\InventoryReservation;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use App\Services\SalesChannels\Inventory\PoolKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsStockPools;

/**
 * The hub says which shared stock needs a person (promised beyond what is held, shown beyond what is left, shown though its source is not confirmed, quantities frozen by a late
 * order import, order units no stock is told about, reservations held for days) and the stock screen says how each pool is shared. Both only read.
 */
class AdminSalesChannelsStockAttentionTest extends AdminSalesChannelsTestCase
{
    use BuildsRemoteOrders;
    use BuildsStockPools;

    private const PRODUCT = 50;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        app(InventoryLocationService::class)->ensureDefaults();
        $this->storeProduct(self::PRODUCT);
    }

    private function hub(): string
    {
        return $this->get(route('admin.sales-channels.index'))->assertOk()->getContent();
    }

    private function divide(): void
    {
        app(PoolAllocationService::class)->allocate(new PoolKey($this->torodLocation()->id, self::PRODUCT));
    }

    public function test_the_hub_lists_a_pool_promised_beyond_what_it_holds_with_the_orders_holding_it_newest_first(): void
    {
        $this->providerFigure(self::PRODUCT, 3, now()->subMinutes(30));
        $this->storeOrder(1, self::PRODUCT, 2);
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($account, 'ORD-A', 'BAR-1', 1);
        $this->ingestOrder($account, 'ORD-B', 'BAR-1', 1);
        $this->divide();

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_deficit')), $html);
        $this->assertStringContainsString('Product 50', $html);
        $this->assertStringContainsString('1 ' . e(translate('units_promised_beyond_the_stock')), $html);
        $this->assertStringContainsString(e(translate('held_by_orders')) . ': ORD-B, ORD-A', $html, 'the order that arrived last is first');
        $this->assertSame(0, InventoryReservation::where('state', 'released')->count(), 'the hub only reads');
        Http::assertNothingSent();
    }

    public function test_the_hub_lists_a_pool_whose_channels_are_shown_more_than_is_left(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        $first = $this->stockListing($this->trendyolAccount(), self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $second = $this->stockListing($this->trendyolAccount(sellerId: '7654321'), self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $this->divide();
        SalesChannelProductMapping::query()->update(['last_published_quantity' => 10]);
        $this->storeOrder(1, self::PRODUCT, 10);
        $this->divide();

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_over_offered')), $html);
        $this->assertStringContainsString(e(translate('shown_on_channels')) . ': 20 · ' . e(translate('net_to_offer')) . ': 10', $html);
        $this->assertNotNull($first->id + $second->id);
    }

    public function test_the_hub_lists_a_listing_that_still_shows_stock_its_source_no_longer_confirms(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subHours(5));
        $listing = $this->stockListing($this->trendyolAccount(), self::PRODUCT, $this->torodLocation(), 'BAR-STALE');
        $listing->forceFill(['last_published_quantity' => 6])->save();
        $this->divide();

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_source_not_confirmed')), $html);
        $this->assertStringContainsString('BAR-STALE', $html);
        $this->assertStringContainsString(e(translate('publish_skip_stock_not_confirmed')) . ' (' . e(translate('stock_quality_stale')) . ')', $html);
    }

    public function test_the_hub_says_which_accounts_have_their_quantities_frozen_by_a_late_order_import(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $late = $this->trendyolAccount();
        $never = $this->trendyolAccount(sellerId: '7654321');
        $backlog = $this->trendyolAccount(sellerId: '7654322');
        $withoutImport = $this->trendyolAccount(capabilities: ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing'], sellerId: '7654323');
        foreach ([$late, $never, $backlog, $withoutImport] as $number => $account) {
            $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-' . $number);
        }
        SalesChannelSyncState::create(['account_id' => $late->id, 'resource' => 'orders', 'last_success_at' => now()->subHour()]);
        SalesChannelSyncState::create(['account_id' => $never->id, 'resource' => 'orders']);
        SalesChannelSyncState::create(['account_id' => $backlog->id, 'resource' => 'orders', 'last_success_at' => now()->subMinute(), 'cursor' => json_encode(['page' => 26])]);
        $this->divide();

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_raises_frozen')), $html);
        $this->assertStringContainsString(e(translate('last_successful_order_import')) . ': ' . now()->subHour()->format('Y-m-d H:i'), $html);
        $this->assertStringContainsString(e(translate('no_successful_order_import_yet')), $html);
        $this->assertStringContainsString(e(translate('order_import_is_part_way_through_a_backlog')), $html, 'a sweep that stopped part of the way stamped its time: the hub does not call that a recent import');
        $this->assertStringContainsString(e(translate('order_import_is_off_so_a_quantity_here_is_never_raised')), $html);
    }

    public function test_the_hub_counts_order_units_no_stock_is_told_about(): void
    {
        $account = $this->trendyolAccount();
        $this->ingestOrder($account, 'ORD-X', 'NOT-A-LISTING', 4);

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_unattributed')), $html);
        $this->assertStringContainsString('4 ' . e(translate('units_on_orders_linked_to_no_product_or_place')), $html);
    }

    public function test_the_hub_lists_reservations_held_for_days(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $mapping = $this->ingestOrder($account, 'ORD-OLD', 'BAR-1', 2);
        InventoryReservation::query()->update(['reserved_at' => now()->subDays(10)]);

        $html = $this->hub();

        $this->assertStringContainsString(e(translate('attention_stock_aged_holds')), $html);
        $this->assertStringContainsString('ORD-OLD', $html);
        $this->assertStringContainsString('2 ' . e(translate('units_held_since')) . ' ' . now()->subDays(10)->format('Y-m-d'), $html);
        $this->assertNotNull($mapping);
    }

    public function test_a_healthy_pool_raises_none_of_them(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->ingestOrder($account, 'ORD-1', 'BAR-1', 2);
        $this->completeOrderSweep($account);
        $this->divide();

        $html = $this->hub();

        foreach (['attention_stock_deficit', 'attention_stock_over_offered', 'attention_stock_source_not_confirmed', 'attention_stock_raises_frozen', 'attention_stock_unattributed', 'attention_stock_aged_holds'] as $key) {
            $this->assertStringNotContainsString(e(translate($key)), $html, $key);
        }
    }

    public function test_the_stock_screen_shows_how_a_pool_is_shared_and_what_each_channel_promised(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        InventoryPool::create((new PoolKey($this->torodLocation()->id, self::PRODUCT))->columns() + ['safety_buffer' => 2]);
        $first = $this->trendyolAccount();
        $second = $this->trendyolAccount(sellerId: '7654321');
        $this->stockListing($first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->stockListing($second, self::PRODUCT, $this->torodLocation(), 'BAR-B', ['stock_share_mode' => 'fixed', 'stock_share_value' => 5]);
        $this->ingestOrder($first, 'ORD-1', 'BAR-A', 2);
        $this->divide();

        $html = $this->get(route('admin.sales-channels.inventory.index', ['location' => $this->torodLocation()->id]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<td class="fs-12 k-table__num">20<\/td>\s*<td class="fs-12 k-table__num">2(?:<div.*?<\/div>)*<\/td>\s*<td class="fs-12 k-table__num">0<\/td>\s*<td class="fs-12 k-table__num">16/s', $html, '20, less 2 held, less the pool\'s buffer of 2: 16');
        $this->assertStringContainsString('trendyol: 2', $html, 'what each channel promised');
        $this->assertStringContainsString(e(translate('pool_buffer')) . ': 2', $html);
        $this->assertStringContainsString(e(translate('listings_sharing_this_stock')) . ' (2)', $html);
        foreach (['BAR-A', 'BAR-B', $first->name] as $shown) {
            $this->assertStringContainsString($shown, $html);
        }
        $this->assertStringContainsString(e(translate('stock_entitled')) . ': 5', $html, 'the fixed share of the second listing');
        $this->assertStringContainsString(e(translate('stock_entitled')) . ': 11', $html, 'and the first takes what is left: 16 - 5');
        Http::assertNothingSent();
    }
}
