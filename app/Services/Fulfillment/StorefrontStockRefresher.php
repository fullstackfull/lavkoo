<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Contracts\WritesStorefrontStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the storefront's own stock for ONE product again, from the figures the provider has already given — without asking the provider anything.
 *
 * Why it is needed. The storefront's number is the provider's figure minus every promise against it: store orders the provider has not taken on yet, and the units open
 * channel orders hold. That subtraction happens when a provider figure ARRIVES, which is a sync cycle away (thirty minutes as it ships). So between two figures a channel can
 * sell three units and the storefront keeps offering the old number. This closes that window: the same arithmetic is done again on the figure already stored.
 *
 * `writeFor()` is the one a change calls INSIDE its own transaction (a channel hold does), so the hold and the number can never disagree and nothing is left for a schedule
 * to catch up on. `refresh()` is the same work for one product, which the outbox's worker uses for the changes a transaction could not cover.
 *
 * What it does NOT do:
 *
 *  - IT ASKS NO PROVIDER, AND IT DOES NOT MAKE THE FIGURE LOOK NEWER. It re-applies `provider_stock`, the figure the last sync stored, with the time that sync read it
 *    (`provider_stock_synced_at`) — and leaves that time exactly as it was. So a shipment the provider accepted after the figure is still held back, exactly as on a real
 *    sync. Re-stamping it would be the worst thing this class could do: a stale figure would read as current, and units the provider already holds would go back on sale.
 *  - IT HAS NO ARITHMETIC OF ITS OWN. The write goes through InventorySyncService, the one writer of the storefront's stock: its locking, its refusals (a whole-product figure
 *    on a product sold per variant, a variant with no price, a product no longer assigned to the provider) and its stock-movement line all apply unchanged. A second copy of
 *    that arithmetic is the one thing that would make the two numbers disagree.
 *  - IT TOUCHES NO PRODUCT THE PROVIDER DOES NOT OWN. A product fulfilled in-house has no provider figure to re-apply, and its stock is a person's to set.
 *  - IT NEVER TOUCHES Amazon FBA OR Noon FBN STOCK. Those are the channel's own shelves, not a provider's, and they are not what `provider_stock` holds.
 */
class StorefrontStockRefresher implements WritesStorefrontStock
{
    /**
     * Writes these products' storefront stock now, for a caller that has just changed what is promised against them (a channel hold being taken or given back).
     *
     * It is meant to be called INSIDE that caller's own transaction, so the hold and the number cannot disagree: either both are written or neither is. With the inline write
     * switched off it writes nothing and says so, and the outbox's worker does it a moment later instead.
     *
     * @param list<int> $productIds
     */
    public function writeFor(array $productIds): bool
    {
        if (!(bool) config('sales_channels.stock.outbox.rewrite_storefront_stock_inline', true)) {
            return false;
        }
        foreach (array_unique($productIds) as $productId) {
            $this->refresh((int) $productId);
        }

        return true;
    }

    public function __construct(private readonly InventorySyncService $sync)
    {
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

        $applied = 0;
        $refused = 0;
        foreach ($this->storedFigures($provider, $productId) as $readAt => $figures) {
            // `stampTheFigure: false` is the whole point: the figure is no newer than it was. Recording it as freshly read would make a stale figure look current, and would
            // make a shipment the provider has accepted since look as though it were already counted — which would put units back on sale that are gone.
            $stats = $this->sync->applyToAssignedProducts($provider, $figures, $readAt === '' ? null : CarbonImmutable::parse($readAt), stampTheFigure: false);
            $applied += $stats['updated'] + $stats['unchanged'];
            $refused += $stats['invalid'] + $stats['unsupported'] + $stats['not_assigned'] + $stats['unmapped'];
        }

        return ['applied' => $applied, 'refused' => $refused];
    }

    /** The provider the product is assigned to right now, or null when the store fulfils it itself (or the product is gone). */
    private function providerOf(int $productId): ?ProviderCode
    {
        $assigned = DB::table('products')->where('id', $productId)->value('fulfillment_provider');

        return $assigned === null ? null : ProviderCode::tryFrom((string) $assigned);
    }

    /**
     * The figures already stored for this product, grouped by the moment each was read — because the writer holds back shipments accepted after the figure, and two variants
     * of one product can have been read at different moments. A mapping with no figure yet is left out: there is nothing to re-apply, and a missing figure is never a zero.
     *
     * @return array<string, array<string, int>> read time (or '' for none) => the writer's keys => figure
     */
    private function storedFigures(ProviderCode $provider, int $productId): array
    {
        $grouped = [];
        $mappings = ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $productId)
            ->where('status', MappingStatus::Approved->value)
            ->whereNotNull('provider_stock')
            ->get(['external_id', 'external_variant_id', 'provider_stock', 'provider_stock_synced_at']);

        foreach ($mappings as $mapping) {
            $key = (string) $mapping->external_variant_id !== ''
                ? $mapping->external_id . '|' . $mapping->external_variant_id
                : (string) $mapping->external_id;
            $readAt = $mapping->provider_stock_synced_at?->toDateTimeString() ?? '';
            $grouped[$readAt][$key] = (int) $mapping->provider_stock;
        }

        return $grouped;
    }
}
