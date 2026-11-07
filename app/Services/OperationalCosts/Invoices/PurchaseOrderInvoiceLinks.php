<?php

namespace App\Services\OperationalCosts\Invoices;

use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Services\Fulfillment\Support\CurrencyCode;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\PurchaseOrders\OrderLineCandidates;
use App\Services\OperationalCosts\PurchaseOrders\PlatformProcurement;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseLine;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseLineEvaluator;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Where a purchase invoice line meets the platform's purchase orders, so that the same goods are not costed twice. With the purchase-order source on:
 *
 *  - a line that names the purchase-order line it bills (`purchase_order_item_id`) makes no layer of its own, because the order's receipt is the layer; it records a cost variance
 *    against what the order says the goods cost;
 *  - a line that names none but may be goods an order covers (the same product from the same supplier while an order line has received units that no linked invoice line has
 *    billed, whatever the quantity) holds the invoice for a person, who links it or says it is a separate purchase (`markNotDuplicate`). The hold is decided when the invoice is
 *    saved and again when it is approved, until a person has looked. Goods of an order that has received nothing yet are not held here: if the invoice is approved first, the
 *    order's receipt is not layered a second time (see CoveringLayers).
 *
 * With the source off nothing here does anything: a line is costed exactly as it always was.
 */
class PurchaseOrderInvoiceLinks
{
    /** The mark of a line a person has looked at and said is a separate purchase (NULL: never looked at, or nothing to compare it with; an order line's id: held). */
    public const REVIEWED = 0;

    public function __construct(
        private readonly OrderLineCandidates $candidates,
        private readonly PlatformProcurement $procurement,
        private readonly PurchaseLineEvaluator $evaluator,
    ) {
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
            $target = $this->procurement->line((int) $row['purchase_order_item_id']);
            if ($target === null || $target->orderSellerId !== null) {
                throw new InvalidContractChange("Line {$line}: purchase order line {$row['purchase_order_item_id']} does not exist among the platform's own purchase orders.");
            }
            if (!isset($row['product_id']) || (int) $row['product_id'] !== $target->productId || !isset($row['quantity']) || !Money::of($row['quantity'])->isPositive()) {
                throw new InvalidContractChange("Line {$line}: a line linked to a purchase order line names the same product and a quantity.");
            }
            $this->assertMakesALayer($target, "Line {$line}");
            $suppliers[(int) $target->supplierId] = (int) $target->supplierId;
        }
        $suppliers = array_filter($suppliers);
        if (count($suppliers) > 1) {
            throw new InvalidContractChange('The lines are linked to purchase orders of different suppliers: an invoice is from one.');
        }

        return $suppliers === [] ? null : reset($suppliers);
    }

    /**
     * The order line each unlinked purchase line may be the goods of, by the line's position (see OrderLineCandidates).
     *
     * @param array<string, mixed> $invoice
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    public function resemblances(array $invoice, array $rows): array
    {
        if ($invoice['kind'] !== 'purchase' || !$this->candidates->enabled()) {
            return [];
        }

        $found = [];
        foreach ($rows as $index => $row) {
            if (isset($row['purchase_order_item_id']) || !isset($row['product_id'], $row['quantity']) || !Money::of($row['quantity'])->isPositive()) {
                continue;
            }
            $candidate = $this->candidates->lines(productId: (int) $row['product_id'], supplierId: $invoice['supplier_id'] ?? null, supplierName: $invoice['party_name'] ?? null, limit: 1)[0] ?? null;
            if ($candidate !== null) {
                $found[$index] = $candidate;
            }
        }

        return $found;
    }

    /**
     * Looks again, as the invoice is about to be approved, at the lines nobody has looked at: an order may have been made since the invoice was saved, or the invoice was saved
     * while the source was not on. A line that may be goods an order covers is marked and the invoice put under review. True when anything is held.
     */
    public function holdForReview(OperationalCostInvoice $invoice): bool
    {
        if ($invoice->kind !== 'purchase' || !$this->candidates->enabled()) {
            return false;
        }

        $held = false;
        foreach ($invoice->items()->whereNull('purchase_order_item_id')->whereNull('resembles_purchase_order_item_id')->whereNotNull('product_id')->where('quantity', '>', 0)->get() as $item) {
            $candidate = $this->candidates->lines(productId: (int) $item->product_id, supplierId: $invoice->supplier_id, supplierName: $invoice->party_name, limit: 1)[0] ?? null;
            if ($candidate !== null) {
                $item->forceFill(['resembles_purchase_order_item_id' => $candidate])->save();
                $held = true;
            }
        }
        if ($held) {
            $invoice->forceFill(['status' => OperationalCostInvoice::STATUS_UNDER_REVIEW])->save();
        }

        return $held;
    }

    /** What the order says the goods of a linked line cost, in the order's currency: its unit cost for the quantity billed. Null when the order line is not there any more. */
    public function expectedCost(OperationalCostInvoiceItem $item): ?array
    {
        $target = $this->procurement->line((int) $item->purchase_order_item_id);

        return $target === null ? null : ['amount' => Money::of($target->unitCost)->times($item->quantity), 'currency' => CurrencyCode::canonical($target->currency)];
    }

    /** @throws InvalidContractChange when the invoice cannot be approved while it is held, or while a link has nothing to link to or leads to a line that makes no layer */
    public function assertMayBeApproved(OperationalCostInvoice $invoice, Collection $items): void
    {
        if ($items->contains(static fn (OperationalCostInvoiceItem $item): bool => $item->purchase_order_item_id === null && (int) $item->resembles_purchase_order_item_id > self::REVIEWED)) {
            throw new InvalidContractChange('This invoice has lines that may be goods a purchase order already received: link them to the order line or confirm it is a separate purchase before approving it.');
        }
        $linked = $items->filter(static fn (OperationalCostInvoiceItem $item): bool => $item->purchase_order_item_id !== null);
        if ($invoice->kind !== 'purchase' || $linked->isEmpty()) {
            return;
        }
        if (!$this->enabled()) {
            throw new InvalidContractChange('This invoice is linked to purchase order lines, whose layers come from the purchase-order source: switch it on (OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS=on) before approving it, or the goods would have no layer at all.');
        }
        foreach ($linked as $item) {
            $target = $this->procurement->line((int) $item->purchase_order_item_id);
            if ($target === null) {
                throw new InvalidContractChange("Line {$item->line_no}: purchase order line {$item->purchase_order_item_id} is not there any more, so the goods would have no cost layer at all.");
            }
            $this->assertMakesALayer($target, "Line {$item->line_no}");
        }
    }

    /** A person has looked: the lines are not the goods of an order. They are marked so, and are not held again. */
    public function release(OperationalCostInvoice $invoice): void
    {
        if (Schema::hasColumn('operational_cost_invoice_items', 'resembles_purchase_order_item_id')) {
            OperationalCostInvoiceItem::where('invoice_id', $invoice->id)->where('resembles_purchase_order_item_id', '>', self::REVIEWED)->update(['resembles_purchase_order_item_id' => self::REVIEWED]);
        }
    }

    private function enabled(): bool
    {
        return $this->candidates->enabled();
    }

    /**
     * A linked line makes no layer of its own, because the order's receipt is the layer: so the order line has to be able to make one. The reason it cannot is the one the layers
     * report (a cost of zero, a product with variants, a currency with no rate, ...).
     *
     * @throws InvalidContractChange
     */
    private function assertMakesALayer(PurchaseLine $target, string $where): void
    {
        $refusal = $this->evaluator->verdict($target, $this->procurement->products([(int) $target->productId])[(int) $target->productId] ?? null)->refusal;
        if ($refusal !== null) {
            throw new InvalidContractChange("{$where}: purchase order line {$target->id} cannot be made into a cost layer ({$refusal->value}: {$refusal->meaning()}), and a linked line makes none of its own, so the goods would have no cost layer at all. Correct the order line, or enter this line without the link and confirm that it is a separate purchase.");
        }
    }
}
