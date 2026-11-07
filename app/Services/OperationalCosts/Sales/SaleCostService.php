<?php

namespace App\Services\OperationalCosts\Sales;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostConsumption;
use App\Models\OperationalCostEntry;
use App\Models\ProviderFulfillmentItem;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Data\CostDraft;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Data\RecordOutcome;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * What the goods of a sale cost us, in the ledger.
 *
 *  1. ESTIMATE, as soon as there is a sale: a line from our own stock is costed at the average of the open cost layers (or the product's own
 *     purchase price, if that is all there is); a dropshipped line at the supplier cost frozen when the order was placed. No figure at
 *     all is a MISSING cost, not zero.
 *  2. RECOGNISE, once the sale has shipped or been delivered (the channel's rule in `config/operational_costs.php`): the line takes its units from
 *     the cost layers first in, first out, and the cost actually taken becomes the ACTUAL cost of the line, replacing the estimate. If the
 *     layers cannot cover every unit, the line is costed as far as it can be and the rest is reported as missing (or estimated, when an
 *     estimate exists) until the purchase invoice that covers it is recorded.
 *  3. A sale that is cancelled before it ships takes nothing from the layers; one already recognised puts its units back. The same goes for one line of an order whose
 *     units were all cancelled while the rest of the order stands: its units go back and what was recorded for it stops counting.
 *
 * Moving stock to another location is not a sale and never reaches this class.
 */
class SaleCostService
{
    public function __construct(
        private readonly InventoryCostLayerService $layers,
        private readonly CostLedger $ledger,
        private readonly FxConverter $fx,
    ) {
    }

    /** @return list<RecordOutcome> */
    public function refresh(SaleDocument $document): array
    {
        if ($document->isCancelled()) {
            $this->releaseCostLayers($document);

            return [];
        }

        $recognition = (string) config('operational_costs.cogs_recognition.' . $document->salesChannel, 'delivered');
        $outcomes = [];
        foreach ($document->lines as $line) {
            if ($line->quantity < 1) {
                $cancelled = $this->cancelLine($document, $line);
                if ($cancelled !== null) {
                    $outcomes[] = $cancelled;
                }
                continue;
            }
            $outcomes[] = $line->productId === null
                ? $this->missing($document, $line, CostComponent::Cogs, 'product_not_linked')
                : match ($line->costBasis) {
                    SaleLine::BASIS_SUPPLIER => $this->supplierCost($document, $line),
                    SaleLine::BASIS_OWNED_STOCK => $document->isRecognised($recognition) ? $this->recognise($document, $line) : $this->estimate($document, $line),
                    default => $this->missing($document, $line, CostComponent::Cogs, 'product_cost_unknown'),
                };
        }

        return $outcomes;
    }

    /** What a line cost stops counting once all its units are cancelled: its units go back to the layers and its slot holds a settled zero. Nothing is recorded for a line that never had a cost. */
    private function cancelLine(SaleDocument $document, SaleLine $line): ?RecordOutcome
    {
        $this->layers->reverse($line->targetType, $line->targetId);
        $recorded = OperationalCostEntry::where('subject_type', $line->targetType)->where('subject_id', $line->targetId)
            ->whereIn('cost_component', [CostComponent::Cogs->value, CostComponent::SupplierCost->value])->where('is_effective', true)->first();
        if ($recorded === null) {
            return null;
        }

        return $this->ledger->record($this->draft(
            $document, $line, $recorded->component() ?? CostComponent::Cogs, CostState::Actual, Money::zero(), $this->fx->baseCurrency(),
            identity: 'line-cancelled', source: 'layer', inputs: ['quantity' => 0],
        ));
    }

    /** Takes back the units a recognised sale took, when that sale is cancelled. */
    public function releaseCostLayers(SaleDocument $document): int
    {
        $restored = 0;
        foreach ($document->lines as $line) {
            $restored += $this->layers->reverse($line->targetType, $line->targetId);
        }

        return $restored;
    }

    /**
     * Units of a recognised line that come back into stock (a return that is put on the shelf again). They re-enter at the cost they left
     * at, as a new layer, and that part of the line's cost is reversed in the ledger. Repeating a reference changes nothing.
     */
    public function restock(SaleDocument $document, SaleLine $line, int $quantity, string $reference): ?RecordOutcome
    {
        if ($quantity < 1 || $line->inventoryLocationId === null || $line->productId === null) {
            return null;
        }
        $consumed = InventoryCostConsumption::where('consumer_type', $line->targetType)->where('consumer_id', $line->targetId)->whereNull('reversed_at')->get();
        $units = (int) $consumed->sum('quantity');
        if ($units < 1) {
            return null;
        }
        if ($quantity > $units) {
            throw new InvalidArgumentException("Only {$units} units of this line were taken from stock; {$quantity} cannot come back.");
        }

        $perUnit = Money::sum($consumed->map(static fn (InventoryCostConsumption $row): Money => Money::of($row->total_cost)))->dividedBy($units);
        $this->layers->receive(new LayerReceipt(
            locationId: $line->inventoryLocationId,
            productId: $line->productId,
            quantity: $quantity,
            unitCost: $perUnit,
            currency: $this->fx->baseCurrency(),
            receivedAt: now(),
            sourceType: 'return',
            identity: 'restock:' . $reference . ':' . $line->targetType . ':' . $line->targetId,
            variantKey: $line->variantKey,
            sku: $line->sku,
            sourceRef: $reference,
            sellerId: $document->sellerId,
        ));

        return $this->ledger->record(new CostDraft(
            subjectType: $line->targetType,
            subjectId: $line->targetId,
            component: CostComponent::Cogs,
            state: CostState::Actual,
            amount: $perUnit->times($quantity)->negated(),
            currency: $this->fx->baseCurrency(),
            periodDate: $document->occurredAt,
            source: 'layer',
            identity: 'restock:' . $reference,
            serviceRef: 'restock:' . $reference,
            sellerId: $document->sellerId,
            salesChannel: $document->salesChannel,
            accountId: $document->accountId,
            orderId: $document->orderId,
            externalOrderMappingId: $document->mappingId,
            inventoryLocationId: $line->inventoryLocationId,
            productId: $line->productId,
            variantKey: $line->variantKey,
            sku: $line->sku,
            note: 'Units returned to stock: ' . $reference,
            occurredAt: now(),
        ));
    }

    private function estimate(SaleDocument $document, SaleLine $line): RecordOutcome
    {
        $estimate = $line->inventoryLocationId === null ? null : $this->layers->estimatedUnitCost($line->inventoryLocationId, (int) $line->productId, $line->variantKey, $document->sellerId);
        if ($estimate === null) {
            return $this->missing($document, $line, CostComponent::Cogs, 'product_cost_unknown');
        }

        $amount = $estimate['cost']->times($line->quantity);

        return $this->ledger->record($this->draft(
            $document, $line, CostComponent::Cogs, CostState::Estimated, $amount, $estimate['currency'],
            identity: 'estimate:' . $estimate['basis'] . ':' . $estimate['cost']->toStorage() . ':' . $line->quantity,
            source: 'layer',
            inputs: ['basis' => $estimate['basis'], 'unit_cost' => $estimate['cost']->toStorage(), 'quantity' => $line->quantity],
        ));
    }

    private function recognise(SaleDocument $document, SaleLine $line): RecordOutcome
    {
        if ($line->inventoryLocationId === null) {
            return $this->missing($document, $line, CostComponent::Cogs, 'inventory_location_unknown');
        }

        $taken = $this->layers->consume($line->targetType, $line->targetId, $line->inventoryLocationId, (int) $line->productId, $line->variantKey, $line->quantity, $document->occurredAt, $document->sellerId);

        if ($taken->isComplete()) {
            return $this->ledger->record($this->draft(
                $document, $line, CostComponent::Cogs, CostState::Actual, $taken->totalCost, $taken->currency,
                identity: 'layers:' . hash('sha256', json_encode($taken->pieces)),
                source: 'layer',
                inputs: ['pieces' => $taken->pieces, 'quantity' => $line->quantity],
            ));
        }

        // Some units have no cost layer yet. Cost what is known; the rest is an ESTIMATE at the cost of the open layers, or, when none are
        // left, at the cost of the units just taken. With nothing to go on at all the cost is missing.
        $estimate = $this->layers->estimatedUnitCost($line->inventoryLocationId, (int) $line->productId, $line->variantKey, $document->sellerId);
        if ($estimate === null && $taken->covered > 0) {
            $estimate = ['cost' => $taken->totalCost->dividedBy($taken->covered), 'currency' => $taken->currency, 'basis' => 'consumed_layers_average'];
        }
        if ($estimate === null) {
            return $this->missing($document, $line, CostComponent::Cogs, 'cost_layers_short', ['covered' => 0, 'shortfall' => $taken->shortfall()]);
        }

        $amount = $taken->totalCost->plus($estimate['cost']->times($taken->shortfall()));

        return $this->ledger->record($this->draft(
            $document, $line, CostComponent::Cogs, CostState::Estimated, $amount, $taken->currency,
            identity: 'partial:' . hash('sha256', json_encode([$taken->pieces, $estimate['cost']->toStorage()])),
            source: 'layer',
            inputs: ['covered' => $taken->covered, 'shortfall' => $taken->shortfall(), 'pieces' => $taken->pieces, 'estimate_unit_cost' => $estimate['cost']->toStorage(), 'estimate_basis' => $estimate['basis']],
        ));
    }

    private function supplierCost(SaleDocument $document, SaleLine $line): RecordOutcome
    {
        $item = $line->targetType === 'order_line' && Schema::hasTable('provider_fulfillment_items')
            ? ProviderFulfillmentItem::where('order_detail_id', $line->targetId)->first()
            : null;
        $unit = $item?->unit_cost;
        if ($unit === null) {
            return $this->missing($document, $line, CostComponent::SupplierCost, 'supplier_cost_unknown');
        }

        $currency = (string) ($item->snapshot['mapping']['supplier_cost_currency'] ?? $document->currency);
        $amount = Money::of($unit)->times($line->quantity);

        return $this->ledger->record($this->draft(
            $document, $line, CostComponent::SupplierCost, CostState::Estimated, $amount, $currency,
            identity: 'supplier:' . Money::of($unit)->toStorage() . ':' . $line->quantity . ':' . $currency,
            source: 'provider_field',
            inputs: ['unit_cost' => Money::of($unit)->toStorage(), 'quantity' => $line->quantity, 'provider_item_id' => (int) $item->id],
        ));
    }

    /** @param array<string, mixed> $inputs */
    private function missing(SaleDocument $document, SaleLine $line, CostComponent $component, string $reason, array $inputs = []): RecordOutcome
    {
        return $this->ledger->record(new CostDraft(
            subjectType: $line->targetType,
            subjectId: $line->targetId,
            component: $component,
            state: CostState::Missing,
            amount: null,
            currency: $document->currency !== '' ? $document->currency : $this->fx->baseCurrency(),
            periodDate: $document->occurredAt,
            source: 'layer',
            identity: 'missing:' . $reason . ':' . hash('sha256', json_encode($inputs)),
            sellerId: $document->sellerId,
            salesChannel: $document->salesChannel,
            accountId: $document->accountId,
            orderId: $document->orderId,
            externalOrderMappingId: $document->mappingId,
            inventoryLocationId: $line->inventoryLocationId,
            productId: $line->productId,
            variantKey: $line->variantKey,
            sku: $line->sku,
            calcInputs: $inputs,
            missingReason: $reason,
            occurredAt: $document->occurredAt,
        ));
    }

    /** @param array<string, mixed> $inputs */
    private function draft(SaleDocument $document, SaleLine $line, CostComponent $component, CostState $state, Money $amount, string $currency, string $identity, string $source, array $inputs): CostDraft
    {
        $fx = $this->fx->convert($amount, $currency, $this->fx->baseCurrency());

        return new CostDraft(
            subjectType: $line->targetType,
            subjectId: $line->targetId,
            component: $component,
            state: $state,
            amount: $amount,
            currency: $currency,
            periodDate: $document->occurredAt,
            source: $source,
            identity: $identity,
            sellerId: $document->sellerId,
            salesChannel: $document->salesChannel,
            accountId: $document->accountId,
            orderId: $document->orderId,
            externalOrderMappingId: $document->mappingId,
            provider: $line->provider,
            inventoryLocationId: $line->inventoryLocationId,
            productId: $line->productId,
            variantKey: $line->variantKey,
            sku: $line->sku,
            calcInputs: $inputs,
            occurredAt: $document->occurredAt,
            quantity: Money::of($line->quantity),
            unitAmount: $line->quantity > 0 ? $amount->dividedBy($line->quantity) : null,
            fxRate: $fx['rate'] ?? null,
            baseAmount: $fx['amount'] ?? null,
            baseCurrency: $fx === null ? null : $this->fx->baseCurrency(),
        );
    }
}
