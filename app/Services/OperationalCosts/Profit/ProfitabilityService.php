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
 * Everything is totalled in the reporting currency. An amount that cannot be converted (no recorded exchange rate) is left out and the result says so: a note, the
 * currency in `gaps`, an unknown revenue instead of a revenue of zero, and a figure that is never complete.
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

        $gaps = new ConversionGaps();
        $revenue = $document->isCancelled() ? Money::zero() : $document->netRevenue();
        if ($document->currency !== '' && $document->currency !== $base && !$revenue->isZero()) {
            $converted = $this->fx->convert($revenue, $document->currency, $base);
            if ($converted === null) {
                $notes[] = 'revenue_not_convertible';
                $revenueTrusted = false;
                $gaps = $gaps->withRevenue($document->currency);
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

        // **An estimate is not counted beside the settled actual of the same service.**
        //
        // The owner's decision two, approved 2026-11-10 (docs/sales-channels/decisions.md §8): the confirmed actual replaces
        // the estimate for the same service WHATEVER CURRENCY it arrives in, and the two are never both counted.
        //
        // The ledger cannot express that by superseding, and must not try. A slot is keyed on the service reference, and a
        // channel net whose currency differs from the order's is filed under a reference of its own (`channel-net:<CUR>`) so
        // that two currencies never add up into one meaningless figure. Retiring across slots at WRITE time was measured and
        // is wrong four ways: it only looks backwards, so a settlement that arrives before the estimate is priced leaves
        // both counted again (34.402174 against an actual of 18.750000); a 1 TRY fee retired a 15.652174 SAR estimate and
        // the order then read as fully settled at 0.6% of its commission; an actual in a currency with no rate retired a
        // convertible estimate and the figure was lost; and a credit on its own retired the estimate, which
        // `InvoiceService` has always guarded against with `isPositive()`.
        //
        // So the two rows both stay in the ledger -- auditable, nothing destroyed, the expectation still there to compare
        // against what arrived, which is the third and fifth conditions -- and the choice of WHICH ONE COUNTS is made here,
        // at read time, where it is order-independent by construction.
        //
        // The test is deliberately narrow. `isSettled()` is Actual or Reconciled only, so a DISPUTED figure never displaces an
        // estimate. And a settled figure counts only if it can be converted to the base
        // currency: with no trustworthy rate the estimate stays the best figure we have and the result still says the cost
        // is not convertible, so nothing is invented and nothing is presented as final (the fourth condition).
        $finallySettled = [];
        $awaitingSettlement = [];
        foreach ($entries as $entry) {
            $service = $this->serviceOf($entry);
            if ($entry->state === CostState::Estimated) {
                $awaitingSettlement[$service] = true;
                continue;
            }
            if ($this->isAProvenSettlement($entry) && $this->inBase($entry, $base) !== null) {
                $finallySettled[$service] = true;
            }
        }

        foreach ($entries as $entry) {
            if ($document->isCancelled() && $this->isNotOwedByACancelledSale($entry)) {
                continue;
            }
            if ($entry->state === CostState::Estimated && isset($finallySettled[$this->serviceOf($entry)])) {
                // The settlement of this service is PROVEN, so the estimate is what it expected and not what it cost. Kept
                // on the record, out of the total.
                $bucket = $buckets[$entry->cost_component] ??= new CostBucket($entry->cost_component, $entry->cost_class);
                $bucket->supersededEstimates++;
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
                $gaps = $gaps->withCost($entry->currency);
                continue;
            }

            if ($entry->state === CostState::Disputed) {
                $bucket->disputed++;
                $disputed = $disputed->plus($amount);
                continue;
            }

            $service = $this->serviceOf($entry);
            if ($entry->state->isSettled() && !isset($finallySettled[$service]) && isset($awaitingSettlement[$service])) {
                // **Fees have arrived; the settlement has not been proven.** So this figure is reported as OBSERVED and the
                // service's estimate stays the expectation beside it. Adding both to one total would charge the cost twice,
                // and letting this one stand in for the estimate is the opposite error: a single 1 TRY fee once cut a
                // 15.652174 SAR commission to 0.093750 and cleared the pending marker, so the order read as fully settled
                // at 0.6% of what it will cost.
                $actual = $actual->plus($amount);
                $bucket->actual = $bucket->actual->plus($amount);
                $bucket->pendingActual++;
                $pending[$entry->cost_component] = $entry->cost_component;
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
            gaps: $gaps,
        );
    }

    /**
     * Whether this entry PROVES the service it belongs to has been settled in full.
     *
     * **`Actual` on its own does not, and that was the defect.** `CostState::Actual` says a real amount arrived from the
     * channel, nothing more: the channel reports fees as they accrue, so one fee is "what we have been charged so far"
     * and never "what this service cost". Treating it as a settlement let a 1 TRY fee displace a 15.652174 SAR estimate.
     *
     * **And `approved_at` is not it either.** `CostLedger::approve()` is reached from the cost-entry screen
     * (CostEntryController.php:92), where a person vouches for THE AMOUNT OF ONE ENTRY. Approving a 1 SAR fee says that
     * fee is right, not that the commission is complete, so letting it close the estimate would be the same mistake in
     * slower motion. The ledger's own `$heldIsFinal` (CostLedger.php:205) accepts either, because it answers a different
     * question -- may this row be overwritten -- and that is not the question here.
     *
     * What proves it is `Reconciled`, and only `ReconciliationService` writes it: at :361 and :184 when an invoice line
     * MATCHED its estimate within tolerance, and at :273 when a person accepted or wrote off the difference. In each case
     * the proof is an invoice with a party and a period, compared against what we expected, rather than a figure arriving
     * on its own. `markReconciled()` carries the date and the signer with it.
     *
     * No amount is compared and no `max(estimated, actual)` is taken: a fee that happens to be large is still just a fee.
     */
    private function isAProvenSettlement(OperationalCostEntry $entry): bool
    {
        return $entry->state === CostState::Reconciled;
    }

    /**
     * The SERVICE an entry belongs to, so that settling one never speaks for another.
     *
     * One component carries several different services of the same subject -- a reversal (`reversal:<id>`), an invoice line
     * (`invoice-item:<id>`), a statement difference -- and a proven settlement of one of those says nothing about the
     * others. Keying on the component alone let a reconciled reversal displace an unrelated commission estimate, which a
     * test caught.
     *
     * **The service reference is not a column.** `CostDraft::$serviceRef` is only an input to `slotKey()`
     * (CostDraft.php:75), so at read time all that survives is the hash. The family is therefore identified by RECOMPUTING
     * the two slot keys it could have -- the contract-priced default (`''`) and this entry's own channel net
     * (`channel-net:<its currency>`) -- with the ledger's own recipe. An entry whose slot is neither is its own service and
     * keys on its slot, so nothing outside the family can displace anything.
     *
     * Those two belong together on purpose: a net whose currency differs from the order's is filed apart so two currencies
     * never add into one figure, but it settles the very service the estimate expected. That is the owner's rule that the
     * actual replaces the estimate REGARDLESS OF CURRENCY, and it is the only pair this treats as one.
     */
    private function serviceOf(OperationalCostEntry $entry): string
    {
        $component = (string) $entry->cost_component;
        $subject = ['slot', (int) $entry->seller_id, (string) $entry->subject_type, (int) $entry->subject_id, $component];

        $family = [
            hash('sha256', implode('|', [...$subject, ''])),
            hash('sha256', implode('|', [...$subject, 'channel-net:' . (string) $entry->currency])),
        ];

        return $component . '|' . (in_array((string) $entry->slot_key, $family, true) ? 'channel-net' : (string) $entry->slot_key);
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
