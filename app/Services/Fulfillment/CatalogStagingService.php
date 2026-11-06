<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\ProviderCatalogItem;

/**
 * Keeps a faithful, de-duplicated copy of what a provider lists, before anything becomes a store product.
 *
 * One row per (provider, provider item id), however many times it is fetched: re-fetching updates the
 * copy and `last_seen_at`, and never creates a second row. That is what stops "sync products" from
 * creating duplicate products — the import reads from here, and links by the provider's id.
 */
class CatalogStagingService
{
    /**
     * @param list<array{external_id: string, name?: ?string, sku?: ?string, barcode?: ?string, stock?: ?int, cost?: ?float, retail_price?: ?float, payload: array<array-key, mixed>}> $records
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function stage(ProviderCode $provider, string $source, array $records): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($records as $record) {
            $hash = hash('sha256', json_encode($record['payload'], JSON_THROW_ON_ERROR));
            $item = ProviderCatalogItem::where('provider', $provider->value)->where('external_id', $record['external_id'])->first();

            $values = [
                'source' => $source,
                'payload' => $record['payload'],
                'payload_hash' => $hash,
                'name' => $record['name'] ?? null,
                'sku' => $record['sku'] ?? null,
                'barcode' => $record['barcode'] ?? null,
                'stock' => $record['stock'] ?? null,
                'cost' => $record['cost'] ?? null,
                'retail_price' => $record['retail_price'] ?? null,
                'last_seen_at' => now(),
            ];

            if ($item === null) {
                ProviderCatalogItem::create($values + [
                    'provider' => $provider->value,
                    'external_id' => $record['external_id'],
                    'status' => ProviderCatalogItem::STATUS_NEW,
                    'first_seen_at' => now(),
                ]);
                $stats['created']++;
            } elseif ($item->payload_hash !== $hash) {
                $item->forceFill($values)->save();
                $stats['updated']++;
            } else {
                $item->forceFill(['last_seen_at' => now()])->save();
                $stats['unchanged']++;
            }
        }

        return $stats;
    }
}
