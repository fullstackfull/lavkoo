<?php

namespace App\Services\SalesChannels\Publishing;

use App\Models\InventorySourcePolicy;
use App\Models\SalesChannelProductMapping;

/**
 * Where a listing's quantity comes from and how much of it is held back. The most specific setting wins: the listing, then its product's policy, then the account's.
 * Whether anything is pushed is decided by the listing alone: a policy never switches publishing on for listings a person did not opt in one by one, so a listing
 * synced tomorrow can not start receiving stock because an account-wide default exists.
 */
class PublishPolicyResolver
{
    public function resolve(SalesChannelProductMapping $mapping): PublishPolicy
    {
        $product = $mapping->product_id === null ? null : InventorySourcePolicy::where('account_id', $mapping->account_id)->where('scope', InventorySourcePolicy::SCOPE_PRODUCT)
            ->where('product_id', $mapping->product_id)->where('variant_key', (string) $mapping->variant_key)->where('is_active', true)->first();
        $account = InventorySourcePolicy::where('account_id', $mapping->account_id)->where('scope', InventorySourcePolicy::SCOPE_ACCOUNT)->where('is_active', true)->first();
        $policy = $product ?? $account;
        // A share is a mode and its value together: they come from the most specific level that names a mode.
        $share = collect([$mapping, $product, $account])->first(static fn ($level): bool => $level?->stock_share_mode !== null);
        $priority = collect([$mapping, $product, $account])->first(static fn ($level): bool => $level?->stock_priority !== null);

        return new PublishPolicy(
            stockLocationId: $mapping->stock_location_id ?? $product?->stock_location_id ?? $account?->stock_location_id,
            inventoryPush: (bool) $mapping->inventory_publish,
            pricePush: (bool) $mapping->price_publish,
            priceSource: (string) ($mapping->price_source ?: 'internal'),
            safetyBuffer: (int) ($mapping->safety_buffer ?? $product?->safety_buffer ?? $account?->safety_buffer ?? 0),
            maxQuantity: $mapping->max_publish_quantity ?? $product?->max_publish_quantity ?? $account?->max_publish_quantity,
            shareMode: $share?->stock_share_mode,
            shareValue: (int) $share?->stock_share_value,
            priority: $priority?->stock_priority,
        );
    }
}
