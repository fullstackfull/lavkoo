<?php

namespace App\Services\OperationalCosts\Profit;

use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostAllocation;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Collection;

/**
 * Profit per product.
 *
 * A line's revenue is what its customer paid for it, plus its share (by value) of what the order charged for shipping and gave away in
 * discounts. Its costs are the cost of its own goods, plus its allocated share of every charge that covered several items. A charge no one
 * could split stays in a row of its own ("unallocated") so that it is visible instead of vanishing into an average.
 *
 * An amount in a currency with no recorded exchange rate is never multiplied by zero or skipped without a word: it is left out and the row records it in `gaps`
 * (counts and currencies), exactly as ProfitabilityService does for one sale, so a row built on it is incomplete and its profit is not presented as a finished number.
 * A charge in dispute is left out of the cost figures and counted as disputed; a cost nobody determined is counted as missing. The three are different things.
 */
class ProductProfitability
{
    public const UNALLOCATED = 'unallocated';

    public function __construct(
        private readonly ProfitabilityService $profit,
        private readonly FxConverter $fx,
    ) {
    }

    /**
     * @param iterable<SaleDocument> $documents
     * @return array<string, ProfitRow> by product id, with the `unallocated` row last
     */
    public function rows(iterable $documents): array
    {
        $rows = [];
        $base = $this->fx->baseCurrency();

        foreach ($documents as $document) {
            if ($document->isCancelled()) {
                continue;
            }
            $factor = $this->conversionFactor($document->currency, $base);
            $shares = $this->orderLevelShares($document);

            foreach ($document->lines as $index => $line) {
                $row = $this->rowFor($rows, $line->productId);
                $row->units += $line->quantity;
                $revenue = $line->netRevenue()->plus($shares[$index]);
                if ($factor === null) {
                    $row->gaps = $revenue->isZero() ? $row->gaps : $row->gaps->withRevenue($document->currency);

                    continue;
                }
                $row->revenue = $row->revenue->plus($revenue->times($factor));
                $row->convertedRevenues++;
            }

            $entries = $this->profit->entriesForDocuments([$document])[$document->cacheKey()] ?? collect();
            $allocations = OperationalCostAllocation::whereIn('entry_id', $entries->pluck('id'))->get()->groupBy('entry_id');
            foreach ($entries as $entry) {
                $this->addEntry($rows, $entry, $allocations->get($entry->id, collect()), $base);
            }
        }

        $unallocated = $rows[self::UNALLOCATED] ?? null;
        unset($rows[self::UNALLOCATED]);
        ksort($rows, SORT_NATURAL);
        if ($unallocated !== null) {
            $rows[self::UNALLOCATED] = $unallocated;
        }

        return $rows;
    }

    /**
     * @param array<string, ProfitRow> $rows
     * @param Collection<int, OperationalCostAllocation> $allocations
     */
    private function addEntry(array &$rows, OperationalCostEntry $entry, Collection $allocations, string $base): void
    {
        if ($entry->state === CostState::Missing) {
            $this->rowFor($rows, $entry->product_id)->missingCosts++;

            return;
        }

        $factor = $this->conversionFactor($entry->currency, $base, $entry);
        $parts = $allocations->isNotEmpty()
            ? $allocations->map(static fn (OperationalCostAllocation $allocation): array => [$allocation->product_id, Money::of($allocation->amount)])->all()
            : [[$entry->product_id, Money::of($entry->amount)]];

        foreach ($parts as [$productId, $amount]) {
            $row = $this->rowFor($rows, $productId);
            if ($factor === null) {
                $row->gaps = $row->gaps->withCost($entry->currency);
                continue;
            }
            $converted = $amount->times($factor);
            if ($entry->state === CostState::Disputed) {
                $row->disputedCosts++;
                $row->disputedCost = $row->disputedCost->plus($converted);
                continue;
            }
            $row->estimatedCost = $row->estimatedCost->plus($converted);
            if ($entry->state->isSettled()) {
                $row->actualCost = $row->actualCost->plus($converted);
            }
        }
    }

    /** @param array<string, ProfitRow> $rows */
    private function rowFor(array &$rows, int|string|null $productId): ProfitRow
    {
        $key = $productId === null ? self::UNALLOCATED : (string) $productId;

        return $rows[$key] ??= new ProfitRow($key, $key);
    }

    /**
     * What the order charged for shipping less what it gave away, split over the lines by their revenue.
     *
     * @return list<Money>
     */
    private function orderLevelShares(SaleDocument $document): array
    {
        $orderLevel = $document->shippingCharged->minus($document->orderDiscounts);
        if ($document->lines === []) {
            return [];
        }

        return array_values($orderLevel->allocate(array_map(static fn ($line): Money => $line->revenue->abs(), $document->lines)));
    }

    /** @return Money|null the multiplier into the reporting currency, or null when no rate is known */
    private function conversionFactor(string $currency, string $base, ?OperationalCostEntry $entry = null): ?Money
    {
        if ($currency === '' || $currency === $base) {
            return Money::of(1);
        }
        if ($entry?->base_amount !== null && $entry->base_currency === $base && Money::of($entry->amount)->compareTo(0) !== 0) {
            return Money::of($entry->base_amount)->dividedBy($entry->amount, 10);
        }

        return $this->fx->convert(Money::of(1), $currency, $base)['amount'] ?? null;
    }
}
