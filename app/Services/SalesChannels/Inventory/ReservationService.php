<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\InventoryReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Promises units of a location's stock to open orders so that the quantity published to the channels does not sell the same unit twice.
 *
 *  - A reservation is keyed by its order line: reserving the same line again changes nothing, except that while it is still only reserved the number of units follows the
 *    line (a unit cancelled gives its place back).
 *  - RESERVED: the order is ours, the location has not been told yet. It counts against what may be published.
 *  - COMMITTED: the location accepted the order. It keeps counting only until the location reports a stock figure taken AFTER that moment,
 *    because from then on its own count already has the units off, and subtracting them again would take them off twice. Unlinking the shipment reopens it.
 *  - RELEASED: the order was cancelled or never went out. It counts for nothing.
 */
class ReservationService
{
    /** @param string|null $salesChannel the channel and account the order came from: which channel promised how many units of a pool is what the division's margin is worked out from */
    public function reserve(string $key, int $locationId, int $productId, string $variantKey, int $quantity, ?string $sku = null, ?int $mappingId = null, ?int $orderId = null, ?string $salesChannel = null, ?int $accountId = null): InventoryReservation
    {
        $existing = InventoryReservation::where('reservation_key', $key)->first();
        if ($existing !== null) {
            if ($existing->state === InventoryReservation::RESERVED && (int) $existing->quantity !== $quantity) {
                $existing->forceFill(['quantity' => $quantity])->save();
            }
            if ($existing->account_id === null && $accountId !== null) {
                $existing->forceFill(['sales_channel' => $salesChannel, 'account_id' => $accountId])->save();
            }

            return $existing;
        }

        try {
            return InventoryReservation::create([
                'reservation_key' => $key,
                'location_id' => $locationId,
                'product_id' => $productId,
                'variant_key' => $variantKey,
                'sku' => $sku,
                'quantity' => $quantity,
                'state' => InventoryReservation::RESERVED,
                'external_order_mapping_id' => $mappingId,
                'order_id' => $orderId,
                'sales_channel' => $salesChannel,
                'account_id' => $accountId,
                'reserved_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return InventoryReservation::where('reservation_key', $key)->firstOrFail();
        }
    }

    /** @param CarbonInterface|null $at when the units left the shelf, when that is known to be earlier than now (an order first heard of after it shipped) */
    public function commit(string $key, ?CarbonInterface $at = null): ?InventoryReservation
    {
        $reservation = InventoryReservation::where('reservation_key', $key)->first();
        if ($reservation === null || $reservation->state !== InventoryReservation::RESERVED) {
            return $reservation;
        }
        $reservation->forceFill(['state' => InventoryReservation::COMMITTED, 'committed_at' => $at ?? now()])->save();

        return $reservation;
    }

    /**
     * An order whose provider shipment was unlinked, or whose internal order went away (the dispatch was reset), is ours to fulfil again: its committed units, and units released only because
     * an internal order counted them, are promised again, from now on. Any other released hold stays released.
     */
    public function reopen(string $key): ?InventoryReservation
    {
        $reservation = InventoryReservation::where('reservation_key', $key)->first();
        $countedByAnInternalOrder = $reservation?->state === InventoryReservation::RELEASED && $reservation->release_reason === ChannelOrderReservations::INTERNAL_ORDER_COUNTS_IT;
        if ($reservation === null || ($reservation->state !== InventoryReservation::COMMITTED && !$countedByAnInternalOrder)) {
            return $reservation;
        }
        $reservation->forceFill(['state' => InventoryReservation::RESERVED, 'committed_at' => null, 'released_at' => null, 'release_reason' => null])->save();

        return $reservation;
    }

    public function release(string $key, string $reason): ?InventoryReservation
    {
        $reservation = InventoryReservation::where('reservation_key', $key)->first();
        if ($reservation === null || $reservation->state === InventoryReservation::RELEASED) {
            return $reservation;
        }
        $reservation->forceFill(['state' => InventoryReservation::RELEASED, 'released_at' => now(), 'release_reason' => mb_substr($reason, 0, 120)])->save();

        return $reservation;
    }

    /**
     * Units promised and not yet reflected in the location's own count.
     *
     * @param CarbonInterface|null $snapshotAt when the location's figure was taken
     * @param string|null $exceptKey a reservation to leave out: an order asking whether the shelf can cover IT must not be counted against itself
     * @param bool $untilApplied the figure is the storefront's own stock, read at this very moment: a count taken "after" a commit then says nothing about whether the units are off
     *                           it, so a committed reservation counts until the storefront's stock has been lowered for it (when that is switched on), however old it is
     */
    public function inFlight(int $locationId, int $productId, string $variantKey, ?CarbonInterface $snapshotAt, ?string $exceptKey = null, bool $untilApplied = false): int
    {
        $others = static fn ($query) => $query->when($exceptKey !== null, static fn ($inner) => $inner->where('reservation_key', '!=', $exceptKey));
        $reserved = (int) InventoryReservation::where('location_id', $locationId)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where('state', InventoryReservation::RESERVED)->tap($others)->sum('quantity');
        $committed = InventoryReservation::where('location_id', $locationId)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where('state', InventoryReservation::COMMITTED)->tap($others)->tap(fn ($query) => $this->notYetInTheFigure($query, $snapshotAt, $untilApplied))
            ->sum('quantity');

        return $reserved + (int) $committed;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<InventoryReservation> $committed */
    private function notYetInTheFigure($committed, ?CarbonInterface $snapshotAt, bool $untilApplied): void
    {
        if (!$untilApplied) {
            $committed->when($snapshotAt !== null, static fn ($query) => $query->where('committed_at', '>', $snapshotAt));

            return;
        }
        $committed->when((bool) config('sales_channels.inventory.apply_channel_orders_to_platform_stock'), static fn ($query) => $query->whereNull('applied_to_platform_at'));
    }

    /**
     * The same units `inFlight` counts, by the channel that promised them (a reservation made before the channel was recorded is under '').
     *
     * @return array<string, int>
     */
    public function inFlightByChannel(int $locationId, int $productId, string $variantKey, ?CarbonInterface $snapshotAt, bool $untilApplied = false): array
    {
        return InventoryReservation::where('location_id', $locationId)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where(fn ($query) => $query->where('state', InventoryReservation::RESERVED)->orWhere(fn ($committed) => $this->notYetInTheFigure($committed->where('state', InventoryReservation::COMMITTED), $snapshotAt, $untilApplied)))
            ->selectRaw('sales_channel, sum(quantity) as units')->groupBy('sales_channel')->orderBy('sales_channel')->pluck('units', 'sales_channel')
            ->map(static fn ($units): int => (int) $units)->all();
    }
}
