<?php

namespace App\Services\Fulfillment\Providers\M5azn;

use App\Enums\Fulfillment\MappingStatus;
use App\Enums\Fulfillment\ProviderCode;
use App\Enums\Fulfillment\StockSource;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProviderCatalogItem;
use App\Models\ProviderPriceChange;
use App\Models\ProviderProductMapping;
use App\Services\Fulfillment\Data\ImportResult;
use App\Services\Fulfillment\FieldMapStore;
use App\Services\Fulfillment\InventorySyncService;
use App\Services\Fulfillment\ProductProviderAssignmentService;
use App\Services\Fulfillment\ProviderConnectionManager;
use App\Services\Fulfillment\Support\FieldMap;
use App\Services\Fulfillment\Support\ProviderConfig;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\Fulfillment\Support\RemoteImageFetcher;
use App\Services\Marketplace\PriceChangeRecorder;
use App\Traits\FileManagerTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a staged M5AZN product into a store product — once, and then keeps it in step.
 *
 * Idempotent by construction: the link between a store product and M5AZN is a mapping row keyed on M5AZN's
 * own id. A product that is already mapped is UPDATED (stock and price only — never recreated); a staged
 * item never matches an existing store product by SKU, barcode or name, because a coincidence must not
 * decide ownership. A clash is reported for a human instead.
 *
 * One price source of truth, and no double markup:
 *   store_controlled  our `unit_price` is the selling price. It is set ONCE, from M5AZN's retail price if
 *                     the verified map has one; after that M5AZN's numbers only update the supplier cost.
 *   supplier_retail   our `unit_price` follows M5AZN's retail price, which already contains the margin
 *                     configured on the M5AZN platform. We add nothing on top.
 * Supplier cost is never written to `products.purchase_price` (the platform resets it to 0 on every edit);
 * it lives on the mapping, with a history row for every change.
 *
 * Imported products start UNPUBLISHED unless `publish_on_import` is on: price, category and images get a
 * human look before customers see them.
 */
class M5aznProductImporter
{
    use FileManagerTrait;

    private const IMAGE_LIMIT = 6;

    public function __construct(
        private readonly ProviderConnectionManager $connections,
        private readonly FieldMapStore $fieldMaps,
        private readonly ProviderCurrency $currency,
        private readonly InventorySyncService $inventory,
        private readonly ProductProviderAssignmentService $assignments,
        private readonly RemoteImageFetcher $images,
    ) {
    }

    public function import(int $catalogItemId, ?int $actorId = null, bool $force = false): ImportResult
    {
        $item = ProviderCatalogItem::where('provider', ProviderCode::M5azn->value)->findOrFail($catalogItemId);
        $config = $this->connections->config(ProviderCode::M5azn);
        $stored = $this->fieldMaps->get(ProviderCode::M5azn, M5aznFieldMaps::CATALOG);

        if (!$stored->verified) {
            return ImportResult::blocked('field_map_unverified');
        }
        if (!$config->bool('import_enabled') || $config->get('default_category_id') === null) {
            return ImportResult::blocked('import_not_enabled');
        }

        $map = $stored->map();
        $payload = (array) $item->payload;
        $variants = $this->variants($payload, $map);

        $existing = ProviderProductMapping::where('provider', ProviderCode::M5azn->value)->where('external_id', $item->external_id)->get();
        $result = $existing->isNotEmpty()
            ? $this->update($item, $existing, $variants, $map, $config)
            : $this->create($item, $payload, $variants, $map, $config, $actorId, $force);

        $item->forceFill([
            'status' => $result->succeeded() ? ProviderCatalogItem::STATUS_IMPORTED : ProviderCatalogItem::STATUS_NEEDS_REVIEW,
            'product_id' => $result->productId ?? $item->product_id,
            'note' => $result->reason,
            'imported_at' => $result->succeeded() ? now() : $item->imported_at,
            'imported_hash' => $result->succeeded() ? $item->payload_hash : $item->imported_hash,
        ])->save();

        return $result;
    }

    /**
     * Bring the store up to date with what the seller has selected: products never imported before first, then
     * imported ones whose M5AZN data has CHANGED since they were last imported. Idempotent — running it twice
     * does the same work once — and fair: a long list of unchanged products can never crowd out a newly
     * selected one, because unchanged ones are not touched at all. `$refreshAll` re-applies the settings
     * (price policy, publishing, category…) to every imported product.
     *
     * @return array{imported: int, updated: int, needs_review: int, blocked: int}
     */
    public function importPending(int $limit = 500, ?int $actorId = null, bool $refreshAll = false): array
    {
        $stats = ['imported' => 0, 'updated' => 0, 'needs_review' => 0, 'blocked' => 0];

        $new = ProviderCatalogItem::where('provider', ProviderCode::M5azn->value)
            ->where('status', ProviderCatalogItem::STATUS_NEW)
            ->orderBy('id')->limit($limit)->pluck('id');

        $changed = $new->count() >= $limit ? collect() : ProviderCatalogItem::where('provider', ProviderCode::M5azn->value)
            ->where('status', ProviderCatalogItem::STATUS_IMPORTED)
            ->unless($refreshAll, static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('imported_hash')->orWhereColumn('imported_hash', '!=', 'payload_hash')))
            ->orderBy('id')->limit($limit - $new->count())->pluck('id');

        foreach ($new->merge($changed) as $id) {
            $stats[$this->import((int) $id, $actorId)->outcome]++;
        }

        return $stats;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param list<array<array-key, mixed>> $variants
     */
    private function create(ProviderCatalogItem $item, array $payload, array $variants, FieldMap $map, ProviderConfig $config, ?int $actorId, bool $force): ImportResult
    {
        $name = $item->name ?? $map->string($payload, 'name');
        if ($name === null) {
            return ImportResult::needsReview('name_missing');
        }

        $multi = count($variants) > 1;
        $single = $variants[0] ?? [];
        $sku = $multi ? null : ($this->variantValue($map, $single, 'sku') ?? $item->sku);
        $barcode = $this->cleanBarcode($item->barcode);
        $stock = $multi ? null : $this->intOrNull($this->variantValue($map, $single, 'stock', number: true) ?? $item->stock);
        $retail = $multi ? null : ($this->variantNumber($map, $single, 'retail_price') ?? (float) $item->retail_price ?: null);
        $cost = $multi ? null : ($this->variantNumber($map, $single, 'cost') ?? (float) $item->cost ?: null);

        if (!$force && $this->clashesWithExistingProduct($sku, $barcode)) {
            return ImportResult::needsReview('sku_or_barcode_already_used_by_another_product');
        }
        if ($multi && $this->variantSkuMissing($variants, $map)) {
            return ImportResult::needsReview('variant_sku_missing');
        }

        $storedPrice = $this->price($retail);
        $productId = DB::transaction(function () use ($item, $payload, $variants, $map, $config, $actorId, $name, $multi, $sku, $barcode, $stock, $storedPrice, $retail, $cost): int {
            $product = Product::create($this->attributes($item, $payload, $variants, $map, $config, $actorId, $name, $multi, $sku, $barcode, $stock, $storedPrice));

            foreach ($multi ? $variants : [[]] as $variant) {
                $this->mapping($product->id, $item, $map, $variant, $multi, $multi ? null : $sku, $multi ? null : $barcode, $name, $multi ? null : $cost, $multi ? null : $retail, $multi ? null : $stock);
            }
            $this->recordHistory($product->id, null, $multi ? null : $cost, $multi ? null : $retail, null, $storedPrice, 'import');

            return (int) $product->id;
        });

        // Ownership is set last, by the one service allowed to, once the approved mapping exists.
        $assigned = $this->assignments->assign($productId, ProviderCode::M5azn->value, 'system', $actorId, 'm5azn_import');
        if (!$assigned->ok) {
            return ImportResult::needsReview('assignment_refused:' . $assigned->reasonKey);
        }

        return ImportResult::imported($productId);
    }

    /**
     * An already-mapped product: stock and supplier cost always, selling price only under the
     * `supplier_retail` policy, content only if explicitly enabled. Never a second product.
     *
     * @param \Illuminate\Support\Collection<int, ProviderProductMapping> $mappings
     * @param list<array<array-key, mixed>> $variants
     */
    private function update(ProviderCatalogItem $item, $mappings, array $variants, FieldMap $map, ProviderConfig $config): ImportResult
    {
        $productId = (int) $mappings->first()->product_id;
        $stock = [];
        $policy = $config->string('price_policy', 'store_controlled');

        foreach ($mappings as $mapping) {
            $variant = $this->variantFor($mapping, $variants, $map);
            $key = $mapping->external_variant_id !== '' ? $mapping->external_id . '|' . $mapping->external_variant_id : $mapping->external_id;
            $quantity = $this->variantValue($map, $variant, 'stock', number: true) ?? ($variants === [] ? $item->stock : null);
            if ($quantity !== null) {
                $stock[$key] = (int) $quantity;
            }

            $newCost = $this->variantNumber($map, $variant, 'cost') ?? ($variants === [] ? (float) $item->cost ?: null : null);
            $newRetail = $this->variantNumber($map, $variant, 'retail_price') ?? ($variants === [] ? (float) $item->retail_price ?: null : null);
            $this->syncMappingPrices($mapping, $newCost, $newRetail);

            if ($policy === 'supplier_retail' && $newRetail !== null) {
                $this->followRetailPrice($productId, $mapping, $newRetail, $config);
            }
        }

        if ($stock !== []) {
            $this->inventory->apply(ProviderCode::M5azn, $stock);
        }

        return ImportResult::updated($productId);
    }

    /** @return array<string, mixed> the product row, mirroring what the admin product form stores */
    private function attributes(ProviderCatalogItem $item, array $payload, array $variants, FieldMap $map, ProviderConfig $config, ?int $actorId, string $name, bool $multi, ?string $sku, ?string $barcode, ?int $stock, ?float $storedPrice): array
    {
        $storage = config('filesystems.disks.default') ?? 'public';
        $images = $this->importImages($payload, $map);
        $categoryId = (int) $config->get('default_category_id');
        [$choiceOptions, $attributeIds, $variation, $totalStock, $minPrice] = $multi
            ? $this->variationData($variants, $map, $config)
            : [[], [], [], $stock ?? 0, $storedPrice];

        return [
            'added_by' => 'admin',
            'user_id' => $actorId ?? (int) DB::table('admins')->min('id'),
            'shop_id' => getInHouseShopConfig('id'),
            'name' => $name,
            'code' => $sku ?? 'M5-' . $item->external_id,
            'barcode' => $barcode,
            'slug' => (Str::slug($name, '-') ?: 'product') . '-' . Str::random(6),
            'category_ids' => json_encode([['id' => (string) $categoryId, 'position' => 1]]),
            'category_id' => $categoryId,
            'sub_category_id' => null,
            'sub_sub_category_id' => null,
            'brand_id' => null,
            'unit' => 'pc',
            'product_type' => 'physical',
            'details' => $map->string($payload, 'description') ?? '',
            'colors' => json_encode([]),
            'choice_options' => json_encode($choiceOptions),
            'variation' => json_encode($variation),
            'digital_product_file_types' => [],
            'digital_product_extensions' => [],
            'unit_price' => $minPrice ?? 0,
            'purchase_price' => 0,
            'tax' => 0,
            'tax_type' => 'percent',
            'tax_model' => 'include',
            'discount' => 0,
            'discount_type' => 'flat',
            'attributes' => json_encode($attributeIds),
            'current_stock' => $totalStock,
            'minimum_order_qty' => 1,
            'video_provider' => 'youtube',
            // Unpublished unless the operator opted in, and always unpublished when there is no price.
            'status' => $config->bool('publish_on_import') && ($minPrice ?? 0) > 0 ? 1 : 0,
            'request_status' => 1,
            'shipping_cost' => 0,
            'multiply_qty' => 0,
            'color_image' => json_encode([]),
            'images' => json_encode($images['gallery']),
            'thumbnail' => $images['thumbnail'],
            'thumbnail_storage_type' => $images['thumbnail'] !== null ? $storage : null,
            'meta_title' => $name,
        ];
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array{thumbnail: ?string, gallery: list<string>}
     */
    private function importImages(array $payload, FieldMap $map): array
    {
        $urls = [];
        $thumbnail = $map->string($payload, 'thumbnail');
        if ($thumbnail !== null) {
            $urls[] = $thumbnail;
        }
        $gallery = $map->path('images') === null ? [] : (array) Arr::get($payload, (string) $map->path('images'), []);
        foreach ($gallery as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? $entry['src'] ?? $entry['image'] ?? null) : $entry;
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        $saved = [];
        foreach (array_slice(array_values(array_unique($urls)), 0, self::IMAGE_LIMIT) as $url) {
            $file = $this->images->fetch($url);
            if ($file !== null) {
                $saved[] = $this->upload('product/', 'webp', $file);
            }
        }

        return ['thumbnail' => $saved[0] ?? null, 'gallery' => array_slice($saved, 1)];
    }

    /**
     * @param list<array<array-key, mixed>> $variants
     * @return array{0: list<array<string, mixed>>, 1: list<int>, 2: list<array<string, mixed>>, 3: int, 4: ?float}
     */
    private function variationData(array $variants, FieldMap $map, ProviderConfig $config): array
    {
        $attribute = Attribute::withoutGlobalScope('translate')->firstOrCreate(['name' => $config->string('variant_attribute_name', 'Option')]);
        $options = [];
        $variation = [];
        $total = 0;
        $prices = [];

        foreach ($variants as $variant) {
            $label = $this->variantValue($map, $variant, 'name') ?? $this->variantValue($map, $variant, 'sku');
            $price = $this->price($this->variantNumber($map, $variant, 'retail_price'));
            $quantity = (int) ($this->variantValue($map, $variant, 'stock', number: true) ?? 0);
            $options[] = (string) $label;
            $variation[] = ['type' => str_replace(' ', '', (string) $label), 'price' => $price ?? 0, 'sku' => (string) $this->variantValue($map, $variant, 'sku'), 'qty' => $quantity];
            $total += $quantity;
            if ($price !== null) {
                $prices[] = $price;
            }
        }

        return [
            [['name' => 'choice_' . $attribute->id, 'title' => $attribute->name, 'options' => $options]],
            [(int) $attribute->id],
            $variation,
            $total,
            $prices === [] ? null : min($prices),
        ];
    }

    /** @param array<array-key, mixed> $variant */
    private function mapping(int $productId, ProviderCatalogItem $item, FieldMap $map, array $variant, bool $multi, ?string $sku, ?string $barcode, string $name, ?float $cost, ?float $retail, ?int $stock): void
    {
        $variantSku = $multi ? $this->variantValue($map, $variant, 'sku') : null;
        $label = $multi ? ($this->variantValue($map, $variant, 'name') ?? $variantSku) : null;

        ProviderProductMapping::create([
            'provider' => ProviderCode::M5azn->value,
            'product_id' => $productId,
            'variant_key' => $multi ? str_replace(' ', '', (string) $label) : '',
            'external_id' => $item->external_id,
            'external_variant_id' => $multi ? (string) $variantSku : '',
            'external_sku' => $multi ? $variantSku : $sku,
            'external_barcode' => $barcode,
            'external_name' => $name,
            'status' => MappingStatus::Approved,
            'stock_source' => StockSource::ProviderSync,
            'provider_stock' => $multi ? (int) ($this->variantValue($map, $variant, 'stock', number: true) ?? 0) : $stock,
            'provider_stock_synced_at' => now(),
            'supplier_cost' => $multi ? $this->variantNumber($map, $variant, 'cost') : $cost,
            'supplier_cost_currency' => $this->currency->code(),
            'supplier_retail_price' => $multi ? $this->variantNumber($map, $variant, 'retail_price') : $retail,
            'approved_at' => now(),
            'meta' => ['source' => 'm5azn_import'],
        ]);
    }

    private function syncMappingPrices(ProviderProductMapping $mapping, ?float $cost, ?float $retail): void
    {
        $changes = [];
        if ($cost !== null && (float) $mapping->supplier_cost !== $cost) {
            $changes['supplier_cost'] = [$mapping->supplier_cost, $cost];
        }
        if ($retail !== null && (float) $mapping->supplier_retail_price !== $retail) {
            $changes['supplier_retail_price'] = [$mapping->supplier_retail_price, $retail];
        }

        foreach ($changes as $field => [$old, $new]) {
            ProviderPriceChange::create([
                'provider' => ProviderCode::M5azn->value, 'product_id' => $mapping->product_id, 'mapping_id' => $mapping->id,
                'field' => $field, 'old_value' => $old, 'new_value' => $new, 'source' => 'sync',
            ]);
            $mapping->{$field} = $new;
        }
        if ($changes !== []) {
            $mapping->save();
        }
    }

    /** `supplier_retail` policy only: the store price follows M5AZN's retail price, with nothing added. */
    private function followRetailPrice(int $productId, ProviderProductMapping $mapping, float $retail, ProviderConfig $config): void
    {
        $stored = $this->price($retail);
        $product = Product::withoutGlobalScope('translate')->find($productId);
        if ($stored === null || $product === null) {
            return;
        }

        if ($mapping->variant_key === '') {
            if (abs((float) $product->unit_price - $stored) > 0.000001) {
                $old = (float) $product->unit_price;
                PriceChangeRecorder::attributeTo('import', 'm5azn_retail_sync', fn () => $product->forceFill(['unit_price' => $stored])->save());
                $this->recordHistory($productId, $mapping->id, null, null, $old, $stored, 'sync');
            }

            return;
        }

        $variations = json_decode((string) $product->variation, true) ?: [];
        foreach ($variations as &$variation) {
            if (($variation['type'] ?? null) === $mapping->variant_key) {
                $variation['price'] = $stored;
            }
        }
        unset($variation);
        DB::table('products')->where('id', $productId)->update(['variation' => json_encode($variations), 'updated_at' => now()]);
    }

    private function recordHistory(int $productId, ?int $mappingId, ?float $cost, ?float $retail, ?float $oldPrice, ?float $newPrice, string $source): void
    {
        foreach ([['supplier_cost', null, $cost], ['supplier_retail_price', null, $retail], ['selling_price', $oldPrice, $newPrice]] as [$field, $old, $new]) {
            if ($new !== null) {
                ProviderPriceChange::create([
                    'provider' => ProviderCode::M5azn->value, 'product_id' => $productId, 'mapping_id' => $mappingId,
                    'field' => $field, 'old_value' => $old, 'new_value' => $new, 'source' => $source,
                ]);
            }
        }
    }

    /** M5AZN's retail price in the platform's stored units, or null when there is none or it cannot be converted. */
    private function price(?float $retail): ?float
    {
        return $retail === null || $retail <= 0 ? null : $this->currency->toStored($retail);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<array<array-key, mixed>>
     */
    private function variants(array $payload, FieldMap $map): array
    {
        $path = $map->path('variants');
        $found = $path === null ? null : Arr::get($payload, $path);

        return is_array($found) && $found !== [] && array_is_list($found) && is_array($found[0]) ? $found : [];
    }

    /**
     * @param list<array<array-key, mixed>> $variants
     * @return array<array-key, mixed>
     */
    private function variantFor(ProviderProductMapping $mapping, array $variants, FieldMap $map): array
    {
        if ($variants === []) {
            return [];
        }
        foreach ($variants as $variant) {
            if ($mapping->external_variant_id === '' || $this->variantValue($map, $variant, 'sku') === $mapping->external_variant_id) {
                return $variant;
            }
        }

        return [];
    }

    /** @param array<array-key, mixed> $variant */
    private function variantValue(FieldMap $map, array $variant, string $logical, bool $number = false): string|float|null
    {
        if ($variant === []) {
            return null;
        }
        $path = $map->path('variant_' . $logical);
        $value = $path === null ? null : Arr::get($variant, $path);

        return is_scalar($value) && (string) $value !== '' ? ($number && is_numeric($value) ? (float) $value : (string) $value) : null;
    }

    /** @param array<array-key, mixed> $variant */
    private function variantNumber(FieldMap $map, array $variant, string $logical): ?float
    {
        $value = $this->variantValue($map, $variant, $logical, number: true);

        return is_float($value) ? $value : null;
    }

    /** @param list<array<array-key, mixed>> $variants */
    private function variantSkuMissing(array $variants, FieldMap $map): bool
    {
        foreach ($variants as $variant) {
            if ($this->variantValue($map, $variant, 'sku') === null) {
                return true;
            }
        }

        return false;
    }

    private function clashesWithExistingProduct(?string $sku, ?string $barcode): bool
    {
        return DB::table('products')->where(static function ($query) use ($sku, $barcode): void {
            $any = false;
            if ($sku !== null && $sku !== '') {
                $query->orWhere('code', $sku);
                $any = true;
            }
            if ($barcode !== null && $barcode !== '') {
                $query->orWhere('barcode', $barcode);
                $any = true;
            }
            if (!$any) {
                $query->whereRaw('1 = 0');
            }
        })->exists();
    }

    private function cleanBarcode(?string $barcode): ?string
    {
        $clean = preg_replace('/[^0-9A-Za-z\-]/', '', (string) $barcode);

        return $clean === '' ? null : mb_substr($clean, 0, 64);
    }

    private function intOrNull(string|float|int|null $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
