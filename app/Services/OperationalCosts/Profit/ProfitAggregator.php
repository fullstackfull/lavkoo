<?php

namespace App\Services\OperationalCosts\Profit;

/** Adds ProfitResults up by a dimension. Estimated and actual stay two columns, and a gap in any sale (a missing cost, an amount with no exchange rate) marks the whole line provisional. */
class ProfitAggregator
{
    public const BY_CHANNEL = 'channel';
    public const BY_ACCOUNT = 'account';
    public const BY_PROVIDER = 'provider';
    public const BY_MODE = 'mode';
    public const BY_MONTH = 'month';
    public const BY_DAY = 'day';
    public const BY_STATE = 'state';

    public const DIMENSIONS = [self::BY_CHANNEL, self::BY_ACCOUNT, self::BY_PROVIDER, self::BY_MODE, self::BY_MONTH, self::BY_DAY, self::BY_STATE];

    /**
     * @param iterable<ProfitResult> $results
     * @return array<string, ProfitRow> rows by key, in key order
     */
    public function group(iterable $results, string $dimension): array
    {
        $rows = [];
        foreach ($results as $result) {
            $key = $this->keyOf($result, $dimension);
            $row = $rows[$key] ??= new ProfitRow($key, $key);
            $row->orders++;
            $row->revenue = $row->revenue->plus($result->revenue);
            $row->estimatedCost = $row->estimatedCost->plus($result->estimatedCost);
            $row->actualCost = $row->actualCost->plus($result->actualCost);
            $row->disputedCost = $row->disputedCost->plus($result->disputedCost);
            $row->incompleteEstimated += $result->estimatedComplete() ? 0 : 1;
            $row->incompleteActual += $result->actualComplete() ? 0 : 1;
            $row->missingCosts += count($result->missingComponents);
            $row->disputedCosts += array_sum(array_map(static fn (CostBucket $bucket): int => $bucket->disputed, $result->buckets));
            $row->gaps = $row->gaps->plus($result->gaps);
            $row->convertedRevenues += $result->revenueKnown() ? 1 : 0;
        }
        ksort($rows);

        return $rows;
    }

    private function keyOf(ProfitResult $result, string $dimension): string
    {
        return match ($dimension) {
            self::BY_CHANNEL => $result->salesChannel,
            self::BY_ACCOUNT => $result->accountId === null ? $result->salesChannel : $result->salesChannel . '#' . $result->accountId,
            self::BY_PROVIDER => $result->provider ?? 'none',
            self::BY_MODE => $result->fulfillmentMode ?? 'unknown',
            self::BY_MONTH => substr($result->occurredOn, 0, 7),
            self::BY_DAY => $result->occurredOn,
            self::BY_STATE => $result->state,
            default => throw new \InvalidArgumentException("Unknown grouping '{$dimension}'."),
        };
    }
}
