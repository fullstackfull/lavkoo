<?php

namespace App\Services\Fulfillment\Contracts;

/**
 * Writes the storefront's own stock for some products again, from the figures already stored — so that a change made elsewhere can bring OUR OWN shop's number up to date in
 * the same breath, instead of leaving it for a sweep.
 *
 * Why it is an interface and not the class itself: the caller is in the sales-channel module, and `products.current_stock` belongs to Fulfillment. The module asks; Fulfillment
 * decides how, holds the lock, and keeps its ledger. A second copy of that arithmetic anywhere is the one thing that would make the two numbers disagree.
 *
 * The write is a RECOMPUTE, never a decrement: the provider's figure minus every promise against it. So asking twice takes nothing off twice, and an ask that arrives late is
 * not an ask that arrives wrong.
 */
interface WritesStorefrontStock
{
    /**
     * @param list<int> $productIds
     * @return bool true when the write was attempted for them (so nobody else need do it), false when it is switched off and a later pass must
     */
    public function writeFor(array $productIds): bool;
}
