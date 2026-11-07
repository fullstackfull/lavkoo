<?php

namespace App\Services\OperationalCosts;

use App\Models\InventoryCostConsumption;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Services\Fulfillment\Support\ProviderCurrency;
use App\Services\OperationalCosts\Data\ConsumptionResult;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\Exceptions\CostInputMissing;
use App\Services\OperationalCosts\PurchaseOrders\LastPurchaseOrderCost;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What the goods in stock cost us, kept as layers, first in first out.
 *
 *  - Goods come in as a LAYER (a purchase invoice, an opening balance, a transfer from another location, a return) with a unit cost and,
 *    when policy says freight or duty belongs to the stock, a landed cost per unit.
 *  - A sale takes its units from the oldest open layer of its product at its location, and the cost taken is frozen in a CONSUMPTION row.
 *    Whatever the layers cannot cover is reported as a shortfall; it is never costed at zero.
 *  - Moving stock to another location (Torod Point to Amazon FBA) is a transfer: the layers move with their cost, no sale happens, and
 *    the cost of the move can be added to the units it moved.
 *  - Reversing a sale puts the units back into the layers they came from.
 *
 * Every write is idempotent: a layer by its identity, a consumption by its consumer, a transfer by its reference.
 */
class InventoryCostLayerService
{
    public function __construct(
        private readonly FxConverter $fx,
        private readonly ProviderCurrency $storeCurrency,
        private readonly LastPurchaseOrderCost $purchaseOrderCosts,
    ) {
    }

    /** The name a layer is found again by: the same seller, place, product, variant, kind of source and identity are the same layer. */
    public static function dedupeKey(int $sellerId, int $locationId, int $productId, string $variantKey, string $sourceType, string $identity): string
    {
        return hash('sha256', implode('|', ['layer', $sellerId, $locationId, $productId, $variantKey, $sourceType, $identity]));
    }

    /** @throws CostInputMissing when the goods were paid for in a currency with no known rate */
    public function receive(LayerReceipt $receipt): InventoryCostLayer
    {
        if ($receipt->quantity < 1) {
            throw new InvalidArgumentException('A layer holds at least one unit.');
        }
        $dedupeKey = self::dedupeKey(sellerId: $receipt->sellerId, locationId: $receipt->locationId, productId: $receipt->productId, variantKey: $receipt->variantKey, sourceType: $receipt->sourceType, identity: $receipt->identity);
        $existing = InventoryCostLayer::where('dedupe_key', $dedupeKey)->first() ?? $this->layerOfTheSameUnits($receipt);
        if ($existing !== null) {
            return $existing;
        }

        $base = $this->fx->baseCurrency();
        $unitCost = $receipt->unitCost;
        $landed = $receipt->landedUnitCost ?? Money::zero();
        $original = [];
        if ($receipt->currency !== $base) {
            $converted = $this->fx->convert($unitCost, $receipt->currency, $base);
            $convertedLanded = $this->fx->convert($landed, $receipt->currency, $base);
            if ($converted === null || $convertedLanded === null) {
                throw new CostInputMissing('fx_unavailable', "No exchange rate between {$receipt->currency} and {$base}: set it before recording these goods.");
            }
            $original = ['original_currency' => $receipt->currency, 'original_unit_cost' => $unitCost->toStorage(), 'fx_rate' => $converted['rate']->toScale(10)];
            $unitCost = $converted['amount'];
            $landed = $convertedLanded['amount'];
        }

        try {
            return InventoryCostLayer::create([
                'seller_id' => $receipt->sellerId,
                'inventory_location_id' => $receipt->locationId,
                'product_id' => $receipt->productId,
                'variant_key' => $receipt->variantKey,
                'sku' => $receipt->sku,
                'source_type' => $receipt->sourceType,
                'source_id' => $receipt->sourceId,
                'source_ref' => $receipt->sourceRef,
                'dedupe_key' => $dedupeKey,
                'received_at' => $receipt->receivedAt,
                'quantity_received' => $receipt->quantity,
                'quantity_remaining' => $receipt->quantity,
                'unit_cost' => $unitCost->toStorage(),
                'landed_unit_cost' => $landed->toStorage(),
                'currency' => $base,
                'supplier_name' => $receipt->supplierName,
                'status' => InventoryCostLayer::OPEN,
            ] + $original + $this->provenance($receipt));
        } catch (UniqueConstraintViolationException $violation) {
            return InventoryCostLayer::where('dedupe_key', $dedupeKey)->first() ?? $this->layerOfTheSameUnits($receipt) ?? throw $violation;
        }
    }

    /**
     * The layer that already holds these units of a purchase-order line, whatever location or identity it was made with: the layer table is unique on the line and where its units
     * start, so a second run (or a second location) can never make a second layer of the same units. Null for every other kind of layer.
     */
    private function layerOfTheSameUnits(LayerReceipt $receipt): ?InventoryCostLayer
    {
        return $receipt->sourceFrom === null
            ? null
            : InventoryCostLayer::where('source_type', $receipt->sourceType)->where('source_id', $receipt->sourceId)->where('source_from', $receipt->sourceFrom)->first();
    }

    /**
     * The columns only a layer made from a purchase order fills; left out when there is nothing to say, so that every other layer is written exactly as before (and without the
     * purchase-order migration).
     *
     * @return array<string, int|string>
     */
    private function provenance(LayerReceipt $receipt): array
    {
        return array_filter([
            'source_from' => $receipt->sourceFrom,
            'source_to' => $receipt->sourceTo,
            'supplier_id' => $receipt->supplierId,
            'received_at_basis' => $receipt->receivedAtBasis,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    /**
     * Takes `$quantity` units for a consumer from the oldest layers. Calling it again for the same consumer tops up only what is still
     * uncovered, so a retry never takes a unit twice; a later layer (the purchase invoice arrived after the sale) completes a sale that
     * was short. A consumed row is never edited, so when the units still needed would have to come from a layer the consumer already took
     * from (the sale grew, or a cancelled sale gave units back to that layer), the consumer gives everything back and takes the whole
     * quantity afresh in a new generation: the cost is then what first-in-first-out gives for the quantity it has now.
     */
    public function consume(string $consumerType, int $consumerId, int $locationId, int $productId, string $variantKey, int $quantity, CarbonInterface $at, int $sellerId = 0): ConsumptionResult
    {
        return DB::transaction(function () use ($consumerType, $consumerId, $locationId, $productId, $variantKey, $quantity, $at, $sellerId): ConsumptionResult {
            $generation = $this->currentGeneration($consumerType, $consumerId);
            $alreadyTaken = $this->takenBy($consumerType, $consumerId, $generation);
            if ($alreadyTaken > $quantity) {
                // The sale got smaller (units cancelled): give everything back and take the smaller quantity afresh, so the layers and the cost follow it.
                $this->reverse($consumerType, $consumerId);
                $generation = $this->currentGeneration($consumerType, $consumerId);
                $alreadyTaken = 0;
            }
            $needed = $quantity - $alreadyTaken;

            if ($needed > 0) {
                $layers = $this->openLayers($locationId, $productId, $variantKey, $sellerId);
                if ($alreadyTaken > 0 && $this->wouldTakeFromALayerAlreadyUsed($layers, $needed, $consumerType, $consumerId, $generation)) {
                    $this->reverse($consumerType, $consumerId);
                    $generation = $this->currentGeneration($consumerType, $consumerId);
                    $needed = $quantity;
                    $layers = $this->openLayers($locationId, $productId, $variantKey, $sellerId);
                }

                foreach ($layers as $layer) {
                    if ($needed <= 0) {
                        break;
                    }
                    $take = min($needed, (int) $layer->quantity_remaining);
                    $this->take($layer, $take, $consumerType, $consumerId, $generation, $at);
                    $needed -= $take;
                }
            }

            return $this->resultFor($consumerType, $consumerId, $generation, $quantity);
        }, attempts: 3);
    }

    /** @return Collection<int, InventoryCostLayer> the layers with units left, oldest first, locked for the transaction */
    private function openLayers(int $locationId, int $productId, string $variantKey, int $sellerId): Collection
    {
        return InventoryCostLayer::query()
            ->where('seller_id', $sellerId)
            ->where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->where('status', InventoryCostLayer::OPEN)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, InventoryCostLayer> $layers */
    private function wouldTakeFromALayerAlreadyUsed(Collection $layers, int $needed, string $consumerType, int $consumerId, int $generation): bool
    {
        $used = InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)->where('generation', $generation)->whereNull('reversed_at')->pluck('layer_id')->all();
        foreach ($layers as $layer) {
            if ($needed <= 0) {
                return false;
            }
            if (in_array($layer->id, $used, true)) {
                return true;
            }
            $needed -= (int) $layer->quantity_remaining;
        }

        return false;
    }

    /** Puts a consumer's units back into the layers they came from. Returns the number of units restored. */
    public function reverse(string $consumerType, int $consumerId): int
    {
        return DB::transaction(function () use ($consumerType, $consumerId): int {
            $restored = 0;
            $rows = InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)->whereNull('reversed_at')->lockForUpdate()->get();
            foreach ($rows as $row) {
                $layer = InventoryCostLayer::whereKey($row->layer_id)->lockForUpdate()->first();
                if ($layer !== null) {
                    $layer->forceFill([
                        'quantity_remaining' => $layer->quantity_remaining + $row->quantity,
                        'status' => $layer->status === InventoryCostLayer::VOID ? InventoryCostLayer::VOID : InventoryCostLayer::OPEN,
                    ])->save();
                }
                $row->forceFill(['reversed_at' => now()])->save();
                $restored += $row->quantity;
            }

            return $restored;
        }, attempts: 3);
    }

    /**
     * Takes a layer off the shelf: what is left of it is no longer stock (units that were put back for a sale that is then cancelled are counted once more by the sale giving its
     * units back). Units other sales already took from it stay theirs. Returns the units that were taken off; a layer taken off already gives none.
     */
    public function void(int $layerId): int
    {
        return DB::transaction(function () use ($layerId): int {
            $layer = InventoryCostLayer::whereKey($layerId)->lockForUpdate()->first();
            if ($layer === null || $layer->status === InventoryCostLayer::VOID) {
                return 0;
            }
            $removed = (int) $layer->quantity_remaining;
            $layer->forceFill(['quantity_remaining' => 0, 'status' => InventoryCostLayer::VOID])->save();

            return $removed;
        }, attempts: 3);
    }

    /**
     * Moves stock from one location to another without selling it. The layers consumed at the source reappear at the destination with
     * the same cost, plus `$extraCostPerUnit` (the freight of the move) when that cost is meant to be part of the stock. Repeating a
     * transfer reference changes nothing.
     */
    public function transfer(string $transferRef, int $fromLocationId, int $toLocationId, int $productId, string $variantKey, int $quantity, CarbonInterface $at, ?Money $extraCostPerUnit = null, int $sellerId = 0): ConsumptionResult
    {
        if ($fromLocationId === $toLocationId) {
            throw new InvalidArgumentException('A transfer needs two different locations.');
        }

        return DB::transaction(function () use ($transferRef, $fromLocationId, $toLocationId, $productId, $variantKey, $quantity, $at, $extraCostPerUnit, $sellerId): ConsumptionResult {
            $done = InventoryCostLayer::where('source_type', 'transfer_in')->where('source_ref', $transferRef)->where('inventory_location_id', $toLocationId)->get();
            if ($done->isNotEmpty()) {
                return new ConsumptionResult(
                    requested: $quantity,
                    covered: (int) $done->sum('quantity_received'),
                    totalCost: Money::sum($done->map(fn (InventoryCostLayer $layer): Money => Money::of($layer->unit_cost)->plus($layer->landed_unit_cost)->times($layer->quantity_received))),
                    currency: (string) $done->first()->currency,
                    pieces: [],
                );
            }

            $layers = InventoryCostLayer::query()
                ->where('seller_id', $sellerId)->where('inventory_location_id', $fromLocationId)->where('product_id', $productId)
                ->where('variant_key', $variantKey)->where('status', InventoryCostLayer::OPEN)->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();

            $needed = $quantity;
            $pieces = [];
            $total = Money::zero();
            $currency = $this->fx->baseCurrency();
            foreach ($layers as $layer) {
                if ($needed <= 0) {
                    break;
                }
                $take = min($needed, (int) $layer->quantity_remaining);
                $landed = Money::of($layer->landed_unit_cost)->plus($extraCostPerUnit ?? Money::zero());
                $destination = InventoryCostLayer::create([
                    'seller_id' => $sellerId,
                    'inventory_location_id' => $toLocationId,
                    'product_id' => $productId,
                    'variant_key' => $variantKey,
                    'sku' => $layer->sku,
                    'source_type' => 'transfer_in',
                    'source_id' => $layer->id,
                    'source_ref' => $transferRef,
                    'dedupe_key' => hash('sha256', implode('|', ['layer-transfer', $transferRef, $layer->id])),
                    'received_at' => $layer->received_at,
                    'quantity_received' => $take,
                    'quantity_remaining' => $take,
                    'unit_cost' => $layer->unit_cost,
                    'landed_unit_cost' => $landed->toStorage(),
                    'currency' => $layer->currency,
                    'supplier_name' => $layer->supplier_name,
                    'status' => InventoryCostLayer::OPEN,
                ] + $this->supplierLink($layer));
                $this->take($layer, $take, 'transfer', (int) $destination->id, 1, $at);
                $pieces[] = ['layer_id' => (int) $layer->id, 'quantity' => $take, 'unit_cost' => (string) $layer->unit_cost, 'landed_unit_cost' => (string) $layer->landed_unit_cost];
                $total = $total->plus(Money::of($layer->unit_cost)->plus($layer->landed_unit_cost)->times($take));
                $currency = (string) $layer->currency;
                $needed -= $take;
            }

            return new ConsumptionResult($quantity, $quantity - $needed, $total, $currency, $pieces);
        }, attempts: 3);
    }

    /**
     * The best current figure for what a unit costs, used for ESTIMATES before a sale is recognised: the average of the open layers at the
     * location, else (with the purchase-order source on) the cost of the latest eligible purchase-order line, else the product's own recorded
     * purchase price. Null when none exists.
     *
     * @return array{cost: Money, currency: string, basis: string}|null
     */
    public function estimatedUnitCost(int $locationId, int $productId, string $variantKey = '', int $sellerId = 0): ?array
    {
        $layers = InventoryCostLayer::where('seller_id', $sellerId)->where('inventory_location_id', $locationId)->where('product_id', $productId)
            ->where('variant_key', $variantKey)->where('status', InventoryCostLayer::OPEN)->where('quantity_remaining', '>', 0)->get();
        if ($layers->isNotEmpty()) {
            $units = (int) $layers->sum('quantity_remaining');
            $value = Money::sum($layers->map(fn (InventoryCostLayer $layer): Money => Money::of($layer->unit_cost)->plus($layer->landed_unit_cost)->times($layer->quantity_remaining)));

            return ['cost' => $value->dividedBy($units), 'currency' => $this->fx->baseCurrency(), 'basis' => 'open_layers_average'];
        }

        $fromPurchaseOrder = $this->purchaseOrderCosts->forProduct($productId, $variantKey, $sellerId);
        if ($fromPurchaseOrder !== null) {
            return $fromPurchaseOrder;
        }

        $purchasePrice = Product::query()->whereKey($productId)->value('purchase_price');
        $converted = $purchasePrice !== null && (float) $purchasePrice > 0 ? $this->storeCurrency->fromStored($purchasePrice) : null;
        if ($converted !== null && $this->storeCurrency->code() === $this->fx->baseCurrency()) {
            return ['cost' => Money::of($converted), 'currency' => $this->fx->baseCurrency(), 'basis' => 'product_purchase_price'];
        }

        return null;
    }

    private function take(InventoryCostLayer $layer, int $quantity, string $consumerType, int $consumerId, int $generation, CarbonInterface $at): void
    {
        $landed = Money::of($layer->landed_unit_cost);
        InventoryCostConsumption::create([
            'layer_id' => $layer->id,
            'consumer_type' => $consumerType,
            'consumer_id' => $consumerId,
            'generation' => $generation,
            'quantity' => $quantity,
            'unit_cost' => $layer->unit_cost,
            'landed_unit_cost' => $landed->toStorage(),
            'total_cost' => Money::of($layer->unit_cost)->plus($landed)->times($quantity)->toStorage(),
            'currency' => $layer->currency,
            'consumed_at' => $at,
        ]);
        $remaining = $layer->quantity_remaining - $quantity;
        $layer->forceFill(['quantity_remaining' => $remaining, 'status' => $remaining === 0 ? InventoryCostLayer::DEPLETED : $layer->status])->save();
    }

    private function takenBy(string $consumerType, int $consumerId, int $generation): int
    {
        return (int) InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)
            ->where('generation', $generation)->whereNull('reversed_at')->sum('quantity');
    }

    /** The generation a consumer is on: a new one starts once every row of the latest has been reversed. */
    private function currentGeneration(string $consumerType, int $consumerId): int
    {
        $latest = (int) InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)->max('generation');
        if ($latest === 0) {
            return 1;
        }
        $live = InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)->where('generation', $latest)->whereNull('reversed_at')->exists();

        return $live ? $latest : $latest + 1;
    }

    private function resultFor(string $consumerType, int $consumerId, int $generation, int $requested): ConsumptionResult
    {
        $rows = InventoryCostConsumption::where('consumer_type', $consumerType)->where('consumer_id', $consumerId)
            ->where('generation', $generation)->whereNull('reversed_at')->get();
        $layers = InventoryCostLayer::whereIn('id', $rows->pluck('layer_id')->unique()->all())->get()->keyBy('id');

        return new ConsumptionResult(
            requested: $requested,
            covered: (int) $rows->sum('quantity'),
            totalCost: Money::sum($rows->map(fn (InventoryCostConsumption $row): Money => Money::of($row->total_cost))),
            currency: (string) ($rows->first()->currency ?? $this->fx->baseCurrency()),
            pieces: $rows->map(fn (InventoryCostConsumption $row): array => [
                'layer_id' => (int) $row->layer_id, 'quantity' => (int) $row->quantity,
                'unit_cost' => (string) $row->unit_cost, 'landed_unit_cost' => (string) $row->landed_unit_cost,
            ] + $this->supplierLink($layers->get($row->layer_id), withName: true))->all(),
        );
    }

    /**
     * The supplier a layer carries, to hand on to what is made from it (a transferred layer, the entry a sale's cost goes into). Nothing for a layer with no supplier link, so that the
     * pieces of every layer without one are exactly what they were (an entry's identity is a hash of them).
     *
     * @return array<string, int|string|null>
     */
    private function supplierLink(?InventoryCostLayer $layer, bool $withName = false): array
    {
        if ($layer === null || $layer->getAttribute('supplier_id') === null) {
            return [];
        }

        return ['supplier_id' => (int) $layer->supplier_id] + ($withName ? ['supplier_name' => $layer->supplier_name] : []);
    }
}
