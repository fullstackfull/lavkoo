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
     * Call it straight AFTER the transaction that changed what is promised has committed — never inside it. Inside, the reads that count the promises see that transaction's
     * own snapshot and miss every hold another process committed meanwhile, and the number comes out short by all of them.
     *
     * @param list<int> $productIds
     */
    public function writeFor(array $productIds): void;
}
