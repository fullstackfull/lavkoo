<?php

namespace App\Services\Fulfillment;

/**
 * Splits the cash a customer owes on delivery between the parties that will each collect part of it.
 *
 * Never lets two collectors each ask for the whole amount: the parts are proportional to the value each
 * collector ships and ALWAYS sum, to the smallest coin of the currency, to the amount owed. Rounding
 * remainder goes to the collector shipping the most, so the total can never drift. Used only when mixed
 * COD has been explicitly enabled and verified; by default such orders are held instead.
 *
 * **The smallest coin is the currency's, not always a hundredth** (C-15). A riyal is 100 halalas, so a
 * Saudi split works in hundredths and always did; a Kuwaiti dinar is 1,000 fils, a Bahraini dinar 1,000
 * and an Omani rial 1,000 baisa. Splitting a fils amount in hundredths asks the courier for up to five
 * fils more than the order owes and loses a single fils entirely, so the caller says how many decimals
 * the money it is splitting has ({@see ProviderCurrency::decimals()}). Two is the default, which is what
 * every Saudi split passes and what keeps that split what it has always been.
 */
class CodAllocator
{
    /** What a Saudi riyal is split in, and what a caller that names no currency gets. */
    private const HUNDREDTHS = 2;

    /**
     * @param array<string, float|int|string> $valueByCollector shipped value per collector key
     * @param int $decimals how many decimals the currency being split has: 2 for the riyal, 3 for the dinar and the rial
     * @return array<string, float>|null null when the split is impossible (no value to weigh)
     */
    public function allocate(float $amountOwed, array $valueByCollector, int $decimals = self::HUNDREDTHS): ?array
    {
        $coinsInAUnit = 10 ** max(0, $decimals);
        $amountInCoins = (int) round($amountOwed * $coinsInAUnit);
        $weights = array_map(static fn ($value): float => max(0.0, (float) $value), $valueByCollector);
        $total = array_sum($weights);

        if ($amountInCoins <= 0) {
            return array_map(static fn (): float => 0.0, $weights);
        }
        if ($total <= 0.0) {
            return null;
        }

        $coins = [];
        foreach ($weights as $key => $weight) {
            $coins[$key] = (int) floor($amountInCoins * $weight / $total);
        }

        $remainder = $amountInCoins - array_sum($coins);
        if ($remainder > 0) {
            $largest = array_keys($weights, max($weights), true)[0];
            $coins[$largest] += $remainder;
        }

        return array_map(static fn (int $value): float => $value / $coinsInAUnit, $coins);
    }
}
