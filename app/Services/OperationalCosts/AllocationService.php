<?php

namespace App\Services\OperationalCosts;

use App\Models\OperationalCostAllocation;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Data\AllocationTarget;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Splits a charge that covers several items (one fee for a parcel of three products) across those items.
 *
 * The rules, because money is at stake:
 *  - the shares add up to EXACTLY the charge (largest-remainder rounding at storage precision);
 *  - a per-shipment fee is split, never multiplied by the number of items;
 *  - the basis is declared (quantity, weight, value or equal) and recorded on every share; if the basis cannot be applied because a
 *    measure is unknown (an item without a weight) the split falls back to quantity and says so;
 *  - a charge with no items to take it, or no amount, stays UNALLOCATED and is reported as such until a person looks at it;
 *  - splitting the same charge twice changes nothing.
 */
class AllocationService
{
    public const BASIS_QUANTITY = 'quantity';
    public const BASIS_WEIGHT = 'weight';
    public const BASIS_VALUE = 'value';
    public const BASIS_EQUAL = 'equal';

    /**
     * @param list<AllocationTarget> $targets
     * @return list<OperationalCostAllocation>
     */
    public function allocate(OperationalCostEntry $entry, array $targets, string $basis): array
    {
        $existing = OperationalCostAllocation::where('entry_id', $entry->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing->all();
        }
        if ($entry->amount === null || $targets === []) {
            $entry->forceFill(['allocation_status' => 'unallocated'])->save();

            return [];
        }

        [$appliedBasis, $weights] = $this->weights($targets, $basis);
        $total = Money::sum($weights);
        $shares = Money::of($entry->amount)->allocate($weights);

        return DB::transaction(function () use ($entry, $targets, $appliedBasis, $weights, $shares, $total): array {
            $created = [];
            foreach ($targets as $index => $target) {
                $created[] = OperationalCostAllocation::create([
                    'entry_id' => $entry->id,
                    'target_type' => $target->type,
                    'target_id' => $target->id,
                    'product_id' => $target->productId,
                    'variant_key' => $target->variantKey,
                    'basis' => $appliedBasis,
                    'basis_value' => $weights[$index]->toStorage(),
                    'share' => $total->isZero() ? Money::of(1)->dividedBy(count($targets), 10)->toScale(10) : $weights[$index]->dividedBy($total, 10)->toScale(10),
                    'amount' => $shares[$index]->toStorage(),
                    'currency' => $entry->currency,
                ]);
            }
            $entry->forceFill(['allocation_status' => 'allocated'])->save();

            return $created;
        });
    }

    /**
     * @param list<AllocationTarget> $targets
     * @return array{0: string, 1: list<Money>}
     */
    private function weights(array $targets, string $basis): array
    {
        if ($basis === self::BASIS_WEIGHT && in_array(null, array_map(static fn (AllocationTarget $target): ?Money => $target->weight === null ? null : $target->weight->times($target->quantity), $targets), true)) {
            $basis = self::BASIS_QUANTITY;
        }

        $weights = array_map(static fn (AllocationTarget $target): Money => match ($basis) {
            self::BASIS_WEIGHT => $target->weight->times($target->quantity),
            self::BASIS_VALUE => $target->value,
            self::BASIS_EQUAL => Money::of(1),
            default => $target->quantity,
        }, $targets);

        // A basis that adds up to nothing (every item free, every weight zero) cannot split anything: share evenly and say so.
        if (Money::sum($weights)->isZero()) {
            return [self::BASIS_EQUAL, array_map(static fn (): Money => Money::of(1), $targets)];
        }

        return [$basis === self::BASIS_WEIGHT || $basis === self::BASIS_VALUE || $basis === self::BASIS_EQUAL ? $basis : self::BASIS_QUANTITY, array_values($weights)];
    }
}
