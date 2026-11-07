<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Models\InventoryCostLayer;
use App\Models\InventoryReservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the cost module's FIFO layers and the platform's own purchase, stock and order tables disagree. It is computed on demand from what is there and is READ-ONLY (it issues
 * nothing but SELECT statements and keeps nothing). It changes nothing it finds: the platform's tables are the truth and this only shows the difference.
 *
 *  - per product: the units the open layers still hold against what the platform says is on hand, which is its stock plus the units of store orders that already left the stock
 *    but are not delivered yet (the store takes units off at placement, the layers are taken from at delivery). The units channel orders took from the layers are shown beside the
 *    difference, because the platform's stock never saw them;
 *  - per product: the units received on purchase orders against the units layered from them;
 *  - the received purchase-order lines no layer covers yet and the ones that never can be, each with its reason, and the lines whose received quantity fell below what was layered;
 *  - the platform's stock that no layer covers at all, and the platform's negative stock.
 */
class PlatformReconciliation
{
    public const BALANCED = 'balanced';
    public const LAYERS_ABOVE_PLATFORM = 'layers_above_platform';
    public const LAYERS_BELOW_PLATFORM = 'layers_below_platform';
    public const PRODUCT_NOT_FOUND = 'product_not_found';

    /** The states of a store order in which its units are no longer waiting to be delivered (the same ones SaleDocumentReader reads as delivered, cancelled or returned). */
    private const STORE_ORDER_CLOSED = ['delivered', 'canceled', 'failed', 'returned', 'refunded'];

    private const PRODUCT_PAGE = 500;

    /** @var array<string, list<string>> the columns of the platform's store orders the comparison reads (the order's id, whose it is and how far it is; its lines' order, product and quantity) */
    private const STORE_ORDER_COLUMNS = ['orders' => ['id', 'seller_is', 'order_status'], 'order_details' => ['order_id', 'product_id', 'qty']];

    public function __construct(
        private readonly PlatformProcurement $procurement,
        private readonly PurchaseOrderLayerPlanner $planner,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(int $limit = 50, ?int $productId = null): array
    {
        $missing = $this->procurement->missing();
        if ($missing !== []) {
            return [
                'available' => false,
                'missing' => $missing,
                'message' => 'The platform\'s own tables are not complete here (' . implode(', ', $missing) . '): they come from the installation dump. Nothing was compared.',
            ];
        }

        ['section' => $purchaseOrders, 'units' => $orderUnits] = $this->purchaseOrderLines($limit, $productId);

        return [
            'available' => true,
            'skipped' => $this->missingStoreOrderColumns() === [] ? [] : ['store_orders' => $this->missingStoreOrderColumns()],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'products' => $this->products($orderUnits, $limit, $productId),
            'purchase_orders' => $purchaseOrders,
            'stock_without_layers' => $this->stockWithoutLayers($limit, $productId),
            'negative_stock' => $this->negativeStock($limit, $productId),
        ];
    }

    /**
     * @return array{section: array<string, mixed>, units: array<int, array{received: int, pending: int}>} the section of the report, and the units received and not yet layered per product
     */
    private function purchaseOrderLines(int $limit, ?int $productId): array
    {
        $summary = ['lines_received' => 0, 'units_received' => 0, 'lines_without_layer' => 0, 'units_without_layer' => 0, 'lines_never_layered' => 0];
        $reasons = [];
        $without = [];
        $never = [];
        $reduced = [];
        $units = [];

        foreach ($this->planner->plans(productId: $productId) as $plan) {
            $line = $plan->line;
            $summary['lines_received']++;
            $summary['units_received'] += $line->quantityReceived;
            if ($line->productId !== null) {
                $units[$line->productId] ??= ['received' => 0, 'pending' => 0];
                $units[$line->productId]['received'] += $line->quantityReceived;
            }

            if ($plan->reducedBy > 0) {
                $reduced[] = $this->lineRow($plan) + ['layered_up_to' => $plan->accountedUnits];
            }
            if (!$plan->verdict->isEligible()) {
                $reason = $plan->verdict->refusal->value;
                $reasons[$reason] ??= ['meaning' => $plan->verdict->refusal->meaning(), 'lines' => 0, 'units' => 0];
                $reasons[$reason]['lines']++;
                $reasons[$reason]['units'] += $line->quantityReceived;
                $summary['lines_never_layered']++;
                $never[] = $this->lineRow($plan) + ['reason' => $reason];
            } elseif ($plan->tranche !== null) {
                $summary['lines_without_layer']++;
                $summary['units_without_layer'] += $plan->tranche->quantity();
                $units[$line->productId]['pending'] += $plan->tranche->quantity();
                $without[] = $this->lineRow($plan) + ['units_without_layer' => $plan->tranche->quantity(), 'received_at' => $plan->tranche->receivedAt->toDateTimeString(), 'date_is_approximate' => $plan->tranche->dateIsApproximate()];
            }
        }

        $layered = $this->unitsLayeredFromPurchaseOrders($productId);

        return [
            'section' => $summary + [
                'units_layered' => array_sum($layered),
                'without_layer' => ['rows' => array_slice($without, 0, $limit), 'truncated' => count($without) > $limit],
                'never_layered' => ['by_reason' => $reasons, 'rows' => array_slice($never, 0, $limit), 'truncated' => count($never) > $limit],
                'received_below_layered' => ['rows' => array_slice($reduced, 0, $limit), 'truncated' => count($reduced) > $limit],
            ],
            'units' => $units,
        ];
    }

    /** @return array<string, int|string|null> */
    private function lineRow(LinePlan $plan): array
    {
        return [
            'line_id' => $plan->line->id,
            'purchase_order' => $plan->line->reference,
            'product_id' => $plan->line->productId,
            'supplier' => $plan->line->supplierName,
            'units_received' => $plan->line->quantityReceived,
        ];
    }

    /**
     * Every product the layers or the purchase orders mention, the layers against the platform's stock.
     *
     * @param array<int, array{received: int, pending: int}> $orderUnits
     * @return array<string, mixed>
     */
    private function products(array $orderUnits, int $limit, ?int $productId): array
    {
        $layers = $this->openLayerUnits($productId);
        $layered = $this->unitsLayeredFromPurchaseOrders($productId);
        $ids = array_values(array_unique([...array_keys($layers), ...array_keys($orderUnits), ...array_keys($layered)]));
        sort($ids);

        $counts = [self::BALANCED => 0, self::LAYERS_ABOVE_PLATFORM => 0, self::LAYERS_BELOW_PLATFORM => 0, self::PRODUCT_NOT_FOUND => 0];
        $rows = [];
        foreach (array_chunk($ids, self::PRODUCT_PAGE) as $page) {
            $facts = $this->procurement->products($page);
            $undelivered = $this->undeliveredStoreUnits($page);
            $channel = $this->channelUnitsTaken($page);
            $held = $this->channelUnitsHeldOnAProviderShelf($page);
            foreach ($page as $id) {
                $row = $this->productRow($id, $facts[$id] ?? null, $layers[$id] ?? 0, $undelivered[$id] ?? 0, $channel[$id] ?? 0, $held[$id] ?? 0, $orderUnits[$id] ?? ['received' => 0, 'pending' => 0], $layered[$id] ?? 0);
                $counts[$row['status']]++;
                if ($row['status'] !== self::BALANCED) {
                    $rows[] = $row;
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => abs((int) $b['difference']) <=> abs((int) $a['difference']) ?: $a['product_id'] <=> $b['product_id']);

        return ['examined' => count($ids)] + $counts + ['rows' => array_slice($rows, 0, $limit), 'truncated' => count($rows) > $limit];
    }

    /**
     * @param array{received: int, pending: int} $orders
     * @return array<string, mixed>
     */
    private function productRow(int $id, ?ProductFacts $product, int $layerUnits, int $undelivered, int $channelTaken, int $channelHeld, array $orders, int $layered): array
    {
        $expected = $product === null ? null : $product->currentStock + $undelivered + $channelHeld;
        $difference = $expected === null ? $layerUnits : $layerUnits - $expected;

        return [
            'product_id' => $id,
            'name' => $product?->name,
            'has_variants' => $product?->hasVariants,
            'platform_stock' => $product?->currentStock,
            'undelivered_store_units' => $undelivered,
            'channel_units_held' => $channelHeld,
            'expected_layer_units' => $expected,
            'layer_units' => $layerUnits,
            'difference' => $difference,
            'channel_units_taken' => $channelTaken,
            'purchase_order_units_received' => $orders['received'],
            'purchase_order_units_layered' => $layered,
            'purchase_order_units_without_layer' => $orders['pending'],
            'status' => match (true) {
                $product === null => self::PRODUCT_NOT_FOUND,
                $difference === 0 => self::BALANCED,
                $difference > 0 => self::LAYERS_ABOVE_PLATFORM,
                default => self::LAYERS_BELOW_PLATFORM,
            },
        ];
    }

    /** @return array<int, int> the units the open layers of the platform's own stock still hold, by product */
    private function openLayerUnits(?int $productId): array
    {
        return InventoryCostLayer::query()->where('seller_id', 0)->where('status', InventoryCostLayer::OPEN)->where('quantity_remaining', '>', 0)
            ->when($productId !== null, static fn ($query) => $query->where('product_id', $productId))
            ->selectRaw('product_id, sum(quantity_remaining) as units')->groupBy('product_id')->pluck('units', 'product_id')
            ->map(static fn ($units): int => (int) $units)->all();
    }

    /** @return array<int, int> the units layered from purchase orders (what the layers were made with, used or not), by product */
    private function unitsLayeredFromPurchaseOrders(?int $productId): array
    {
        return InventoryCostLayer::query()->where('source_type', PurchaseOrderLayerPlanner::SOURCE_TYPE)
            ->when($productId !== null, static fn ($query) => $query->where('product_id', $productId))
            ->selectRaw('product_id, sum(quantity_received) as units')->groupBy('product_id')->pluck('units', 'product_id')
            ->map(static fn ($units): int => (int) $units)->all();
    }

    /**
     * Units of the platform's own store orders that already left its stock and are not delivered (or cancelled, or returned) yet. An order made to fulfil a channel order is not one:
     * the channel order is the sale.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    private function undeliveredStoreUnits(array $productIds): array
    {
        if ($this->missingStoreOrderColumns() !== []) {
            return [];
        }
        $leftTheStock = Schema::hasColumn('order_details', 'is_stock_decreased');

        return DB::table('order_details as detail')->join('orders as sale', 'sale.id', '=', 'detail.order_id')
            ->where('sale.seller_is', 'admin')->whereNotIn('sale.order_status', self::STORE_ORDER_CLOSED)
            ->whereIn('detail.product_id', $productIds)
            ->when($leftTheStock, static fn ($query) => $query->where('detail.is_stock_decreased', 1))
            ->when(Schema::hasTable('external_order_mappings'), static fn ($query) => $query->whereNotIn('sale.id', DB::table('external_order_mappings')->whereNotNull('order_id')->select('order_id')))
            ->selectRaw('detail.product_id as product, sum(detail.qty) as units')->groupBy('detail.product_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->product => (int) $row->units])->all();
    }

    /** @return list<string> the columns ("orders.seller_is") of the platform's store orders the comparison needs and that are not there: it leaves the store orders out and says so */
    private function missingStoreOrderColumns(): array
    {
        $missing = [];
        foreach (self::STORE_ORDER_COLUMNS as $table => $columns) {
            $present = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
            array_push($missing, ...array_map(static fn (string $column): string => "{$table}.{$column}", array_values(array_diff($columns, $present))));
        }

        return $missing;
    }

    /**
     * Units open channel orders hold at a provider's location and that no layer has been consumed for yet (a channel order's cost of goods is taken when the sale is recognised, not
     * when the order arrives). The provider's own count still has them, so they are part of the stock the layers should cover; the storefront's mirror of that count does not, because
     * the provider sync takes them off it (see operations.md, *The storefront's side of a provider's shelf*). Added back here, they are not a difference between layers and stock.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    private function channelUnitsHeldOnAProviderShelf(array $productIds): array
    {
        if (!Schema::hasTable('inventory_reservations') || !Schema::hasTable('inventory_locations')) {
            return [];
        }

        return DB::table('inventory_reservations as hold')->join('inventory_locations as place', 'place.id', '=', 'hold.location_id')
            ->whereNotNull('place.provider')->where('hold.state', InventoryReservation::RESERVED)
            ->whereIn('hold.product_id', $productIds)
            ->selectRaw('hold.product_id as product, sum(hold.quantity) as units')->groupBy('hold.product_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->product => (int) $row->units])->all();
    }

    /**
     * Units that channel orders took from the layers and have not given back. The platform's stock never saw them as a sale of its own: a channel order decrements nothing there (a unit
     * an OPEN channel order holds of a provider's shelf is another matter: `channelUnitsHeldOnAProviderShelf`).
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    private function channelUnitsTaken(array $productIds): array
    {
        return DB::table('inventory_cost_consumptions as taken')->join('inventory_cost_layers as layer', 'layer.id', '=', 'taken.layer_id')
            ->where('taken.consumer_type', 'channel_order_line')->whereNull('taken.reversed_at')->where('layer.seller_id', 0)
            ->whereIn('layer.product_id', $productIds)
            ->selectRaw('layer.product_id as product, sum(taken.quantity) as units')->groupBy('layer.product_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->product => (int) $row->units])->all();
    }

    /**
     * Physical stock of the platform's own products that no open layer covers: what sells with no cost to take (a product assigned to M5AZN keeps no stock of its own and is left out).
     *
     * @return array<string, mixed>
     */
    private function stockWithoutLayers(int $limit, ?int $productId): array
    {
        $query = $this->ownPhysicalProducts($productId)->where('product.current_stock', '>', 0)
            ->whereNotExists(static fn ($layers) => $layers->selectRaw('1')->from('inventory_cost_layers as layer')->whereColumn('layer.product_id', 'product.id')
                ->where('layer.seller_id', 0)->where('layer.status', InventoryCostLayer::OPEN)->where('layer.quantity_remaining', '>', 0));

        return $this->productsAndUnits($query, 'product.current_stock', $limit, descending: true);
    }

    /**
     * Products whose platform stock is below zero. The platform allows it on purpose (an order paid after the stock ran out); here it only shows.
     *
     * @return array<string, mixed>
     */
    private function negativeStock(int $limit, ?int $productId): array
    {
        return $this->productsAndUnits($this->ownPhysicalProducts($productId)->where('product.current_stock', '<', 0), 'product.current_stock', $limit, descending: false);
    }

    /** The platform's own physical products, not assigned to M5AZN. */
    private function ownPhysicalProducts(?int $productId): QueryBuilder
    {
        return DB::table('products as product')->where('product.added_by', 'admin')->where('product.product_type', 'physical')
            ->when($productId !== null, static fn ($query) => $query->where('product.id', $productId))
            ->when(Schema::hasColumn('products', 'fulfillment_provider'), static fn ($query) => $query->where(static fn ($provider) => $provider->whereNull('product.fulfillment_provider')->orWhere('product.fulfillment_provider', '!=', 'm5azn')));
    }

    /** @return array{products: int, units: int, rows: list<array<string, mixed>>, truncated: bool} */
    private function productsAndUnits(QueryBuilder $query, string $column, int $limit, bool $descending): array
    {
        $total = (clone $query)->selectRaw("count(*) as products, coalesce(sum({$column}), 0) as units")->first();
        $rows = (clone $query)->orderBy($column, $descending ? 'desc' : 'asc')->orderBy('product.id')->limit($limit)->get(['product.id', 'product.current_stock', ...(Schema::hasColumn('products', 'name') ? ['product.name'] : [])]);

        return [
            'products' => (int) $total->products,
            'units' => (int) $total->units,
            'rows' => $rows->map(static fn (object $row): array => ['product_id' => (int) $row->id, 'name' => $row->name ?? null, 'platform_stock' => (int) $row->current_stock])->all(),
            'truncated' => (int) $total->products > $limit,
        ];
    }
}
