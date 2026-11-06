<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventorySourcePolicy;
use App\Models\SalesChannelProductMapping;

/**
 * Where a listing's quantity comes from and whether anything may be pushed to it. The most specific setting wins: the listing, then its product's policy, then the
 * account's. Pushing is an opt-in at every level; the absence of a setting is "off".
 */
class PublishPolicyResolver
{
    public function resolve(SalesChannelProductMapping $mapping): PublishPolicy
    {
        $product = $mapping->product_id === null ? null : InventorySourcePolicy::where('account_id', $mapping->account_id)->where('scope', InventorySourcePolicy::SCOPE_PRODUCT)
            ->where('product_id', $mapping->product_id)->where('variant_key', (string) $mapping->variant_key)->where('is_active', true)->first();
        $account = InventorySourcePolicy::where('account_id', $mapping->account_id)->where('scope', InventorySourcePolicy::SCOPE_ACCOUNT)->where('is_active', true)->first();
        $policy = $product ?? $account;

        return new PublishPolicy(
            stockLocationId: $mapping->stock_location_id ?? $product?->stock_location_id ?? $account?->stock_location_id,
            inventoryPush: (bool) $mapping->inventory_publish || $policy?->inventory_publish_mode === 'push',
            pricePush: (bool) $mapping->price_publish || $policy?->price_publish_mode === 'push',
            priceSource: (string) ($mapping->price_source ?: 'internal'),
            safetyBuffer: (int) ($mapping->safety_buffer ?? $product?->safety_buffer ?? $account?->safety_buffer ?? 0),
            maxQuantity: $mapping->max_publish_quantity ?? $product?->max_publish_quantity ?? $account?->max_publish_quantity,
        );
    }
}
