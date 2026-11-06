<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use Illuminate\Support\Facades\DB;

/**
 * Who sets a product's stock: the store (a person types it) or the warehouse it is assigned to (a verified sync writes it)?
 *
 * Torod Point's warehouse is the only physical stock of the products explicitly assigned to it. Once its stock sync has been
 * verified AND deliberately switched on, its figure is the authority for those products and a manual figure must not replace it.
 * Until then, and for every product that is not assigned to it, the storefront's own stock is the only stock there is and nothing
 * here changes it.
 */
class ProviderStockOwnership
{
    /** Providers whose warehouse is the authority for the stock of the products assigned to them (M5AZN's catalogue quantity is its own flow). */
    private const WAREHOUSES = [ProviderCode::TorodPoint];

    public function __construct(private readonly ProviderRegistry $registry)
    {
    }

    /** The warehouse whose verified, switched-on sync sets this product's stock, or null when its stock is the store's own. */
    public function controllingProvider(int|string $productId): ?ProviderCode
    {
        if (!FulfillmentSchema::productColumnReady()) {
            return null;
        }

        $owner = ProviderCode::tryFrom((string) DB::table('products')->where('id', $productId)->value('fulfillment_provider'));
        if ($owner === null || !in_array($owner, self::WAREHOUSES, true)) {
            return null;
        }

        $syncIsLive = $this->registry->adapter($owner)->readiness()->canUse('inventory_sync');

        return $syncIsLive && $this->approvedVariantKeys($owner, $productId) !== [] ? $owner : null;
    }

    /**
     * An admin's product form posts a stock figure for the product and for each variant; for a product the warehouse controls,
     * what the form says is ignored and the figure the warehouse sync last wrote is kept (the next sync would replace
     * a typed one anyway, but only after the storefront had sold from it).
     *
     * @param array<string, mixed> $update the columns the form is about to write
     * @param object|array<string, mixed> $product the product as it is stored now
     * @return array<string, mixed>
     */
    public function keepProviderStock(array $update, object|array $product): array
    {
        $product = (object) $product;
        $provider = $this->controllingProvider($product->id);
        if ($provider === null) {
            return $update;
        }

        $controlledVariants = $this->approvedVariantKeys($provider, $product->id);
        $submitted = $this->variations($update['variation'] ?? null);
        if ($submitted === []) {
            $update['current_stock'] = (int) $product->current_stock;

            return $update;
        }

        $stored = array_column($this->variations($product->variation ?? null), 'qty', 'type');
        foreach ($submitted as &$variation) {
            $type = $variation['type'] ?? null;
            if (in_array($type, $controlledVariants, true) && array_key_exists($type, $stored)) {
                $variation['qty'] = $stored[$type];
            }
        }
        unset($variation);

        $update['variation'] = json_encode($submitted);
        $update['current_stock'] = (int) array_sum(array_map(static fn (array $variation): int => (int) ($variation['qty'] ?? 0), $submitted));

        return $update;
    }

    /** @return list<string> the variant keys ('' for a product without variants) mapped to the provider and approved */
    private function approvedVariantKeys(ProviderCode $provider, int|string $productId): array
    {
        return ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $productId)
            ->approved()
            ->pluck('variant_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function variations(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
