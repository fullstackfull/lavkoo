<?php

namespace App\Services\Fulfillment;

use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use Illuminate\Support\Facades\DB;

/**
 * Stops the parent order being marked delivered while a provider shipment is still outstanding — or while a
 * line owned by a provider has not even been handed over to it yet.
 *
 * Called by the admin status endpoint only for the "delivered" transition, and by the order-edit funnel. An order with no provider
 * fulfillments (every existing order, and any all-in-house order) is never blocked, so the existing
 * flow is unchanged for it. A provider that cannot report delivery (M5AZN documents no status webhook)
 * is handled by an operator marking that one fulfillment delivered, with a reason, which is audited.
 */
class FulfillmentOrderGuard
{
    /** Statuses of an order that is live again: moving a closed order into one of them would revive it. */
    private const REOPENING_STATUSES = ['pending', 'confirmed', 'processing', 'out_for_delivery'];

    public function __construct(private readonly FulfillmentAggregator $aggregator)
    {
    }

    /**
     * Why this order may not move to `$newStatus`, or null when it may. The one question every place that writes an
     * order's status asks before writing: the admin and vendor panels, the seller and delivery-man apps, and the
     * delivery webhooks.
     */
    public function statusChangeBlockedReason(int $orderId, string $newStatus): ?string
    {
        return match (true) {
            $newStatus === 'delivered' => $this->deliveryBlockedReason($orderId),
            in_array($newStatus, self::REOPENING_STATUSES, true) => $this->reopenBlockedReason($orderId),
            default => null,
        };
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

    /**
     * Why this order's lines may not be edited, or null when they may.
     *
     * The platform lets an admin or vendor edit a pending / confirmed order's lines: quantities change, lines are
     * deleted and new ones added. A provider-owned line is frozen at checkout and is sent to its provider as
     * frozen, so an edit would leave the provider shipping something other than what the order now says - and
     * a provider-owned product ADDED by an edit would never get an ownership snapshot at all, silently becoming an
     * in-house line nobody ships. Until an edit can re-snapshot and amend the provider's order, such orders are
     * cancelled and re-placed instead of edited.
     *
     * @param iterable<array<string, mixed>> $editedLines the lines the edit would save (to catch an added provider product)
     */
    public function editBlockedReason(int $orderId, iterable $editedLines = []): ?string
    {
        if (!FulfillmentSchema::ready()) {
            return null;
        }

        if ($this->hasProviderWork($orderId)) {
            return translate('order_with_provider_items_cannot_be_edited');
        }

        $productIds = collect($editedLines)->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isNotEmpty() && FulfillmentSchema::productColumnReady()
            && DB::table('products')->whereIn('id', $productIds)->whereNotNull('fulfillment_provider')->exists()) {
            return translate('provider_product_cannot_be_added_to_existing_order');
        }

        return null;
    }

    /**
     * A closed order's provider shipments were cancelled and its unrouted provider lines were closed with it. Reopening
     * the order would leave goods nobody ships, so a closed order with provider lines is placed again instead.
     */
    private function reopenBlockedReason(int $orderId): ?string
    {
        if (!FulfillmentSchema::ready() || !$this->hasProviderWork($orderId)) {
            return null;
        }

        $closed = DB::table('orders')->where('id', $orderId)->whereIn('order_status', OrderEligibility::CLOSED_STATUSES)->exists();

        return $closed ? translate('closed_order_with_provider_items_cannot_be_reopened') : null;
    }

    /** True once any line of the order belongs to a provider, whether or not it has been handed over yet. */
    private function hasProviderWork(int $orderId): bool
    {
        return ProviderFulfillmentItem::where('order_id', $orderId)->exists() || ProviderFulfillment::where('order_id', $orderId)->exists();
    }
}
