<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Jobs\SalesChannels\DrainStockEvents;
use App\Models\ChannelStockEvent;
use App\Models\InventoryLocation;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\Outbox\StockEventDrain;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Support\ChannelOperations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\BuildsRemoteOrders;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\BuildsStockPools;
use Tests\Support\CreatesChannelAccounts;
use Tests\TestCase;

/**
 * The outbox: no change that could change what a channel should be shown may be lost, and none may wait a quarter of an hour.
 *
 * What these tests are really pinning is the gap that existed before. A change used to mark its pools in a statement of its OWN, after the change had been written — so a
 * process that died between the two left a unit held and the quantity still on offer, and a host with the division switched off never marked anything at all. Now the change
 * and the record of it are one transaction: either both are there or neither is.
 *
 * Nothing here reaches a channel (stray requests are refused), and nothing here is allowed to decide whether a quantity may be sent: that stays with the publisher and the
 * transport, and a test below pins that a listing the channel owns the stock of is still skipped.
 */
class StockOutboxTest extends TestCase
{
    use BuildsRemoteOrders;
    use BuildsSalesChannelSchema;
    use BuildsStockPools;
    use CreatesChannelAccounts;

    private const PRODUCT = 60;

    private SalesChannelAccount $account;

    private mixed $realQueue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        config(['sales_channels.enabled' => true, 'sales_channels.external_writes_enabled' => true]);
        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
        ChannelOperations::flush();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00 UTC'));
        // The test queue runs a job the moment it is pushed, which would drain the outbox inside the very call that recorded it. These tests are about what is RECORDED and
        // what a drain then does, so the nudges are collected instead of run; the job's own wiring is proved by handling it directly below, and the one test about a host
        // whose queue really is `sync` puts the real one back.
        $this->realQueue = $this->app->make('queue');
        Queue::fake();
        StockEventOutbox::$runId = '';
        $this->account = $this->trendyolAccount();
        $this->storeProduct(self::PRODUCT);
        $this->providerFigure(self::PRODUCT, 20, now()->subHour());
    }

    private function outbox(): StockEventOutbox
    {
        return app(StockEventOutbox::class);
    }

    /** Amazon's own shelf: its stock is Amazon's, and it must never be mixed into the shared pool. */
    private function amazonFbaLocation(SalesChannelAccount $amazon): InventoryLocation
    {
        return InventoryLocation::create([
            'name' => 'Amazon FBA', 'code' => 'amazon-fba-test', 'type' => InventoryLocation::TYPE_AMAZON_FBA, 'source_of_truth' => InventoryLocation::TRUTH_CHANNEL,
            'sales_channel_account_id' => $amazon->id, 'is_active' => true, 'storefront_sellable' => false, 'publishable_channels' => [],
        ]);
    }

    private function storefrontStock(int $productId = self::PRODUCT): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('current_stock');
    }

    /** The figures the warehouse reports, applied the way the scheduled `fulfillment:sync --only=inventory` applies them. */
    private function providerSync(int $units): void
    {
        app(InventorySyncService::class)->applyToAssignedProducts(ProviderCode::TorodPoint, ['ext-' . self::PRODUCT => $units], now());
    }

    /** The listing this product is offered by, made once however many orders a test ingests. */
    private function listing(): SalesChannelProductMapping
    {
        return SalesChannelProductMapping::firstWhere('listing_key', 'LK-' . self::PRODUCT)
            ?? $this->stockListing($this->account, self::PRODUCT, $this->torodLocation(), 'LK-' . self::PRODUCT);
    }

    /** A channel order of `$quantity` units. Ingesting it holds its units, and that is what records the event. */
    private function channelOrderFor(int $quantity, string $orderId = 'TY-1', ChannelOrderStatus $status = ChannelOrderStatus::New): ExternalOrderMapping
    {
        $this->listing();

        return $this->ingestOrder($this->account, $orderId, 'LK-' . self::PRODUCT, $quantity, status: $status);
    }

    // ---- the guarantee: the change and the record are one transaction -------------------------------------

    public function test_a_channel_order_records_the_pools_it_changed_in_the_same_transaction_as_the_hold(): void
    {
        $this->channelOrderFor(3);

        $events = ChannelStockEvent::orderBy('id')->get();
        $this->assertCount(1, $events, 'one pool changed, so one event');
        $this->assertSame(ChannelStockEvent::ORDER_IMPORTED, $events[0]->reason, 'an order we had never seen is an import, not a change');
        $this->assertSame(self::PRODUCT, (int) $events[0]->product_id);
        $this->assertSame((int) $this->torodLocation()->id, (int) $events[0]->location_id);
        $this->assertSame(ChannelStockEvent::PENDING, $events[0]->state);
        $this->assertTrue($events[0]->rewrites_storefront_stock, 'a channel taking units is also the storefront\'s own number changing');
        $this->assertSame('external_order_mapping', $events[0]->subject_type);
    }

    public function test_the_event_carries_no_figure_so_a_row_worked_late_sends_a_fresh_quantity_late_not_a_stale_one(): void
    {
        $this->channelOrderFor(3);

        $columns = array_keys(ChannelStockEvent::firstOrFail()->getAttributes());

        foreach (['quantity', 'units', 'available', 'figure', 'stock'] as $forbidden) {
            $this->assertSame([], array_values(array_filter($columns, static fn (string $column): bool => $column === $forbidden)), "the outbox stores a {$forbidden}: a row worked late would then be a stale quantity");
        }
    }

    public function test_a_rolled_back_change_leaves_neither_the_hold_nor_the_event(): void
    {
        $mapping = $this->channelOrderFor(3, 'TY-ROLLBACK');
        // Back to the state before the order was heard of, so that what the transaction writes is all there is to see.
        DB::table('inventory_reservations')->delete();
        ChannelStockEvent::query()->delete();

        try {
            DB::transaction(function () use ($mapping): void {
                app(ChannelOrderReservations::class)->sync($mapping, ChannelStockEvent::ORDER_IMPORTED);
                throw new RuntimeException('the caller failed after the hold was written');
            });
        } catch (RuntimeException) {
            // what the test is about
        }

        $this->assertSame(0, ChannelStockEvent::count(), 'the event outlived the change it describes');
        $this->assertSame(0, DB::table('inventory_reservations')->where('state', 'reserved')->count(), 'the hold outlived the event');
    }

    public function test_the_change_is_recorded_even_with_the_division_switched_off_which_is_the_gap_that_was_permanent(): void
    {
        // With the division off, DirtyPools::mark does nothing at all: before the outbox, a host set up this way never heard about a change until the next full sweep.
        config(['sales_channels.stock.allocation.enabled' => false]);

        $this->channelOrderFor(3);

        $this->assertSame(0, DB::table('inventory_pools')->whereNotNull('dirty_at')->count(), 'the mark is off, as it was');
        $this->assertSame(1, ChannelStockEvent::count(), 'and the change is still recorded');
    }

    public function test_a_change_that_changed_nothing_records_nothing(): void
    {
        $this->channelOrderFor(3);
        ChannelStockEvent::query()->delete();

        // Bringing the very same order into line again changes no hold.
        app(ChannelOrderReservations::class)->sync(ExternalOrderMapping::firstOrFail());

        $this->assertSame(0, ChannelStockEvent::count());
    }

    public function test_both_pools_are_recorded_when_a_change_affects_two(): void
    {
        $torod = $this->torodLocation();
        $own = $this->ownLocation();

        DB::transaction(fn (): int => $this->outbox()->record(
            [new PoolKey((int) $torod->id, self::PRODUCT), new PoolKey((int) $own->id, self::PRODUCT)],
            ChannelStockEvent::LISTING_SETTINGS,
        ));

        $this->assertEqualsCanonicalizing(
            [(int) $torod->id, (int) $own->id],
            ChannelStockEvent::pluck('location_id')->map(static fn ($id): int => (int) $id)->all(),
            'a change that moves a listing from one shelf to another has to work out the pool it left as well as the one it joined',
        );
    }

    public function test_a_cancelled_order_gives_its_units_back_and_that_is_recorded_too(): void
    {
        $this->channelOrderFor(3);
        ChannelStockEvent::query()->delete();

        $this->channelOrderFor(3, 'TY-1', ChannelOrderStatus::Cancelled);

        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::ORDER_CANCELLED, $event->reason);
        $this->assertTrue($event->rewrites_storefront_stock, 'units coming back are the storefront\'s number changing too');
    }

    // ---- claiming, retrying, dying -------------------------------------------------------------------------

    public function test_two_runs_never_take_the_same_event(): void
    {
        $this->channelOrderFor(3);

        $first = $this->outbox()->claim();
        StockEventOutbox::$runId = '';
        $second = $this->outbox()->claim();

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'a second run took an event the first holds');
    }

    public function test_an_event_a_dead_run_still_holds_is_taken_back_and_worked_rather_than_lost(): void
    {
        $this->channelOrderFor(3);
        $this->outbox()->claim();
        $this->assertSame(ChannelStockEvent::CLAIMED, ChannelStockEvent::firstOrFail()->state);

        $this->travelTo(now()->addMinutes(11));
        $reclaimed = $this->outbox()->reclaimAbandoned();

        $this->assertSame(1, $reclaimed);
        $this->assertSame(ChannelStockEvent::PENDING, ChannelStockEvent::firstOrFail()->state);
        $this->assertNull(ChannelStockEvent::firstOrFail()->claimed_by);
    }

    public function test_a_failed_batch_waits_and_is_tried_again_and_is_never_called_done(): void
    {
        config(['sales_channels.stock.outbox.attempts' => 3, 'sales_channels.stock.outbox.retry_seconds' => 30]);
        $this->channelOrderFor(3);
        $id = (int) ChannelStockEvent::firstOrFail()->id;

        $this->outbox()->retryLater([$id], 'stock_event_batch_failed');

        $event = ChannelStockEvent::findOrFail($id);
        $this->assertSame(ChannelStockEvent::PENDING, $event->state);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('stock_event_batch_failed', $event->failure_code);
        $this->assertTrue($event->available_at->gt(now()), 'a failed event was made due at once, so it would be retried in a tight loop');
        $this->assertSame([], $this->outbox()->claim(), 'an event that is waiting its turn was claimed anyway');
    }

    public function test_an_event_nobody_can_work_is_kept_as_dead_for_a_person_not_quietly_dropped(): void
    {
        config(['sales_channels.stock.outbox.attempts' => 2]);
        $this->channelOrderFor(3);
        $id = (int) ChannelStockEvent::firstOrFail()->id;

        $this->outbox()->retryLater([$id], 'stock_event_batch_failed');
        $this->outbox()->retryLater([$id], 'stock_event_batch_failed');

        $this->assertSame(ChannelStockEvent::DEAD, ChannelStockEvent::findOrFail($id)->state);
        $this->assertSame(1, $this->outbox()->lag()['dead'], 'a dead event is counted, so it can be put on a screen');
    }

    public function test_pruning_deletes_worked_events_and_keeps_the_dead_ones(): void
    {
        config(['sales_channels.stock.outbox.done_event_days' => 7]);
        $pool = new PoolKey((int) $this->torodLocation()->id, self::PRODUCT);
        DB::transaction(fn () => $this->outbox()->record([$pool], ChannelStockEvent::MANUAL));
        $done = ChannelStockEvent::firstOrFail();
        $this->outbox()->finish([(int) $done->id]);
        ChannelStockEvent::whereKey($done->id)->update(['worked_at' => now()->subDays(30)]);
        DB::transaction(fn () => $this->outbox()->record([$pool], ChannelStockEvent::MANUAL));
        $dead = ChannelStockEvent::where('state', ChannelStockEvent::PENDING)->firstOrFail();
        ChannelStockEvent::whereKey($dead->id)->update(['state' => ChannelStockEvent::DEAD, 'updated_at' => now()->subDays(30)]);

        $this->artisan('channels:prune')->assertSuccessful();

        $this->assertNull(ChannelStockEvent::find($done->id), 'a worked event was kept for ever');
        $this->assertNotNull(ChannelStockEvent::find($dead->id), 'a dead event was deleted: a quantity nobody corrected would vanish with it');
    }

    // ---- the drain -----------------------------------------------------------------------------------------

    public function test_the_storefronts_own_stock_drops_in_the_same_pass_without_asking_the_provider(): void
    {
        $this->providerSync(20);
        $this->assertSame(20, $this->storefrontStock(), 'the shelf holds twenty and nothing is promised yet');

        $this->channelOrderFor(3);
        app(StockEventDrain::class)->drain();

        $this->assertSame(17, $this->storefrontStock(), 'three units a channel sold were still on offer in our own shop');
        Http::assertNothingSent();
    }

    public function test_the_drain_works_the_events_and_marks_them_done(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['claimed']);
        $this->assertSame(1, $worked['pools']);
        $this->assertSame(1, $worked['products']);
        $this->assertSame(0, $worked['failed']);
        $this->assertSame(ChannelStockEvent::DONE, ChannelStockEvent::firstOrFail()->state);
        $this->assertSame(0, $this->outbox()->lag()['pending']);
    }

    public function test_many_events_for_one_pool_are_worked_once(): void
    {
        $this->providerSync(20);
        $pool = new PoolKey((int) $this->torodLocation()->id, self::PRODUCT);
        DB::transaction(function () use ($pool): void {
            $this->outbox()->record([$pool], ChannelStockEvent::ORDER_IMPORTED);
            $this->outbox()->record([$pool], ChannelStockEvent::ORDER_CHANGED);
            $this->outbox()->record([$pool], ChannelStockEvent::UNITS_RETURNED);
        });

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(3, $worked['claimed']);
        $this->assertSame(1, $worked['pools'], 'one pool was worked out three times');
        $this->assertSame(3, ChannelStockEvent::where('state', ChannelStockEvent::DONE)->count());
    }

    public function test_a_batch_that_threw_goes_back_and_nothing_is_marked_done(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);
        // Something below the drain fails: the channel refusing everything, a lock it could not take, a bug. The events must survive it.
        $this->app->bind(DirtyPoolRun::class, fn () => new class extends DirtyPoolRun {
            public function __construct()
            {
            }

            public function run(?SalesChannelAccount $only = null): array
            {
                throw new RuntimeException('the publisher fell over');
            }
        });

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['failed']);
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $event->state, 'an event whose batch threw was marked done');
        $this->assertSame(1, $event->attempts);
        $this->assertSame('stock_event_batch_failed', $event->failure_code);
    }

    public function test_the_drain_sends_nothing_for_a_listing_whose_stock_the_channel_itself_owns(): void
    {
        // An Amazon FBA listing: Amazon holds that stock, and it is never part of the shared pool. The drain hands pools to the publisher, so the publisher's refusal stands.
        $amazon = $this->amazonAccount();
        $fba = $this->amazonFbaLocation($amazon);
        $this->stockListing($amazon, self::PRODUCT, $fba, 'FBA-1', ['fulfillment_mode' => 'fba']);
        DB::transaction(fn () => $this->outbox()->record([new PoolKey((int) $fba->id, self::PRODUCT)], ChannelStockEvent::PROVIDER_FIGURE));

        app(StockEventDrain::class)->drain();

        Http::assertNothingSent();
        $this->assertSame(ChannelStockEvent::DONE, ChannelStockEvent::firstOrFail()->state, 'the gate said no, which is an answer, not a failure to retry for ever');
    }

    public function test_with_the_outbox_switched_off_nothing_is_recorded_and_the_scheduled_runs_do_the_work(): void
    {
        config(['sales_channels.stock.outbox.enabled' => false]);

        $this->channelOrderFor(3);

        $this->assertSame(0, ChannelStockEvent::count());
        $this->assertSame(1, DB::table('inventory_pools')->whereNotNull('dirty_at')->count(), 'the mark the scheduled run reads is still made');
        $this->assertSame(['claimed' => 0, 'pools' => 0, 'products' => 0, 'storefront' => ['applied' => 0, 'refused' => 0], 'sent' => [], 'failed' => 0, 'reclaimed' => 0], app(StockEventDrain::class)->drain());
    }

    // ---- the nudge -----------------------------------------------------------------------------------------

    public function test_a_recorded_change_asks_for_the_worker_at_once(): void
    {
        $this->channelOrderFor(3);

        Queue::assertPushed(DrainStockEvents::class, 1);
    }

    public function test_many_changes_in_a_moment_do_not_ask_many_times(): void
    {
        config(['sales_channels.stock.outbox.nudge_seconds' => 30]);

        $this->channelOrderFor(3, 'TY-A');
        $this->channelOrderFor(2, 'TY-B');
        $this->channelOrderFor(1, 'TY-C');

        // A drain takes everything that is due, so one job covers all three.
        Queue::assertPushed(DrainStockEvents::class, 1);
        $this->assertSame(3, $this->outbox()->lag()['pending'], 'and all three changes are still recorded');
    }

    public function test_nothing_is_nudged_where_the_job_would_run_inside_the_request_itself(): void
    {
        // A host whose queue is `sync` has no worker: a dispatched job runs there and then. A channel order arriving by webhook would otherwise push quantities to a
        // marketplace inside that webhook's own request.
        Queue::swap($this->realQueue);
        config(['queue.default' => 'sync']);

        $this->channelOrderFor(3);

        $this->assertSame(1, $this->outbox()->lag()['pending'], 'the change is recorded all the same; the minute schedule works it');
        $this->assertSame(0, DB::table('products')->where('id', self::PRODUCT)->where('current_stock', 17)->count(), 'the drain ran inside the request that recorded the change');
    }

    public function test_with_the_nudge_off_no_job_is_asked_for_and_the_events_still_wait_in_the_table(): void
    {
        config(['sales_channels.stock.outbox.nudge_enabled' => false]);

        $this->channelOrderFor(3);

        Queue::assertNotPushed(DrainStockEvents::class);
        $this->assertSame(1, $this->outbox()->lag()['pending'], 'the table is the guarantee; the job is only the speed');
    }

    public function test_the_job_works_the_outbox_when_a_worker_runs_it(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);

        app()->call([new DrainStockEvents(), 'handle']);

        $this->assertSame(ChannelStockEvent::DONE, ChannelStockEvent::firstOrFail()->state);
        $this->assertSame(17, $this->storefrontStock());
    }

    // ---- a provider's figure moving --------------------------------------------------------------------------

    public function test_a_provider_figure_that_moved_is_recorded_for_that_providers_shelves_only(): void
    {
        $torod = $this->torodLocation();
        $fba = $this->amazonFbaLocation($this->amazonAccount());

        $this->providerSync(14);

        $locations = ChannelStockEvent::where('reason', ChannelStockEvent::PROVIDER_FIGURE)->pluck('location_id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertContains((int) $torod->id, $locations);
        $this->assertNotContains((int) $fba->id, $locations, 'the channel\'s own shelf was mixed into the shared pool');
    }

    public function test_a_figure_that_came_back_the_same_is_not_recorded(): void
    {
        $this->providerSync(20);
        ChannelStockEvent::query()->delete();

        $this->providerSync(20);

        $this->assertSame(0, ChannelStockEvent::count(), 'nothing changed, so there was nothing to tell anybody');
    }

    public function test_the_storefront_refresher_asks_no_provider_and_keeps_the_figures_own_read_time(): void
    {
        $this->providerFigure(self::PRODUCT, 20, now()->subHours(2));
        $this->providerSync(20);
        $this->channelOrderFor(4);

        $stats = app(StorefrontStockRefresher::class)->refresh(self::PRODUCT);

        $this->assertSame(1, $stats['applied']);
        $this->assertSame(0, $stats['refused']);
        $this->assertSame(16, $this->storefrontStock());
        Http::assertNothingSent();
    }

    public function test_a_product_the_store_fulfils_itself_has_no_provider_figure_to_re_apply(): void
    {
        $this->storeProduct(61, provider: null, stock: 7);

        $stats = app(StorefrontStockRefresher::class)->refresh(61);

        $this->assertSame(['applied' => 0, 'refused' => 0], $stats);
        $this->assertSame(7, $this->storefrontStock(61), 'a product nobody else counts had its stock rewritten');
    }

    // ---- the command ---------------------------------------------------------------------------------------

    public function test_the_command_says_what_is_waiting_and_works_it(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);

        $this->artisan('channels:stock-events --status')->expectsOutputToContain('"pending":1')->assertSuccessful();
        $this->assertSame(ChannelStockEvent::PENDING, ChannelStockEvent::firstOrFail()->state, '--status worked an event');

        $this->artisan('channels:stock-events')->assertSuccessful();

        $this->assertSame(ChannelStockEvent::DONE, ChannelStockEvent::firstOrFail()->state);
        $this->assertSame(17, $this->storefrontStock());
    }

    public function test_the_command_can_put_the_dead_events_back_once_somebody_has_dealt_with_them(): void
    {
        config(['sales_channels.stock.outbox.attempts' => 1]);
        $this->channelOrderFor(3);
        $this->outbox()->retryLater([(int) ChannelStockEvent::firstOrFail()->id], 'stock_event_batch_failed');
        $this->assertSame(ChannelStockEvent::DEAD, ChannelStockEvent::firstOrFail()->state);

        $this->artisan('channels:stock-events --retry-dead --status')->assertSuccessful();

        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $event->state);
        $this->assertSame(0, $event->attempts);
        $this->assertNull($event->available_at);
    }

    public function test_the_command_does_nothing_at_all_while_the_channels_are_switched_off(): void
    {
        config(['sales_channels.enabled' => false]);
        $this->channelOrderFor(3);

        $this->artisan('channels:stock-events')->expectsOutputToContain('switched off')->assertSuccessful();

        Http::assertNothingSent();
    }
}
