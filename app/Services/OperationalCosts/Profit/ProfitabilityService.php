<?php

namespace App\Services\OperationalCosts\Profit;

use App\Enums\OperationalCosts\CostClass;
use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Sales\SaleDocument;
use App\Services\OperationalCosts\Sales\SaleLine;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Generator;
use Illuminate\Support\Collection;

/**
 * Turns a sale and the cost ledger into profit.
 *
 * Revenue is what the customer paid for the goods and shipping, less discounts, less refunds, without the tax in it. A payout from a
 * marketplace is NOT revenue (it is the marketplace handing over money for sales already counted), and fees the marketplace deducted are costs
 * that appear once, in the ledger.
 *
 * Costs are the EFFECTIVE ledger entries of the sale: one per service, so an estimate and the real charge for the same service never both
 * count. Two figures come out: estimated profit (every cost with an amount) and actual profit (only settled costs). A cost nobody could determine is
 * counted nowhere and named in `missingComponents`; a cost still only estimated is named in `pendingActualComponents`. A disputed cost is in neither
 * figure and shown on its own. A cancelled sale has no revenue, no cost of goods, and owes no commission or gateway fee that was only ever an estimate or a gap; what was
 * really charged (a cancellation fee, a commission actually billed) stays.
 *
 * A sale of goods that nobody has costed yet (no cost of goods row at all, not even a "missing" one) is not a sale that costs nothing: its cost of goods is named missing,
 * so its profit is provisional until the cost refresh has looked at it.
 *
 * Everything is totalled in the reporting currency. An amount that cannot be converted is left out and the result says so.
 */
class ProfitabilityService
{
    public function __construct(private readonly FxConverter $fx)
    {
    }

    public function forDocument(SaleDocument $document): ProfitResult
    {
        return $this->compute($document, $this->entriesForDocuments([$document])[$document->cacheKey()] ?? collect());
    }

    /**
     * @param iterable<SaleDocument> $documents
     * @return Generator<int, ProfitResult>
     */
    public function forDocuments(iterable $documents, int $chunkSize = 200): Generator
    {
        $chunk = [];
        foreach ($documents as $document) {
            $chunk[] = $document;
            if (count($chunk) >= $chunkSize) {
                yield from $this->computeChunk($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield from $this->computeChunk($chunk);
        }
    }

    /**
     * @param list<SaleDocument> $chunk
     * @return Generator<int, ProfitResult>
     */
    private function computeChunk(array $chunk): Generator
    {
        $entries = $this->entriesForDocuments($chunk);
        foreach ($chunk as $document) {
            yield $this->compute($document, $entries[$document->cacheKey()] ?? collect());
        }
    }

    /**
     * @param list<SaleDocument> $documents
     * @return array<string, Collection<int, OperationalCostEntry>> entries by document key
     */
    public function entriesForDocuments(array $documents): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map(static fn (SaleDocument $document): ?int => $document->orderId, $documents))));
        $mappingIds = array_values(array_unique(array_filter(array_map(static fn (SaleDocument $document): ?int => $document->mappingId, $documents))));
        if ($orderIds === [] && $mappingIds === []) {
            return [];
        }

        $rows = OperationalCostEntry::query()
            ->where('is_effective', true)
            ->where(static function ($query) use ($orderIds, $mappingIds): void {
                if ($orderIds !== []) {
                    $query->orWhereIn('order_id', $orderIds);
                }
                if ($mappingIds !== []) {
                    $query->orWhereIn('external_order_mapping_id', $mappingIds);
                }
            })
            ->get();

        $byDocument = [];
        foreach ($documents as $document) {
            $byDocument[$document->cacheKey()] = $rows->filter(static fn (OperationalCostEntry $entry): bool => (int) $entry->seller_id === $document->sellerId
                && (($document->orderId !== null && (int) $entry->order_id === $document->orderId)
                    || ($document->mappingId !== null && (int) $entry->external_order_mapping_id === $document->mappingId)))->values();
        }

        return $byDocument;
    }

    /** @param Collection<int, OperationalCostEntry> $entries */
    private function compute(SaleDocument $document, Collection $entries): ProfitResult
    {
        $base = $this->fx->baseCurrency();
        $notes = $document->revenueNotes;
        $revenueTrusted = $document->revenueNotes === [];

        $revenue = $document->isCancelled() ? Money::zero() : $document->netRevenue();
        if ($document->currency !== '' && $document->currency !== $base && !$revenue->isZero()) {
            $converted = $this->fx->convert($revenue, $document->currency, $base);
            if ($converted === null) {
                $notes[] = 'revenue_not_convertible';
                $revenueTrusted = false;
                $revenue = Money::zero();
            } else {
                $revenue = $converted['amount'];
            }
        }

        $buckets = [];
        $estimated = Money::zero();
        $actual = Money::zero();
        $disputed = Money::zero();
        $missing = [];
        $pending = [];

        if (!$document->isCancelled() && $this->hasUnitsToCost($document) && $entries->doesntContain(static fn (OperationalCostEntry $entry): bool => $entry->cost_class === CostClass::ProductCost->value)) {
            $missing[CostComponent::Cogs->value] = CostComponent::Cogs->value;
        }

        foreach ($entries as $entry) {
            if ($document->isCancelled() && $this->isNotOwedByACancelledSale($entry)) {
                continue;
            }
            $bucket = $buckets[$entry->cost_component] ??= new CostBucket($entry->cost_component, $entry->cost_class);

            if ($entry->state === CostState::Missing) {
                $bucket->missing++;
                $missing[$entry->cost_component] = $entry->cost_component;
                continue;
            }

            $amount = $this->inBase($entry, $base);
            if ($amount === null) {
                $bucket->missing++;
                $missing[$entry->cost_component] = $entry->cost_component;
                $notes[] = 'cost_not_convertible:' . $entry->cost_component;
                continue;
            }

            if ($entry->state === CostState::Disputed) {
                $bucket->disputed++;
                $disputed = $disputed->plus($amount);
                continue;
            }

            $estimated = $estimated->plus($amount);
            $bucket->estimated = $bucket->estimated->plus($amount);
            if ($entry->state->isSettled()) {
                $actual = $actual->plus($amount);
                $bucket->actual = $bucket->actual->plus($amount);
            } else {
                $bucket->pendingActual++;
                $pending[$entry->cost_component] = $entry->cost_component;
            }
        }

        return new ProfitResult(
            subjectType: $document->subjectType,
            subjectId: $document->subjectId,
            salesChannel: $document->salesChannel,
            accountId: $document->accountId,
            provider: $document->provider,
            fulfillmentMode: $document->fulfillmentMode,
            state: $document->state,
            currency: $base,
            occurredOn: $document->occurredAt->toDateString(),
            reference: $document->reference,
            revenue: $revenue,
            buckets: $buckets,
            estimatedCost: $estimated,
            actualCost: $actual,
            disputedCost: $disputed,
            missingComponents: array_values($missing),
            pendingActualComponents: array_values($pending),
            revenueTrusted: $revenueTrusted,
            notes: array_values(array_unique($notes)),
        );
    }

    private function hasUnitsToCost(SaleDocument $document): bool
    {
        return array_filter($document->lines, static fn (SaleLine $line): bool => $line->quantity >= 1) !== [];
    }

    private function isNotOwedByACancelledSale(OperationalCostEntry $entry): bool
    {
        if ($entry->cost_class === CostClass::ProductCost->value) {
            return true;
        }

        return in_array($entry->cost_class, [CostClass::MarketplaceFee->value, CostClass::PaymentFee->value], true) && in_array($entry->state, [CostState::Estimated, CostState::Missing], true);
    }

    private function inBase(OperationalCostEntry $entry, string $base): ?Money
    {
        if ($entry->base_amount !== null && $entry->base_currency === $base) {
            return Money::of($entry->base_amount);
        }
        if ($entry->currency === $base) {
            return Money::of($entry->amount);
        }
        $converted = $this->fx->convert(Money::of($entry->amount), $entry->currency, $base);

        return $converted['amount'] ?? null;
    }
}
