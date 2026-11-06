<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Providers\TorodPoint\TorodPointAdapter;
use Illuminate\Support\Facades\DB;

/**
 * Who sets a product's stock: the store (a person types it) or the warehouse it is assigned to (a verified sync writes it)?
 *
 * Torod Point's warehouse is the only physical stock of the products explicitly assigned to it. Once its stock sync has been
 * verified AND deliberately switched on, its figure is the authority for those products and a manual figure must not replace it.
 * Until then, and for every product that is not assigned to it, the storefront's own stock is the only stock there is and nothing
 * here changes it.
 *
 * The warehouse sets the stock of a variant (or of a product without variants) only while its figure is recent: a mapping the
 * warehouse has stopped answering for goes back to manual after a few hours instead of being frozen. A whole-product mapping on a
 * product that has variants is not controlled at all (one figure cannot be spread over variants), and a variant added or renamed
 * after the assignment has no mapping, so its stock stays manual.
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
        $owner = $this->warehouseOf($productId);

        return $owner !== null && $this->controlledVariantKeys($owner, $productId) !== [] ? $owner : null;
    }

    /**
     * The variants whose stock the warehouse sets right now ('' stands for a product without variants).
     *
     * @return list<string>
     */
    public function controlledVariantKeys(ProviderCode $provider, int|string $productId): array
    {
        if (!$this->registry->adapter($provider)->readiness()->canUse('inventory_sync')) {
            return [];
        }

        $fresh = ProviderProductMapping::where('provider', $provider->value)
            ->where('product_id', $productId)
            ->approved()
            ->where('provider_stock_synced_at', '>=', now()->subHours(TorodPointAdapter::STOCK_FRESH_HOURS))
            ->pluck('variant_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();
        $types = array_map('strval', array_column($this->variations(DB::table('products')->where('id', $productId)->value('variation')), 'type'));

        return $types === []
            ? (in_array('', $fresh, true) ? [''] : [])
            : array_values(array_intersect($types, $fresh));
    }

    /**
     * An admin's product form posts a stock figure for the product and for each variant; for the stock the warehouse sets, what the
     * form says is ignored (the next sync would replace a typed figure anyway, but only after the storefront had sold from it). The
     * stock is not written back from what the request read when it began: a checkout that happened meanwhile must not be undone.
     *
     * @param array<string, mixed> $update the columns the form is about to write
     * @param object|array<string, mixed> $product the product as it is stored now
     * @return array<string, mixed>
     */
    public function keepProviderStock(array $update, object|array $product): array
    {
        $productId = ((object) $product)->id;
        if (($update['product_type'] ?? 'physical') !== 'physical' || ($provider = $this->controllingProvider($productId)) === null) {
            return $update;
        }

        $controlled = $this->controlledVariantKeys($provider, $productId);
        $submitted = $this->variations($update['variation'] ?? null);
        if ($submitted === []) {
            return in_array('', $controlled, true) ? array_diff_key($update, ['current_stock' => true]) : $update;
        }

        $storedQuantity = array_column($this->variations(DB::table('products')->where('id', $productId)->value('variation')), 'qty', 'type');
        foreach ($submitted as &$variation) {
            $type = (string) ($variation['type'] ?? '');
            if (in_array($type, $controlled, true) && array_key_exists($type, $storedQuantity)) {
                $variation['qty'] = $storedQuantity[$type];
            }
        }
        unset($variation);

        $update['variation'] = json_encode($submitted);
        $update['current_stock'] = (int) array_sum(array_map(static fn (array $variation): int => (int) ($variation['qty'] ?? 0), $submitted));

        return $update;
    }

    /** The warehouse a product is assigned to, when that warehouse is one whose figure sets the stock. */
    private function warehouseOf(int|string $productId): ?ProviderCode
    {
        if (!FulfillmentSchema::productColumnReady()) {
            return null;
        }

        $owner = ProviderCode::tryFrom((string) DB::table('products')->where('id', $productId)->value('fulfillment_provider'));

        return $owner !== null && in_array($owner, self::WAREHOUSES, true) ? $owner : null;
    }

    /** @return list<array<string, mixed>> */
    private function variations(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
