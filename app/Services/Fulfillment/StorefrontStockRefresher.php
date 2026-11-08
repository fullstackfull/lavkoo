<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the storefront's own stock for ONE product again, from the figures the provider has already given — without asking the provider anything.
 *
 * Why it is needed. The storefront's number is the provider's figure minus every promise against it: store orders the provider has not taken on yet, and the units open
 * channel orders hold. That subtraction happens when a provider figure ARRIVES, which is a sync cycle away (thirty minutes as it ships). So between two figures a channel can
 * sell three units and the storefront keeps offering the old number. This closes that window: as soon as a channel's units are held, the same arithmetic is done again on the
 * figure already stored, and the storefront's number drops at once.
 *
 * What it does NOT do:
 *
 *  - IT ASKS NO PROVIDER. It re-applies `provider_stock`, the figure the last sync stored on the mapping, with the time it was read (`provider_stock_synced_at`) — so a
 *    shipment the provider accepted after that figure is still held back, exactly as it is on a real sync. Nothing here makes a stale figure look fresh.
 *  - IT HAS NO ARITHMETIC OF ITS OWN. The write goes through InventorySyncService, the one writer of the storefront's stock: its locking, its refusals (a whole-product figure
 *    on a product sold per variant, a variant with no price, a product no longer assigned to the provider) and its stock-movement line all apply unchanged. A second copy of
 *    that arithmetic is the one thing that would make the two numbers disagree.
 *  - IT TOUCHES NO PRODUCT THE PROVIDER DOES NOT OWN. A product fulfilled in-house has no provider figure to re-apply, and its stock is a person's to set.
 *  - IT NEVER TOUCHES Amazon FBA OR Noon FBN STOCK. Those are the channel's own shelves, not a provider's, and they are not what `provider_stock` holds.
 */
class StorefrontStockRefresher
{
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
            $stats = $this->sync->applyToAssignedProducts($provider, $figures, $readAt === '' ? null : CarbonImmutable::parse($readAt));
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
