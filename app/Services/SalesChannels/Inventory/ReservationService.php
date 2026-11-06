<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\InventoryReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Promises units of a location's stock to open orders so that the quantity published to the channels does not sell the same unit twice.
 *
 *  - A reservation is keyed by its order line: reserving the same line again changes nothing.
 *  - RESERVED: the order is ours, the location has not been told yet. It counts against what may be published.
 *  - COMMITTED: the location accepted the order. It keeps counting only until the location reports a stock figure taken AFTER that moment,
 *    because from then on its own count already has the units off, and subtracting them again would take them off twice.
 *  - RELEASED: the order was cancelled or never went out. It counts for nothing.
 */
class ReservationService
{
    public function reserve(string $key, int $locationId, int $productId, string $variantKey, int $quantity, ?string $sku = null, ?int $mappingId = null, ?int $orderId = null): InventoryReservation
    {
        $existing = InventoryReservation::where('reservation_key', $key)->first();
        if ($existing !== null) {
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
                'reserved_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return InventoryReservation::where('reservation_key', $key)->firstOrFail();
        }
    }

    public function commit(string $key): ?InventoryReservation
    {
        $reservation = InventoryReservation::where('reservation_key', $key)->first();
        if ($reservation === null || $reservation->state !== InventoryReservation::RESERVED) {
            return $reservation;
        }
        $reservation->forceFill(['state' => InventoryReservation::COMMITTED, 'committed_at' => now()])->save();

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

    /** Units promised and not yet reflected in the location's own count. @param CarbonInterface|null $snapshotAt when the location's figure was taken */
    public function inFlight(int $locationId, int $productId, string $variantKey, ?CarbonInterface $snapshotAt): int
    {
        $reserved = (int) InventoryReservation::where('location_id', $locationId)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where('state', InventoryReservation::RESERVED)->sum('quantity');
        $committed = InventoryReservation::where('location_id', $locationId)->where('product_id', $productId)->where('variant_key', $variantKey)
            ->where('state', InventoryReservation::COMMITTED)
            ->when($snapshotAt !== null, static fn ($query) => $query->where('committed_at', '>', $snapshotAt))
            ->sum('quantity');

        return $reserved + (int) $committed;
    }
}
