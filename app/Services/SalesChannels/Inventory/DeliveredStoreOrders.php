<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\OrderEligibility;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Units on store orders that were accepted after a person typed a pool's figure and have been delivered since.
 *
 * The platform stops counting an order against a provider's stock once it is delivered, because the provider's own count is read again before long and shows the parcel gone. A
 * figure a person TYPED is never read again: it says what the shelf held when it was typed, so every unit that left the shelf afterwards stays off it until a person types a new
 * figure, however far its order has travelled. Only an order that was cancelled, failed or returned gave its units back.
 *
 * Read only. Together with `InventorySyncService::reservedNotYetWithProvider` (the orders not delivered yet) this is every store order that the typed figure does not show.
 */
class DeliveredStoreOrders
{
    /** @param CarbonInterface $typedAt when the figure was typed: an order the provider accepted before that is in the figure, as it is for an order that is still on its way */
    public function unitsSince(ProviderCode $provider, int $productId, string $variantKey, CarbonInterface $typedAt): int
    {
        return (int) DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->leftJoin('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.provider', $provider->value)
            ->where('items.product_id', $productId)
            ->where('items.variant_key', $variantKey)
            ->where('items.status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
            ->whereNotIn('orders.order_status', OrderEligibility::CLOSED_STATUSES)
            ->where(static fn ($delivered) => $delivered->where('orders.order_status', 'delivered')->orWhere('pf.status', FulfillmentStatus::Delivered->value))
            ->where(static fn ($shipment) => $shipment->whereNull('pf.status')->orWhereNotIn('pf.status', [FulfillmentStatus::Cancelled->value, FulfillmentStatus::Returned->value]))
            ->whereRaw('COALESCE(pf.submitted_at, pf.last_received_at, pf.updated_at, orders.created_at) >= ?', [$typedAt])
            ->sum('items.quantity');
    }
}
