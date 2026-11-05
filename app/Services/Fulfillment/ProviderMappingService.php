<?php

namespace App\Services\Fulfillment;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderProductMapping;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Links store products (and variants) to a provider's own items — only ever by an explicit approval.
 *
 * A name, a barcode or a SKU that happens to match is a SUGGESTION shown to a person; it never creates an
 * approved mapping and never changes who fulfils a product. Approving a mapping also does not assign the
 * product to the provider: that is a separate, explicit step (ProductProviderAssignmentService).
 */
class ProviderMappingService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function approve(
        ProviderCode $provider,
        int $productId,
        string $variantKey,
        string $externalId,
        ?string $externalVariantId = null,
        ?string $externalSku = null,
        ?string $externalBarcode = null,
        ?string $externalName = null,
        ?int $approvedBy = null,
        string $source = 'manual',
    ): ProviderProductMapping {
        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new InvalidArgumentException('The provider item id is required.');
        }

        return DB::transaction(function () use ($provider, $productId, $variantKey, $externalId, $externalVariantId, $externalSku, $externalBarcode, $externalName, $approvedBy, $source): ProviderProductMapping {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if ($product === null || ($product->added_by ?? 'admin') !== 'admin' || ($product->product_type ?? 'physical') !== 'physical') {
                throw new InvalidArgumentException('Only an admin-owned physical product can be mapped to a provider.');
            }

            $owner = ProviderCode::tryFrom((string) ($product->fulfillment_provider ?? ''));
            if ($owner !== null && $owner !== $provider) {
                throw new InvalidArgumentException('This product is owned by another provider and cannot be mapped here.');
            }

            $externalVariantId ??= '';
            $takenBy = ProviderProductMapping::where('provider', $provider->value)
                ->where('external_id', $externalId)
                ->where('external_variant_id', $externalVariantId)
                ->where(static fn ($query) => $query->where('product_id', '!=', $productId)->orWhere('variant_key', '!=', $variantKey))
                ->where('status', '!=', MappingStatus::Retired->value)
                ->first();
            if ($takenBy !== null) {
                throw new InvalidArgumentException('This provider item is already mapped to another store product.');
            }

            $mapping = ProviderProductMapping::firstOrNew([
                'provider' => $provider->value,
                'product_id' => $productId,
                'variant_key' => $variantKey,
            ]);
            $before = $mapping->exists ? $mapping->only(['external_id', 'external_variant_id', 'status']) : null;

            $mapping->fill([
                'external_id' => $externalId,
                'external_variant_id' => $externalVariantId,
                'external_sku' => $externalSku,
                'external_barcode' => $externalBarcode,
                'external_name' => $externalName,
                'status' => MappingStatus::Approved,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'meta' => array_replace($mapping->meta ?? [], ['source' => $source]),
            ]);
            if ($mapping->stock_source === null) {
                $mapping->stock_source = StockSource::None;
            }
            $mapping->save();

            $this->audit->record(
                action: 'provider_mapping.approved',
                subject: ['type' => 'provider_product_mapping', 'id' => $mapping->id],
                before: $before,
                after: ['provider' => $provider->value, 'product_id' => $productId, 'variant_key' => $variantKey, 'external_id' => $externalId],
            );

            return $mapping;
        });
    }

    public function retire(int $mappingId): void
    {
        $mapping = ProviderProductMapping::findOrFail($mappingId);
        $owner = DB::table('products')->where('id', $mapping->product_id)->value('fulfillment_provider');
        if ($owner === $mapping->provider) {
            throw new InvalidArgumentException('Move the product off this provider before retiring its mapping.');
        }

        $mapping->forceFill(['status' => MappingStatus::Retired])->save();
        $this->audit->record(
            action: 'provider_mapping.retired',
            subject: ['type' => 'provider_product_mapping', 'id' => $mapping->id],
        );
    }

    /** A person types a stock figure for a provider product whose stock cannot be synced; it is labelled manual. */
    public function setManualStock(int $mappingId, int $quantity): void
    {
        ProviderProductMapping::whereKey($mappingId)->update([
            'provider_stock' => max(0, $quantity),
            'provider_stock_synced_at' => now(),
            'stock_source' => StockSource::Manual->value,
        ]);
    }

    /**
     * Store products that LOOK like a staged provider item. Suggestions only: each carries the reasons it
     * was suggested, and a person has to approve it.
     *
     * @return list<array{product_id: int, name: string, code: ?string, barcode: ?string, reasons: list<string>}>
     */
    public function suggestionsFor(ProviderCatalogItem $item, ?string $echoedExternalId = null): array
    {
        $provider = ProviderCode::from($item->provider);
        $query = DB::table('products')
            ->where('added_by', 'admin')
            ->where('product_type', 'physical')
            ->where(static fn ($owned) => $owned->whereNull('fulfillment_provider')->orWhere('fulfillment_provider', $provider->value));

        $query->where(static function ($match) use ($item, $echoedExternalId): void {
            $any = false;
            if ($item->barcode !== null && $item->barcode !== '' && Schema::hasColumn('products', 'barcode')) {
                $match->orWhere('barcode', $item->barcode);
                $any = true;
            }
            if ($item->sku !== null && $item->sku !== '') {
                $match->orWhere('code', $item->sku);
                $any = true;
            }
            if ($echoedExternalId !== null && ctype_digit($echoedExternalId)) {
                $match->orWhere('id', (int) $echoedExternalId);
                $any = true;
            }
            if (!$any) {
                $match->whereRaw('1 = 0');
            }
        });

        $suggestions = [];
        foreach ($query->limit(10)->get(['id', 'name', 'code', 'barcode']) as $product) {
            $reasons = [];
            if ($item->barcode !== null && $item->barcode !== '' && ($product->barcode ?? null) === $item->barcode) {
                $reasons[] = 'barcode_match';
            }
            if ($item->sku !== null && $item->sku !== '' && ($product->code ?? null) === $item->sku) {
                $reasons[] = 'sku_match';
            }
            if ($echoedExternalId !== null && (string) $product->id === $echoedExternalId) {
                $reasons[] = 'external_id_echo';
            }

            $suggestions[] = [
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'code' => $product->code ?? null,
                'barcode' => $product->barcode ?? null,
                'reasons' => $reasons,
            ];
        }

        return $suggestions;
    }
}
