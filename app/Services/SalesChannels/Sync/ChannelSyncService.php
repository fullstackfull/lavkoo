<?php

namespace App\Services\SalesChannels\Sync;

use App\Enums\SalesChannels\IntegrationCapability;
use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\ChannelConnectorRegistry;
use App\Services\SalesChannels\Contracts\ReadsChannelInventory;
use App\Services\SalesChannels\Contracts\ReadsFinances;
use App\Services\SalesChannels\Contracts\ReadsListings;
use App\Services\SalesChannels\Contracts\ReadsOrders;
use App\Services\SalesChannels\Data\Pages;
use App\Services\SalesChannels\Finance\ChannelFeeImporter;
use App\Services\SalesChannels\Inventory\ChannelInventoryWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

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
    public const FINANCES = 'finances';

    public function __construct(
        private readonly ChannelConnectorRegistry $connectors,
        private readonly ChannelSyncRunner $runner,
        private readonly SyncStateStore $states,
        private readonly ChannelOrderIngestor $orders,
        private readonly ListingSyncService $listings,
        private readonly ChannelInventoryWriter $inventory,
        private readonly ChannelFeeImporter $fees,
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

    /** @return array<string, array{0: IntegrationCapability, 1: class-string, 2: string}> */
    private function resources(): array
    {
        return [
            self::ORDERS => [IntegrationCapability::OrderImport, ReadsOrders::class, 'syncOrders'],
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
            $stats = ['pages' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'needs_review' => 0, 'skipped' => 0];

            return $this->sweep(
                lease: $lease,
                fetch: fn (?array $cursor): Pages => $reader->orders($account, $since, $cursor),
                handle: function ($page) use ($account, &$stats): void {
                    $stats['skipped'] += $page->skipped;
                    foreach ($page->items as $remote) {
                        $result = $this->orders->ingest($account, $remote);
                        $stats[$result->outcome]++;
                        $stats['needs_review'] += $result->mapping->review_reason !== null ? 1 : 0;
                    }
                },
                stats: $stats,
                finish: static fn (?CarbonInterface $newest, CarbonInterface $started): CarbonInterface => $newest ?? $started,
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
            );
        });
    }

    /** @return array{result: string, detail: string|null, stats: array<string, mixed>} */
    public function syncFinances(SalesChannelAccount $account): array
    {
        return $this->runner->run($account, self::FINANCES, function (SyncLease $lease) use ($account): array {
            $reader = $this->connectors->able($account, ReadsFinances::class);
            $to = CarbonImmutable::now();
            // Corrections reach a statement days after the sale, so each run re-reads further back than an order sync does; a repeated fact is recorded once.
            $from = $lease->watermark === null
                ? $to->subDays((int) config('sales_channels.sync.finance_backfill_days', 30))
                : CarbonImmutable::parse($lease->watermark)->subDays((int) config('sales_channels.sync.finance_overlap_days', 3));
            $stats = ['pages' => 0, 'new' => 0, 'duplicates' => 0, 'costs_recorded' => 0, 'needs_review' => 0];

            return $this->sweep(
                lease: $lease,
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
     * Pages until the source says it is done or the page budget is spent.
     *
     * @param Closure(?array): Pages $fetch
     * @param Closure(Pages): void $handle
     * @param array<string, mixed> $stats counted in place by the callers' closures as well as here
     * @param Closure(?CarbonInterface, CarbonInterface): ?CarbonInterface $finish what to record as the new watermark once the sweep is complete
     * @return array{watermark?: CarbonInterface|null, cursor?: array<string, mixed>|null, stats: array<string, mixed>}
     */
    private function sweep(SyncLease $lease, Closure $fetch, Closure $handle, array &$stats, Closure $finish): array
    {
        $held = $lease->cursor ?? [];
        $started = isset($held['_started']) ? CarbonImmutable::parse($held['_started']) : CarbonImmutable::now();
        $newest = isset($held['_newest']) ? CarbonImmutable::parse($held['_newest']) : null;
        $cursor = $held === [] ? null : array_filter($held, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $budget = (int) config('sales_channels.sync.max_pages_per_run', 25);
        $pages = 0;

        do {
            $page = $fetch($cursor === [] ? null : $cursor);
            $handle($page);
            $stats['pages'] = ($stats['pages'] ?? 0) + 1;
            $newest = $page->highWatermark !== null && ($newest === null || $page->highWatermark->gt($newest)) ? CarbonImmutable::instance($page->highWatermark) : $newest;
            $cursor = $page->nextCursor;
            $this->states->renew($lease);
            $pages++;
        } while ($cursor !== null && $pages < $budget);

        if ($cursor !== null) {
            return ['cursor' => $cursor + ['_started' => $started->toIso8601String(), '_newest' => $newest?->toIso8601String()], 'stats' => $stats];
        }

        return ['watermark' => $finish($newest, $started), 'stats' => $stats];
    }
}
