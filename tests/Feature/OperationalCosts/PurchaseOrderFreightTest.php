<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoiceItem;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\TestCase;

/**
 * The freight of a purchase invoice reaches the cost of the goods it was paid for, or a cost entry a person sees: it never vanishes. It is shared over ALL the goods lines of the
 * invoice by value. The share of a line that makes a layer of its own goes into that layer's landed cost; the share of a line linked to a purchase order line, whose layer the order
 * makes and is not changed after the fact, is recorded as an `inbound_freight` cost entry of its own. The platform's tables are INFERRED.
 */
class PurchaseOrderFreightTest extends TestCase
{
    use BuildsPurchaseOrderWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    /** @return array{0: int, 1: int} a product and its order line, received in full and layered from the order */
    private function layeredReceipt(): array
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 10);
        $this->applyLayers();

        return [$product, $line->id];
    }

    /** @return list<array{0: string, 1: string, 2: string}> state, amount and allocation of every effective freight entry */
    private function freightEntries(): array
    {
        return OperationalCostEntry::where('cost_component', 'inbound_freight')->where('is_effective', true)->orderBy('id')->get()
            ->map(static fn (OperationalCostEntry $entry): array => [$entry->state->value, (string) $entry->amount, (string) $entry->allocation_status])->all();
    }

    public function test_the_freight_of_a_fully_linked_invoice_is_a_cost_entry_of_its_own_and_the_orders_layer_is_left_as_it_is(): void
    {
        [$product, $line] = $this->layeredReceipt();
        $invoice = $this->purchaseInvoice([
            ['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
        ])->invoice;

        $this->invoices()->approve($invoice, adminId: 1);

        $this->assertSame([['actual', '20.000000', 'unallocated']], $this->freightEntries());
        $this->assertSame('0.000000', InventoryCostLayer::sole()->landed_unit_cost, 'Changing a layer\'s cost after the fact is not built.');
        $freight = OperationalCostInvoiceItem::whereNull('product_id')->sole();
        $this->assertSame(OperationalCostEntry::where('cost_component', 'inbound_freight')->value('id'), $freight->entry_id, 'The invoice line says where its amount went.');
    }

    public function test_the_freight_of_a_mixed_invoice_is_shared_over_all_its_goods_by_value_the_linked_share_is_an_entry_and_the_rest_is_in_the_layer(): void
    {
        [$linked, $line] = $this->layeredReceipt();
        $unlinked = $this->platformProduct();
        $invoice = $this->purchaseInvoice([
            ['product_id' => $linked, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line],
            ['product_id' => $unlinked, 'quantity' => 10, 'amount' => '125.00'],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
        ])->invoice;

        $this->invoices()->approve($invoice, adminId: 1);

        $this->assertSame('1.000000', InventoryCostLayer::where('product_id', $unlinked)->sole()->landed_unit_cost, 'Half of the goods by value, half of the 20.00 freight, over 10 units.');
        $this->assertSame([['actual', '10.000000', 'unallocated']], $this->freightEntries(), 'The other half belongs to the linked goods.');
    }

    public function test_freight_on_an_invoice_whose_goods_all_make_a_layer_is_in_the_layers_and_makes_no_entry(): void
    {
        $first = $this->platformProduct();
        $second = $this->platformProduct();
        $invoice = $this->purchaseInvoice([
            ['product_id' => $first, 'quantity' => 10, 'amount' => '100.00'],
            ['product_id' => $second, 'quantity' => 5, 'amount' => '100.00'],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
        ])->invoice;

        $this->invoices()->approve($invoice, adminId: 1);

        $this->assertSame(['1.000000', '2.000000'], [InventoryCostLayer::where('product_id', $first)->sole()->landed_unit_cost, InventoryCostLayer::where('product_id', $second)->sole()->landed_unit_cost]);
        $this->assertSame([], $this->freightEntries());
    }

    public function test_freight_on_an_invoice_with_no_goods_lines_is_a_cost_entry_and_not_lost(): void
    {
        $invoice = $this->purchaseInvoice([['cost_component' => 'inbound_freight', 'amount' => '20.00']])->invoice;

        $this->invoices()->approve($invoice, adminId: 1);

        $this->assertSame([['actual', '20.000000', 'unallocated']], $this->freightEntries());
    }

    public function test_two_freight_lines_are_each_shared_and_the_linked_shares_are_one_entry_for_each(): void
    {
        [$linked, $line] = $this->layeredReceipt();
        $unlinked = $this->platformProduct();
        $invoice = $this->purchaseInvoice([
            ['product_id' => $linked, 'quantity' => 10, 'amount' => '75.00', 'purchase_order_item_id' => $line],
            ['product_id' => $unlinked, 'quantity' => 10, 'amount' => '25.00'],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
            ['cost_component' => 'inbound_freight', 'amount' => '8.00'],
        ])->invoice;

        $this->invoices()->approve($invoice, adminId: 1);

        $this->assertSame('0.700000', InventoryCostLayer::where('product_id', $unlinked)->sole()->landed_unit_cost, 'A quarter of 28.00 over ten units.');
        $this->assertSame([['actual', '15.000000', 'unallocated'], ['actual', '6.000000', 'unallocated']], $this->freightEntries(), 'Three quarters of each freight line.');
    }

    public function test_goods_worth_nothing_share_the_freight_equally_and_approving_again_records_nothing_twice(): void
    {
        [$linked, $line] = $this->layeredReceipt();
        $unlinked = $this->platformProduct();
        $invoice = $this->purchaseInvoice([
            ['product_id' => $linked, 'quantity' => 10, 'amount' => '0.00', 'purchase_order_item_id' => $line],
            ['product_id' => $unlinked, 'quantity' => 10, 'amount' => '0.00'],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
        ], number: 'INV-F')->invoice;

        $this->invoices()->approve($invoice, adminId: 1);
        $this->invoices()->approve($invoice->fresh(), adminId: 1);

        $this->assertSame('1.000000', InventoryCostLayer::where('product_id', $unlinked)->sole()->landed_unit_cost);
        $this->assertSame([['actual', '10.000000', 'unallocated']], $this->freightEntries());
    }
}
