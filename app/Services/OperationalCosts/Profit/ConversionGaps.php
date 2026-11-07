<?php

namespace App\Services\OperationalCosts\Profit;

/**
 * What a profit figure left out because the amount is in a currency with no recorded exchange rate: how many revenue amounts and how many cost entries, and in which currencies.
 *
 * An amount that cannot be converted is never counted as zero and never guessed at: it is left out, and whatever is built on it says so. A sale, a product row, a group of
 * sales and a grand total all carry one of these, so a figure with a gap in it is never shown as a finished number (see ProfitRow, ProfitResult and ProfitTotals).
 */
final readonly class ConversionGaps
{
    /**
     * @param array<string, int> $revenue currency => revenue amounts left out
     * @param array<string, int> $costs currency => cost entries left out
     */
    public function __construct(public array $revenue = [], public array $costs = [])
    {
    }

    public function withRevenue(string $currency): self
    {
        return new self(self::added($this->revenue, $currency), $this->costs);
    }

    public function withCost(string $currency): self
    {
        return new self($this->revenue, self::added($this->costs, $currency));
    }

    public function plus(self $other): self
    {
        return new self(self::merged($this->revenue, $other->revenue), self::merged($this->costs, $other->costs));
    }

    public function isEmpty(): bool
    {
        return $this->revenue === [] && $this->costs === [];
    }

    public function revenueCount(): int
    {
        return array_sum($this->revenue);
    }

    public function costCount(): int
    {
        return array_sum($this->costs);
    }

    /** @return list<string> the currencies that have no rate, revenue and costs together, in alphabetical order */
    public function currencies(): array
    {
        $currencies = array_keys($this->revenue + $this->costs);
        sort($currencies);

        return $currencies;
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private static function added(array $counts, string $currency): array
    {
        $counts[$currency] = ($counts[$currency] ?? 0) + 1;

        return $counts;
    }

    /** @param array<string, int> $first @param array<string, int> $second @return array<string, int> */
    private static function merged(array $first, array $second): array
    {
        foreach ($second as $currency => $count) {
            $first[$currency] = ($first[$currency] ?? 0) + $count;
        }

        return $first;
    }
}
