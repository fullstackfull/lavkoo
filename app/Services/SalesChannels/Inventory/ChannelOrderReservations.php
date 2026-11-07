<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\SalesChannels\ChannelOrderStatus;
use App\Enums\SalesChannels\FulfillmentMode;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryReservation;
use App\Models\SalesChannelProductMapping;
use App\Services\SalesChannels\Inventory\Allocation\DirtyPools;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
 *  - an order that has an internal order (one the application created for it) holds nothing either: that order is a store order, and the pool already counts it (the provider
 *    items of an internal order, or the store's own stock decrement), so a hold besides would take the same unit off twice. Its hold is released and never made again.
 *
 * A line linked to no product, or whose listing and routing name no location, holds nothing: no pool can be told about it (`unattributedUnits` says how many units that is).
 *
 * Safe to run as often as you like: it only brings the reservations in line with the order as it is now.
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
    ) {
    }

    /** Brings the order's reservations in line with the order, and marks the pools whose promises that changed so that their division is worked out again. */
    public function sync(ExternalOrderMapping $mapping): void
    {
        $before = $this->holdsOf($mapping);
        $this->bringIntoLine($mapping);
        $after = $this->holdsOf($mapping);

        if ($before->all() !== $after->all()) {
            $this->dirtyPools->mark($before->merge($after)->map(static fn (array $hold): PoolKey => new PoolKey(locationId: $hold['location_id'], productId: $hold['product_id'], variantKey: $hold['variant_key']))->unique(static fn (PoolKey $key): string => (string) $key)->values());
        }
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

            if ($mapping->order_id !== null) {
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
            if (!$shipped) {
                $this->reservations->reopen($key);
            }
            $reservation = $this->reservations->reserve($key, $location, (int) $line->product_id, (string) $line->variant_key, $units, $line->sku, (int) $mapping->id, salesChannel: $mapping->sales_channel, accountId: (int) $mapping->account_id);
            if ($shipped) {
                // An order first heard of after it shipped left the shelf when the channel says it did, not when we found out: a count taken since then shows it, an older one does not.
                $this->reservations->commit($key, at: $reservation->wasRecentlyCreated ? $this->leftTheShelfAt($mapping) : null);
            }
        }
    }

    public static function keyOf(ExternalOrderMapping $mapping, ExternalOrderLine $line): string
    {
        return 'channel-order:' . $mapping->id . ':' . $line->id;
    }

    /**
     * Units that open orders of ours to fulfil have promised and that no pool knows about, per account: lines linked to no product, and lines whose listing and routing name no location.
     *
     * @return array<int, int> units by account id
     */
    public function unattributedUnits(): array
    {
        $units = [];
        ExternalOrderMapping::query()
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
