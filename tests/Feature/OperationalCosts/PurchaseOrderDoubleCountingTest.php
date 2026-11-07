<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Services\OperationalCosts\Data\LayerReceipt;
use App\Services\OperationalCosts\InventoryCostLayerService;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\PurchaseOrders\PlatformReconciliation;
use App\Services\OperationalCosts\Sales\SaleCostService;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsPurchaseOrderWorld;
use Tests\Support\CreatesOperationalCostData;
use Tests\TestCase;

/**
 * The same goods are costed once. An invoice or a cost typed by hand and a purchase order's receipt may be the same goods; with the purchase-order source on, an invoice that may be
 * goods an order received is held when it is saved and when it is approved (see PurchaseOrderInvoiceLinksTest), and a receipt that an invoice or a typed cost may already hold is not
 * layered unless a person says it is a separate purchase. The platform's tables are INFERRED.
 */
class PurchaseOrderDoubleCountingTest extends TestCase
{
    use BuildsPurchaseOrderWorld;
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
        $this->setUpPurchaseOrderWorld();
    }

    private function typedLayer(int $product, string $sourceType = 'manual', ?string $supplierName = 'Acme Pharma Supply', string $receivedAt = '2026-11-10'): InventoryCostLayer
    {
        return app(InventoryCostLayerService::class)->receive(new LayerReceipt(
            locationId: $this->own->id,
            productId: $product,
            quantity: 10,
            unitCost: Money::of('12.5'),
            currency: 'SAR',
            receivedAt: CarbonImmutable::parse($receivedAt),
            sourceType: $sourceType,
            identity: 'typed-' . $sourceType . '-' . $product . '-' . $receivedAt,
            sourceRef: 'typed',
            supplierName: $supplierName,
        ));
    }

    public function test_goods_an_invoice_billed_before_the_order_was_received_are_not_layered_a_second_time_when_the_order_is_received(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame(InvoiceResult::CREATED, $invoice->result, 'Nothing was received yet: nothing resembles it.');
        $this->invoices()->approve($invoice->invoice, adminId: 1);
        $this->receiveOnPlatform($line, 10);

        $run = $this->applyLayers();

        $this->assertSame(10, (int) DB::table('products')->where('id', $product)->value('current_stock'), 'The platform holds ten units.');
        $this->assertSame(10, $this->layerUnits($product), 'The module holds the same ten units, once.');
        $this->assertSame(['purchase_invoice'], InventoryCostLayer::pluck('source_type')->all());
        $this->assertSame([['covered_by_other_layers' => 1], [$line->id], 0], [$run->refused, $run->coveredLines, $run->created]);
    }

    public function test_the_reconciliation_has_no_surplus_to_show_when_the_invoice_came_first(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']])->invoice, adminId: 1);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();

        $rows = collect(app(PlatformReconciliation::class)->report()['products']['rows'])->where('product_id', $product);

        $this->assertSame([], $rows->all(), 'The layers and the platform agree: ten units, so the product is not listed.');
    }

    public function test_a_later_delivery_at_a_higher_cost_is_costed_at_its_own_price_not_at_the_price_of_a_surplus_layer(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']])->invoice, adminId: 1);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
        $second = $this->purchaseOrder($this->supplier('Later Supplier'), [[$product, 10, 15.0]]);
        $this->receiveOnPlatform($second->items->first(), 10);
        $this->applyLayers();
        $this->storefrontSale(orderId: 981, productId: $product);
        DB::table('order_details')->where('order_id', 981)->update(['qty' => 20]);
        app(SaleCostService::class)->refresh(app(SaleDocumentReader::class)->forOrder(981));

        $cost = OperationalCostEntry::where('subject_type', 'order_line')->where('product_id', $product)->where('cost_component', 'cogs')->where('is_effective', true)->sole();

        $this->assertSame('275.000000', (string) $cost->amount, 'Twenty real units were bought: 10 at 12.50 and 10 at 15.00.');
    }

    public function test_an_invoice_for_the_ordered_quantity_entered_during_a_partial_receipt_is_held_and_does_not_double_the_layers(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 6);

        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame(InvoiceResult::RESEMBLES_RECEIPT, $invoice->result);
        $this->receiveOnPlatform($line, 4);
        $this->applyLayers();

        $this->assertSame(['purchase_order'], InventoryCostLayer::pluck('source_type')->all(), 'The order\'s ten units, none for the held invoice.');
        $this->assertSame(10, $this->layerUnits($product));
    }

    public function test_an_invoice_for_part_of_a_received_line_is_held_and_does_not_double_those_units(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();

        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 6, 'amount' => '75.00']]);

        $this->assertSame(InvoiceResult::RESEMBLES_RECEIPT, $invoice->result);
        $this->assertSame(10, $this->layerUnits($product));
    }

    public function test_a_held_invoice_a_person_says_is_a_separate_purchase_adds_its_own_layer(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $invoice = $this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame(1, InventoryCostLayer::count(), 'Held: nothing was layered.');

        $this->invoices()->markNotDuplicate($invoice->invoice, adminId: 1);
        $this->invoices()->approve($invoice->invoice->fresh(), adminId: 1);

        $this->assertSame(['purchase_order', 'purchase_invoice'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all());
    }

    public function test_a_person_who_names_the_line_as_a_separate_purchase_gets_its_layer_too_and_the_decision_is_in_the_audit_line(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']])->invoice, adminId: 1);
        $this->receiveOnPlatform($line, 10);
        $this->applyLayers();
        $this->assertSame(1, InventoryCostLayer::count());

        $run = $this->applyLayers(included: [$line->id]);

        $this->assertSame([1, 10], [$run->created, $run->createdUnits]);
        $this->assertSame(['purchase_invoice', 'purchase_order'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all());
        $context = json_decode((string) DB::table('audit_logs')->where('action', 'operational_cost.layers_from_purchase_orders')->orderByDesc('id')->value('context'), true);
        $this->assertSame([$line->id], $context['included_lines'], 'The decision is written down.');
    }

    public function test_a_cost_typed_by_hand_before_the_receipt_is_not_layered_a_second_time_either(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->typedLayer($product);
        $this->receiveOnPlatform($line, 10);

        $run = $this->applyLayers();

        $this->assertSame(['covered_by_other_layers' => 1], $run->refused);
        $this->assertSame(['manual'], InventoryCostLayer::pluck('source_type')->all());
    }

    /** @return array<string, array{0: string, 1: ?string, 2: string, 3: bool}> */
    public static function otherLayers(): array
    {
        return [
            'an opening balance is the owner\'s own count' => ['opening_balance', 'Acme Pharma Supply', '2026-11-10', false],
            'a transfer is not new goods' => ['transfer', 'Acme Pharma Supply', '2026-11-10', false],
            'another supplier\'s goods' => ['purchase_invoice', 'Somebody Else', '2026-11-10', false],
            'a layer from before the cutover is the opening balance\'s' => ['purchase_invoice', 'Acme Pharma Supply', '2026-10-31', false],
            'the same supplier spelled another way' => ['manual', '  ACME pharma supply ', '2026-11-10', true],
            'a supplier nobody typed cannot be told apart' => ['manual', null, '2026-11-10', true],
        ];
    }

    #[DataProvider('otherLayers')]
    public function test_only_goods_bought_from_the_same_supplier_since_the_cutover_cover_a_receipt(string $sourceType, ?string $supplierName, string $receivedAt, bool $covers): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->typedLayer($product, $sourceType, $supplierName, $receivedAt);
        $this->receiveOnPlatform($line, 10);

        $run = $this->applyLayers(since: '2026-11-01');

        $this->assertSame($covers ? [0, ['covered_by_other_layers' => 1]] : [1, []], [$run->created, $run->refused]);
    }

    public function test_a_line_the_order_already_layers_goes_on_being_layered_whatever_is_typed_later(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $line = $this->receiveOnPlatform($line, 6);
        $this->applyLayers();
        $this->typedLayer($product);
        $this->receiveOnPlatform($line, 4);

        $run = $this->applyLayers();

        $this->assertSame([1, 4, []], [$run->created, $run->createdUnits, $run->refused], 'A person decided about the typed cost when they typed it.');
    }

    public function test_the_dry_run_lists_the_covered_lines_and_the_command_takes_the_decision_as_an_option(): void
    {
        [$product, $line] = $this->placedLine(ordered: 10, cost: 12.5);
        $this->invoices()->approve($this->purchaseInvoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']])->invoice, adminId: 1);
        $this->receiveOnPlatform($line, 10);

        $this->assertSame(0, Artisan::call('costs:layers-from-purchase-orders', ['--since' => '2026-11-01']));
        $preview = Artisan::output();
        $this->assertStringContainsString('covered_by_other_layers', $preview);
        $this->assertStringContainsString('covered lines: ' . $line->id, $preview);
        $this->assertStringContainsString('--include=' . $line->id, $preview);

        $this->assertSame(0, Artisan::call('costs:layers-from-purchase-orders', ['--json' => true, '--since' => '2026-11-01', '--include' => (string) $line->id]));
        $included = json_decode(Artisan::output(), true);
        $this->assertSame([1, []], [$included['planned_layers'], $included['covered_lines']], 'Naming the line in a dry run shows what it would do.');
        $this->assertSame(1, InventoryCostLayer::count(), 'A dry run writes nothing.');

        $this->assertSame(0, Artisan::call('costs:layers-from-purchase-orders', ['--apply' => true, '--since' => '2026-11-01', '--include' => $line->id . ',' . $line->id]));
        $this->assertSame(['purchase_invoice', 'purchase_order'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all());
    }

    public function test_the_lines_to_include_are_ids_and_anything_else_is_refused_before_anything_is_read(): void
    {
        $this->assertSame(1, Artisan::call('costs:layers-from-purchase-orders', ['--include' => '1,x']));
        $this->assertStringContainsString('ids separated by commas', Artisan::output());
        $this->assertSame(1, Artisan::call('costs:layers-from-purchase-orders', ['--include' => '']));
    }
}
