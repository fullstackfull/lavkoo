<?php

namespace App\Services\SalesChannels\Inventory;

use App\Models\InventoryLocation;
use App\Models\SalesChannelAccount;

/**
 * Creates and finds the places stock lives. Every location is separate: Torod Point's warehouse, M5AZN's supplier stock and each Amazon
 * account's fulfilment centres never share a balance, and what a location may be shown to is part of the location.
 */
class InventoryLocationService
{
    public const TOROD_CODE = 'torod-warehouse';
    public const M5AZN_CODE = 'm5azn-supplier';
    public const OWN_CODE = 'own-warehouse';

    /** @return list<InventoryLocation> the three locations every installation has; safe to call again */
    public function ensureDefaults(): array
    {
        return [
            $this->ensure(self::TOROD_CODE, 'Torod Point warehouse', InventoryLocation::TYPE_TOROD, 'torod_point', InventoryLocation::TRUTH_PROVIDER, true, ['storefront', 'trendyol', 'amazon_fbm']),
            $this->ensure(self::M5AZN_CODE, 'M5AZN supplier stock', InventoryLocation::TYPE_M5AZN, 'm5azn', InventoryLocation::TRUTH_PROVIDER, true, ['storefront', 'trendyol', 'amazon_fbm']),
            $this->ensure(self::OWN_CODE, 'Own warehouse', InventoryLocation::TYPE_OWN, null, InventoryLocation::TRUTH_MANUAL, true, ['storefront', 'trendyol', 'amazon_fbm']),
        ];
    }

    /** Amazon's fulfilment centres for one account. Their stock is Amazon's: it can be seen and costed, never sold from the storefront and never published as ours. */
    public function ensureFbaLocation(SalesChannelAccount $account, ?string $marketplaceId = null): InventoryLocation
    {
        return $this->ensure(
            code: 'amazon-fba-' . $account->id . ($marketplaceId === null ? '' : '-' . $marketplaceId),
            name: 'Amazon FBA - ' . $account->name,
            type: InventoryLocation::TYPE_AMAZON_FBA,
            provider: null,
            truth: InventoryLocation::TRUTH_CHANNEL,
            storefrontSellable: false,
            publishableChannels: [],
            accountId: (int) $account->id,
            marketplaceId: $marketplaceId,
            sellerId: (int) $account->seller_id,
        );
    }

    /** @return list<int> the FBA locations of one account (one per marketplace) */
    public function fbaLocationIds(SalesChannelAccount $account): array
    {
        return InventoryLocation::where('type', InventoryLocation::TYPE_AMAZON_FBA)->where('sales_channel_account_id', $account->id)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    public function forProvider(string $providerCode): ?InventoryLocation
    {
        return InventoryLocation::where('provider', $providerCode)->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * @param list<string> $publishableChannels
     */
    private function ensure(string $code, string $name, string $type, ?string $provider, string $truth, bool $storefrontSellable, array $publishableChannels, ?int $accountId = null, ?string $marketplaceId = null, int $sellerId = 0): InventoryLocation
    {
        return InventoryLocation::firstOrCreate(['code' => $code], [
            'name' => $name,
            'seller_id' => $sellerId,
            'type' => $type,
            'provider' => $provider,
            'sales_channel_account_id' => $accountId,
            'marketplace_id' => $marketplaceId,
            'source_of_truth' => $truth,
            'storefront_sellable' => $storefrontSellable,
            'publishable_channels' => $publishableChannels,
            'is_active' => true,
        ]);
    }
}
