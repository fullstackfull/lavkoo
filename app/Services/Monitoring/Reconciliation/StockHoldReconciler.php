<?php

namespace App\Services\Monitoring\Reconciliation;

use App\Models\ChannelStockEvent;
use App\Models\InventoryReservation;
use App\Services\Fulfillment\OrderEligibility;
use App\Services\Fulfillment\Readiness\Compatibility\CompatibilityCheck;
use App\Services\Fulfillment\Readiness\Compatibility\ReadOnlyProbe;

/**
 * Every hold on stock and every change waiting in the outbox, compared with the order or the movement that made it (M2-V6).
 *
 * Discovery-01 report 07, section 3.5, asks for this in four words: zero orphan hold and zero unexplained dead event. Until now the
 * platform WATCHED these rows -- the sales-channel hub lists holds that are days old and outbox rows that are late, `channels:stock-events
 * --status` says what waits -- and nothing COMPARED a hold with the thing that is supposed to justify it. The difference is the one that
 * matters when a market opens: a hold whose order was cancelled holds units of a shared provider shelf forever, and no screen that lists
 * holds by age can tell it from a hold that is merely old.
 *
 * ## Read-only, and the proof of it
 *
 * Every read goes through `ReadOnlyProbe`, so the session is locked read-only, every statement is recorded, and the report states that none
 * of them was a write. The reconciler changes nothing it finds: a hold the order no longer justifies is reported, never released. Releasing
 * one is a decision about somebody's stock, and the whole point of a reconciler is that it can be run on a copy of production by someone
 * who is not allowed to change it.
 *
 * ## What each finding means
 *
 *   - A hold with NOTHING behind it (no order and no channel-order mapping) cannot be traced to a sale at all.
 *   - A hold whose order or channel order is GONE outlived the row that made it: the hold is unreachable from the order side, so nothing
 *     will ever release it.
 *   - A hold the order NO LONGER JUSTIFIES is still `reserved` although its order is finished (delivered, cancelled, returned, failed).
 *     Committed and released holds are the two correct ends; `reserved` with a finished order is the stuck one.
 *   - A DEAD outbox row with no `failure_code` is the unexplained dead event: the queue gave up on a stock change and did not say why.
 *   - A WORKED row with no `outcome` says a pass attended to it and not what the pass came to, which is the same gap one layer along.
 *   - A CLAIMED row that was never worked is a run that died holding it; nothing else will pick it up while the claim stands.
 *
 * The counts are exact (they are `count(*)` over an indexed condition), not samples. A table that is not there yet -- the fulfillment and
 * sales-channel migrations are dormant on an unmigrated host -- is reported as not compared rather than as zero findings, because zero
 * findings and no table are not the same answer.
 */
final class StockHoldReconciler
{
    private const HOLDS = 'inventory_reservations';

    private const OUTBOX = 'channel_stock_events';

    private const ORDERS = 'orders';

    private const CHANNEL_ORDERS = 'external_order_mappings';

    private const SECTION = 'stock holds';

    /** How long a claim may stand before the run that took it is presumed dead. The worker's own lease is minutes; this is deliberately generous. */
    private const CLAIM_HOURS = 6;

    /** @return list<CompatibilityCheck> */
    public function checks(ReadOnlyProbe $probe): array
    {
        return [...$this->holdChecks($probe), ...$this->outboxChecks($probe)];
    }

    /** @return list<CompatibilityCheck> */
    private function holdChecks(ReadOnlyProbe $probe): array
    {
        if (!$probe->hasTable(self::HOLDS)) {
            return [$this->notCompared('holds', self::HOLDS)];
        }

        $held = $probe->count(self::HOLDS, static fn ($query) => $query->where('state', InventoryReservation::RESERVED));
        $checks = [new CompatibilityCheck(
            section: self::SECTION,
            name: 'holds by state',
            status: CompatibilityCheck::INFO,
            detail: $this->asList($probe->distribution(self::HOLDS, 'state', limit: 10)) . '; ' . $held . ' of them hold stock now',
            facts: ['by_state' => $probe->distribution(self::HOLDS, 'state', limit: 10), 'holding' => $held],
        )];

        $checks[] = $this->countCheck(
            name: 'holds with nothing behind them',
            total: $probe->count(self::HOLDS, static fn ($query) => $query
                ->where('state', InventoryReservation::RESERVED)
                ->whereNull('order_id')
                ->whereNull('external_order_mapping_id')),
            whenNone: 'every hold that holds stock names the order or the channel order it was made for',
            whenSome: 'hold(s) in state reserved name neither an order nor a channel order, so no sale can be shown to justify the units they hold',
        );

        $checks[] = $this->countCheck(
            name: 'holds whose order is gone',
            total: $probe->hasTable(self::ORDERS)
                ? $probe->count(self::HOLDS, static fn ($query) => $query
                    ->whereNotNull('order_id')
                    ->whereNotIn('state', [InventoryReservation::RELEASED])
                    ->whereNotExists(static fn ($order) => $order->selectRaw('1')->from(self::ORDERS)->whereColumn(self::ORDERS . '.id', self::HOLDS . '.order_id')))
                : null,
            whenNone: 'every hold that names a store order names one that exists',
            whenSome: 'hold(s) name a store order that is no longer there, so nothing reachable from the order side will release them',
            notCompared: self::ORDERS . ' is not there (it comes from the installation dump)',
        );

        $checks[] = $this->countCheck(
            name: 'holds whose channel order is gone',
            total: $probe->hasTable(self::CHANNEL_ORDERS)
                ? $probe->count(self::HOLDS, static fn ($query) => $query
                    ->whereNotNull('external_order_mapping_id')
                    ->whereNotIn('state', [InventoryReservation::RELEASED])
                    ->whereNotExists(static fn ($mapping) => $mapping->selectRaw('1')->from(self::CHANNEL_ORDERS)->whereColumn(self::CHANNEL_ORDERS . '.id', self::HOLDS . '.external_order_mapping_id')))
                : null,
            whenNone: 'every hold that names a channel order names one that exists',
            whenSome: 'hold(s) name a channel order that is no longer there',
            notCompared: self::CHANNEL_ORDERS . ' is not there (the sales-channel migrations have not run)',
        );

        $checks[] = $this->countCheck(
            name: 'holds the order no longer justifies',
            total: $probe->hasTable(self::ORDERS)
                ? $probe->count(self::HOLDS, static fn ($query) => $query
                    ->where('state', InventoryReservation::RESERVED)
                    ->whereNotNull('order_id')
                    ->whereExists(static fn ($order) => $order->selectRaw('1')->from(self::ORDERS)
                        ->whereColumn(self::ORDERS . '.id', self::HOLDS . '.order_id')
                        ->whereIn(self::ORDERS . '.order_status', OrderEligibility::FINISHED_STATUSES)))
                : null,
            whenNone: 'no hold holds stock for an order that is already finished',
            whenSome: 'hold(s) still hold stock although their order is delivered, cancelled, returned or failed: the units are held and no sale is waiting for them',
            notCompared: self::ORDERS . ' is not there (it comes from the installation dump)',
        );

        return $checks;
    }

    /** @return list<CompatibilityCheck> */
    private function outboxChecks(ReadOnlyProbe $probe): array
    {
        if (!$probe->hasTable(self::OUTBOX)) {
            return [$this->notCompared('outbox', self::OUTBOX)];
        }

        $byState = $probe->distribution(self::OUTBOX, 'state', limit: 10);

        return [
            new CompatibilityCheck(
                section: self::SECTION,
                name: 'outbox by state',
                status: CompatibilityCheck::INFO,
                detail: $this->asList($byState),
                facts: ['by_state' => $byState],
            ),
            $this->countCheck(
                name: 'dead outbox rows with no reason',
                total: $probe->count(self::OUTBOX, static fn ($query) => $query
                    ->where('state', ChannelStockEvent::DEAD)
                    ->whereNull('failure_code')),
                whenNone: 'every stock change the queue gave up on says why it gave up',
                whenSome: 'stock change(s) were given up on without a failure_code: nobody can say what went wrong or whether the quantity behind them was ever sent',
            ),
            $this->countCheck(
                name: 'worked outbox rows with no outcome',
                total: $probe->count(self::OUTBOX, static fn ($query) => $query
                    ->where('state', ChannelStockEvent::WORKED)
                    ->whereNull('outcome')),
                whenNone: 'every row a pass attended to says what the pass came to',
                whenSome: 'row(s) are marked worked without an outcome, so the row says a pass attended to it and not whether anything was sent',
            ),
            $this->countCheck(
                name: 'outbox rows claimed and never worked',
                total: $probe->count(self::OUTBOX, fn ($query) => $query
                    ->where('state', ChannelStockEvent::CLAIMED)
                    ->where('claimed_at', '<', now()->subHours(self::CLAIM_HOURS))),
                whenNone: 'no claim on a stock change is older than ' . self::CLAIM_HOURS . ' hours',
                whenSome: 'stock change(s) have stood claimed for more than ' . self::CLAIM_HOURS . ' hours: the run that took them did not finish and nothing else will take them while the claim stands',
                status: CompatibilityCheck::WARN,
            ),
        ];
    }

    /**
     * One finding from one count. A null total means the comparison could not be made, which is a different answer from zero and is said
     * so: a reconciler that reports "none found" about a table it never read is worse than one that reports nothing at all.
     */
    private function countCheck(string $name, ?int $total, string $whenNone, string $whenSome, string $notCompared = '', string $status = CompatibilityCheck::FAIL): CompatibilityCheck
    {
        if ($total === null) {
            return new CompatibilityCheck(self::SECTION, $name, CompatibilityCheck::INFO, 'not compared: ' . $notCompared, ['compared' => false]);
        }

        return new CompatibilityCheck(
            section: self::SECTION,
            name: $name,
            status: $total === 0 ? CompatibilityCheck::PASS : $status,
            detail: $total === 0 ? $whenNone : $total . ' ' . $whenSome,
            facts: ['rows' => $total],
        );
    }

    private function notCompared(string $what, string $table): CompatibilityCheck
    {
        return new CompatibilityCheck(
            section: self::SECTION,
            name: $what . ' not compared',
            status: CompatibilityCheck::INFO,
            detail: "there is no {$table} table on this database, so nothing about it was compared (its migrations are dormant until they run)",
            facts: ['compared' => false, 'table' => $table],
        );
    }

    /** @param array<string, int> $distribution */
    private function asList(array $distribution): string
    {
        if ($distribution === []) {
            return 'no rows';
        }

        return implode(', ', array_map(static fn (string $state, int $rows): string => "{$state}: {$rows}", array_keys($distribution), $distribution));
    }
}
