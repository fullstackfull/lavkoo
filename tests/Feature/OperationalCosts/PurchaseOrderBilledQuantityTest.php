<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\OperationalCostReconciliation;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\TestCase;

/**
 * An order line is billed for the units it received (or, before the goods come, the units it ordered), once. A linked invoice line is compared with the order's cost for the units of
 * the line that no other approved invoice has billed, so a second invoice for the same units, or one for more units than the line holds, is a variance for a person to look at and not
 * a second "matched". The platform's tables are INFERRED.
 */
class PurchaseOrderBilledQuantityTest extends TestCase
{
    use BuildsPurchaseOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    /** @return array<string, string> the status of the comparison of each invoice's line, by invoice number */
    private function statuses(): array
    {
        return OperationalCostReconciliation::query()->join('operational_cost_invoices as invoice', 'invoice.id', '=', 'operational_cost_reconciliations.invoice_id')->orderBy('invoice.invoice_number')
            ->pluck('operational_cost_reconciliations.status', 'invoice.invoice_number')->all();
    }

    public function test_billing_the_same_order_line_twice_is_a_variance_on_the_second_invoice(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $first = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], number: 'INV-A')->invoice;
        $this->invoices()->approve($first, adminId: 1);
        $second = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-14'], number: 'INV-B')->invoice;
        $this->invoices()->approve($second, adminId: 1);

        $this->assertSame(['INV-A' => 'matched', 'INV-B' => 'variance'], $this->statuses());
        $row = OperationalCostReconciliation::where('invoice_id', $second->id)->sole();
        $this->assertSame(['0.000000', '125.000000', '125.000000'], [$row->estimated_amount, $row->actual_amount, $row->variance_amount], 'Nothing is left of the line to bill: the whole second invoice is the difference.');
    }

    public function test_a_supplier_that_bills_in_parts_is_matched_part_by_part_against_the_units_left(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 6, 'amount' => '75.00', 'purchase_order_item_id' => $line->id]], number: 'INV-A')->invoice, adminId: 1);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 4, 'amount' => '50.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-12'], number: 'INV-B')->invoice, adminId: 1);

        $this->assertSame(['INV-A' => 'matched', 'INV-B' => 'matched'], $this->statuses());
    }

    public function test_an_invoice_for_more_units_than_the_line_holds_is_a_variance_for_the_excess(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 12, 'amount' => '150.00', 'purchase_order_item_id' => $line->id]])->invoice;
        $this->invoices()->approve($invoice, adminId: 1);

        $row = OperationalCostReconciliation::sole();
        $this->assertSame(['variance', '125.000000', '150.000000', '25.000000'], [$row->status, $row->estimated_amount, $row->actual_amount, $row->variance_amount], 'Two units more than the ten the order has.');
    }

    public function test_an_invoice_that_comes_before_the_goods_is_compared_with_the_ordered_quantity_and_a_second_one_for_the_same_units_is_not(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], number: 'INV-A')->invoice, adminId: 1);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-12'], number: 'INV-B')->invoice, adminId: 1);

        $this->assertSame(['INV-A' => 'matched', 'INV-B' => 'variance'], $this->statuses());
    }

    public function test_the_units_are_used_up_in_the_order_the_invoices_are_approved_not_the_order_they_were_saved(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $first = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], number: 'INV-A')->invoice;
        $second = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-12'], number: 'INV-B')->invoice;

        $this->invoices()->approve($second, adminId: 1);
        $this->invoices()->approve($first, adminId: 1);

        $this->assertSame(['INV-A' => 'variance', 'INV-B' => 'matched'], $this->statuses());
    }
}
