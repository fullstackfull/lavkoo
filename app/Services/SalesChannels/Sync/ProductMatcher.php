<?php

namespace App\Services\SalesChannels\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposes which of OUR products a channel listing might be, from identifiers that are supposed to be unique (a seller SKU against a product code or a variant SKU, a barcode against
 * a barcode). It only proposes. A name is never a match, and a proposal is never a link: a person (or an account setting that accepts an exact, unambiguous identifier) makes the link.
 */
class ProductMatcher
{
    /** @return list<array{product_id: int, variant_key: string, reason: string}> */
    public function candidates(?string $sku, ?string $barcode): array
    {
        if (!Schema::hasTable('products')) {
            return [];
        }
        $sku = $sku === null ? null : trim($sku);
        $barcode = $barcode === null ? null : trim($barcode);
        $found = [];

        $query = DB::table('products')->where('added_by', 'admin')->where('product_type', 'physical')->select('id', 'code', 'barcode', 'variation');
        $query->where(static function ($q) use ($sku, $barcode): void {
            if ($sku !== null && $sku !== '') {
                $q->orWhere('code', $sku)->orWhere('barcode', $sku)->orWhere('variation', 'like', '%"sku":' . json_encode($sku) . '%');
            }
            if ($barcode !== null && $barcode !== '') {
                $q->orWhere('barcode', $barcode)->orWhere('variation', 'like', '%"sku":' . json_encode($barcode) . '%');
            }
        });
        if (($sku === null || $sku === '') && ($barcode === null || $barcode === '')) {
            return [];
        }

        foreach ($query->limit(50)->get() as $product) {
            if ($sku !== null && $sku !== '' && (string) $product->code === $sku) {
                $found["{$product->id}|"] = ['product_id' => (int) $product->id, 'variant_key' => '', 'reason' => 'product_code'];
            }
            foreach (array_filter([$sku, $barcode], static fn ($value): bool => $value !== null && $value !== '') as $identifier) {
                if ((string) $product->barcode === $identifier) {
                    $found["{$product->id}|"] ??= ['product_id' => (int) $product->id, 'variant_key' => '', 'reason' => 'barcode'];
                }
            }
            foreach ($this->variations($product->variation) as $variation) {
                $variationSku = (string) ($variation['sku'] ?? '');
                if ($variationSku !== '' && in_array($variationSku, array_filter([$sku, $barcode]), true)) {
                    $key = (string) ($variation['type'] ?? '');
                    $found["{$product->id}|{$key}"] = ['product_id' => (int) $product->id, 'variant_key' => $key, 'reason' => 'variant_sku'];
                }
            }
        }

        return array_values($found);
    }

    /** @return list<array<string, mixed>> */
    private function variations(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }
}
