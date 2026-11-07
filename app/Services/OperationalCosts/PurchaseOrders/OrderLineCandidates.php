<?php

namespace App\Services\OperationalCosts\PurchaseOrders;

use App\Models\OperationalCostInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own order lines that goods of a product from a supplier may be the same goods as: lines that have received more units than the invoice lines linked to them have
 * billed, whatever the quantity of the invoice and the state of the order. With the purchase-order source on, the goods of an order are costed from its receipt, so a purchase invoice
 * line or a hand-typed cost for the same product and supplier that names no order line is held for a person while such a line exists: a supplier bills in parts and in other
 * quantities than it delivers, and nothing else tells the two apart. A line is no longer a candidate once linked invoice lines have billed all that it received. Goods of an order
 * that has received nothing yet are not looked at here: a cost made of them first is found when the order is layered (see CoveringLayers).
 */
class OrderLineCandidates
{
    /** The kinds of hand-typed layer that are goods bought from somebody (an opening balance is the owner's own count and is never held). */
    public const TYPED_SOURCES = ['purchase_invoice', 'manual'];

    public function __construct(private readonly PurchaseOrderSchema $schema)
    {
    }

    /** True while the purchase-order source is on and everything it needs is there. */
    public function enabled(): bool
    {
        return PurchaseOrderSourceMode::active() && $this->schema->ready();
    }

    /**
     * @param int|null $supplierId the platform's supplier, when the invoice names one
     * @param string|null $supplierName the company as the invoice or the typed cost spells it; with neither a supplier nor a name any supplier's lines are candidates
     * @return list<int> line ids, the newest first
     */
    public function lines(int $productId, ?int $supplierId, ?string $supplierName, int $limit = 5): array
    {
        $billed = '(select coalesce(sum(billed.quantity), 0) from operational_cost_invoice_items billed join operational_cost_invoices bill on bill.id = billed.invoice_id where billed.purchase_order_item_id = item.id and bill.status <> ?)';
        $query = DB::table('purchase_order_items as item')->join('purchase_orders as po', 'po.id', '=', 'item.purchase_order_id')
            ->whereNull('po.seller_id')->where('item.product_id', $productId)->whereRaw("item.qty_received > {$billed}", [OperationalCostInvoice::STATUS_VOID]);

        if ($supplierId !== null) {
            $query->where('po.supplier_id', $supplierId);
        } elseif (($name = mb_strtolower(trim((string) $supplierName))) !== '') {
            if (!Schema::hasTable('suppliers') || !Schema::hasColumn('suppliers', 'name')) {
                return [];
            }
            $query->whereIn('po.supplier_id', DB::table('suppliers')->whereRaw('lower(name) = ?', [$name])->select('id'));
        }

        return $query->orderByDesc('item.id')->limit($limit)->pluck('item.id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * The candidates of a cost typed by hand; none while the source is not on, for a source that is not goods bought, or for stock that is not the platform's own.
     *
     * @return list<int>
     */
    public function forTypedCost(int $productId, string $sourceType, ?string $supplierName, int $sellerId): array
    {
        if ($sellerId !== 0 || !in_array($sourceType, self::TYPED_SOURCES, true) || !$this->enabled()) {
            return [];
        }

        return $this->lines(productId: $productId, supplierId: null, supplierName: $supplierName);
    }
}
