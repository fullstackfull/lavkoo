<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Models\InventoryCostLayer;
use App\Services\OperationalCosts\InventoryCostLayerService;

/**
 * How many units of each purchase-order line the module's layers already account for, worked out from the layers that exist for the line (`source_type` and `source_id`) and from
 * nothing else the line says.
 *
 * A layer made by the purchase-order source says which units it holds (`source_from`, `source_to`). One that lost both (the migration that adds them was rolled back and applied
 * again) is found by its name instead: the hash that names a layer holds the units too (`po-item:{line}:{from}-{to}`), so the units it can have held are tried until the hash is
 * the layer's. When even that fails the units it holds are taken as accounted for. Either way a line is never layered a second time because a column was emptied.
 */
class LayeredUnits
{
    public function __construct(private readonly PurchaseOrderSchema $schema)
    {
    }

    /**
     * @param list<PurchaseLine> $lines
     * @return array<int, int> the units the layers of each line account for, by line id (a line with no layer is absent)
     */
    public function forLines(array $lines): array
    {
        if ($lines === [] || $this->schema->missingOwnColumns() !== []) {
            return [];
        }

        $layers = InventoryCostLayer::query()->where('source_type', PurchaseOrderLayerPlanner::SOURCE_TYPE)->whereIn('source_id', array_map(static fn (PurchaseLine $line): int => $line->id, $lines))
            ->get(['source_id', 'seller_id', 'inventory_location_id', 'product_id', 'variant_key', 'dedupe_key', 'quantity_received', 'source_from', 'source_to'])->groupBy('source_id');

        $received = [];
        foreach ($lines as $line) {
            $received[$line->id] = $line->quantityReceived;
        }

        $accounted = [];
        foreach ($layers as $lineId => $held) {
            $lineId = (int) $lineId;
            $units = (int) $held->sum('quantity_received');
            $reach = $held->map(fn (InventoryCostLayer $layer): int => $layer->source_to !== null ? (int) $layer->source_to : $this->reachOfLayerWithoutBookkeeping($layer, $lineId, max($units, $received[$lineId])))->max();
            $accounted[$lineId] = max($reach, $units);
        }

        return $accounted;
    }

    /** Where the units of a layer that lost its `source_to` end, by finding the identity its name was made from; its own quantity when none matches. */
    private function reachOfLayerWithoutBookkeeping(InventoryCostLayer $layer, int $lineId, int $mostUnitsPossible): int
    {
        $quantity = (int) $layer->quantity_received;
        for ($from = 0; $from + $quantity <= $mostUnitsPossible; $from++) {
            $name = InventoryCostLayerService::dedupeKey(
                sellerId: (int) $layer->seller_id,
                locationId: (int) $layer->inventory_location_id,
                productId: (int) $layer->product_id,
                variantKey: (string) $layer->variant_key,
                sourceType: PurchaseOrderLayerPlanner::SOURCE_TYPE,
                identity: Tranche::identityOf(lineId: $lineId, from: $from, to: $from + $quantity),
            );
            if (hash_equals((string) $layer->dedupe_key, $name)) {
                return $from + $quantity;
            }
        }

        return $quantity;
    }
}
