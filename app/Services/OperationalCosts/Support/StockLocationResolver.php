<?php

namespace App\Services\OperationalCosts\Support;

use App\Enums\Fulfillment\ProviderCode;
use App\Models\InventoryLocation;
use Illuminate\Support\Facades\Schema;

/**
 * Which stock location goods belong to: the one decision the sale reader and the purchase-order layers share, so that a layer is made at the place the sale will take it from.
 *
 * A provider's code names its location (Torod Point, M5AZN); a line no provider ships comes from the own warehouse; an Amazon FBA order comes from the fulfilment centre of its
 * own account. Only active locations count, and when several share a provider the first one made is used. Read once and remembered for the life of the instance.
 */
class StockLocationResolver
{
    private const OWN = 'own_warehouse';

    /** @var array<string, int>|null */
    private ?array $locations = null;

    /** The location a sale line is read from: the provider that ships it, or the own warehouse when none does. */
    public function forProvider(?string $provider): ?int
    {
        return $this->locations()[$provider ?? self::OWN] ?? null;
    }

    /** The fulfilment centre of one Amazon account. */
    public function forAmazonFba(?int $accountId): ?int
    {
        return $accountId === null ? null : ($this->locations()['amazon_fba:' . $accountId] ?? null);
    }

    /**
     * The pool the goods of a product are sold from: Torod Point's warehouse for a product assigned to it, the own warehouse for every other one. This is the assignment on the
     * product, which is also what the order's ownership snapshot is taken from (not the freshness-based control of the stock figure).
     */
    public function forProduct(?string $fulfillmentProvider): ?int
    {
        return $this->forProvider($fulfillmentProvider === ProviderCode::TorodPoint->value ? $fulfillmentProvider : null);
    }

    /** @return array<string, int> location ids by provider code, 'own_warehouse' and 'amazon_fba:{account}' */
    private function locations(): array
    {
        if ($this->locations !== null) {
            return $this->locations;
        }
        $map = [];
        if (Schema::hasTable('inventory_locations')) {
            foreach (InventoryLocation::query()->active()->orderBy('id')->get() as $location) {
                if ($location->provider !== null) {
                    $map[$location->provider] ??= (int) $location->id;
                }
                if ($location->type === InventoryLocation::TYPE_OWN) {
                    $map[self::OWN] ??= (int) $location->id;
                }
                if ($location->type === InventoryLocation::TYPE_AMAZON_FBA && $location->sales_channel_account_id !== null) {
                    $map['amazon_fba:' . $location->sales_channel_account_id] = (int) $location->id;
                }
            }
        }

        return $this->locations = $map;
    }
}
