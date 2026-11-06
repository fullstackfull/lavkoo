<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps cash on delivery from being offered for a basket whose cash could not be collected safely.
 *
 * One order's goods leaving from two collectors (two providers, or a provider and the shop's own delivery) would
 * have two parties each asking the customer for money. That is only allowed when mixed collection is enabled and
 * every provider involved is verified for it; otherwise the order would be accepted at checkout and then sit held,
 * unshipped, until someone resolved it. Better to tell the customer at the payment step, while paying online is
 * still one click away. The router and dispatcher keep their own hold as the last line of defence.
 */
class CheckoutCodGuard
{
    public function __construct(private readonly CodPolicy $policy)
    {
    }

    /**
     * Why cash on delivery cannot be used for these checked cart groups, or null when it can.
     *
     * @param list<int|string> $cartGroupIds the cart groups that will become orders (one order per group)
     */
    public function blockedReason(array $cartGroupIds): ?string
    {
        if ($cartGroupIds === [] || !FulfillmentSchema::ready() || !FulfillmentSchema::productColumnReady()) {
            return null;
        }

        $providersByGroup = DB::table('carts')
            ->join('products', 'products.id', '=', 'carts.product_id')
            ->whereIn('carts.cart_group_id', $cartGroupIds)
            ->where('carts.is_checked', 1)
            ->get(['carts.cart_group_id', 'products.fulfillment_provider'])
            ->groupBy('cart_group_id');

        foreach ($providersByGroup as $rows) {
            if ($this->splitsCash($rows->pluck('fulfillment_provider')->unique()->values())) {
                return translate('cash_on_delivery_unavailable_for_mixed_provider_cart');
            }
        }

        return null;
    }

    /** @param Collection<int, string|null> $providers each distinct provider of one group's items; null is the shop's own delivery */
    private function splitsCash(Collection $providers): bool
    {
        $named = $providers->filter()->values();

        return $named->isNotEmpty()
            && $providers->count() > 1
            && !$this->policy->mixedCodAllowed(providers: $named->all(), hasInHouse: $providers->contains(null));
    }
}
