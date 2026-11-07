<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Enums\OperationalCosts\PurchaseLineRefusal;
use App\Services\AuditLogger;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\InventoryCostLayerService;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Makes the FIFO cost layers of the goods the platform received on its purchase orders, so that the cost is not typed a second time.
 *
 * The platform's tables are only READ. What is written is this module's own layers, one for each tranche of units a line has received since its last layer (see
 * PurchaseOrderLayerPlanner), and a single audit line for a run. A layer is made only for a line that may be (a catalogue product of the platform's own that is physical and has no
 * variants, a cost above zero, a currency with a rate, a known place): anything else is left alone and reported, never made at zero. Running it again makes nothing; two runs at the same
 * moment make one layer between them, because the layer table is unique on a line and where its units start.
 *
 * `preview()` writes nothing anywhere, and counts the default stock locations that do not exist yet as there, because `apply()` is run after the sales-channel set-up has made them. Making layers
 * needs the purchase-order source switched on and the cost module's master switch on.
 */
class PurchaseOrderLayerService
{
    /** How often a line is looked at again after another run made a layer of its units first. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly InventoryCostLayerService $layers,
        private readonly PurchaseOrderLayerPlanner $planner,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * What a run would do, written nowhere.
     *
     * @param list<int> $included the lines a person has said are separate purchases (see PurchaseOrderLayerPlanner::plans)
     */
    public function preview(?int $productId = null, ?int $lineId = null, ?CarbonImmutable $since = null, int $sample = 50, array $included = []): LayerRun
    {
        $run = new LayerRun($sample);
        foreach ($this->planner->plans(productId: $productId, lineId: $lineId, since: $since, included: $included, assumeDefaultLocations: true) as $plan) {
            $this->count($run, $plan);
        }

        return $run;
    }

    /**
     * Makes the layers.
     *
     * @param list<int> $included the lines a person has said are separate purchases: they are layered although an invoice or a typed cost may hold the same goods
     * @throws LogicException when the purchase-order source is not on (the caller says so to the person; this is the last line of defence)
     */
    public function apply(CarbonImmutable $since, ?int $productId = null, ?int $lineId = null, int $sample = 50, array $included = []): LayerRun
    {
        $this->mustBeOn();
        $run = new LayerRun($sample);
        foreach ($this->planner->plans(productId: $productId, lineId: $lineId, since: $since, included: $included) as $plan) {
            $this->count($run, $plan);
            $this->make($run, $plan, $since, $included);
        }
        $this->audit->record(action: 'operational_cost.layers_from_purchase_orders', context: $run->toArray() + ['since' => $since->toDateString(), 'product_id' => $productId, 'line_id' => $lineId, 'included_lines' => $included]);

        return $run;
    }

    /**
     * The layer of one tranche of one line. It is the only place that writes, and it is safe to call from several processes at once for the same line: the layer table refuses a
     * second layer for the same units, whatever the location or the other end of the tranche, and the one that lost is told so.
     *
     * @throws LogicException when the purchase-order source is not on, or the plan has nothing to make
     */
    public function layerTranche(LinePlan $plan): TrancheOutcome
    {
        $this->mustBeOn();
        if ($plan->tranche === null || !$plan->verdict->isEligible() || $plan->verdict->locationId === null) {
            throw new LogicException('Only a line with units to layer, a layer it may have and a place to put it is made into one.');
        }
        $line = $plan->line;
        $tranche = $plan->tranche;

        try {
            $layer = $this->layers->receive(new LayerReceipt(
                locationId: $plan->verdict->locationId,
                productId: $line->productId,
                quantity: $tranche->quantity(),
                unitCost: $plan->verdict->unitCost,
                currency: $plan->verdict->currency,
                receivedAt: $tranche->receivedAt,
                sourceType: PurchaseOrderLayerPlanner::SOURCE_TYPE,
                identity: $tranche->identity($line->id),
                sourceId: $line->id,
                sourceRef: mb_substr($line->reference, 0, 120),
                supplierName: $line->supplierName === null ? null : mb_substr($line->supplierName, 0, 160),
                sourceFrom: $tranche->from,
                sourceTo: $tranche->to,
                supplierId: $line->supplierId,
                receivedAtBasis: $tranche->dateBasis,
            ));
        } catch (CostInputMissing) {
            return TrancheOutcome::refused(PurchaseLineRefusal::NoExchangeRate);
        }

        return match (true) {
            $layer->wasRecentlyCreated => TrancheOutcome::created($layer),
            (int) $layer->source_to === $tranche->to => TrancheOutcome::alreadyMade($layer),
            default => TrancheOutcome::overtaken($layer),
        };
    }

    private function count(LayerRun $run, LinePlan $plan): void
    {
        $run->lines++;
        if ($plan->reducedBy > 0) {
            $run->receivedBelowLayered++;

            return;
        }
        if (!$plan->verdict->isEligible()) {
            $reason = $plan->verdict->refusal->value;
            $run->refused[$reason] = ($run->refused[$reason] ?? 0) + 1;
            if ($plan->verdict->refusal === PurchaseLineRefusal::CoveredByOtherLayers) {
                $run->rememberCovered($plan->line->id);
            }

            return;
        }
        if ($plan->tranche === null) {
            $run->upToDate++;

            return;
        }

        $run->planned++;
        $run->plannedUnits += $plan->tranche->quantity();
        $run->approximateDates += $plan->tranche->dateIsApproximate() ? 1 : 0;
        $run->remember([
            'line_id' => $plan->line->id,
            'purchase_order' => $plan->line->reference,
            'product_id' => $plan->line->productId,
            'location_id' => $plan->verdict->locationId,
            'units' => $plan->tranche->quantity(),
            'from' => $plan->tranche->from,
            'to' => $plan->tranche->to,
            'unit_cost' => $plan->verdict->unitCost->toStorage() . ' ' . $plan->verdict->currency,
            'received_at' => $plan->tranche->receivedAt->toDateTimeString(),
            'date_is_approximate' => $plan->tranche->dateIsApproximate(),
        ]);
    }

    /**
     * Makes the layer of a planned tranche; when another run made the layer of the same units first, the line is looked at again (a few times) for what is left.
     *
     * @param list<int> $included
     */
    private function make(LayerRun $run, LinePlan $plan, CarbonImmutable $since, array $included): void
    {
        for ($attempt = 1; $plan->tranche !== null && $plan->verdict->isEligible() && $attempt <= self::ATTEMPTS; $attempt++) {
            $outcome = $this->layerTranche($plan);
            if ($outcome->state === TrancheOutcome::CREATED) {
                $run->created++;
                $run->createdUnits += $plan->tranche->quantity();
            }
            if ($outcome->state === TrancheOutcome::ALREADY_MADE) {
                $run->alreadyMade++;
            }
            if ($outcome->state === TrancheOutcome::REFUSED) {
                $run->refused[$outcome->refusal->value] = ($run->refused[$outcome->refusal->value] ?? 0) + 1;
            }
            if ($outcome->state !== TrancheOutcome::OVERTAKEN) {
                return;
            }
            $run->alreadyMade++;
            $plan = $this->planner->plans(lineId: $plan->line->id, since: $since, included: $included)->current() ?? $plan;
        }
    }

    private function mustBeOn(): void
    {
        if (!PurchaseOrderSourceMode::active()) {
            throw new LogicException('The purchase-order source is not on (OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=on with OPERATIONAL_COSTS_ENABLED): no layer is made.');
        }
    }
}
