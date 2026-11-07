<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Models\InventoryPool;
use App\Models\InventoryReservation;
use App\Models\InventorySourcePolicy;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Models\SalesChannelSyncState;
use App\Services\SalesChannels\Inventory\Allocation\AllocationResult;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Allocation\MemberShare;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocation;
use App\Services\SalesChannels\Inventory\Allocation\PoolAllocationService;
use App\Services\SalesChannels\Inventory\Allocation\StockAllocator;
use App\Services\SalesChannels\Inventory\Allocation\StorefrontReserve;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Inventory\ReservationService;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Support\ChannelOperations;
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
 * One physical stock offered by several listings of several accounts: what all of them are shown never adds up to more than is left, a quantity is raised only when that is
 * safe, and a sale on one channel lowers what every channel is shown within one quick run.
 */
class StockAllocationTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

    private const SELLER_A = '1234567';

    private const SELLER_B = '7654321';

    private SalesChannelAccount $first;

    private SalesChannelAccount $second;

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
        $this->first = $this->trendyolAccount(sellerId: self::SELLER_A);
        $this->second = $this->trendyolAccount(sellerId: self::SELLER_B);
        $this->storeProduct(self::PRODUCT);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
        RacingAllocations::reset();
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------------------

    private function figure(int $quantity): void
    {
        $this->providerFigure(self::PRODUCT, $quantity, now()->subMinutes(30));
    }

    /** @return array{0: SalesChannelProductMapping, 1: SalesChannelProductMapping} one listing of each account, both on the pool of the Torod warehouse */
    private function listings(array $first = [], array $second = []): array
    {
        return [$this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A', $first), $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B', $second)];
    }

    private function publish(SalesChannelAccount ...$accounts): void
    {
        foreach ($accounts as $account) {
            app(StockPricePublisher::class)->publishStock($account);
        }
    }

    /** @return list<int> the quantities sent to one account's channel, oldest first */
    private function sent(string $seller): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/sellers/{$seller}/products/price-and-inventory"))
            ->map(fn (array $pair): int => $pair[0]->data()['items'][0]['quantity'])->values()->all();
    }

    private function pool(): InventoryPool
    {
        return InventoryPool::where($this->key()->columns())->firstOrFail();
    }

    private function key(): PoolKey
    {
        return new PoolKey($this->torodLocation()->id, self::PRODUCT);
    }

    // ---- dividing one stock ----------------------------------------------------------------------------------------------------------------

    public function test_two_accounts_on_one_pool_are_never_shown_more_in_total_than_the_net(): void
    {
        $this->figure(20);
        $this->storeOrder(1, self::PRODUCT, 3);
        [$a, $b] = $this->listings();

        $this->publish($this->first, $this->second);

        $this->assertSame([8], $this->sent(self::SELLER_A));
        $this->assertSame([8], $this->sent(self::SELLER_B));
        $pool = $this->pool();
        $this->assertLessThanOrEqual($pool->last_net, array_sum($this->sent(self::SELLER_A)) + array_sum($this->sent(self::SELLER_B)), 'what the two channels are shown together never adds up to more than 20 on the shelf less the 3 units of the store order');
        $this->assertSame([1, 17, 0], [$pool->version, $pool->last_net, $pool->deficit]);
        $this->assertSame(['equal', 8, 8, 17], [$a->refresh()->allocation['rule'], $a->allocation['entitlement'], $a->allocation['publish'], $a->allocation['net']]);
        $this->assertSame((string) $this->key(), $b->refresh()->allocation['pool']);
    }

    public function test_a_second_run_with_nothing_changed_leaves_the_version_and_sends_nothing(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);

        $this->publish($this->first, $this->second);

        $this->assertSame(1, $this->pool()->version, 'the same inputs give the same division, and the version stays');
        $this->assertCount(1, $this->sent(self::SELLER_A));
        $this->assertCount(1, $this->sent(self::SELLER_B));
    }

    public function test_the_storefront_reserve_comes_off_first_and_a_pool_can_name_its_own(): void
    {
        $this->figure(14);
        $this->listings();
        config(['sales_channels.stock.allocation.storefront_reserve_mode' => 'percent', 'sales_channels.stock.allocation.storefront_reserve_value' => 50]);

        $this->publish($this->first);
        $this->assertSame([3], $this->sent(self::SELLER_A), '14, half for the storefront, 7 shared by two listings: 3 each, and the unit left over stays with the storefront');

        InventoryPool::where($this->key()->columns())->update(['storefront_reserve_mode' => 'fixed', 'storefront_reserve_value' => 4]);
        $this->completeOrderSweep($this->first, $this->second);
        $this->publish($this->first);
        $this->assertSame([3, 5], $this->sent(self::SELLER_A), 'the pool\'s own reserve of 4 units wins over the default: 10 shared by two');
    }

    public function test_shares_come_from_the_listing_then_the_product_policy_then_the_account_policy(): void
    {
        $this->figure(14);
        [$a, $b] = $this->listings();
        InventorySourcePolicy::create(['scope' => 'account', 'account_id' => $this->second->id, 'stock_share_mode' => 'percent', 'stock_share_value' => 50, 'is_active' => true]);
        InventorySourcePolicy::create(['scope' => 'product', 'account_id' => $this->second->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'stock_share_mode' => 'percent', 'stock_share_value' => 25, 'is_active' => true]);

        $this->publish($this->first, $this->second);
        $this->assertSame([10], $this->sent(self::SELLER_A), 'B asked for 25% (3 of 14): A takes what is left');
        $this->assertSame([3], $this->sent(self::SELLER_B), 'the product policy wins over the account policy');

        $b->forceFill(['stock_share_mode' => 'fixed', 'stock_share_value' => 9])->save();
        $this->completeOrderSweep($this->first, $this->second);
        $this->publish($this->first, $this->second);
        $this->assertSame([10, 5], $this->sent(self::SELLER_A));
        $this->assertSame([3, 9], $this->sent(self::SELLER_B), 'the listing\'s own share wins over both policies');
    }

    public function test_a_priority_listing_fills_first_up_to_its_cap_and_the_rest_goes_to_the_others(): void
    {
        $this->figure(20);
        $this->listings(['stock_share_mode' => 'priority', 'stock_priority' => 1, 'max_publish_quantity' => 12]);

        $this->publish($this->first, $this->second);

        $this->assertSame([12], $this->sent(self::SELLER_A));
        $this->assertSame([8], $this->sent(self::SELLER_B));
    }

    public function test_a_listing_buffer_and_a_pool_buffer_are_taken_off_and_never_more_than_the_cap_is_shown(): void
    {
        $this->figure(20);
        InventoryPool::create($this->key()->columns() + ['safety_buffer' => 2]);
        $this->listings(['safety_buffer' => 1, 'max_publish_quantity' => 3]);

        $this->publish($this->first, $this->second);

        $this->assertSame([3], $this->sent(self::SELLER_A), '20 - 2 (the pool\'s buffer) = 18; the cap of 3 and the listing\'s buffer of 1 let it take 4, and show 3');
        $this->assertSame([14], $this->sent(self::SELLER_B), 'what the capped listing cannot take goes to the other');
    }

    public function test_a_listing_that_cannot_take_stock_takes_no_part_of_the_pool(): void
    {
        $this->figure(20);
        $this->listings(['fulfillment_mode' => 'fba']);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'NOT-OPTED-IN', ['inventory_publish' => false]);

        $this->publish($this->first, $this->second);

        $this->assertSame([], $this->sent(self::SELLER_A));
        $this->assertSame([20], $this->sent(self::SELLER_B), 'an FBA listing and a listing nobody opted in leave the whole pool to the one that can use it');
    }

    // ---- a sale on one channel -------------------------------------------------------------------------------------------------------------

    public function test_a_sale_on_one_channel_lowers_what_every_channel_is_shown_in_one_quick_run(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);
        $this->assertSame([10], $this->sent(self::SELLER_B));

        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2);
        $this->assertSame([(string) $this->key()], array_map('strval', app(DirtyPools::class)->pending()), 'the import marked the pool');
        $this->travel(2)->seconds();
        $run = app(DirtyPoolRun::class)->run();

        $this->assertSame(1, $run['pools']);
        $this->assertSame([10, 9], $this->sent(self::SELLER_A));
        $this->assertSame([10, 9], $this->sent(self::SELLER_B), 'the other channel is lowered too, though the sale was not on it');
        $this->assertNull($this->pool()->dirty_at, 'the run worked the pool out again');
        $this->assertSame([], app(DirtyPools::class)->pending());
    }

    public function test_the_quick_run_never_raises_a_quantity(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);

        $this->figure(30);
        app(DirtyPools::class)->mark([$this->key()]);
        app(DirtyPoolRun::class)->run();

        $this->assertSame([10], $this->sent(self::SELLER_A));
        $this->assertSame([10], $this->sent(self::SELLER_B));
    }

    public function test_a_small_drop_of_a_large_quantity_is_left_to_the_regular_run_and_a_small_quantity_is_always_lowered(): void
    {
        $this->figure(200);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->first);
        $this->assertSame([200], $this->sent(self::SELLER_A));

        $this->storeOrder(1, self::PRODUCT, 4);
        app(DirtyPools::class)->mark([$this->key()]);
        app(DirtyPoolRun::class)->run();
        $this->assertSame([200], $this->sent(self::SELLER_A), '196 instead of 200: 2%, not worth a call');

        $this->storeOrder(2, self::PRODUCT, 40);
        app(DirtyPools::class)->mark([$this->key()]);
        app(DirtyPoolRun::class)->run();
        $this->assertSame([200, 156], $this->sent(self::SELLER_A), '156: a fifth less');

        $this->storeOrder(3, self::PRODUCT, 146);
        app(DirtyPools::class)->mark([$this->key()]);
        app(DirtyPoolRun::class)->run();
        $this->assertSame([200, 156, 10], $this->sent(self::SELLER_A), '10 or fewer left: always sent');

        $this->storeOrder(4, self::PRODUCT, 1);
        app(DirtyPools::class)->mark([$this->key()]);
        app(DirtyPoolRun::class)->run();
        $this->assertSame([200, 156, 10, 9], $this->sent(self::SELLER_A));
    }

    public function test_orders_mark_their_pools_only_when_what_they_promise_changed(): void
    {
        $this->figure(20);
        $this->listings();
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2);
        $this->assertNotNull($this->pool()->dirty_at);
        $this->travel(2)->seconds();
        app(PoolAllocationService::class)->allocate($this->key());
        $this->assertNull($this->pool()->dirty_at);

        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2, '2026-11-10 10:05:00');
        $this->assertNull($this->pool()->dirty_at, 'the same order read again promises the same: nothing to work out');

        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2, '2026-11-10 10:10:00', status: ChannelOrderStatus::Cancelled);
        $this->assertNotNull($this->pool()->dirty_at, 'a cancelled order gives its units back: the pool is worked out again');
    }

    public function test_a_mark_made_while_a_division_is_being_worked_out_is_kept(): void
    {
        $this->figure(20);
        $this->listings();
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2);
        $this->pool()->forceFill(['dirty_at' => now()])->save();

        app(PoolAllocationService::class)->allocate($this->key());

        $this->assertNotNull($this->pool()->dirty_at, 'marked in the same second the division began: it is worked out once more, never lost');
        $this->travel(1)->seconds();
        app(PoolAllocationService::class)->allocate($this->key());
        $this->assertNull($this->pool()->dirty_at);
    }

    // ---- when a quantity may be raised -------------------------------------------------------------------------------------------------------

    public function test_a_quantity_is_not_raised_while_an_order_import_is_behind_and_is_when_it_is_not(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);
        $this->completeOrderSweep($this->first);
        SalesChannelSyncState::create(['account_id' => $this->second->id, 'resource' => 'orders', 'last_success_at' => now()->subHour()]);

        $this->figure(40);
        $this->publish($this->first, $this->second);
        $this->assertSame([10], $this->sent(self::SELLER_A), 'more stock has arrived, but the orders of one account are an hour old');
        $this->assertSame([10], $this->sent(self::SELLER_B));

        SalesChannelSyncState::where('account_id', $this->second->id)->update(['last_success_at' => now()->subMinutes(2)]);
        $this->publish($this->first, $this->second);
        $this->assertSame([10, 20], $this->sent(self::SELLER_A));
        $this->assertSame([10, 20], $this->sent(self::SELLER_B));
    }

    public function test_a_lowering_goes_out_even_when_the_order_import_is_behind(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);
        SalesChannelSyncState::create(['account_id' => $this->first->id, 'resource' => 'orders', 'last_success_at' => now()->subDay()]);

        $this->storeOrder(1, self::PRODUCT, 4);
        $this->publish($this->first, $this->second);

        $this->assertSame([10, 8], $this->sent(self::SELLER_B));
    }

    public function test_an_account_that_never_imports_orders_has_nothing_to_be_late(): void
    {
        $this->figure(20);
        $this->listings();

        $this->publish($this->first, $this->second);

        $this->assertSame([10], $this->sent(self::SELLER_A), 'no order import has ever run, so none is behind: the first publish is not held up');
    }

    public function test_after_a_deficit_a_quantity_stays_down_for_a_few_computations_and_then_is_raised(): void
    {
        $this->figure(10);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->first);
        $this->assertSame([10], $this->sent(self::SELLER_A));

        $this->storeOrder(1, self::PRODUCT, 8);
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 4);
        $this->publish($this->first);
        $this->assertSame([10, 0], $this->sent(self::SELLER_A), 'promised 12 of 10: nothing is offered');
        $this->assertSame([0, 2], [$this->pool()->last_net, $this->pool()->deficit]);

        DB::table('orders')->where('id', 1)->update(['order_status' => 'canceled']);
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 4, '2026-11-10 10:05:00', status: ChannelOrderStatus::Cancelled);
        foreach ([1, 2] as $computation) {
            $result = app(StockPricePublisher::class)->publishStock($this->first);
            $this->assertSame(['deficit_block' => 1], $result['stats']['skipped'], "computation {$computation} after the deficit: still held down");
        }
        $this->assertSame([10, 0], $this->sent(self::SELLER_A));

        $this->completeOrderSweep($this->first);
        $this->publish($this->first);
        $this->assertSame([10, 0, 10], $this->sent(self::SELLER_A), 'two clean computations later it is raised');
    }

    public function test_a_listing_is_raised_only_after_the_listing_that_gave_units_up_has_been_lowered(): void
    {
        $this->figure(20);
        $a = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->first);
        $this->assertSame([20], $this->sent(self::SELLER_A));

        $b = $this->stockListing($this->second, self::PRODUCT, $this->torodLocation(), 'BAR-B');
        $result = app(StockPricePublisher::class)->publishStock($this->second);

        $this->assertSame(['awaiting_siblings' => 1], $result['stats']['skipped'], 'A still shows 20 and is entitled to 10: B waits for it to come down');
        $this->publish($this->first);
        $this->assertSame([20, 10], $this->sent(self::SELLER_A));
        app(StockPricePublisher::class)->publishStock($this->second);
        $this->assertSame([10], $this->sent(self::SELLER_B));
        $this->assertSame([10, 10], [$a->refresh()->last_published_quantity, $b->refresh()->last_published_quantity]);
    }

    // ---- a channel that lowered its own quantity ---------------------------------------------------------------------------------------------

    public function test_a_quantity_the_channel_lowered_itself_is_not_put_back_unless_the_pool_has_more_than_it_had(): void
    {
        $this->figure(20);
        $listing = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->first);
        $this->assertSame([20], $this->sent(self::SELLER_A));
        $this->travel(2)->hours();
        $this->figure(20);

        $listing->refresh()->forceFill(['remote_quantity' => 15, 'last_seen_at' => now()])->save();
        $result = app(StockPricePublisher::class)->publishStock($this->first);
        $this->assertSame(['unchanged' => 1], $result['stats']['skipped'], 'the channel sold 5 and shows 15: our 20 is not written again');
        $this->assertSame([20], $this->sent(self::SELLER_A));

        $this->figure(30);
        $this->completeOrderSweep($this->first);
        $this->publish($this->first);
        $this->assertSame([20, 30], $this->sent(self::SELLER_A), 'stock has arrived: the pool has more than when we wrote');
    }

    public function test_a_read_back_older_than_our_write_changes_nothing_about_what_is_sent(): void
    {
        $this->figure(20);
        $listing = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->publish($this->first);
        $this->travel(2)->hours();
        $this->figure(20);
        $listing->refresh()->forceFill(['remote_quantity' => 7, 'last_seen_at' => now()->subHours(3)])->save();

        $this->publish($this->first);

        $this->assertSame([20, 20], $this->sent(self::SELLER_A), 'a channel read before our write says nothing about what it shows now: the existing rule sends again');
    }

    // ---- a division another process wrote meanwhile ----------------------------------------------------------------------------------------------

    public function test_a_plan_made_on_a_division_that_has_since_moved_is_planned_again_before_it_is_sent(): void
    {
        $this->figure(20);
        $this->listings();
        $this->publish($this->first, $this->second);
        $this->travel(2)->hours();
        $this->figure(20);
        $this->app->bind(PoolAllocationService::class, RacingAllocations::class);
        RacingAllocations::fireBeforeDivision(2, function (): void {
            app(ReservationService::class)->reserve('channel-order:9:9', $this->torodLocation()->id, self::PRODUCT, '', 6, accountId: $this->second->id);
        });
        $this->storeOrder(1, self::PRODUCT, 2);

        app(StockPricePublisher::class)->publishStock($this->first);

        $this->assertSame([10, 6], $this->sent(self::SELLER_A), '(20 - 2) / 2 = 9 was planned; 6 more units were promised before it was sent: (20 - 2 - 6) / 2 = 6 is what goes out');
    }

    public function test_a_long_run_is_checked_against_the_pools_again_before_every_chunk_that_is_sent(): void
    {
        foreach (range(1, 60) as $number) {
            $this->storeProduct(100 + $number);
            $this->providerFigure(100 + $number, 10, now()->subMinutes(30));
            $this->stockListing($this->first, 100 + $number, $this->torodLocation(), sprintf('B-%02d', $number));
        }
        $this->app->bind(PoolAllocationService::class, RacingAllocations::class);
        RacingAllocations::fireBeforeDivision(111, fn (PoolKey $key) => $this->storeOrder(9000, $key->productId, 4));

        app(StockPricePublisher::class)->publishStock($this->first);

        $calls = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))->map(fn (array $pair): array => $pair[0]->data()['items'])->values()->all();
        $this->assertSame([50, 10], array_map('count', $calls), 'two calls: the first 50 quantities, then the rest');
        $this->assertSame([10], array_values(array_unique(array_column($calls[0], 'quantity'))), 'the first chunk went out before anything changed');
        $second = array_column($calls[1], 'quantity', 'barcode');
        $this->assertSame(6, $second['B-51'], 'a store order arrived for the first listing of the second chunk: it is planned again from the pool as it is, not sent as planned');
        $this->assertSame([10], array_values(array_unique(array_diff_key($second, ['B-51' => 0]))));
    }

    public function test_a_store_order_placed_while_a_run_is_sending_lowers_what_its_later_chunks_send(): void
    {
        foreach (range(101, 151) as $product) {
            $this->storeProduct($product);
            $this->providerFigure($product, 10, now()->subMinutes(30));
            $this->stockListing($this->first, $product, $this->torodLocation(), 'BAR-' . $product);
        }
        $placed = false;
        Http::fake(['apigw.trendyol.com/*' => function () use (&$placed) {
            if (!$placed) {
                $placed = true;
                $this->storeOrder(900, 151, 8);
            }

            return Http::response(['batchRequestId' => 'batch-' . (int) $placed]);
        }]);

        $this->publish($this->first);

        $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))->flatMap(fn (array $pair): array => $pair[0]->data()['items'])->keyBy('barcode');
        $this->assertTrue($placed, 'a customer bought 8 units of the last product while the first 50 were being sent');
        $this->assertSame(10, $sent['BAR-101']['quantity'], 'the first chunk was already on its way');
        $this->assertSame(2, $sent['BAR-151']['quantity'], 'the last product is in the second chunk: it is sent as the 2 units that were left, not as the 10 planned at the start of the run');
    }

    public function test_a_division_that_would_give_away_more_than_the_net_is_a_defect_and_is_never_stored_or_sent(): void
    {
        $this->figure(20);
        $listing = $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A');
        $this->app->bind(StockAllocator::class, OverAllocator::class);

        $result = app(StockPricePublisher::class)->publishStock($this->first);

        $this->assertSame('failed', $result['result'], 'the run fails loudly instead of sending a division that is wrong');
        $this->assertStringContainsString('gives away 21 units of a net of 20', (string) $result['detail']);
        $this->assertSame(0, $this->pool()->version, 'rolled back');
        $this->assertNull($listing->refresh()->allocation);
        $this->assertSame([], $this->sent(self::SELLER_A));
    }

    // ---- switched off, and old behaviour ---------------------------------------------------------------------------------------------------------

    public function test_with_the_division_switched_off_every_listing_is_offered_the_whole_net_and_no_pool_is_touched(): void
    {
        config(['sales_channels.stock.allocation.enabled' => false]);
        $this->figure(20);
        $this->storeOrder(1, self::PRODUCT, 3);
        $this->listings();

        $this->publish($this->first, $this->second);
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 2);

        $this->assertSame([17], $this->sent(self::SELLER_A));
        $this->assertSame([17], $this->sent(self::SELLER_B));
        $this->assertSame(0, InventoryPool::count(), 'the rollback switch leaves the pool table alone');
        $this->assertSame(1, InventoryReservation::count(), 'the order still holds its units');
    }

    public function test_a_listing_with_no_policy_and_no_one_to_share_with_is_offered_what_it_always_was(): void
    {
        $this->figure(20);
        $this->stockListing($this->first, self::PRODUCT, $this->torodLocation(), 'BAR-A', ['safety_buffer' => 2, 'max_publish_quantity' => 30]);
        $this->ingestOrder($this->first, 'ORD-1', 'BAR-A', 3);

        $this->publish($this->first);

        $this->assertSame([15], $this->sent(self::SELLER_A), '20 less 3 held by its order, less its buffer of 2');
    }

    // ---- the margin for what a channel sells before we hear of it ----------------------------------------------------------------------------------

    public function test_a_channel_that_has_been_selling_is_shown_a_little_less_when_the_margin_is_switched_on(): void
    {
        config(['sales_channels.stock.allocation.lag_window_minutes' => 20]);
        $this->figure(40);
        $this->listings();
        SalesChannelSyncState::create(['account_id' => $this->first->id, 'resource' => 'orders', 'last_success_at' => now()->subMinute()]);
        // Sold at the first channel and already in the figure (committed before it was taken), plus a released one and an old one: only the first two count as recent sales.
        foreach ([['2:1', 6, 'committed', 40], ['2:2', 6, 'committed', 50], ['1:1', 12, 'released', 30], ['3:1', 4, 'committed', 500]] as [$key, $quantity, $state, $minutesAgo]) {
            InventoryReservation::create([
                'reservation_key' => 'channel-order:' . $key, 'location_id' => $this->torodLocation()->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'quantity' => $quantity, 'state' => $state,
                'committed_at' => now()->subMinutes(35), 'sales_channel' => 'trendyol', 'account_id' => $this->first->id, 'reserved_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        $this->publish($this->first, $this->second);

        $this->assertSame([18], $this->sent(self::SELLER_A), '20 each; 12 units sold in the last two hours, scaled to a window of 20 minutes: 2 held back');
        $this->assertSame([20], $this->sent(self::SELLER_B), 'the other channel has sold nothing recently');
        $this->assertSame(2, SalesChannelProductMapping::where('listing_key', 'BAR-A')->firstOrFail()->allocation['lag']);
    }

    public function test_the_margin_is_nothing_at_all_by_default(): void
    {
        $this->figure(40);
        $this->listings();
        InventoryReservation::create([
            'reservation_key' => 'channel-order:2:1', 'location_id' => $this->torodLocation()->id, 'product_id' => self::PRODUCT, 'variant_key' => '', 'quantity' => 6, 'state' => 'committed',
            'committed_at' => now()->subMinutes(35), 'sales_channel' => 'trendyol', 'account_id' => $this->first->id, 'reserved_at' => now()->subMinutes(40),
        ]);

        $this->publish($this->first);

        $this->assertSame([20], $this->sent(self::SELLER_A));
    }
}

/** A pool writer that lets something else happen just before one of its divisions is worked out, as a customer or an import does while a run is sending. */
class RacingAllocations extends PoolAllocationService
{
    private static int $divisions = 0;

    private static ?int $fireAt = null;

    /** @var (callable(PoolKey): void)|null */
    private static $beforeTheDivision;

    /** @param callable(PoolKey): void $hook runs once, just before the given division is worked out (1 is the first), with the pool it is worked out for */
    public static function fireBeforeDivision(int $number, callable $hook): void
    {
        self::$divisions = 0;
        self::$fireAt = $number;
        self::$beforeTheDivision = $hook;
    }

    public static function reset(): void
    {
        self::$beforeTheDivision = null;
    }

    public function allocate(PoolKey $key): PoolAllocation
    {
        if (self::$beforeTheDivision !== null && ++self::$divisions === self::$fireAt) {
            $hook = self::$beforeTheDivision;
            self::$beforeTheDivision = null;
            $hook($key);
        }

        return parent::allocate($key);
    }
}

/** An allocator with a defect: it gives away one unit more than the net. */
class OverAllocator extends StockAllocator
{
    public function allocate(int $net, StorefrontReserve $reserve, array $members): AllocationResult
    {
        return new AllocationResult($net, 0, array_combine(array_map(static fn ($member): int => $member->listingId, $members), array_map(static fn ($member): MemberShare => new MemberShare($member->listingId, 'equal', $net + 1, 0, 0), $members)));
    }
}
