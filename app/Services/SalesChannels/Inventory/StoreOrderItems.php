<?php

namespace App\Services\SalesChannels\Inventory;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\OrderEligibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The lines of store orders (the platform's own orders, internal orders made for channel orders included) that still stand: the line is not cancelled, its order is not closed
 * (cancelled, failed, returned, refunded) and the provider has not cancelled or sent back its shipment. Read only.
 */
class StoreOrderItems
{
    /**
     * The standing lines of one product (or variant), as a query to narrow further: the order is joined as `orders`, the provider's shipment, when the line has one, as `pf`.
     */
    public function standing(int $productId, string $variantKey): Builder
    {
        return DB::table('provider_fulfillment_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->leftJoin('provider_fulfillments as pf', 'pf.id', '=', 'items.provider_fulfillment_id')
            ->where('items.product_id', $productId)
            ->where('items.variant_key', $variantKey)
            ->where('items.status', '!=', ProviderFulfillmentItem::STATUS_CANCELLED)
            ->whereNotIn('orders.order_status', OrderEligibility::CLOSED_STATUSES)
            ->where(static fn ($shipment) => $shipment->whereNull('pf.status')->orWhereNotIn('pf.status', [FulfillmentStatus::Cancelled->value, FulfillmentStatus::Returned->value]));
    }

    /** Does this order still hold a line of the product (or variant) that the store's side counts? */
    public function holds(int $orderId, int $productId, string $variantKey): bool
    {
        return $this->standing($productId, $variantKey)->where('items.order_id', $orderId)->exists();
    }
}
