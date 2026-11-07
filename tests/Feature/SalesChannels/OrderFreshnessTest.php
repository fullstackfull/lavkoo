<?php

namespace Tests\Feature\SalesChannels;

use App\Models\SalesChannelAccount;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Inventory\Allocation\OrderFreshness;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * A quantity is raised only on proof that every order of every account offering the pool has been imported: a COMPLETE sweep of the account's orders that finished recently. A
 * sweep that stopped part of the way through a backlog, an import that never ran and an account that does not import orders at all prove nothing. Lowering never needs proof.
 */
class OrderFreshnessTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private const SELLER = '1234567';

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
        $this->account = $this->trendyolAccount(sellerId: self::SELLER);
        $this->storeProduct(self::PRODUCT);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
    }

    /** @return list<int> the quantities sent to the account's channel, oldest first */
    private function sent(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/sellers/' . self::SELLER . '/products/price-and-inventory'))
            ->flatMap(fn (array $pair): array => array_column($pair[0]->data()['items'], 'quantity'))->values()->all();
    }

    private function publish(SalesChannelAccount $account): array
    {
        return app(StockPricePublisher::class)->publishStock($account);
    }

    private function staleAccounts(): array
    {
        return app(OrderFreshness::class)->staleAccounts([$this->account->id]);
    }

    public function test_a_quantity_is_not_raised_while_the_order_import_is_part_way_through_a_backlog(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->completeOrderSweep($this->account);
        $this->publish($this->account);
        $this->assertSame([10], $this->sent());

        $this->travel(20)->minutes();
        $this->providerFigure(self::PRODUCT, 40, now());
        SalesChannelSyncState::where('account_id', $this->account->id)->update(['last_success_at' => now()->subMinute(), 'cursor' => json_encode(['page' => 26])]);

        $result = $this->publish($this->account);

        $this->assertSame(['order_sync_stale' => 1], $result['stats']['skipped'], 'a sweep that stopped at page 26 stamped its time and left the rest of the backlog unread');
        $this->assertSame([10], $this->sent());
        $this->assertSame([$this->account->id], $this->staleAccounts());
    }

    public function test_a_quantity_is_not_raised_for_an_account_whose_orders_are_not_imported_at_all(): void
    {
        $account = $this->trendyolAccount(capabilities: ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing'], sellerId: '5550001');
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-Q');
        $this->publish($account);
        $this->providerFigure(self::PRODUCT, 40, now());
        $this->travel(20)->minutes();

        $result = $this->publish($account);

        $this->assertSame(['order_sync_stale' => 1], $result['stats']['skipped'], 'every sale this channel makes is unknown to us: its quantity is never raised');
        $this->assertSame([$account->id], app(OrderFreshness::class)->staleAccounts([$account->id]));
    }

    public function test_the_first_quantity_of_a_listing_needs_no_proof_but_a_raise_above_what_the_channel_shows_does(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $listing = $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->account);
        $this->assertSame([10], $this->sent(), 'nothing was ever shown for this listing: there is no earlier quantity to protect');

        $listing->refresh()->forceFill(['remote_quantity' => 3, 'last_seen_at' => now()->addMinute()])->save();
        $this->travel(2)->minutes();
        $this->providerFigure(self::PRODUCT, 40, now());
        $result = $this->publish($this->account);

        $this->assertSame(['order_sync_stale' => 1], $result['stats']['skipped'], 'the channel shows 3 now (read back after our write) and the pool has more than when we wrote: 40 is a raise');
        $this->assertSame([10], $this->sent());
    }

    public function test_a_complete_sweep_is_proof_for_the_minutes_the_setting_names_and_no_longer(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $listing = $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $listing->forceFill(['remote_quantity' => 0, 'last_seen_at' => now()->subMinute()])->save();
        $this->completeOrderSweep($this->account);
        $this->assertSame([], $this->staleAccounts());

        $this->publish($this->account);
        $this->assertSame([10], $this->sent(), 'proof at hand: the quantity goes from 0 to 10');

        $this->travel((int) config('sales_channels.stock.allocation.order_fresh_minutes') + 1)->minutes();
        $this->assertSame([$this->account->id], $this->staleAccounts(), 'the last complete sweep is older than the window');
    }

    public function test_a_lowering_needs_no_proof(): void
    {
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->completeOrderSweep($this->account);
        $this->publish($this->account);
        $this->travel(1)->day();
        $this->providerFigure(self::PRODUCT, 10, now()->subMinutes(30));
        $this->storeOrder(1, self::PRODUCT, 4);

        $this->publish($this->account);

        $this->assertSame([10, 6], $this->sent(), 'the order import is a day old and the quantity still comes down');
    }

    public function test_the_reason_an_account_has_no_proof_is_told_apart(): void
    {
        $withoutImport = $this->trendyolAccount(capabilities: ['connection_test', 'read_only_sync', 'product_sync', 'inventory_publishing'], sellerId: '5550001');
        $backlog = $this->trendyolAccount(sellerId: '5550002');
        $neverFinished = $this->trendyolAccount(sellerId: '5550003');
        $late = $this->trendyolAccount(sellerId: '5550004');
        SalesChannelSyncState::create(['account_id' => $backlog->id, 'resource' => 'orders', 'last_success_at' => now()->subMinute(), 'cursor' => json_encode(['page' => 2])]);
        SalesChannelSyncState::create(['account_id' => $late->id, 'resource' => 'orders', 'last_success_at' => now()->subHours(2)]);

        $why = app(OrderFreshness::class)->why([$withoutImport->id, $backlog->id, $neverFinished->id, $late->id]);

        $this->assertSame(
            [OrderFreshness::IMPORT_OFF, OrderFreshness::BACKLOG, OrderFreshness::NEVER_COMPLETED, OrderFreshness::LATE],
            array_column($why, 'reason'),
        );
        $this->assertTrue($why[$late->id]['last_success_at']->eq(now()->subHours(2)));
    }
}
