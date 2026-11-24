<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillment;
use Illuminate\Support\Facades\Schema;

/**
 * Why a seller's shipment has not left, in words the seller can read (H1-04, H1-06).
 *
 * A shipment can be held for a reason that is the seller's to act on -- a product with no permission to reach the destination, a regulated
 * product with no licence on file, an address a carrier cannot serve -- and until now nothing told them. `hold_reason` was written by the
 * dispatcher and read only by services and the admin panel, so a seller watching an order that never moved had no way to find out why,
 * and the fourth criterion of H1-04 ("the reason is shown ... on both apps") could not be met by a screen that had nothing to show.
 *
 * What is exposed is the REASON and nothing else. Not `last_error_message`, which can carry a carrier's own API text and was translated in
 * whatever language the queue happened to run in; not the provider, because which carrier the platform uses is not the seller's
 * arrangement. The reason is translated fresh on each read, so the seller reads it in their own language rather than the queue's.
 */
final class SellerHeldShipments
{
    /**
     * The held shipments of one order, oldest first.
     *
     * Ownership is the caller's to establish before asking: this reads an order id and says what is held on it. The seller API asks only
     * after it has satisfied itself that the order is the seller's, the same way the order's own details do.
     *
     * @return list<array{id: int, reason_code: ?string, reason: ?string, held_at: ?string}>
     */
    public function ofOrder(int|string|null $orderId): array
    {
        if ($orderId === null || !Schema::hasTable('provider_fulfillments')) {
            return [];
        }

        return ProviderFulfillment::query()
            ->where('order_id', $orderId)
            ->where('status', FulfillmentStatus::Held->value)
            ->orderBy('id')
            ->get(['id', 'hold_reason', 'updated_at'])
            ->map(static fn (ProviderFulfillment $held): array => [
                'id' => (int) $held->id,
                'reason_code' => $held->hold_reason,
                // Null stays null: a hold with no reason recorded is a hold nobody can explain, and inventing a sentence for it would be
                // worse than saying nothing.
                'reason' => $held->hold_reason === null ? null : translate($held->hold_reason),
                'held_at' => $held->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
