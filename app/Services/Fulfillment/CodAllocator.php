<?php

namespace App\Services\Fulfillment;

/**
 * Splits the cash a customer owes on delivery between the parties that will each collect part of it.
 *
 * Never lets two collectors each ask for the whole amount: the parts are proportional to the value each
 * collector ships and ALWAYS sum, to the cent, to the amount owed. Rounding remainder goes to the
 * collector shipping the most, so the total can never drift. Used only when mixed COD has been
 * explicitly enabled and verified; by default such orders are held instead.
 */
class CodAllocator
{
    /**
     * @param array<string, float|int|string> $valueByCollector shipped value per collector key
     * @return array<string, float>|null null when the split is impossible (no value to weigh)
     */
    public function allocate(float $amountOwed, array $valueByCollector): ?array
    {
        $amountCents = (int) round($amountOwed * 100);
        $weights = array_map(static fn ($value): float => max(0.0, (float) $value), $valueByCollector);
        $total = array_sum($weights);

        if ($amountCents <= 0) {
            return array_map(static fn (): float => 0.0, $weights);
        }
        if ($total <= 0.0) {
            return null;
        }

        $cents = [];
        foreach ($weights as $key => $weight) {
            $cents[$key] = (int) floor($amountCents * $weight / $total);
        }

        $remainder = $amountCents - array_sum($cents);
        if ($remainder > 0) {
            $largest = array_keys($weights, max($weights), true)[0];
            $cents[$largest] += $remainder;
        }

        return array_map(static fn (int $value): float => $value / 100, $cents);
    }
}
