<?php

namespace App\Services\OperationalCosts;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\Data\AllocationTarget;
use App\Services\OperationalCosts\Data\ComputedCost;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use App\Services\OperationalCosts\Support\OperationalCostsSwitch;
use App\Services\SalesChannels\Inventory\LocationStockReader;
use App\Services\SalesChannels\Inventory\StockAvailability;
use Carbon\CarbonImmutable;

/**
 * What it costs to keep stock at a provider's warehouse, one day at a time.
 *
 * For each day it reads the location's stock (only figures the location vouches for), prices the storage services of the provider's contract
 * (a daily rate per unit, a flat monthly fee charged on the month's last day, a per-unit charge) and records one ledger entry per service for the
 * location and day, allocated to products by their units. A day with no stock figure we can trust is a MISSING cost with that reason, never a
 * free day. Amazon FBA storage is not priced here: Amazon reports the real fee. Nothing is accrued while OPERATIONAL_COSTS_ENABLED is off (the default).
 */
class StorageCostService
{
    public function __construct(
        private readonly ServiceCostCalculator $calculator,
        private readonly CostLedger $ledger,
        private readonly AllocationService $allocator,
        private readonly LocationStockReader $stock,
        private readonly FxConverter $fx,
    ) {
    }

    /** @return list<RecordOutcome> */
    public function accrue(InventoryLocation $location, CarbonImmutable $day): array
    {
        if (!OperationalCostsSwitch::enabled() || $location->provider === null || $location->type === InventoryLocation::TYPE_AMAZON_FBA) {
            return [];
        }

        $rows = array_values(array_filter($this->stock->snapshot($location), static fn (array $row): bool => $row['availability']->isPublishable() && $row['product_id'] !== null));
        $units = array_sum(array_map(static fn (array $row): int => (int) $row['availability']->available, $rows));
        $components = [CostComponent::StorageDaily, CostComponent::StoragePerUnit];
        if ($day->isSameDay($day->endOfMonth())) {
            $components[] = CostComponent::StorageMonthly;
        }

        $context = new ServiceContext(
            serviceDate: $day,
            currency: $this->fx->baseCurrency(),
            units: $units,
            days: 1,
        );

        $outcomes = [];
        foreach ($components as $component) {
            $dayContext = $component === CostComponent::StorageMonthly ? $this->monthContext($context, $day) : $context;
            $cost = $this->calculator->price((string) $location->provider, [$component], $dayContext, (int) $location->seller_id)[0];
            if ($cost->kind === ComputedCost::NOT_PRICED || $this->isNotInUse($rows, $cost)) {
                continue;
            }
            $outcome = $this->ledger->record($this->draft($location, $day, $cost, $units, $rows === []));
            if ($outcome->result === RecordOutcome::CREATED && $cost->isPriced()) {
                $this->allocate($outcome->entry, $rows);
            }
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /** A location that holds nothing and that no contract prices has no storage cost to be missing: it is not in use (a day it holds nothing under a contract is still missing). @param list<array<string, mixed>> $rows */
    private function isNotInUse(array $rows, ComputedCost $cost): bool
    {
        return $rows === [] && $cost->isMissing() && $cost->missingReason === 'no_contract';
    }

    private function monthContext(ServiceContext $context, CarbonImmutable $day): ServiceContext
    {
        return new ServiceContext(
            serviceDate: $day,
            currency: $context->currency,
            units: $context->units,
            days: $day->daysInMonth,
        );
    }

    private function draft(InventoryLocation $location, CarbonImmutable $day, ComputedCost $cost, int $units, bool $noStockFigure): CostDraft
    {
        $serviceRef = $cost->component === CostComponent::StorageMonthly ? $day->format('Y-m') : $day->format('Y-m-d');
        $common = [
            'subjectType' => 'location_period',
            'subjectId' => (int) $location->id,
            'component' => $cost->component,
            'periodDate' => $day,
            'serviceRef' => $serviceRef,
            'sellerId' => (int) $location->seller_id,
            'provider' => $location->provider,
            'inventoryLocationId' => (int) $location->id,
            'contractId' => $cost->contract?->id,
            'contractVersion' => $cost->contract?->version,
            'occurredAt' => $day,
            'externalReference' => $serviceRef,
        ];

        if ($cost->isMissing() || $noStockFigure) {
            $reason = $noStockFigure ? 'stock_unknown' : $cost->missingReason;

            return new CostDraft(...$common + [
                'state' => CostState::Missing,
                'amount' => null,
                'currency' => $this->fx->baseCurrency(),
                'source' => 'rule',
                'identity' => 'missing:' . $reason . ':' . ($cost->contract?->id ?? 0),
                'missingReason' => $reason,
            ]);
        }

        $result = $cost->result;
        $fx = $this->fx->convert($result->amount, $result->currency, $this->fx->baseCurrency());

        return new CostDraft(...$common + [
            'state' => CostState::Estimated,
            'amount' => $result->amount,
            'currency' => $result->currency,
            'source' => 'rule',
            'identity' => 'rule:' . $cost->contract?->id . ':' . $cost->contract?->version . ':' . $result->ruleId . ':' . hash('sha256', json_encode([$result->amount->toStorage(), $result->inputs, $units])),
            'ruleId' => $result->ruleId,
            'calcInputs' => $result->inputs + ['units' => $units],
            'quantity' => $result->quantity,
            'unitAmount' => $result->unitAmount,
            'fxRate' => $fx['rate'] ?? null,
            'baseAmount' => $fx['amount'] ?? null,
            'baseCurrency' => $fx === null ? null : $this->fx->baseCurrency(),
            'allocationStatus' => 'pending',
        ]);
    }

    /**
     * The storage of a location is shared out per PRODUCT by units, the same unit the profit report keeps: the variants of one product add up to one share.
     *
     * @param list<array{product_id: int|null, variant_key: string, sku: string|null, availability: StockAvailability}> $rows
     */
    private function allocate(OperationalCostEntry $entry, array $rows): void
    {
        $unitsByProduct = [];
        foreach ($rows as $row) {
            $unitsByProduct[(int) $row['product_id']] = ($unitsByProduct[(int) $row['product_id']] ?? 0) + (int) $row['availability']->available;
        }
        ksort($unitsByProduct);

        if ($unitsByProduct === [] || count($unitsByProduct) > (int) config('operational_costs.storage.max_allocation_rows', 5000)) {
            $entry->forceFill(['allocation_status' => 'unallocated'])->save();

            return;
        }

        $this->allocator->allocate($entry, array_map(static fn (int $productId, int $units): AllocationTarget => new AllocationTarget(
            type: 'product',
            id: $productId,
            productId: $productId,
            variantKey: '',
            quantity: Money::of($units),
            weight: null,
            value: Money::of($units),
        ), array_keys($unitsByProduct), $unitsByProduct), AllocationService::BASIS_QUANTITY);
    }
}
