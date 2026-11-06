<?php

namespace App\Services\OperationalCosts\Shipments;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\AllocationService;
use App\Services\OperationalCosts\Calculation\ServiceCostCalculator;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\AllocationTarget;
use App\Services\OperationalCosts\Data\ComputedCost;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\Data\ServiceContext;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;

/**
 * Estimates what a shipment costs from the contract of the company that handles it, one ledger entry per service, and splits each
 * shared charge across the shipment's items.
 *
 * Safe to run as often as you like: an estimate is identified by the contract version, the rule and the figures it used, so running it
 * again records nothing new, and running it after a contract changed records a new estimate that replaces the old one. A service the
 * contract does not price is not recorded at all; one it should price but cannot (no matching rule, an unknown weight or city) is
 * recorded as MISSING with the reason; with no contract at all the shipment gets a single missing shipping cost.
 */
class ShipmentCostService
{
    public function __construct(
        private readonly ServiceCostCalculator $calculator,
        private readonly CostLedger $ledger,
        private readonly AllocationService $allocator,
        private readonly FxConverter $fx,
    ) {
    }

    /** @return list<RecordOutcome> */
    public function estimate(ShipmentFacts $facts): array
    {
        $components = $this->components($facts);
        $base = new ServiceContext(
            serviceDate: $facts->serviceDate,
            currency: $facts->currency,
            units: $facts->units(),
            value: $facts->itemsValue(),
            weightKg: $facts->totalWeightKg(),
            city: $facts->city,
            country: $facts->country,
            paymentMethod: $facts->paymentMethod,
            salesChannel: $facts->salesChannel,
        );

        $computed = [];
        foreach ($components as $component) {
            $context = $component === CostComponent::CodCollection ? $base->withValue($facts->codAmount) : $base;
            $computed[$component->value] = $this->calculator->price($facts->providerCode, [$component], $context, $facts->sellerId)[0];
        }

        $computed = $this->withoutNoiseWhenThereIsNoContract($computed);

        $outcomes = [];
        foreach ($computed as $cost) {
            if ($cost->kind === ComputedCost::NOT_PRICED) {
                continue;
            }
            $outcome = $this->ledger->record($this->draft($facts, $cost));
            if ($outcome->result === RecordOutcome::CREATED && $cost->isPriced()) {
                $this->split($facts, $outcome->entry, $cost);
            }
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /** @return list<CostComponent> */
    private function components(ShipmentFacts $facts): array
    {
        $components = [
            CostComponent::Shipping, CostComponent::ExtraWeight, CostComponent::ZoneSurcharge, CostComponent::OrderProcessing,
            CostComponent::Picking, CostComponent::Packing, CostComponent::PackagingMaterial, CostComponent::Stickers,
            CostComponent::Printing, CostComponent::SpecialFee,
        ];
        if ($facts->cashOnDelivery) {
            $components[] = CostComponent::CodCollection;
        }
        if ($facts->cancelledAfterAcceptance) {
            $components[] = CostComponent::CancellationFee;
        }
        if ($facts->returned) {
            $components[] = CostComponent::ReturnFee;
        }

        return $components;
    }

    /**
     * Without any contract every service would be "missing"; the shipment has one unknown cost, not a dozen. It is recorded once, as the
     * shipping cost.
     *
     * @param array<string, ComputedCost> $computed
     * @return array<string, ComputedCost>
     */
    private function withoutNoiseWhenThereIsNoContract(array $computed): array
    {
        $allWithoutContract = $computed !== [] && collect($computed)->every(static fn (ComputedCost $cost): bool => $cost->isMissing() && $cost->missingReason === 'no_contract');

        return $allWithoutContract ? [CostComponent::Shipping->value => $computed[CostComponent::Shipping->value]] : $computed;
    }

    private function draft(ShipmentFacts $facts, ComputedCost $cost): CostDraft
    {
        $common = [
            'subjectType' => $facts->subjectType,
            'subjectId' => $facts->subjectId,
            'component' => $cost->component,
            'periodDate' => $facts->serviceDate,
            'sellerId' => $facts->sellerId,
            'salesChannel' => $facts->salesChannel,
            'accountId' => $facts->accountId,
            'orderId' => $facts->orderId,
            'externalOrderMappingId' => $facts->mappingId,
            'providerFulfillmentId' => $facts->providerFulfillmentId,
            'provider' => $facts->providerCode,
            'inventoryLocationId' => $facts->inventoryLocationId,
            'contractId' => $cost->contract?->id,
            'contractVersion' => $cost->contract?->version,
            'externalReference' => $facts->reference,
            'occurredAt' => $facts->serviceDate,
        ];

        if ($cost->isMissing()) {
            return new CostDraft(...$common + [
                'state' => CostState::Missing,
                'amount' => null,
                'currency' => $facts->currency,
                'source' => 'rule',
                'identity' => 'missing:' . $cost->missingReason . ':' . ($cost->contract?->id ?? 0),
                'missingReason' => $cost->missingReason,
                'allocationStatus' => 'not_applicable',
            ]);
        }

        $result = $cost->result;
        $fx = $this->fx->convert($result->amount, $result->currency, $this->fx->baseCurrency());

        return new CostDraft(...$common + [
            'state' => CostState::Estimated,
            'amount' => $result->amount,
            'currency' => $result->currency,
            'source' => 'rule',
            'identity' => 'rule:' . $cost->contract?->id . ':' . $cost->contract?->version . ':' . $result->ruleId . ':' . hash('sha256', json_encode([$result->amount->toStorage(), $result->inputs])),
            'ruleId' => $result->ruleId,
            'calcInputs' => $result->inputs,
            'quantity' => $result->quantity,
            'unitAmount' => $result->unitAmount,
            'fxRate' => $fx['rate'] ?? null,
            'baseAmount' => $fx['amount'] ?? null,
            'baseCurrency' => $fx === null ? null : $this->fx->baseCurrency(),
            'allocationStatus' => 'pending',
        ]);
    }

    private function split(ShipmentFacts $facts, OperationalCostEntry $entry, ComputedCost $cost): void
    {
        $targets = array_map(static fn (ShipmentItemFact $item): AllocationTarget => new AllocationTarget(
            type: $item->targetType,
            id: $item->targetId,
            productId: $item->productId,
            variantKey: $item->variantKey,
            quantity: Money::of($item->quantity),
            weight: $item->weightKg,
            value: $item->value,
        ), $facts->items);

        $this->allocator->allocate($entry, $targets, $this->basisFor($cost));
    }

    private function basisFor(ComputedCost $cost): string
    {
        $declared = $cost->contract?->settings['allocation_basis'][$cost->component->value] ?? null;

        return $declared
            ?? config('operational_costs.allocation.by_component.' . $cost->component->value)
            ?? config('operational_costs.allocation.default_basis', 'value');
    }
}
