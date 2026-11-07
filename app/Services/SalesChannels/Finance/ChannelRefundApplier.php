<?php

namespace App\Services\SalesChannels\Finance;

use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\SalesChannelAccount;
use App\Models\SalesChannelEvent;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Takes what a channel's refunds gave back off the revenue of the order lines they are about, the way a Trendyol return does: without tax, set per refund (so reading a
 * refund twice, or after it changed, leaves the line right and two refunds on one line add up), and never more than the line earned (the cap is applied where the revenue is
 * read: SaleDocumentReader).
 *
 * It works from the stored statement events of the refund (the goods, the shipping and a promotion that was taken off reduce the revenue; the tax is recorded beside it).
 * An event waits (`received`) until its order line is found, and nothing is ever counted without one:
 *  - the order is not held (yet): kept for review, counted nowhere, and tried again on every run, so it is applied when the order arrives;
 *  - the order is held but no single line matches the item (by SKU), or the refund is in another currency than the order: kept for a person, not applied.
 *
 * A refund without a physical return (a price adjustment) reduces the revenue and nothing else: the cost of the goods stays, because no unit came back. Putting units back on
 * the shelf is a decision of its own (ReturnDecisionService). This writes order data, not the ledger, so it does not depend on OPERATIONAL_COSTS_ENABLED.
 */
class ChannelRefundApplier
{
    private const REVENUE_PARTS = ['principal', 'shipping', 'promotion'];

    /**
     * Applies the refund events of an account that are waiting for an order line.
     *
     * @return int the order lines whose refunded amount changed
     */
    public function applyPending(SalesChannelAccount $account): int
    {
        $changed = 0;
        foreach (array_chunk($this->pendingReferences($account), 200) as $references) {
            foreach ($references as $reference) {
                $changed += $this->applyReference($account, $reference);
            }
        }

        return $changed;
    }

    /** @return list<string|null> what the waiting refund events are about (`order|package`; null when a refund names no order) */
    private function pendingReferences(SalesChannelAccount $account): array
    {
        return SalesChannelEvent::query()
            ->where('account_id', $account->id)
            ->where('event_type', 'like', 'finance.refund.%')
            ->where(static fn (Builder $query) => $query
                ->where('outcome', SalesChannelEvent::RECEIVED)
                ->orWhere(static fn (Builder $held) => $held->where('outcome', SalesChannelEvent::NEEDS_REVIEW)->where('outcome_note', SalesChannelEvent::NOTE_ORDER_NOT_HELD)))
            ->distinct()->orderBy('external_reference')->pluck('external_reference')->all();
    }

    private function applyReference(SalesChannelAccount $account, ?string $reference): int
    {
        $events = SalesChannelEvent::query()
            ->where('account_id', $account->id)
            ->where('event_type', 'like', 'finance.refund.%')
            ->where('outcome', '!=', SalesChannelEvent::DUPLICATE)
            ->when($reference === null, static fn (Builder $query) => $query->whereNull('external_reference'), static fn (Builder $query) => $query->where('external_reference', $reference))
            ->orderBy('id')->get();
        if ($reference === null) {
            $this->keep($events, SalesChannelEvent::NOTE_LINE_NOT_MATCHED);

            return 0;
        }

        [$orderId, $packageId] = array_pad(explode('|', $reference, 2), 2, '');
        $mapping = ExternalOrderMapping::where('account_id', $account->id)->where('external_order_id', $orderId)->where('external_package_id', $packageId)->first();
        if ($mapping === null) {
            $this->keep($events, SalesChannelEvent::NOTE_ORDER_NOT_HELD);

            return 0;
        }

        $changed = 0;
        foreach ($events->groupBy(fn (SalesChannelEvent $event): string => $this->itemKey($event)) as $key => $item) {
            $changed += $this->applyItem($mapping, (string) $key, $item);
        }

        return $changed;
    }

    /**
     * @param Collection<int, SalesChannelEvent> $item every event of one refunded item
     * @return int 1 when the line's refunded amount changed
     */
    private function applyItem(ExternalOrderMapping $mapping, string $key, Collection $item): int
    {
        $line = $this->lineOf($mapping, (string) ($item->first()->payload['sku'] ?? ''));
        if ($line === null) {
            $this->keep($item, SalesChannelEvent::NOTE_LINE_NOT_MATCHED);

            return 0;
        }
        if ($mapping->currency !== null && $item->contains(static fn (SalesChannelEvent $event): bool => strcasecmp((string) ($event->payload['currency'] ?? ''), $mapping->currency) !== 0)) {
            $this->keep($item, SalesChannelEvent::NOTE_CURRENCY_DIFFERS);

            return 0;
        }

        // The same amount of the same kind within one refund and item is one fact delivered twice (a deferred transaction that was released), never two.
        $reported = ['revenue' => Money::zero(), 'tax' => Money::zero()];
        foreach ($item->unique(static fn (SalesChannelEvent $event): string => ($event->payload['raw_type'] ?? '') . '|' . ($event->payload['amount'] ?? '')) as $event) {
            $part = (string) ($event->payload['meta']['refund_part'] ?? '');
            $amount = Money::of((string) $event->payload['amount']);
            if (in_array($part, self::REVENUE_PARTS, true)) {
                $reported['revenue'] = $reported['revenue']->plus($amount);
            } elseif ($part === 'tax') {
                $reported['tax'] = $reported['tax']->plus($amount);
            }
        }
        // Amazon signs the money going back to the buyer as negative; a promotion that was taken off the refund is the opposite sign, and what is given back is never below zero.
        $changed = $this->record($line, $key, $reported['revenue']->negated()->max(Money::zero()), $reported['tax']->negated()->max(Money::zero()));
        SalesChannelEvent::whereIn('id', $item->pluck('id'))->update(['outcome' => SalesChannelEvent::APPLIED, 'outcome_note' => null, 'processed_at' => now()]);

        return $changed ? 1 : 0;
    }

    /** The line a refunded item is about: the one with its SKU, or the only line of the order when the refund names none. */
    private function lineOf(ExternalOrderMapping $mapping, string $sku): ?ExternalOrderLine
    {
        $lines = $mapping->lines()->get();
        $candidates = trim($sku) === '' ? $lines : $lines->filter(static fn (ExternalOrderLine $line): bool => strcasecmp(trim((string) $line->sku), trim($sku)) === 0);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /** Sets what one refund of one item took back and recomputes the line's total from every refund it has had. */
    private function record(ExternalOrderLine $line, string $key, Money $revenue, Money $tax): bool
    {
        return DB::transaction(function () use ($line, $key, $revenue, $tax): bool {
            $locked = ExternalOrderLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            $snapshot = (array) $locked->snapshot;
            $snapshot['refunded_by_event'][$key] = ['revenue' => $revenue->toStorage(), 'tax' => $tax->toStorage()];
            $total = Money::sum(array_map(static fn (array $refund): Money => Money::of($refund['revenue']), $snapshot['refunded_by_event']));
            $taxTotal = Money::sum(array_map(static fn (array $refund): Money => Money::of($refund['tax']), $snapshot['refunded_by_event']));

            $unchanged = $locked->refunded_total !== null && Money::of($locked->refunded_total)->equals($total) && Money::of($locked->refunded_tax ?? 0)->equals($taxTotal)
                && ($locked->snapshot['refunded_by_event'][$key] ?? null) === $snapshot['refunded_by_event'][$key];
            if (!$unchanged) {
                $locked->forceFill(['refunded_total' => $total->toStorage(), 'refunded_tax' => $taxTotal->toStorage(), 'snapshot' => $snapshot])->save();
            }

            return !$unchanged;
        });
    }

    /** What tells one refunded item from another: the refund it belongs to (or its transaction) and its item. */
    private function itemKey(SalesChannelEvent $event): string
    {
        $meta = (array) ($event->payload['meta'] ?? []);

        return ($meta['refund_id'] ?? $meta['transaction_id'] ?? $event->dedupe_key) . '|' . ($meta['item'] ?? '');
    }

    /** @param Collection<int, SalesChannelEvent> $events */
    private function keep(Collection $events, string $why): void
    {
        $waiting = $events->filter(static fn (SalesChannelEvent $event): bool => $event->outcome !== SalesChannelEvent::APPLIED);
        SalesChannelEvent::whereIn('id', $waiting->pluck('id'))->update(['outcome' => SalesChannelEvent::NEEDS_REVIEW, 'outcome_note' => $why]);
    }
}
