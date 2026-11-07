<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Models\InventoryCostLayer;
use Carbon\CarbonImmutable;

/**
 * Whether the goods of an order line may already be in a layer that something other than the order made: a purchase invoice that was approved, or a cost typed by hand. When the
 * invoice or the typed cost came first (the order had received nothing yet, or the source was off), nothing held it, and layering the receipt as well would count the same goods
 * twice, the second layer being used up first. A layer covers a line when it is of the same product, from the platform's own stock, made from an invoice or typed (not an opening
 * balance, a transfer or returned units), dated on or after the cutover, and from the same supplier or from one nobody can tell apart. Only the first layer of a line is looked at:
 * a line the order already layers goes on being layered, and a later invoice for it is held when it is saved.
 *
 * It errs on the side of refusing: the line is listed and a person decides (see PurchaseOrderLayerService::apply, `included`).
 */
class CoveringLayers
{
    /** What other layers can cover an order's goods. */
    private const SOURCES = ['purchase_invoice', 'manual'];

    public function __construct(private readonly PurchaseOrderSchema $schema)
    {
    }

    /**
     * @param list<PurchaseLine> $lines the lines nothing has been layered for yet
     * @return array<int, true> the ids of the lines that may be covered
     */
    public function coveredLines(array $lines, ?CarbonImmutable $since): array
    {
        $products = array_values(array_unique(array_filter(array_map(static fn (PurchaseLine $line): ?int => $line->productId, $lines))));
        if ($products === [] || $this->schema->missingOwnColumns() !== []) {
            return [];
        }

        $layers = InventoryCostLayer::query()->where('seller_id', 0)->whereIn('product_id', $products)->whereIn('source_type', self::SOURCES)->where('status', '!=', InventoryCostLayer::VOID)
            ->when($since !== null, static fn ($query) => $query->where('received_at', '>=', $since))
            ->get(['product_id', 'supplier_id', 'supplier_name'])->groupBy('product_id');

        $covered = [];
        foreach ($lines as $line) {
            foreach ($layers->get($line->productId, []) as $layer) {
                if ($this->mayBeTheSameSupplier($layer, $line)) {
                    $covered[$line->id] = true;
                    break;
                }
            }
        }

        return $covered;
    }

    private function mayBeTheSameSupplier(InventoryCostLayer $layer, PurchaseLine $line): bool
    {
        if ($layer->supplier_id !== null && $line->supplierId !== null) {
            return (int) $layer->supplier_id === $line->supplierId;
        }
        $layerName = mb_strtolower(trim((string) $layer->supplier_name));
        $lineName = mb_strtolower(trim((string) $line->supplierName));

        return $layerName === '' || $lineName === '' || $layerName === $lineName;
    }
}
