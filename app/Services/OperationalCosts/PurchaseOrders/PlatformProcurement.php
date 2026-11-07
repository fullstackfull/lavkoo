<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's purchase orders, their receipts and the products they are about, READ and never written.
 *
 * Their tables have no migration in this repository: they come from the installation dump, so every read here is behind a check that the table and the columns are there, and when
 * something is not the caller is told what is missing (`missing()`) instead of getting an error. The tables it reads are the platform's: `purchase_orders`, `purchase_order_items`,
 * `products`, and, where they exist, `suppliers` (the name at the time) and `stock_movements` (the dates of the receipts).
 */
class PlatformProcurement
{
    /** @var array<string, list<string>> what the layers cannot be made without */
    private const REQUIRED = [
        'purchase_orders' => ['id', 'reference', 'supplier_id', 'seller_id', 'currency'],
        'purchase_order_items' => ['id', 'purchase_order_id', 'product_id', 'qty_received', 'unit_cost', 'created_at', 'updated_at'],
        'products' => ['id', 'product_type', 'added_by', 'variation', 'current_stock'],
    ];

    private const MOVEMENT_COLUMNS = ['product_id', 'type', 'qty_change', 'reference_type', 'reference_id', 'created_at'];

    /** @var list<string>|null */
    private ?array $productColumns = null;

    private ?bool $suppliersReadable = null;

    private ?bool $orderedColumn = null;

    private ?bool $movementsReadable = null;

    /** @return list<string> the tables ("purchase_orders") and columns ("purchase_orders.currency") the platform does not have, which the layers need */
    public function missing(): array
    {
        $missing = [];
        foreach (self::REQUIRED as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
                continue;
            }
            array_push($missing, ...array_map(static fn (string $column): string => "{$table}.{$column}", array_values(array_diff($columns, Schema::getColumnListing($table)))));
        }

        return $missing;
    }

    /**
     * The received lines (units received above zero) with an id above `$afterId`, oldest first: the order they are made into layers in, read a page at a time.
     *
     * @return list<PurchaseLine>
     */
    public function receivedLines(int $afterId, int $limit, ?int $productId = null, ?int $lineId = null): array
    {
        return $this->lines($this->receivedLineQuery()
            ->where('item.id', '>', $afterId)
            ->when($productId !== null, static fn ($query) => $query->where('item.product_id', $productId))
            ->when($lineId !== null, static fn ($query) => $query->where('item.id', $lineId))
            ->orderBy('item.id')
            ->limit($limit));
    }

    /** One line of any order, received or not (null when the platform has no such line). */
    public function line(int $lineId): ?PurchaseLine
    {
        return $this->lines(DB::table('purchase_order_items as item')->join('purchase_orders as po', 'po.id', '=', 'item.purchase_order_id')->where('item.id', $lineId)->limit(1))[0] ?? null;
    }

    /**
     * The lines of one product that were received most recently (by their last change), newest first.
     *
     * @return list<PurchaseLine>
     */
    public function latestReceivedLines(int $productId, int $limit): array
    {
        return $this->lines($this->receivedLineQuery()->where('item.product_id', $productId)->orderByDesc('item.updated_at')->orderByDesc('item.id')->limit($limit));
    }

    private function receivedLineQuery(): QueryBuilder
    {
        return DB::table('purchase_order_items as item')
            ->join('purchase_orders as po', 'po.id', '=', 'item.purchase_order_id')
            ->where('item.qty_received', '>', 0);
    }

    /** @return list<PurchaseLine> */
    private function lines(QueryBuilder $query): array
    {
        $rows = $query->get(['item.id as line_id', 'item.purchase_order_id', 'item.product_id', 'item.qty_received', 'item.unit_cost', 'item.created_at', 'item.updated_at', 'po.reference', 'po.supplier_id', 'po.seller_id', 'po.currency', ...($this->orderedColumn() ? ['item.qty_ordered'] : [])]);
        $suppliers = $this->supplierNames($rows->pluck('supplier_id')->filter()->unique()->map(static fn ($id): int => (int) $id)->all());

        return $rows->map(static fn (object $row): PurchaseLine => new PurchaseLine(
            id: (int) $row->line_id,
            purchaseOrderId: (int) $row->purchase_order_id,
            reference: (string) $row->reference,
            supplierId: $row->supplier_id === null ? null : (int) $row->supplier_id,
            supplierName: $row->supplier_id === null ? null : ($suppliers[(int) $row->supplier_id] ?? null),
            orderSellerId: $row->seller_id === null ? null : (int) $row->seller_id,
            currency: $row->currency === null ? null : (string) $row->currency,
            productId: $row->product_id === null ? null : (int) $row->product_id,
            quantityReceived: (int) $row->qty_received,
            unitCost: $row->unit_cost,
            createdAt: $row->created_at === null ? null : CarbonImmutable::parse($row->created_at),
            updatedAt: $row->updated_at === null ? null : CarbonImmutable::parse($row->updated_at),
            quantityOrdered: isset($row->qty_ordered) ? (int) $row->qty_ordered : null,
        ))->all();
    }

    /**
     * The receipts the platform's stock ledger recorded for the products of these lines, by purchase order and product ("12:50"): the platform logs a receipt against the ORDER, not
     * the line, so two lines of one order for one product share their entries and cannot be told apart.
     *
     * @param list<PurchaseLine> $lines
     * @return array<string, list<array{quantity: int, at: CarbonImmutable|null}>> oldest first
     */
    public function receiptsByOrderAndProduct(array $lines): array
    {
        if ($lines === [] || !$this->movementsReadable()) {
            return [];
        }

        $rows = DB::table('stock_movements')
            ->where('type', 'receipt')->where('reference_type', 'purchase_order')
            ->whereIn('reference_id', array_values(array_unique(array_map(static fn (PurchaseLine $line): int => $line->purchaseOrderId, $lines))))
            ->whereIn('product_id', array_values(array_unique(array_filter(array_map(static fn (PurchaseLine $line): ?int => $line->productId, $lines)))))
            ->orderBy('id')
            ->get(['reference_id', 'product_id', 'qty_change', 'created_at']);

        $receipts = [];
        foreach ($rows as $row) {
            $receipts[(int) $row->reference_id . ':' . (int) $row->product_id][] = ['quantity' => (int) $row->qty_change, 'at' => $row->created_at === null ? null : CarbonImmutable::parse($row->created_at)];
        }

        return $receipts;
    }

    /**
     * @param list<int> $ids
     * @return array<int, ProductFacts>
     */
    public function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $columns = $this->productColumns();
        $select = ['id', 'product_type', 'added_by', 'variation', 'current_stock', ...array_values(array_intersect(['name', 'fulfillment_provider'], $columns))];

        $facts = [];
        foreach (DB::table('products')->whereIn('id', $ids)->get($select) as $row) {
            $facts[(int) $row->id] = new ProductFacts(
                id: (int) $row->id,
                name: isset($row->name) ? (string) $row->name : null,
                currentStock: (int) $row->current_stock,
                physical: $row->product_type === 'physical',
                platformOwned: $row->added_by === 'admin',
                hasVariants: $this->hasVariants($row->variation),
                fulfillmentProvider: isset($row->fulfillment_provider) && $row->fulfillment_provider !== '' ? (string) $row->fulfillment_provider : null,
            );
        }

        return $facts;
    }

    /** The variants of a product are a JSON list in `products.variation`; a product with none stores an empty list (or nothing). */
    private function hasVariants(mixed $stored): bool
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) && $decoded !== [];
    }

    /** @return list<string> */
    private function productColumns(): array
    {
        return $this->productColumns ??= Schema::getColumnListing('products');
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function supplierNames(array $ids): array
    {
        $this->suppliersReadable ??= Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'name');
        if ($ids === [] || !$this->suppliersReadable) {
            return [];
        }

        return DB::table('suppliers')->whereIn('id', $ids)->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }

    private function orderedColumn(): bool
    {
        return $this->orderedColumn ??= Schema::hasColumn('purchase_order_items', 'qty_ordered');
    }

    private function movementsReadable(): bool
    {
        return $this->movementsReadable ??= Schema::hasTable('stock_movements') && array_diff(self::MOVEMENT_COLUMNS, Schema::getColumnListing('stock_movements')) === [];
    }
}
