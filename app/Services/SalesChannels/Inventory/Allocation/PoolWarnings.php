<?php

namespace App\Services\SalesChannels\Inventory\Allocation;

use App\Models\InventoryLocation;
use App\Models\InventoryPool;
use App\Models\SalesChannelProductMapping;
use App\Services\Fulfillment\FulfillmentSchema;
use App\Services\SalesChannels\Inventory\PoolKey;
use App\Services\SalesChannels\Publishing\PublishPolicyResolver;
use Illuminate\Support\Facades\DB;

/**
 * What a person should be told after changing how a listing takes stock: the stock it takes is shared with listings of other accounts (linking checks one account only), the
 * shares asked for are more than the pool holds, or the listing takes its stock from somewhere other than where the product's own provider keeps it.
 */
class PoolWarnings
{
    public const SHARED = 'shared';
    public const OVER_SUBSCRIBED = 'over_subscribed';
    public const OTHER_LOCATION = 'other_location';

    public function __construct(
        private readonly PoolMembers $members,
        private readonly PublishPolicyResolver $policies,
    ) {
    }

    /** @return list<array{code: string, detail: string}> */
    public function after(SalesChannelProductMapping $listing): array
    {
        $locationId = $this->policies->resolve($listing)->stockLocationId;
        if ($listing->product_id === null || $locationId === null) {
            return [];
        }
        $key = PoolKey::ofListing($listing, $locationId);
        $location = InventoryLocation::find($locationId);

        return array_values(array_filter([
            $this->shared($listing),
            $this->overSubscribed($key, $location),
            $this->otherLocation($listing, $location),
        ]));
    }

    /** @return array{code: string, detail: string}|null */
    private function shared(SalesChannelProductMapping $listing): ?array
    {
        $others = SalesChannelProductMapping::with('account')->mapped()->where('product_id', $listing->product_id)->where('variant_key', (string) $listing->variant_key)
            ->where('id', '!=', $listing->id)->orderBy('id')->get();

        return $others->isEmpty() ? null : ['code' => self::SHARED, 'detail' => $others->map(static fn (SalesChannelProductMapping $other): string => ($other->account?->name ?? '#' . $other->account_id) . ': ' . $other->listing_key)->implode(', ')];
    }

    /** @return array{code: string, detail: string}|null the fixed shares and the reserve ask for more than the pool holds, or the percentages add up to more than all of it */
    private function overSubscribed(PoolKey $key, ?InventoryLocation $location): ?array
    {
        $members = $this->members->of($key, $location);
        $pool = InventoryPool::where($key->columns())->first();
        $reserveMode = $pool?->storefront_reserve_mode ?? config('sales_channels.stock.allocation.storefront_reserve_mode');
        $reserveValue = (int) ($pool?->storefront_reserve_mode !== null ? $pool->storefront_reserve_value : config('sales_channels.stock.allocation.storefront_reserve_value'));

        $units = ($reserveMode === StorefrontReserve::FIXED ? $reserveValue : 0) + array_sum(array_map(static fn (PoolMember $member): int => $member->policy->shareMode === StockShare::FIXED ? $member->policy->shareValue : 0, $members));
        $percent = ($reserveMode === StorefrontReserve::PERCENT ? $reserveValue : 0) + array_sum(array_map(static fn (PoolMember $member): int => $member->policy->shareMode === StockShare::PERCENT ? $member->policy->shareValue : 0, $members));

        return match (true) {
            $percent > 100 => ['code' => self::OVER_SUBSCRIBED, 'detail' => $percent . '%'],
            $pool?->last_net !== null && $units > $pool->last_net => ['code' => self::OVER_SUBSCRIBED, 'detail' => $units . ' / ' . $pool->last_net],
            default => null,
        };
    }

    /** @return array{code: string, detail: string}|null */
    private function otherLocation(SalesChannelProductMapping $listing, ?InventoryLocation $location): ?array
    {
        if ($location === null || !FulfillmentSchema::productColumnReady()) {
            return null;
        }
        $assigned = DB::table('products')->where('id', $listing->product_id)->value('fulfillment_provider');

        return $assigned !== null && $assigned !== '' && $assigned !== $location->provider ? ['code' => self::OTHER_LOCATION, 'detail' => $location->name . ' / ' . $assigned] : null;
    }
}
