<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillment;

/**
 * Stops the parent order being marked delivered while a provider shipment is still outstanding.
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
        if (!FulfillmentSchema::ready() || !ProviderFulfillment::where('order_id', $orderId)->exists()) {
            return null;
        }

        $summary = $this->aggregator->summarize($orderId);

        return $summary->deliveryBlockedReason === null ? null : translate($summary->deliveryBlockedReason);
    }
}
