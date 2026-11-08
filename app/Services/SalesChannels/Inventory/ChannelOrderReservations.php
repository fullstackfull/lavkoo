<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ChannelStockEvent;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\Contracts\WritesStorefrontStock;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Inventory\Outbox\StockEventNudge;
use App\Services\SalesChannels\Inventory\Outbox\StockEventOutbox;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the units that open channel orders have promised out of the quantity offered to the channels, so that stock is neither sold twice nor pushed back over a channel's own
 * deduction. One reservation per order line, at the location the line's listing takes its stock from:
 *
 *  - an order that is open (new, pending, awaiting review, processing) and is ours to fulfil holds its active units (RESERVED), and follows the order when units are cancelled;
 *  - once the shipment is linked or the order has shipped or been delivered the units have left the shelf: the reservation is COMMITTED and counts only until the location
 *    reports a figure taken after that moment, so the same units are never taken off twice; an order whose link is removed and that has not shipped holds them again (RESERVED).
 *    An order first heard of after it shipped is committed at the time the channel gives for the shipment: a figure older than that (a typed one most of all) does not show
 *    the units, and they count; a newer one does, and they do not;
 *  - an order that is cancelled, failed or returned gives its units back (RELEASED);
 *  - an order the channel ships itself (FBA, channel-managed) or whose mode is not known draws no stock of ours and holds nothing;
 *  - an order whose internal order (one the application created for it) is a store order that still counts the units holds nothing: the pool counts the internal order's provider
 *    items, so a hold besides would take the same unit off twice. Its hold is released, and it comes back as soon as the internal order stops counting them (it was closed, the
 *    provider cancelled its shipment, the dispatch was reset) while the channel order is still open.
 *
 * A line linked to no product, or whose listing and routing name no location, holds nothing: no pool can be told about it (`unattributedUnits` says how many units that is).
 *
 * Safe to run as often as you like: it only brings the reservations in line with the order as it is now. And when a hold changes, OUR OWN shop's number is written again at
 * once — in a transaction of its own, straight after the holds are committed, because a read inside the holds' transaction sees only what that transaction can see and would
 * miss every hold another process committed meanwhile. It is never decremented either: it is recomputed from the provider's figure minus every promise against it, so running
 * twice takes nothing off twice. The channels are told by the outbox's worker, because unlike our own shop they have to be asked.
 */
class ChannelOrderReservations
{
    public const INTERNAL_ORDER_COUNTS_IT = 'internal_order_counts_it';

    /** The statuses in which an order of ours to fulfil is still on the shelf (its units are RESERVED). */
    private const RESERVING_STATUSES = ['new', 'pending', 'awaiting_review', 'processing'];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PublishPolicyResolver $policies,
        private readonly DirtyPools $dirtyPools,
        private readonly StoreOrderItems $storeOrders,
        private readonly WritesStorefrontStock $storefront,
        private readonly StockEventOutbox $outbox,
        private readonly StockEventNudge $nudge,
    ) {
    }

    /**
     * Brings the order's reservations in line with the order and, when that changed what is promised, records it.
     *
     * The reservations and the record of the change are written in ONE transaction, so there is no moment in which a unit is held and nothing says the pool must be worked out
     * again. That moment used to exist: the mark was a statement of its own after the writes, so a process that died between them left the unit held and the quantity still on
     * offer until the next full sweep — and on a host with the division switched off the mark never happened at all. Both pools are named: the one the units left and the one
     * they went to.
     *
     * The worker is nudged AFTER the commit, never inside it: a job that started first would read the database as it was. And the nudge is only speed — if it is lost, the
     * rows are still in the table and the next run works them.
     *
     * @param string $reason why, for the person who reads the row later (ChannelStockEvent::ORDER_IMPORTED and the rest)
     */
    public function sync(ExternalOrderMapping $mapping, string $reason = ChannelStockEvent::ORDER_CHANGED): void
    {
        // Three attempts, like the ingestor's own transaction: two processes reading the SAME order at once each hold their reservation rows and each want the product row, so a
        // deadlock is possible and losing one is not a reason to drop a hold. The work is idempotent — it brings the reservations in line with the order as it is now — so a
        // retry is simply the same work again.
        $products = DB::transaction(function () use ($mapping, $reason): array {
            $before = $this->holdsOf($mapping);
            $this->bringIntoLine($mapping);
            $after = $this->holdsOf($mapping);
            if ($before->all() === $after->all()) {
                return [];
            }

            $pools = $before->merge($after)->map(static fn (array $hold): PoolKey => new PoolKey(locationId: $hold['location_id'], productId: $hold['product_id'], variantKey: $hold['variant_key']))->unique(static fn (PoolKey $key): string => (string) $key)->values();
            $this->dirtyPools->mark($pools);
            // The row is written HERE, with the holds, so the intention to correct our own number and to ask the channels cannot be lost. The correcting itself happens below.
            $this->outbox->record($pools, $reason, subjectType: 'external_order_mapping', subjectId: (int) $mapping->id);

            return $pools->map(static fn (PoolKey $key): int => $key->productId)->unique()->values()->all();
        }, attempts: 3);

        if ($products === []) {
            return;
        }

        // AFTER the commit, and in a transaction of its own. Not in the one above, and this is the whole lesson of the concurrency test: inside that transaction the reads
        // that count what is promised see the SNAPSHOT taken when it began — before the other processes holding units of the same product committed theirs. Six orders of two
        // units each then each wrote "twenty minus my two", and our own shop went on offering ten units that were gone. Reading after the commit, serialized on the product's
        // own row lock, each pass counts every hold that exists.
        //
        // It is still immediate: the same request, before the ingest returns, with no queue and no worker. What it is not is atomic with the hold — so the outbox row above
        // keeps the job, and the worker (one at a time, outside any transaction of ours) does it again whatever happens here. A number that can be a moment stale and is then
        // corrected is worth far more than a number that is confidently wrong.
        $this->storefront->writeFor($products);
        $this->nudge->soon();
    }

    /** @return Collection<string, array{location_id: int, product_id: int, variant_key: string, state: string, quantity: int}> what the order holds now, by reservation */
    private function holdsOf(ExternalOrderMapping $mapping): Collection
    {
        return InventoryReservation::where('external_order_mapping_id', $mapping->id)->orderBy('id')->get(['reservation_key', 'location_id', 'product_id', 'variant_key', 'state', 'quantity'])->toBase()
            ->mapWithKeys(static fn (InventoryReservation $hold): array => [$hold->reservation_key => [
                'location_id' => (int) $hold->location_id, 'product_id' => (int) $hold->product_id, 'variant_key' => (string) $hold->variant_key, 'state' => $hold->state, 'quantity' => (int) $hold->quantity,
            ]]);
    }

    private function bringIntoLine(ExternalOrderMapping $mapping): void
    {
        $mapping->loadMissing('lines');
        $holds = $this->mayHold($mapping);

        foreach ($mapping->lines as $line) {
            $key = self::keyOf($mapping, $line);
            $units = $line->activeQuantity();

            if ($this->aStoreOrderCounts($mapping, $line)) {
                $this->reservations->release($key, self::INTERNAL_ORDER_COUNTS_IT);
                continue;
            }
            if (!$holds || $units < 1 || in_array($mapping->orderStatus(), [ChannelOrderStatus::Cancelled, ChannelOrderStatus::Failed, ChannelOrderStatus::Returned], true)) {
                $this->reservations->release($key, 'order_' . $mapping->status);
                continue;
            }
            $location = $this->locationOf($line);
            if ($location === null || $line->product_id === null) {
                continue;
            }

            $shipped = $this->hasLeftTheShelf($mapping);
            $this->reservations->reopen($key, committedToo: !$shipped);
            $reservation = $this->reservations->reserve($key, $location, (int) $line->product_id, (string) $line->variant_key, $units, $line->sku, (int) $mapping->id, salesChannel: $mapping->sales_channel, accountId: (int) $mapping->account_id);
            if ($shipped) {
                // An order first heard of after it shipped left the shelf when the channel says it did, not when we found out: a count taken since then shows it, an older one does not.
                $this->reservations->commit($key, at: $reservation->wasRecentlyCreated ? $this->leftTheShelfAt($mapping) : null);
            }
        }
    }

    /** Does the internal order made for this channel order still count the line's units, so that a hold of the channel order's own would count them twice? */
    private function aStoreOrderCounts(ExternalOrderMapping $mapping, ExternalOrderLine $line): bool
    {
        return $mapping->order_id !== null && $line->product_id !== null && $this->storeOrders->holds(orderId: (int) $mapping->order_id, productId: (int) $line->product_id, variantKey: (string) $line->variant_key);
    }

    public static function keyOf(ExternalOrderMapping $mapping, ExternalOrderLine $line): string
    {
        return 'channel-order:' . $mapping->id . ':' . $line->id;
    }

    /**
     * Units that open orders of ours to fulfil have promised and that no pool knows about, per account: lines linked to no product, and lines whose listing and routing name no location.
     *
     * @param int|null $accountId only this account's orders
     * @return array<int, int> units by account id
     */
    public function unattributedUnits(?int $accountId = null): array
    {
        $units = [];
        ExternalOrderMapping::query()
            ->when($accountId !== null, static fn ($query) => $query->where('account_id', $accountId))
            ->whereNull('order_id')
            ->whereIn('status', self::RESERVING_STATUSES)
            ->where(static fn ($query) => $query->whereNull('shipment_link_state')->orWhere('shipment_link_state', '!=', ExternalOrderMapping::LINK_LINKED))
            ->with('lines')
            ->chunkById(200, function ($mappings) use (&$units): void {
                foreach ($mappings->filter(fn (ExternalOrderMapping $mapping): bool => $this->mayHold($mapping)) as $mapping) {
                    foreach ($mapping->lines as $line) {
                        if ($line->activeQuantity() > 0 && ($line->product_id === null || $this->locationOf($line) === null)) {
                            $units[(int) $mapping->account_id] = ($units[(int) $mapping->account_id] ?? 0) + $line->activeQuantity();
                        }
                    }
                }
            });

        return $units;
    }

    private function mayHold(ExternalOrderMapping $mapping): bool
    {
        return !$mapping->fulfillmentMode()->isFulfilledByChannel() && $mapping->fulfillmentMode() !== FulfillmentMode::Unknown;
    }

    private function hasLeftTheShelf(ExternalOrderMapping $mapping): bool
    {
        return in_array($mapping->orderStatus(), [ChannelOrderStatus::Shipped, ChannelOrderStatus::Delivered], true)
            || $mapping->shipment_link_state === ExternalOrderMapping::LINK_LINKED;
    }

    /** The channel's own time for the shipment (else its last change to the order), never later than now. */
    private function leftTheShelfAt(ExternalOrderMapping $mapping): CarbonInterface
    {
        $reported = $mapping->shipped_at ?? $mapping->delivered_at ?? $mapping->external_updated_at;

        return $reported === null || $reported->isFuture() ? now() : $reported;
    }

    /** The location the line's listing takes its stock from; the one routing chose for the line when the listing names none. */
    private function locationOf(ExternalOrderLine $line): ?int
    {
        $listing = $line->product_mapping_id === null ? null : SalesChannelProductMapping::find($line->product_mapping_id);
        $location = $listing === null ? null : $this->policies->resolve($listing)->stockLocationId;

        return $location ?? $line->inventory_location_id;
    }
}
