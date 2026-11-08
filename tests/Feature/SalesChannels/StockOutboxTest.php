<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\ProviderCode;
use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Jobs\SalesChannels\DrainStockEvents;
use App\Models\ChannelStockEvent;
use App\Models\InventoryLocation;
use App\Models\ProviderProductMapping;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Http\Controllers\Admin\SalesChannels\Support\StockAttention;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\StorefrontStockRefresher;
use App\Services\SalesChannels\Inventory\ChannelOrderReservations;
use App\Services\SalesChannels\Inventory\Outbox\StockEventDrain;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\DirtyPoolRun;
use App\Services\SalesChannels\Publishing\StockPricePublisher;
use App\Services\SalesChannels\Sync\SyncStateStore;
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
        // The row keeps the storefront job whatever the fast path did, so a process that died between the commit and that write loses nothing: the worker re-verifies.
        $this->assertTrue($events[0]->rewrites_storefront_stock);
        $this->assertNull($events[0]->storefront_written_at, 'only a pass that really wrote it stamps this');
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
        $this->assertSame(20, $this->storefrontStock(), 'units coming back are our own number changing too, and before the call returned');
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

    public function test_our_own_shops_number_drops_before_the_call_returns_not_at_the_drain(): void
    {
        $this->providerSync(20);
        $this->assertSame(20, $this->storefrontStock(), 'the shelf holds twenty and nothing is promised yet');

        $this->channelOrderFor(3);

        // No drain has run, no worker, no queue: the number is already right, because it was written straight after the holds were committed, in the same request.
        $this->assertSame(17, $this->storefrontStock(), 'three units a channel sold were still on offer in our own shop until a worker got to it');
        $this->assertSame(1, $this->outbox()->lag()['pending'], 'the channels still have to be told, which is the worker\'s job');
        Http::assertNothingSent();
    }

    public function test_the_number_is_recomputed_not_decremented_so_writing_it_again_takes_nothing_off_twice(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);
        $this->assertSame(17, $this->storefrontStock());

        // Whatever asks, however often: the number is the figure minus the promises, so it cannot drift down.
        app(StorefrontStockRefresher::class)->refresh(self::PRODUCT);
        app(StorefrontStockRefresher::class)->writeFor([self::PRODUCT]);
        app(StockEventDrain::class)->drain();
        app(StockEventDrain::class)->drain();

        $this->assertSame(17, $this->storefrontStock(), 'the same three units were taken off more than once');
    }

    public function test_with_the_inline_write_off_the_worker_does_it_instead_and_the_row_says_so(): void
    {
        config(['sales_channels.stock.outbox.rewrite_storefront_stock_inline' => false]);
        $this->providerSync(20);

        $this->channelOrderFor(3);

        $event = ChannelStockEvent::firstOrFail();
        $this->assertTrue($event->rewrites_storefront_stock, 'nobody was asked to write our own number');
        $this->assertNull($event->storefront_written_at);
        $this->assertSame(20, $this->storefrontStock(), 'the inline write is off, so the number waits');

        app(StockEventDrain::class)->drain();

        $this->assertSame(17, $this->storefrontStock());
        $this->assertNotNull(ChannelStockEvent::firstOrFail()->storefront_written_at);
    }

    public function test_the_drain_works_the_events_and_says_what_the_pass_came_to(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['claimed']);
        $this->assertSame(1, $worked['pools']);
        $this->assertSame(1, $worked['products'], 'the worker re-verifies our own number rather than trusting the fast path');
        $this->assertSame(0, $worked['failed']);
        $this->assertSame(0, $worked['reopened']);
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::WORKED, $event->state);
        $this->assertTrue($event->wasRecomputed(), 'the row was closed without the pool having been divided again');
        $this->assertSame(0, $this->outbox()->lag()['pending']);
    }

    // ---- the four facts, kept apart -----------------------------------------------------------------------

    public function test_a_row_is_never_closed_as_worked_while_the_publisher_settled_nothing(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);
        // The regular run holds the account's stock lease: this pass sent nothing and nothing is settled.
        app(SyncStateStore::class)->acquire((int) $this->account->id, StockPricePublisher::STOCK);

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['reopened'], 'a busy publisher closed the row anyway');
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $event->state);
        $this->assertSame('publisher_busy', $event->failure_code);
        $this->assertSame(1, $event->attempts);
    }

    public function test_a_publisher_that_refused_before_anything_left_this_host_is_recorded_as_not_sent_never_as_a_publish(): void
    {
        // Writing is off on this host: the transport refuses the call before anything leaves, which is a settled answer and not a success.
        config(['sales_channels.external_writes_enabled' => false]);
        $this->providerSync(20);
        $this->channelOrderFor(3);
        // The channel is showing twenty, so seventeen really is a lowering to send: without that the run would have nothing to send and would answer `ok`.
        $this->listing()->forceFill(['last_published_quantity' => 20, 'remote_quantity' => 20, 'last_published_at' => now()->subMinute()])->save();

        $worked = app(StockEventDrain::class)->drain();

        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::WORKED, $event->state, 'a switch the owner turned off is a settled answer, not something to retry for ever');
        $this->assertSame(ChannelStockEvent::NOT_SENT, $event->outcome);
        $this->assertFalse($event->wasSent(), 'a refusal was reported as a publish');
        $this->assertSame(1, $this->outbox()->lag()['not_sent'], 'and it is counted, so nobody reads the queue being empty as everything being published');
        $this->assertSame(ChannelStockEvent::NOT_SENT, $worked['outcome']);
        Http::assertNothingSent();
    }

    public function test_a_pool_no_channel_offers_is_recomputed_and_says_so_rather_than_claiming_a_send(): void
    {
        $this->providerSync(20);
        // A pool with no listing on it at all: there is nothing to tell anybody, and the row must not pretend otherwise.
        $other = $this->storeProduct(62);
        DB::transaction(fn (): int => $this->outbox()->record([new PoolKey((int) $this->torodLocation()->id, $other)], ChannelStockEvent::PROVIDER_FIGURE));

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(ChannelStockEvent::RECOMPUTED_ONLY, $worked['outcome']);
        $event = ChannelStockEvent::where('product_id', $other)->firstOrFail();
        $this->assertSame(ChannelStockEvent::RECOMPUTED_ONLY, $event->outcome);
        $this->assertNull($event->publish_result, 'no publisher was asked, so none is reported');
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
        $this->assertSame(3, ChannelStockEvent::where('state', ChannelStockEvent::WORKED)->count());
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

        $worked = app(StockEventDrain::class)->drain();

        Http::assertNothingSent();
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::WORKED, $event->state, 'the gate said no, which is an answer, not a failure to retry for ever');
        $this->assertFalse($event->wasSent(), 'an FBA pool was reported as published');
        $this->assertNotSame(ChannelStockEvent::SENT, $worked['outcome']);
    }

    public function test_with_the_outbox_switched_off_nothing_is_recorded_and_the_scheduled_runs_do_the_work(): void
    {
        config(['sales_channels.stock.outbox.enabled' => false]);

        $this->channelOrderFor(3);

        $this->assertSame(0, ChannelStockEvent::count());
        $this->assertSame(1, DB::table('inventory_pools')->whereNotNull('dirty_at')->count(), 'the mark the scheduled run reads is still made');
        $drained = app(StockEventDrain::class)->drain();
        $this->assertSame(0, $drained['claimed']);
        $this->assertSame(0, $drained['pools']);
        $this->assertNull($drained['outcome']);
    }

    // ---- what the final review asked: no unit held twice, no old figure pushed out -------------------------

    /**
     * The storefront's number is the figure MINUS the promises, so holding, releasing and re-holding cannot drift it. Nothing here decrements, which is the property that
     * makes a unit impossible to hold twice however many times any of this runs.
     */
    public function test_a_unit_is_never_held_twice_however_many_times_anything_runs(): void
    {
        $this->providerSync(20);

        $this->channelOrderFor(3, 'TY-A');
        $this->channelOrderFor(2, 'TY-B');
        $this->assertSame(15, $this->storefrontStock());

        // Everything that could write it, twice over: the inline path, the worker's path, and the drain itself.
        app(StorefrontStockRefresher::class)->writeFor([self::PRODUCT]);
        app(StorefrontStockRefresher::class)->refresh(self::PRODUCT);
        app(StockEventDrain::class)->drain();
        app(StockEventDrain::class)->drain();
        app(ChannelOrderReservations::class)->sync(ExternalOrderMapping::firstWhere('external_order_id', 'TY-A'));

        $this->assertSame(15, $this->storefrontStock(), 'five units were taken off more than once');
        $this->assertSame(5, (int) DB::table('inventory_reservations')->where('state', 'reserved')->sum('quantity'), 'and the holds themselves doubled');
    }

    /** And a release gives back exactly what was held, not more: the same formula, read again. */
    public function test_a_release_gives_back_exactly_what_was_held(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);
        $this->assertSame(17, $this->storefrontStock());

        $this->channelOrderFor(3, 'TY-1', ChannelOrderStatus::Cancelled);

        $this->assertSame(20, $this->storefrontStock());
        $this->assertSame(0, (int) DB::table('inventory_reservations')->where('state', 'reserved')->sum('quantity'));
    }

    /**
     * An old figure is never pushed to a channel. The drain hands pools to the publisher, and the publisher's own staleness gate decides — so a figure the source has not
     * confirmed within `stale_after_minutes` is not offered, exactly as on a scheduled run.
     */
    public function test_a_figure_too_old_to_trust_is_not_pushed_to_the_channel(): void
    {
        config(['sales_channels.stock.stale_after_minutes' => 60]);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
        $this->providerFigure(self::PRODUCT, 20, now()->subHours(5));
        $this->providerSync(20);
        $this->channelOrderFor(3);
        $this->listing()->forceFill(['last_published_quantity' => 20, 'remote_quantity' => 20, 'last_published_at' => now()->subMinute()])->save();
        // The figure is five hours old, and the re-apply left its read time where it was.
        ProviderProductMapping::where('product_id', self::PRODUCT)->update(['provider_stock_synced_at' => now()->subHours(5)]);

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame([], Http::recorded(static fn ($request): bool => str_contains($request->url(), 'price-and-inventory'))->all(), 'a quantity from a figure nobody has confirmed for five hours went to the channel');
        $this->assertSame(ChannelStockEvent::WORKED, ChannelStockEvent::firstOrFail()->state, 'the gate is an answer, not a failure to retry for ever');
        $this->assertNotSame(null, $worked['outcome']);
    }

    /** A mapping the writer refuses leaves the job on the row instead of closing it over a number nobody updated. */
    public function test_a_refused_storefront_write_is_not_recorded_as_written(): void
    {
        // A product the storefront sells per variant, with a whole-product figure: the writer cannot spread one figure over the variants and refuses it.
        $perVariant = $this->storeProduct(64, stock: 10, variation: json_encode([['type' => 'Red', 'price' => 5, 'qty' => 10]]));
        $this->providerFigure($perVariant, 20, now()->subHour());
        $listing = $this->stockListing($this->account, $perVariant, $this->torodLocation(), 'LK-64');
        $this->ingestOrder($this->account, 'TY-64', 'LK-64', 2);

        $event = ChannelStockEvent::where('product_id', $perVariant)->firstOrFail();
        $this->assertTrue($event->rewrites_storefront_stock, 'a refused write was recorded as done');
        $this->assertNull($event->storefront_written_at);
        $this->assertNotNull($listing->id);
    }

    /**
     * The review's last finding, as a test. A storefront write the writer REFUSES, then a real drain whose publisher settles normally: the refusal must not disappear from
     * follow-up, and must not be recorded as a correction that happened.
     *
     * The refusal here is the permanent kind — a whole-product figure on a product the storefront sells per variant, which no retry can fix: it needs a person to correct the
     * mapping or the product. So it is tried the owner's number of times and then kept in a state a person can see, rather than retried for ever or closed quietly.
     */
    public function test_a_refused_storefront_write_is_neither_closed_as_a_correction_nor_retried_for_ever(): void
    {
        config(['sales_channels.stock.outbox.attempts' => 2]);
        [$product, $event] = $this->givenAStorefrontWriteTheWriterRefuses();
        $this->assertTrue($event->rewrites_storefront_stock, 'the drain has to be the one that tries');
        $stockBefore = $this->storefrontStock($product);

        // A real pass. The publisher settles normally for this pool (no listing offers it), so nothing else could keep the row open.
        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['claimed']);
        $this->assertSame(1, $worked['storefront']['refused'], 'the refusal was not even counted');
        $this->assertSame(0, $worked['storefront']['applied']);

        $event = $event->fresh();
        $this->assertNull($event->storefront_written_at, 'a refused write was recorded as a correction that happened');
        $this->assertTrue($event->rewrites_storefront_stock, 'and the job was cleared off the row');
        $this->assertNotSame(ChannelStockEvent::WORKED, $event->state, 'the row was closed over a number nobody corrected');
        $this->assertSame('storefront_write_refused', $event->failure_code, 'and it does not say what is wrong');
        $this->assertSame($stockBefore, $this->storefrontStock($product), 'the number is indeed still uncorrected');

        // It is tried the owner's number of times and then kept where a person sees it — not retried for ever.
        app(StockEventDrain::class)->drain();
        $this->travelTo(now()->addHours(2));
        app(StockEventDrain::class)->drain();

        $event = $event->fresh();
        $this->assertSame(ChannelStockEvent::DEAD, $event->state);
        $this->assertSame('storefront_write_refused', $event->failure_code);
        $lag = $this->outbox()->lag();
        $this->assertSame(1, $lag['storefront_refused'], 'the administration is not told that a storefront number is wrong');
        $this->assertSame(1, $lag['dead']);
    }

    public function test_the_hub_names_a_storefront_number_nobody_could_correct(): void
    {
        config(['sales_channels.stock.outbox.attempts' => 1]);
        $this->givenAStorefrontWriteTheWriterRefuses();
        app(StockEventDrain::class)->drain();
        $this->assertSame(ChannelStockEvent::DEAD, ChannelStockEvent::firstOrFail()->state);

        $sections = app(StockAttention::class)->sections(SalesChannelAccount::all());
        $titles = array_column($sections, 'title');
        $details = collect($sections)->flatMap(static fn (array $section): array => array_column($section['items'], 'title'))->all();

        $this->assertContains(translate('attention_stock_changes_not_worked'), $titles);
        $this->assertContains(translate('storefront_stock_nobody_could_correct'), $details, 'the hub says nothing about a storefront number that was never corrected');
    }

    /**
     * A change whose storefront write the single writer refuses, with the job left on the row for the drain.
     *
     * The product is sold per variant and its provider mapping is for the whole product: one figure cannot be spread over the variants, so `writeStoreStock` answers
     * UNSUPPORTED. That is the permanent kind of refusal — a person has to correct the mapping or the product.
     *
     * @return array{0: int, 1: ChannelStockEvent}
     */
    private function givenAStorefrontWriteTheWriterRefuses(): array
    {
        $product = $this->storeProduct(65, stock: 10, variation: json_encode([['type' => 'Red', 'price' => 5, 'qty' => 10]]));
        $this->providerFigure($product, 20, now()->subHour());
        $this->ingestOrder($this->account, 'TY-65', $this->stockListing($this->account, $product, $this->torodLocation(), 'LK-65')->listing_key, 2);

        return [$product, ChannelStockEvent::where('product_id', $product)->firstOrFail()];
    }

    // ---- the internal number owes nothing to a queue -------------------------------------------------------

    /**
     * The guarantee the owner asked to have pinned: OUR OWN number needs no worker, no queue and no schedule, while the CHANNELS need all three. Nothing is faked away
     * here — the queue is the real `sync` one, nothing is drained, and no command is run.
     */
    public function test_our_own_number_needs_no_worker_while_the_channels_do(): void
    {
        Queue::swap($this->realQueue);
        config(['queue.default' => 'sync']);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
        $this->providerSync(20);
        $this->listing()->forceFill(['last_published_quantity' => 20, 'remote_quantity' => 20, 'last_published_at' => now()->subMinute()])->save();

        $this->channelOrderFor(3);

        // Our own shop: right already.
        $this->assertSame(17, $this->storefrontStock(), 'our own number waited for a worker');
        // The channels: nothing has gone out, and the row says so.
        $this->assertSame([], Http::recorded(static fn ($request): bool => str_contains($request->url(), 'price-and-inventory'))->all());
        $this->assertSame(1, $this->outbox()->lag()['pending']);

        // And they are told as soon as something works the outbox — a worker, or the minute schedule.
        $this->artisan('channels:stock-events')->assertSuccessful();

        $this->assertSame([17], Http::recorded(static fn ($request): bool => str_contains($request->url(), 'price-and-inventory'))
            ->map(static fn (array $pair): int => (int) $pair[0]->data()['items'][0]['quantity'])->values()->all());
        $this->assertSame(0, $this->outbox()->lag()['pending']);
    }

    // ---- the division switched off ------------------------------------------------------------------------

    /**
     * With the division off, `DirtyPools::mark()` does nothing, so a worker that read the MARKS would find no pools, publish nothing — and close its rows as though the work
     * had been done. The worker hands over its own pools instead, so the work really happens and the row tells the truth either way.
     */
    public function test_with_the_division_off_the_drain_still_does_the_work_and_really_sends(): void
    {
        config(['sales_channels.stock.allocation.enabled' => false]);
        Http::fake(['apigw.trendyol.com/*' => Http::response(['batchRequestId' => 'batch-1'])]);
        $this->providerSync(20);
        $this->channelOrderFor(3);
        $this->listing()->forceFill(['last_published_quantity' => 20, 'remote_quantity' => 20, 'last_published_at' => now()->subMinute()])->save();

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(0, DB::table('inventory_pools')->whereNotNull('dirty_at')->count(), 'the marks are off, as they were');
        $this->assertSame(1, $worked['pools'], 'the worker found its own pools all the same');
        $this->assertGreaterThan(0, $worked['recomputed'], 'the row would have been closed with nothing recomputed');
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::WORKED, $event->state);
        $this->assertTrue($event->wasRecomputed());
        $this->assertSame(ChannelStockEvent::SENT, $event->outcome);
        // And the quantity really went out: seventeen, the shelf's twenty less the three a channel holds.
        $this->assertSame([17], Http::recorded(static fn ($request): bool => str_contains($request->url(), 'price-and-inventory'))
            ->map(static fn (array $pair): int => (int) $pair[0]->data()['items'][0]['quantity'])->values()->all());
    }

    /** The other half of point five: a run that FAILED settles nothing, so the row stays open rather than being closed over a quantity that never left. */
    public function test_a_publisher_that_failed_leaves_the_row_open_and_never_claims_a_send(): void
    {
        $this->providerSync(20);
        $this->channelOrderFor(3);
        $this->listing()->forceFill(['last_published_quantity' => 20, 'remote_quantity' => 20, 'last_published_at' => now()->subMinute()])->save();

        // Nothing answers the channel: the call fails. (Http::preventStrayRequests is on, which is exactly what an unreachable channel looks like from here.)
        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(1, $worked['reopened']);
        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $event->state, 'a failed send closed the row');
        $this->assertSame('publisher_failed', $event->failure_code);
        $this->assertNull($event->outcome, 'and claimed an outcome for a pass that settled nothing');
        // Our own shop's number is right all the same: it never depended on the channel answering.
        $this->assertSame(17, $this->storefrontStock());
    }

    public function test_with_the_division_off_a_pool_nobody_offers_is_still_recomputed_and_recorded_honestly(): void
    {
        config(['sales_channels.stock.allocation.enabled' => false]);
        $this->providerSync(20);
        $other = $this->storeProduct(63);
        DB::transaction(fn (): int => $this->outbox()->record([new PoolKey((int) $this->torodLocation()->id, $other)], ChannelStockEvent::PROVIDER_FIGURE));

        $worked = app(StockEventDrain::class)->drain();

        $this->assertSame(ChannelStockEvent::RECOMPUTED_ONLY, $worked['outcome']);
        $this->assertSame(ChannelStockEvent::RECOMPUTED_ONLY, ChannelStockEvent::where('product_id', $other)->firstOrFail()->outcome);
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

        $this->providerSync(20);
        $this->channelOrderFor(3);

        $event = ChannelStockEvent::firstOrFail();
        $this->assertSame(ChannelStockEvent::PENDING, $event->state, 'the drain ran inside the request that recorded the change');
        $this->assertNull($event->outcome, 'and it even closed the row there');
        $this->assertSame(1, $this->outbox()->lag()['pending'], 'the change is recorded all the same; the minute schedule works it');
        // Our own shop's number is still right: it is written with the hold, which owes nothing to a queue.
        $this->assertSame(17, $this->storefrontStock());
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

        $this->assertSame(ChannelStockEvent::WORKED, ChannelStockEvent::firstOrFail()->state);
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

    /**
     * The note and the figure are one transaction. Recorded after the commit, a process that died between them would leave the new figure stored and nothing to say that
     * whoever else sells the shelf must be told — the very loss the table exists to prevent.
     */
    public function test_a_provider_sync_that_dies_between_the_two_writes_leaves_neither_the_figure_nor_the_event(): void
    {
        $torod = $this->torodLocation();
        $this->providerSync(20);
        ChannelStockEvent::query()->delete();
        $figureBefore = (int) ProviderProductMapping::where('product_id', self::PRODUCT)->value('provider_stock');
        $stockBefore = $this->storefrontStock();

        try {
            DB::transaction(function (): void {
                app(InventorySyncService::class)->applyToAssignedProducts(ProviderCode::TorodPoint, ['ext-' . self::PRODUCT => 11], now());
                throw new RuntimeException('the sync died after storing the figure');
            });
        } catch (RuntimeException) {
            // what the test is about
        }

        $this->assertSame(0, ChannelStockEvent::count(), 'the event outlived the figure it describes');
        $this->assertSame($figureBefore, (int) ProviderProductMapping::where('product_id', self::PRODUCT)->value('provider_stock'), 'the figure outlived the event');
        $this->assertSame($stockBefore, $this->storefrontStock());
        $this->assertNotNull($torod);
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

    /**
     * The nastiest way this could go wrong. Re-applying a figure must not record it as freshly read: a stale figure would then read as current, and a shipment the provider
     * accepted since the figure would look as though it were already counted — which puts units the provider no longer has back on sale.
     */
    public function test_re_applying_a_stored_figure_never_makes_it_look_newly_read(): void
    {
        $readAt = now()->subHours(3);
        $this->providerFigure(self::PRODUCT, 20, $readAt);
        $this->providerSync(20);
        $stamped = ProviderProductMapping::where('product_id', self::PRODUCT)->firstOrFail()->provider_stock_synced_at;
        $this->travelTo(now()->addHour());

        $this->channelOrderFor(4);
        app(StorefrontStockRefresher::class)->refresh(self::PRODUCT);
        app(StockEventDrain::class)->drain();

        $this->assertSame(
            $stamped?->toDateTimeString(),
            ProviderProductMapping::where('product_id', self::PRODUCT)->firstOrFail()->provider_stock_synced_at?->toDateTimeString(),
            'the figure was recorded as freshly read, so a stale one now reads as current',
        );
        $this->assertSame(16, $this->storefrontStock(), 'and the arithmetic itself still holds');
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

        $this->assertSame(ChannelStockEvent::WORKED, ChannelStockEvent::firstOrFail()->state);
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
