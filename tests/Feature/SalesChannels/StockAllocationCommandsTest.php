<?php

namespace Tests\Feature\SalesChannels;

use App\Models\InventoryPool;
use App\Models\InventoryReservation;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Support\ChannelOperations;
use App\Services\SalesChannels\Sync\SyncStateStore;
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

/** The commands around the division of a pool: the quick run after an order import, and the pruning of old released reservations. */
class StockAllocationCommandsTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 50;

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
        $this->storeProduct(self::PRODUCT);
        $this->providerFigure(self::PRODUCT, 20, now()->subMinutes(30));
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
    }

    /** @return list<int> */
    private function sentQuantities(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'price-and-inventory'))->map(fn (array $pair): int => $pair[0]->data()['items'][0]['quantity'])->values()->all();
    }

    public function test_the_allocate_step_lowers_what_an_order_lowered_and_says_what_it_did(): void
    {
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->artisan('channels:publish', ['--only' => 'stock'])->assertSuccessful();
        $this->ingestOrder($account, 'ORD-1', 'BAR-1', 4);
        $this->travel(2)->seconds();

        $this->artisan('channels:publish', ['--only' => 'allocate'])->expectsOutputToContain('allocate: {"pools":1')->assertSuccessful();

        $this->assertSame([20, 16], $this->sentQuantities(), 'a drop of 4 from 20 is a fifth: sent at once');
        $this->assertSame([], app(DirtyPools::class)->pending());
    }

    public function test_the_allocate_step_does_nothing_when_nothing_changed_or_the_channels_are_switched_off(): void
    {
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        $this->artisan('channels:publish', ['--only' => 'allocate'])->expectsOutputToContain('allocate: {"pools":0,"accounts":[]}')->assertSuccessful();
        $this->assertSame([], $this->sentQuantities());

        config(['sales_channels.enabled' => false]);
        app(DirtyPools::class)->mark([new PoolKey($this->torodLocation()->id, self::PRODUCT)]);
        $this->artisan('channels:publish', ['--only' => 'allocate'])->expectsOutputToContain('switched off')->assertSuccessful();
        $this->assertSame([], $this->sentQuantities());
    }

    public function test_a_pool_nobody_offers_is_worked_out_again_and_cleaned_without_a_call(): void
    {
        $this->trendyolAccount();
        app(DirtyPools::class)->mark([new PoolKey($this->torodLocation()->id, self::PRODUCT)]);
        $this->travel(2)->seconds();

        $this->artisan('channels:publish', ['--only' => 'allocate'])->assertSuccessful();

        $this->assertNull(InventoryPool::firstOrFail()->dirty_at);
        $this->assertSame([], $this->sentQuantities());
    }

    public function test_an_account_whose_stock_run_is_busy_leaves_its_pools_marked_for_the_next_run(): void
    {
        $account = $this->trendyolAccount();
        $this->stockListing($account, self::PRODUCT, $this->torodLocation(), 'BAR-1');
        app(DirtyPools::class)->mark([new PoolKey($this->torodLocation()->id, self::PRODUCT)]);
        $this->travel(2)->seconds();
        $this->assertNotNull(app(SyncStateStore::class)->acquire($account->id, 'publish_stock'), 'the regular run holds the lease');

        $this->artisan('channels:publish', ['--only' => 'allocate'])->expectsOutputToContain('busy')->assertSuccessful();

        $this->assertNotNull(InventoryPool::firstOrFail()->dirty_at, 'nothing was worked out: the quick run never overlaps the regular one');
        $this->assertSame([], $this->sentQuantities());
    }

    public function test_only_old_released_reservations_are_pruned(): void
    {
        $row = fn (string $key, string $state, ?string $releasedAt): array => [
            'reservation_key' => $key, 'location_id' => 1, 'product_id' => self::PRODUCT, 'variant_key' => '', 'quantity' => 1, 'state' => $state, 'reserved_at' => now()->subDays(90),
            'released_at' => $releasedAt, 'created_at' => now(), 'updated_at' => now(),
        ];
        InventoryReservation::insert([
            $row('old-released', 'released', now()->subDays(45)->toDateTimeString()),
            $row('recent-released', 'released', now()->subDays(3)->toDateTimeString()),
            $row('old-reserved', 'reserved', null),
            $row('old-committed', 'committed', null),
        ]);

        $this->artisan('channels:prune')->expectsOutputToContain('Deleted 1 released stock reservation(s) older than 30 days')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['recent-released', 'old-reserved', 'old-committed'], InventoryReservation::pluck('reservation_key')->all());
    }
}
