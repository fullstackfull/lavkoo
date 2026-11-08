<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Services\Fulfillment\Contracts\WritesStorefrontStock;
use Illuminate\Support\Facades\DB;

/**
 * Writes the storefront's own stock for ONE product again, from the figures the provider has already given — without asking the provider anything.
 *
 * Why it is needed. The storefront's number is the provider's figure minus every promise against it: store orders the provider has not taken on yet, and the units open
 * channel orders hold. That subtraction happens when a provider figure ARRIVES, which is a sync cycle away (thirty minutes as it ships). So between two figures a channel can
 * sell three units and the storefront keeps offering the old number. This closes that window: the same arithmetic is done again on the figure already stored.
 *
 * `writeFor()` is the one a change calls straight after its own transaction has committed (a channel hold does), so the number is right before the request returns and
 * nothing waits for a schedule. `refresh()` is the same work for one product, which the outbox's worker uses — and which re-verifies what `writeFor()` did, because the
 * worker runs one at a time and outside any transaction of ours.
 *
 * What it does NOT do:
 *
 *  - IT ASKS NO PROVIDER, AND IT DOES NOT MAKE THE FIGURE LOOK NEWER. `InventorySyncService::rewriteStoreStockFromStoredFigures` re-applies `provider_stock`, the figure the
 *    last sync stored, with the time that sync read it (`provider_stock_synced_at`) — and never stamps it. So a shipment the provider accepted after the figure is still held
 *    back, exactly as on a real sync. Re-stamping would be the worst thing this path could do: a stale figure would read as current and units the provider already holds
 *    would go back on sale. A test pins it.
 *  - IT HAS NO ARITHMETIC OF ITS OWN, AND NO SECOND PATH. The write goes through the ONE writer of the storefront's stock, by the one method made for re-applying a stored
 *    figure: its locking, its refusals (a whole-product figure on a product sold per variant, a variant with no price, a product no longer assigned to the provider) and its
 *    stock-movement line all apply unchanged. A second copy of that arithmetic is the one thing that would make the two numbers disagree.
 *  - IT TOUCHES NO PRODUCT THE PROVIDER DOES NOT OWN. A product fulfilled in-house has no provider figure to re-apply, and its stock is a person's to set.
 *  - IT NEVER TOUCHES Amazon FBA OR Noon FBN STOCK. Those are the channel's own shelves, not a provider's, and they are not what `provider_stock` holds.
 */
class StorefrontStockRefresher implements WritesStorefrontStock
{
    public function __construct(private readonly InventorySyncService $sync)
    {
    }

    /**
     * Writes these products' storefront stock now, for a caller that has just changed what is promised against them (a channel hold being taken or given back).
     *
     * It must be called AFTER that caller's transaction has committed, in a transaction of its own — which is what each write below opens. Called inside the caller's
     * transaction, the reads that count the promises would see that transaction's own snapshot, taken before any other process committed its holds, and the number would come
     * out short by every one of them: six orders of two units each would each write "twenty minus my two". The concurrency tests on a real engine are what showed it.
     *
     * Whatever happens here, the outbox row keeps the job, so the worker (one at a time, outside any transaction of ours) does it again. This is the fast path, not the
     * guarantee.
     *
     * @param list<int> $productIds
     */
    public function writeFor(array $productIds): void
    {
        if (!(bool) config('sales_channels.stock.outbox.rewrite_storefront_stock_inline', true)) {
            return;
        }
        foreach (array_unique($productIds) as $productId) {
            $this->refresh((int) $productId);
        }
    }

    /**
     * @return array{applied: int, refused: int} how many of the product's mappings were written (or found already right), and how many the writer refused
     */
    public function refresh(int $productId): array
    {
        $provider = $this->providerOf($productId);
        if ($provider === null) {
            return ['applied' => 0, 'refused' => 0];
        }

        // ONE writer, one arithmetic. `rewriteStoreStockFromStoredFigures` is InventorySyncService's own: it re-applies each mapping's stored figure with the age that figure
        // really has, and it does NOT stamp — so a stale figure stays stale and a shipment the provider has accepted since is still held back. That property belongs to the
        // writer by construction now, rather than to a flag a caller has to remember to pass.
        $stats = $this->sync->rewriteStoreStockFromStoredFigures($provider, $productId);

        return [
            'applied' => $stats['updated'] + $stats['unchanged'],
            // What the writer could not write: a whole-product figure on a product sold per variant, a variant that is gone, a product no longer assigned to this provider.
            'refused' => $stats['unsupported'] + $stats['not_assigned'],
        ];
    }

    /** The provider the product is assigned to right now, or null when the store fulfils it itself (or the product is gone). */
    private function providerOf(int $productId): ?ProviderCode
    {
        $assigned = DB::table('products')->where('id', $productId)->value('fulfillment_provider');

        return $assigned === null ? null : ProviderCode::tryFrom((string) $assigned);
    }
}
