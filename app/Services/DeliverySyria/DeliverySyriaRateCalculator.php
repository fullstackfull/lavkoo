<?php

namespace App\Services\DeliverySyria;

use App\Models\DeliverySyriaGovernorateRate;

/**
 * Estimates a Delivery Syria shipping charge from the synced governorate price list.
 *
 * The courier prices a shipment as "total order weight (KG) × the destination governorate's per-kilo
 * base cost". This computes that estimate from the locally-cached rate so the admin sees a figure
 * before dispatch. It is only ever an estimate: the courier's create-parcel response returns the
 * authoritative `calculated_delivery_charge`, which is what actually gets billed. Returns null when the
 * governorate has no synced rate, so a caller can fall back rather than show a wrong zero.
 */
class DeliverySyriaRateCalculator
{
    /** Estimated charge for a weight (kg) to a governorate code, or null if that code has no rate. */
    public function estimate(float $weightKg, int $governorateCode): ?float
    {
        $rate = DeliverySyriaGovernorateRate::where('code', $governorateCode)->first();
        if ($rate === null) {
            return null;
        }

        return round(max(0.0, $weightKg) * (float) $rate->base_cost, 2);
    }
}
