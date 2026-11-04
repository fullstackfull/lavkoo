<?php

namespace App\Services\Marketplace;

use App\Models\ShippingZone;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a destination-based shipping rate (Phase 3, Stage C).
 *
 * The contract that keeps this non-breaking: `rateFor()` returns null when no zone matches a
 * destination, so a caller that has adopted it can fall straight back to the platform's existing flat
 * shipping. Nothing here changes an order — it computes a rate on request.
 *
 * Resolution prefers a country-specific zone over a catch-all, and the lowest `priority` among equally
 * specific zones, so a precise rule always beats a general one.
 */
class ShippingRateService
{
    /**
     * The best zone serving a destination, or null if none (and no catch-all) applies.
     */
    public function resolveZone(?string $country, ?string $region = null): ?ShippingZone
    {
        if (!Schema::hasTable('shipping_zones')) {
            return null;
        }

        $zones = ShippingZone::where('status', ShippingZone::STATUS_ACTIVE)->orderBy('priority')->orderBy('id')->get();

        $specific = null;
        $catchAll = null;

        foreach ($zones as $zone) {
            $isCatchAll = empty($zone->countries);
            if (!$this->zoneMatches($zone, $country, $region, $isCatchAll)) {
                continue;
            }
            if ($isCatchAll) {
                $catchAll ??= $zone;
            } else {
                $specific ??= $zone;
            }
        }

        return $specific ?? $catchAll;
    }

    /**
     * The shipping cost for a destination, weight and order value.
     *
     * @return array{zone: ShippingZone, cost: float, free: bool}|null null when no zone applies
     */
    public function rateFor(?string $country, float $weight = 0.0, float $orderValue = 0.0, ?string $region = null): ?array
    {
        $zone = $this->resolveZone($country, $region);
        if ($zone === null) {
            return null;
        }

        // Free over a threshold, when one is set and the order clears it.
        if ($zone->free_over !== null && $orderValue >= (float) $zone->free_over) {
            return ['zone' => $zone, 'cost' => 0.0, 'free' => true];
        }

        $cost = round((float) $zone->base_cost + (float) $zone->per_kg_cost * max(0.0, $weight), 2);

        return ['zone' => $zone, 'cost' => $cost, 'free' => false];
    }

    /**
     * The checkout shipping cost for a destination, falling back to the platform's existing cost.
     *
     * This is the seam a checkout consults: it returns `$fallbackCost` unchanged when no zone serves the
     * destination, so a store keeps its current shipping until a matching zone exists. A zone with a
     * met free-over threshold resolves to 0 (free) — distinct from "no zone", which returns the
     * fallback. Config-free by design: whether zone shipping is switched on at all is the caller's gate,
     * so this stays a pure function of its inputs and is unit-testable.
     */
    public function checkoutCost(float $fallbackCost, ?string $country, float $weight = 0.0, float $orderValue = 0.0, ?string $region = null): float
    {
        $rate = $this->rateFor($country, $weight, $orderValue, $region);

        return $rate === null ? $fallbackCost : (float) $rate['cost'];
    }

    private function zoneMatches(ShippingZone $zone, ?string $country, ?string $region, bool $isCatchAll): bool
    {
        if (!$isCatchAll && !$zone->matchesCountry($country)) {
            return false;
        }

        // If the zone is region-scoped, the destination region must be in its list.
        $regions = $zone->regions ?? [];
        if ($regions !== []) {
            if ($region === null || $region === '') {
                return false;
            }
            $needle = mb_strtolower(trim($region));
            foreach ($regions as $r) {
                if (mb_strtolower(trim((string) $r)) === $needle) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
