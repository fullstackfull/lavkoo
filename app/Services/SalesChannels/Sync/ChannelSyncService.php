<?php

namespace App\Services\SalesChannels\Sync;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ReadsChannelInventory;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Contracts\ReadsReturns;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Exceptions\ChannelRateLimitedException;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Enums\SalesChannels\RoutingAction;
use App\Services\SalesChannels\Inventory\ChannelInventoryWriter;
use App\Services\SalesChannels\Routing\DynamicFulfillmentRoutingEngine;
use App\Services\SalesChannels\Shipments\ExternalShipmentLinker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads what a channel has to say, one resource at a time, under the lease and back-off rules of ChannelSyncRunner.
 *
 * Every read is gated twice: here (the account is on and holds the capability, otherwise the resource is skipped silently) and in the transport (the call itself is refused
 * if not). A run reads at most `max_pages_per_run` pages and then pauses with its place saved, carrying the bookkeeping it needs (the newest change seen, when the sweep began)
 * inside the saved cursor under keys that start with an underscore, which no connector reads. Nothing here writes to a channel.
 */
class ChannelSyncService
{
    public const ORDERS = 'orders';
    public const LISTINGS = 'listings';
    public const INVENTORY = 'inventory';
    public const RETURNS = 'returns';
    public const FINANCES = 'finances';

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ChannelSyncRunner $runner,
        private readonly SyncStateStore $states,
        private readonly ChannelOrderIngestor $orders,
        private readonly ListingSyncService $listings,
        private readonly ChannelInventoryWriter $inventory,
        private readonly ChannelFeeImporter $fees,
        private readonly ChannelReturnIngestor $returns,
        private readonly DynamicFulfillmentRoutingEngine $routing,
        private readonly ExternalShipmentLinker $linker,
    ) {
    }

    /**
     * Every resource of the account that is due and permitted.
     *
     * @return array<string, array{result: string, detail: string|null, stats: array<string, mixed>}>
     */
    public function runDue(SalesChannelAccount $account): array
    {
        $results = [];
        foreach ($this->resources() as $resource => [$capability, $ability, $method]) {
            if (!$this->permitted($account, $capability, $ability)) {
                continue;
            }
            if (!$this->states->isDue((int) $account->id, $resource, (int) config("sales_channels.sync.intervals.{$resource}", 60))) {
                continue;
            }
            $results[$resource] = $this->{$method}($account);
        }

        return $results;
    }

    /** @return list<string> every resource a channel can be read for */
    public static function resourceNames(): array
    {
        return [self::ORDERS, self::RETURNS, self::LISTINGS, self::INVENTORY, self::FINANCES];
    }

    /**
     * The named resources of the account now, whether or not they are due (every one the account can read when none is named). A resource the account may not read, or
     * the channel can not be read for, is reported as skipped when it was asked for by name, and left out when nobody named it.
     *
     * @param list<string> $only
     * @return array<string, array{result: string, detail: string|null, stats: array<string, mixed>}>
     */
    public function runNow(SalesChannelAccount $account, array $only = []): array
    {
        $results = [];
        foreach ($this->resources() as $resource => [$capability, $ability]) {
            $named = in_array($resource, $only, true);
            if ($only !== [] && !$named) {
                continue;
            }
            if (!$named && !$this->permitted($account, $capability, $ability)) {
                continue;
            }
            $results[$resource] = $this->runResource($account, $resource);
        }

        return $results;
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function runResource(SalesChannelAccount $account, string $resource): array
    {
        [$capability, $ability, $method] = $this->resources()[$resource] ?? throw new InvalidArgumentException("Unknown resource '{$resource}'.");
        if ($this->permitted($account, $capability, $ability)) {
            return $this->{$method}($account);
        }

        return ['result' => ChannelSyncRunner::SKIPPED, 'detail' => match (true) {
            !$account->enabled => 'account_off',
            !$this->connectors->supports($account, $ability) => 'not_supported_by_channel',
            default => 'capability_off',
        }, 'stats' => []];
    }

    /** @return array<string, array{0: IntegrationCapability, 1: class-string, 2: string}> */
    private function resources(): array
    {
        return [
            self::ORDERS => [IntegrationCapability::OrderImport, ReadsOrders::class, 'syncOrders'],
            self::RETURNS => [IntegrationCapability::OrderImport, ReadsReturns::class, 'syncReturns'],
            self::LISTINGS => [IntegrationCapability::ProductSync, ReadsListings::class, 'syncListings'],
            self::INVENTORY => [IntegrationCapability::ReadOnlySync, ReadsChannelInventory::class, 'syncChannelInventory'],
            self::FINANCES => [IntegrationCapability::FinancialReconciliation, ReadsFinances::class, 'syncFinances'],
        ];
    }

    private function permitted(SalesChannelAccount $account, IntegrationCapability $capability, string $ability): bool
    {
        return $account->enabled && $account->hasCapability($capability) && $this->connectors->supports($account, $ability);
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncOrders(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::ORDERS, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsOrders::class);
            $since = $this->states->readFrom($lease);
            $stats = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'needs_review' => 0, 'skipped' => 0, 'decisions' => 0, 'linked' => 0];

            return $this->sweep(
                lease: $lease,
                fetch: fn (?array $cursor): Pages => $reader->orders($account, $since, $cursor),
                handle: function ($page) use ($account, &$stats): void {
                    $stats['skipped'] += $page->skipped;
                    foreach ($page->items as $remote) {
                        $result = $this->orders->ingest($account, $remote);
                        $stats[$result->outcome]++;
                        $this->decide($result, $stats);
                        $stats['needs_review'] += $result->mapping->fresh()->review_reason !== null ? 1 : 0;
                    }
                },
                stats: $stats,
                finish: static fn (?CarbonInterface $newest, CarbonInterface $started): CarbonInterface => $newest ?? $started,
            );
        });
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncReturns(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::RETURNS, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsReturns::class);
            $since = $this->states->readFrom($lease);
            $stats = ['pages' => 0, 'applied' => 0, 'unchanged' => 0, 'unknown_order' => 0];
            $oldestUnknown = null;

            return $this->sweep(
                lease: $lease,
                fetch: fn (?array $cursor): Pages => $reader->returns($account, $since, $cursor),
                handle: function ($page) use ($account, &$stats, &$oldestUnknown): void {
                    foreach ($page->items as $return) {
                        $outcome = $this->returns->apply($account, $return);
                        $stats[$outcome]++;
                        if ($outcome === ChannelReturnIngestor::UNKNOWN_ORDER && $return->updatedAt !== null && ($oldestUnknown === null || $return->updatedAt->lt($oldestUnknown))) {
                            $oldestUnknown = $return->updatedAt;
                        }
                    }
                },
                stats: $stats,
                // A claim for an order we do not hold yet is not lost: the watermark stays at it (never further back than the orders themselves are read), so the next runs read
                // it again and apply it as soon as the order has arrived.
                finish: static fn (?CarbonInterface $newest, CarbonInterface $started): CarbonInterface => $oldestUnknown === null
                    ? ($newest ?? $started)
                    : max(min($newest ?? $started, $oldestUnknown), $started->copy()->subDays((int) config('sales_channels.sync.order_backfill_days', 30))),
            );
        });
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncListings(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::LISTINGS, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsListings::class);
            $stats = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'suggested' => 0, 'linked' => 0, 'conflicts' => 0, 'unseen' => 0];

            return $this->sweep(
                lease: $lease,
                fetch: fn (?array $cursor): Pages => $reader->listings($account, $cursor),
                handle: function ($page) use ($account, &$stats): void {
                    foreach ($this->listings->sync($account, $page->items) as $key => $count) {
                        $stats[$key] += $count;
                    }
                },
                stats: $stats,
                // Only a COMPLETE sweep may say which listings were not seen.
                finish: function (?CarbonInterface $newest, CarbonInterface $started) use ($account, &$stats): CarbonInterface {
                    $stats['unseen'] = $this->listings->markUnseen($account, $started);

                    return $started;
                },
            );
        });
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncChannelInventory(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::INVENTORY, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsChannelInventory::class);
            $stats = ['pages' => 0, 'stored' => 0, 'unlinked' => 0, 'stale' => 0];

            return $this->sweep(
                lease: $lease,
                fetch: fn (?array $cursor): Pages => $reader->inventory($account, $cursor),
                handle: function ($page) use ($account, &$stats): void {
                    foreach ($this->inventory->store($account, $page->items) as $key => $count) {
                        $stats[$key] += $count;
                    }
                },
                stats: $stats,
                finish: function (?CarbonInterface $newest, CarbonInterface $started) use ($account, &$stats): CarbonInterface {
                    $stats['stale'] = $this->inventory->markUnseenStale($account, $started);

                    return $started;
                },
                resumable: false,
            );
        });
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncFinances(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::FINANCES, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsFinances::class);
            // A run that goes on from a saved place asks for the very window that place was issued for: a page token is only good for the request it came from.
            $held = (array) ($lease->cursor ?? []);
            $now = CarbonImmutable::now()->subMinutes((int) config('sales_channels.sync.finance_end_lag_minutes', 3));
            // Corrections reach a statement days after the sale, so each run re-reads further back than an order sync does; a repeated fact is recorded once.
            $from = isset($held['_from']) ? CarbonImmutable::parse($held['_from']) : ($lease->watermark === null
                ? $now->subDays((int) config('sales_channels.sync.finance_backfill_days', 30))
                : CarbonImmutable::parse($lease->watermark)->subDays((int) config('sales_channels.sync.finance_overlap_days', 3)));
            // One window is never longer than the channels answer for (Amazon returns nothing for a span over 180 days): a longer gap is read window by window over several
            // runs, and the watermark only moves to the end of the window that was read.
            $to = isset($held['_to']) ? CarbonImmutable::parse($held['_to']) : min($now, $from->addDays((int) config('sales_channels.sync.finance_max_window_days', 180) - 1));
            $stats = ['pages' => 0, 'new' => 0, 'duplicates' => 0, 'costs_recorded' => 0, 'needs_review' => 0];

            return $this->sweep(
                lease: $lease,
                remember: ['_from' => $from->toIso8601String(), '_to' => $to->toIso8601String()],
                fetch: fn (?array $cursor): Pages => $reader->finances($account, $from, $to, $cursor),
                handle: function ($page) use ($account, &$stats): void {
                    foreach ($this->fees->import($account, $page->items) as $key => $count) {
                        $stats[$key] += $count;
                    }
                },
                stats: $stats,
                finish: static fn (?CarbonInterface $newest, CarbonInterface $started): CarbonInterface => $to,
            );
        });
    }

    /**
     * Records what is to be done about an order that is new, changed, or has never been decided, and looks for the shipment of one that is only followed. Both only
     * read and record: no shipment is created and nothing is sent to the channel or to a provider.
     *
     * @param array<string, mixed> $stats
     */
    private function decide(IngestResult $result, array &$stats): void
    {
        $mapping = $result->mapping;
        if (in_array($result->outcome, [IngestResult::STALE], true) || ($result->outcome === IngestResult::UNCHANGED && $mapping->execution_policy !== null)) {
            return;
        }

        $decision = $this->routing->route($mapping);
        $stats['decisions'] += $decision?->wasRecentlyCreated ? 1 : 0;
        if ($decision?->action === RoutingAction::MonitorOnly->value && $mapping->fresh()->shipment_link_state === ExternalOrderMapping::LINK_UNLINKED) {
            $stats['linked'] += $this->linker->autoLink($mapping->fresh()) === ExternalShipmentLinker::LINKED ? 1 : 0;
        }
    }

    /**
     * Pages until the source says it is done or the page budget is spent. When the channel (or our own limiter) says wait, the place reached is saved before the run backs off,
     * so a backlog larger than one allowance is read over several runs instead of its first pages again and again.
     *
     * @param Closure(?array): Pages $fetch
     * @param Closure(Pages): void $handle
     * @param array<string, mixed> $stats counted in place by the callers' closures as well as here
     * @param Closure(?CarbonInterface, CarbonInterface): ?CarbonInterface $finish what to record as the new watermark once the sweep is complete
     * @param array<string, string> $remember facts the next run must start from again (the window a paged result was asked for), kept in the saved place under a name that starts with "_"
     * @param bool $resumable false for a read whose page tokens do not outlive the run (a snapshot of stock): it is read in one go, never paused, and a place saved by an
     *                        earlier run is not used
     * @return array{watermark?: CarbonInterface|null, cursor?: array<string, mixed>|null, stats: array<string, mixed>}
     */
    private function sweep(SyncLease $lease, Closure $fetch, Closure $handle, array &$stats, Closure $finish, array $remember = [], bool $resumable = true): array
    {
        $held = $resumable ? ($lease->cursor ?? []) : [];
        $started = isset($held['_started']) ? CarbonImmutable::parse($held['_started']) : CarbonImmutable::now();
        $newest = isset($held['_newest']) ? CarbonImmutable::parse($held['_newest']) : null;
        $cursor = $held === [] ? null : array_filter($held, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $budget = $resumable ? (int) config('sales_channels.sync.max_pages_per_run', 25) : (int) config('sales_channels.sync.max_pages_one_go', 400);
        $pages = 0;

        do {
            try {
                $page = $fetch($cursor === [] ? null : $cursor);
            } catch (ChannelRateLimitedException $limited) {
                if ($pages > 0 && $resumable) {
                    $this->states->checkpoint($lease, ($cursor ?? []) + ['_started' => $started->toIso8601String(), '_newest' => $newest?->toIso8601String()] + $remember);
                }

                throw $limited;
            }
            $handle($page);
            $stats['pages'] = ($stats['pages'] ?? 0) + 1;
            $newest = $page->highWatermark !== null && ($newest === null || $page->highWatermark->gt($newest)) ? CarbonImmutable::instance($page->highWatermark) : $newest;
            $cursor = $page->nextCursor;
            $this->states->renew($lease);
            $pages++;
        } while ($cursor !== null && $pages < $budget);

        if ($cursor !== null && !$resumable) {
            throw new RuntimeException("This read cannot be paused and goes on for more than {$budget} pages; nothing is recorded as complete.");
        }
        if ($cursor !== null) {
            return ['cursor' => $cursor + ['_started' => $started->toIso8601String(), '_newest' => $newest?->toIso8601String()] + $remember, 'stats' => $stats];
        }

        return ['watermark' => $finish($newest, $started), 'stats' => $stats];
    }
}
