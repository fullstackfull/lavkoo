<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;

/**
 * Stops the parent order being marked delivered while a provider shipment is still outstanding — or while a
 * line owned by a provider has not even been handed over to it yet.
 *
 * Called by the admin status endpoint only for the "delivered" transition. An order with no provider
 * fulfillments (every existing order, and any all-in-house order) is never blocked, so the existing
 * flow is unchanged for it. A provider that cannot report delivery (M5AZN documents no status webhook)
 * is handled by an operator marking that one fulfillment delivered, with a reason, which is audited.
 */
class FulfillmentOrderGuard
{
    public function __construct(private readonly FulfillmentAggregator $aggregator)
    {
    }

    /** A translated reason the order cannot be marked delivered yet, or null when it may be. */
    public function deliveryBlockedReason(int $orderId): ?string
    {
        if (!FulfillmentSchema::ready() || !$this->hasProviderWork($orderId)) {
            return null;
        }

        $summary = $this->aggregator->summarize($orderId);

        return $summary->deliveryBlockedReason === null ? null : translate($summary->deliveryBlockedReason);
    }

    /** True once any line of the order belongs to a provider, whether or not it has been handed over yet. */
    private function hasProviderWork(int $orderId): bool
    {
        return ProviderFulfillmentItem::where('order_id', $orderId)->exists() || ProviderFulfillment::where('order_id', $orderId)->exists();
    }
}
