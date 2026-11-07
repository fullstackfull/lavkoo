<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderSchema;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderSourceMode;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a purchase invoice line meets the platform's purchase orders, so that the same goods are not costed twice. With the purchase-order source on:
 *
 *  - a line that names the purchase-order line it bills (`purchase_order_item_id`) makes no layer of its own, because the order's receipt is the layer; it records a cost variance
 *    against what the order says the goods cost;
 *  - a line that names none but looks like goods an order already received (same supplier, same product, same quantity) holds the invoice for a person, who links it or says it is
 *    a separate purchase (`markNotDuplicate`).
 *
 * With the source off nothing here does anything: a line is costed exactly as it always was.
 */
class PurchaseOrderInvoiceLinks
{
    public function __construct(private readonly PurchaseOrderSchema $schema)
    {
    }

    /**
     * Checks the lines that name a purchase-order line and says which platform supplier the invoice is from (when its lines say so).
     *
     * @param array<string, mixed> $invoice the invoice's attributes
     * @param list<array<string, mixed>> $rows the lines' attributes
     * @throws InvalidContractChange
     */
    public function supplierOf(array $invoice, array $rows): ?int
    {
        $suppliers = [];
        foreach ($rows as $index => $row) {
            if (!isset($row['purchase_order_item_id'])) {
                continue;
            }
            $line = $index + 1;
            if ($invoice['kind'] !== 'purchase' || !$this->enabled()) {
                throw new InvalidContractChange("Line {$line}: a line can be linked to a purchase order line only on a purchase invoice, with the purchase-order source switched on (OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=on) and the platform's purchase orders available.");
            }
            $target = $this->purchaseOrderLine((int) $row['purchase_order_item_id']);
            if ($target === null || $target->seller_id !== null) {
                throw new InvalidContractChange("Line {$line}: purchase order line {$row['purchase_order_item_id']} does not exist among the platform's own purchase orders.");
            }
            if (!isset($row['product_id']) || (int) $row['product_id'] !== (int) $target->product_id || !isset($row['quantity']) || !Money::of($row['quantity'])->isPositive()) {
                throw new InvalidContractChange("Line {$line}: a line linked to a purchase order line names the same product and a quantity.");
            }
            $suppliers[(int) $target->supplier_id] = (int) $target->supplier_id;
        }
        $suppliers = array_filter($suppliers);
        if (count($suppliers) > 1) {
            throw new InvalidContractChange('The lines are linked to purchase orders of different suppliers: an invoice is from one.');
        }

        return $suppliers === [] ? null : reset($suppliers);
    }

    /**
     * The received purchase-order line each unlinked purchase line looks like, by the line's position: the same supplier (the platform's supplier of the invoice, or one of the
     * invoice's company name), product and quantity, received, and not billed by another invoice line yet.
     *
     * @param array<string, mixed> $invoice
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    public function resemblances(array $invoice, array $rows): array
    {
        if ($invoice['kind'] !== 'purchase' || !$this->enabled()) {
            return [];
        }

        $found = [];
        foreach ($rows as $index => $row) {
            if (isset($row['purchase_order_item_id']) || !isset($row['product_id'], $row['quantity']) || !Money::of($row['quantity'])->isPositive()) {
                continue;
            }
            $candidate = $this->receivedLineLike($invoice, (int) $row['product_id'], (int) round($row['quantity']));
            if ($candidate !== null) {
                $found[$index] = $candidate;
            }
        }

        return $found;
    }

    /** What the order says the goods of a linked line cost, in the order's currency: its unit cost for the quantity billed. Null when the order line is not there any more. */
    public function expectedCost(OperationalCostInvoiceItem $item): ?array
    {
        $target = $this->purchaseOrderLine((int) $item->purchase_order_item_id);

        return $target === null ? null : ['amount' => Money::of($target->unit_cost)->times($item->quantity), 'currency' => CurrencyCode::canonical($target->currency)];
    }

    /** @throws InvalidContractChange when the invoice cannot be approved while it is held, or while a link has nothing to link to */
    public function assertMayBeApproved(OperationalCostInvoice $invoice, Collection $items): void
    {
        if ($items->contains(static fn (OperationalCostInvoiceItem $item): bool => $item->purchase_order_item_id === null && $item->resembles_purchase_order_item_id !== null)) {
            throw new InvalidContractChange('This invoice has lines that look like goods already received on a purchase order: confirm it is a separate purchase before approving it.');
        }
        if ($invoice->kind === 'purchase' && $items->contains(static fn (OperationalCostInvoiceItem $item): bool => $item->purchase_order_item_id !== null) && !$this->enabled()) {
            throw new InvalidContractChange('This invoice is linked to purchase order lines, whose layers come from the purchase-order source: switch it on (OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=on) before approving it, or the goods would have no layer at all.');
        }
    }

    /** A person has looked: the lines are not copies of goods an order received. */
    public function release(OperationalCostInvoice $invoice): void
    {
        if (Schema::hasColumn('operational_cost_invoice_items', 'resembles_purchase_order_item_id')) {
            OperationalCostInvoiceItem::where('invoice_id', $invoice->id)->whereNotNull('resembles_purchase_order_item_id')->update(['resembles_purchase_order_item_id' => null]);
        }
    }

    private function enabled(): bool
    {
        return PurchaseOrderSourceMode::active() && $this->schema->ready();
    }

    private function purchaseOrderLine(int $id): ?object
    {
        return DB::table('purchase_order_items as item')->join('purchase_orders as po', 'po.id', '=', 'item.purchase_order_id')
            ->where('item.id', $id)->first(['item.product_id', 'item.unit_cost', 'po.seller_id', 'po.supplier_id', 'po.currency']);
    }

    private function receivedLineLike(array $invoice, int $productId, int $quantity): ?int
    {
        $query = DB::table('purchase_order_items as item')->join('purchase_orders as po', 'po.id', '=', 'item.purchase_order_id')
            ->whereNull('po.seller_id')->where('item.product_id', $productId)->where('item.qty_received', $quantity)
            ->whereNotIn('item.id', DB::table('operational_cost_invoice_items')->whereNotNull('purchase_order_item_id')->select('purchase_order_item_id'));

        $query = $this->fromTheSupplierOf($query, $invoice);

        return $query === null ? null : $query->orderByDesc('item.id')->value('item.id');
    }

    private function fromTheSupplierOf(Builder $query, array $invoice): ?Builder
    {
        if (isset($invoice['supplier_id'])) {
            return $query->where('po.supplier_id', $invoice['supplier_id']);
        }
        if (!Schema::hasTable('suppliers') || !Schema::hasColumn('suppliers', 'name')) {
            return null;
        }

        return $query->whereIn('po.supplier_id', DB::table('suppliers')->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $invoice['party_name']))])->select('id'));
    }
}
