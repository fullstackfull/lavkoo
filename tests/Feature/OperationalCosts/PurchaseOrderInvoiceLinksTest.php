<?php

namespace Tests\Feature\OperationalCosts;

use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostInvoiceItem;
use App\Models\OperationalCostReconciliation;
use App\Models\PurchaseOrderItem;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\PurchaseOrderInvoiceLinks;
use App\Services\OperationalCosts\PurchaseOrders\PurchaseOrderLayerService;
use App\Services\SalesChannels\Inventory\InventoryLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\BuildsPlatformProcurementSchema;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/**
 * A purchase invoice and the goods a purchase order already received: one cost, not two. A line linked to the order's line makes no second layer and records how far it is from the
 * order's cost; a line that looks like goods an order received waits for a person. Everything here needs the purchase-order source on. The platform's tables are INFERRED.
 */
class PurchaseOrderInvoiceLinksTest extends TestCase
{
    use BuildsAuditLogTable;
    use BuildsPlatformProcurementSchema;
    use BuildsSalesChannelSchema;

    private InventoryLocation $own;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->buildPlatformProcurementSchema();
        $this->buildAuditLogTable();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR', 'operational_costs.enabled' => true, 'operational_costs.sources.purchase_orders' => 'on']);
        [, , $this->own] = app(InventoryLocationService::class)->ensureDefaults();
        $this->invoices = app(InvoiceService::class);
    }

    /** @return array{0: int, 1: PurchaseOrderItem, 2: int} a product, its line (received in full and layered from the order) and the supplier's id */
    private function layeredReceipt(int $quantity = 10, float $cost = 12.5, string $supplierName = 'Acme Pharma Supply'): array
    {
        $product = $this->platformProduct();
        $supplier = $this->supplier($supplierName);
        $order = $this->purchaseOrder($supplier, [[$product, $quantity, $cost]]);
        $line = $this->receiveOnPlatform($order->items->first(), $quantity);
        app(PurchaseOrderLayerService::class)->apply(since: CarbonImmutable::parse('2000-01-01'));

        return [$product, $line, $supplier->id];
    }

    /** @param list<array<string, mixed>> $lines @param array<string, mixed> $header */
    private function invoice(array $lines, array $header = [], string $number = 'INV-1'): InvoiceResult
    {
        $items = array_map(static fn (array $line): array => $line + ['cost_component' => 'cogs', 'currency' => 'SAR'], $lines);
        $total = array_sum(array_column($lines, 'amount'));

        return $this->invoices->create($header + [
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'acme-pharma', 'party_name' => 'Acme Pharma Supply', 'invoice_number' => $number, 'invoice_date' => '2026-11-10',
            'currency' => 'SAR', 'total' => (string) $total, 'inventory_location_id' => $this->own->id,
        ], $items, adminId: 1);
    }

    public function test_a_line_linked_to_the_orders_line_makes_no_second_layer_and_records_that_it_matches_the_order(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $result = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]]);
        $approved = $this->invoices->approve($result->invoice, adminId: 1);

        $this->assertSame([InvoiceResult::CREATED, 1], [$result->result, $approved]);
        $this->assertSame(1, InventoryCostLayer::count(), 'The order\'s receipt is the layer: the invoice makes none of its own.');
        $this->assertSame('purchase_order', InventoryCostLayer::sole()->source_type);
        $row = OperationalCostReconciliation::sole();
        $this->assertSame(['purchase_order', 'matched', '125.000000', '125.000000', '0.000000'], [$row->basis, $row->status, $row->estimated_amount, $row->actual_amount, $row->variance_amount]);
        $this->assertSame('matched', OperationalCostInvoiceItem::sole()->match_status);
        $this->assertSame($line->id, OperationalCostInvoiceItem::sole()->purchase_order_item_id);
    }

    public function test_a_line_dearer_than_the_order_says_is_a_variance_for_a_person_and_the_layer_keeps_the_orders_cost(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $invoice = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '140.00', 'purchase_order_item_id' => $line->id]])->invoice;
        $this->invoices->approve($invoice, adminId: 1);

        $row = OperationalCostReconciliation::sole();
        $this->assertSame(['variance', '15.000000', '12.0000'], [$row->status, $row->variance_amount, $row->variance_percent]);
        $this->assertSame('partial', OperationalCostInvoiceItem::sole()->match_status);
        $this->assertSame('12.500000', InventoryCostLayer::sole()->unit_cost, 'Changing a layer\'s cost after the fact is not built: a person decides what to do about the difference.');
        $this->assertSame(1, InventoryCostLayer::count());
    }

    public function test_a_cost_in_another_currency_than_the_orders_is_left_for_a_person_to_compare(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $invoice = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '33.00', 'currency' => 'USD', 'purchase_order_item_id' => $line->id]], ['currency' => 'USD'])->invoice;
        $this->invoices->approve($invoice, adminId: 1);

        $row = OperationalCostReconciliation::sole();
        $this->assertSame(['variance', null, null, '33.000000'], [$row->status, $row->estimated_amount, $row->variance_amount, $row->actual_amount]);
    }

    public function test_a_linked_invoice_takes_the_supplier_of_the_order_and_its_freight_is_a_cost_entry_of_its_own_not_added_to_a_layer_it_does_not_make(): void
    {
        [$product, $line, $supplier] = $this->layeredReceipt();

        $invoice = $this->invoice([
            ['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id],
            ['cost_component' => 'inbound_freight', 'amount' => '20.00'],
        ])->invoice;
        $this->invoices->approve($invoice, adminId: 1);

        $this->assertSame($supplier, $invoice->fresh()->supplier_id);
        $this->assertSame('0.000000', InventoryCostLayer::sole()->landed_unit_cost, 'The order\'s layer is not changed after the fact.');
        $this->assertSame(['20.000000'], OperationalCostEntry::where('cost_component', 'inbound_freight')->where('is_effective', true)->pluck('amount')->map(static fn ($amount): string => (string) $amount)->all(), 'The freight is in the ledger, not lost.');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badLinks(): array
    {
        return [
            'a line that does not exist' => ['unknown', 'does not exist'],
            'another product' => ['other_product', 'the same product and a quantity'],
            'no quantity' => ['no_quantity', 'the same product and a quantity'],
            'a vendor\'s purchase order' => ['vendor_order', 'does not exist among the platform\'s own'],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_a_link_that_does_not_lead_to_the_platforms_own_line_for_the_same_product_is_refused_and_nothing_is_saved(string $case, string $message): void
    {
        [$product, $line] = $this->layeredReceipt();
        $vendorOrder = $this->purchaseOrder($this->supplier('Vendor supplier', sellerId: 3), [[$this->platformProduct(), 2, 5.0]], sellerId: 3);
        $target = match ($case) {
            'unknown' => 99999,
            'vendor_order' => $vendorOrder->items->first()->id,
            default => $line->id,
        };
        $row = ['product_id' => $case === 'other_product' ? $this->platformProduct() : ($case === 'vendor_order' ? $vendorOrder->items->first()->product_id : $product), 'quantity' => $case === 'no_quantity' ? null : 10, 'amount' => '125.00', 'purchase_order_item_id' => $target];

        try {
            $this->invoice([array_filter($row, static fn ($value): bool => $value !== null)]);
            $this->fail('The invoice was saved.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
        }
        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_lines_linked_to_orders_of_two_suppliers_are_not_one_invoice(): void
    {
        [$firstProduct, $first] = $this->layeredReceipt(supplierName: 'Acme Pharma Supply');
        [$secondProduct, $second] = $this->layeredReceipt(supplierName: 'Other Supplier');

        $this->expectException(InvalidContractChange::class);
        $this->expectExceptionMessage('different suppliers');
        $this->invoice([
            ['product_id' => $firstProduct, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $first->id],
            ['product_id' => $secondProduct, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $second->id],
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function modesThatAreNotOn(): array
    {
        return ['off' => ['off'], 'shadow' => ['shadow']];
    }

    #[DataProvider('modesThatAreNotOn')]
    public function test_with_the_source_not_on_a_link_is_refused_and_a_line_that_resembles_a_receipt_is_costed_exactly_as_it_always_was(string $mode): void
    {
        [$product, $line] = $this->layeredReceipt();
        config(['operational_costs.sources.purchase_orders' => $mode]);

        try {
            $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]]);
            $this->fail('A link was accepted while the source is not on.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString('source switched on', $refusal->getMessage());
        }

        $same = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame([InvoiceResult::CREATED, OperationalCostInvoice::STATUS_DRAFT], [$same->result, $same->invoice->status]);
        $this->invoices->approve($same->invoice, adminId: 1);
        $this->assertSame(['purchase_order', 'purchase_invoice'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all(), 'The behaviour of a purchase invoice does not change until the source is on.');
    }

    public function test_an_invoice_approved_while_linked_and_the_source_is_off_is_refused_so_the_goods_are_not_left_with_no_layer(): void
    {
        [$product, $line] = $this->layeredReceipt();
        $invoice = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]])->invoice;
        config(['operational_costs.sources.purchase_orders' => 'off']);

        try {
            $this->invoices->approve($invoice, adminId: 1);
            $this->fail('The invoice was approved.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString('switch it on', $refusal->getMessage());
        }
        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertSame(0, OperationalCostReconciliation::count());
    }

    public function test_an_unlinked_line_with_the_supplier_and_product_of_a_receipt_holds_the_invoice_until_a_person_says_it_is_a_separate_purchase(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $result = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);

        $this->assertSame([InvoiceResult::RESEMBLES_RECEIPT, OperationalCostInvoice::STATUS_UNDER_REVIEW, ['looks_like_receipt_' . $line->id]], [$result->result, $result->invoice->status, $result->warnings]);
        $this->assertSame($line->id, OperationalCostInvoiceItem::sole()->resembles_purchase_order_item_id);
        try {
            $this->invoices->approve($result->invoice, adminId: 1);
            $this->fail('The invoice was approved.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString('already received', $refusal->getMessage());
        }
        $this->assertSame(1, InventoryCostLayer::count());

        $this->invoices->markNotDuplicate($result->invoice, adminId: 1);
        $this->assertSame([PurchaseOrderInvoiceLinks::REVIEWED, OperationalCostInvoice::STATUS_DRAFT], [OperationalCostInvoiceItem::sole()->resembles_purchase_order_item_id, $result->invoice->fresh()->status], 'A person has looked: the line is marked so and is not held again.');
        $this->invoices->approve($result->invoice->fresh(), adminId: 1);
        $this->assertSame(['purchase_order', 'purchase_invoice'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all(), 'A person said it is another purchase: it has its own layer.');
    }

    public function test_a_line_for_another_product_or_supplier_or_for_a_receipt_that_linked_lines_have_billed_is_not_held(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $differentProduct = $this->invoice([['product_id' => $this->platformProduct(), 'quantity' => 10, 'amount' => '125.00']], ['invoice_date' => '2026-11-11'], 'INV-P')->result;
        $differentSupplier = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']], ['party_name' => 'Somebody Else', 'invoice_date' => '2026-11-12'], 'INV-S')->result;
        $linked = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-13'], 'INV-L')->result;
        $billedAlready = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']], ['invoice_date' => '2026-11-14'], 'INV-B')->result;

        $this->assertSame([InvoiceResult::CREATED, InvoiceResult::CREATED, InvoiceResult::CREATED, InvoiceResult::CREATED], [$differentProduct, $differentSupplier, $linked, $billedAlready], 'The order\'s line is billed by INV-L: another line of the same size is not "the same goods" again.');
    }

    public function test_a_line_of_another_quantity_than_the_receipt_is_held_as_well_because_a_supplier_bills_in_parts(): void
    {
        [$product, $line] = $this->layeredReceipt();

        $part = $this->invoice([['product_id' => $product, 'quantity' => 6, 'amount' => '75.00']], number: 'INV-6');
        $more = $this->invoice([['product_id' => $product, 'quantity' => 14, 'amount' => '175.00']], ['invoice_date' => '2026-11-11'], 'INV-14');

        $this->assertSame([InvoiceResult::RESEMBLES_RECEIPT, InvoiceResult::RESEMBLES_RECEIPT], [$part->result, $more->result]);
        $this->assertSame([$line->id, $line->id], OperationalCostInvoiceItem::orderBy('id')->pluck('resembles_purchase_order_item_id')->all());
    }

    public function test_the_units_linked_lines_have_billed_are_not_candidates_again_only_the_rest_of_the_receipt_is(): void
    {
        [$product, $line] = $this->layeredReceipt();
        $this->invoice([['product_id' => $product, 'quantity' => 6, 'amount' => '75.00', 'purchase_order_item_id' => $line->id]], number: 'INV-L6');

        $rest = $this->invoice([['product_id' => $product, 'quantity' => 4, 'amount' => '50.00']], ['invoice_date' => '2026-11-11'], 'INV-R4')->result;
        $this->invoice([['product_id' => $product, 'quantity' => 4, 'amount' => '50.00', 'purchase_order_item_id' => $line->id]], ['invoice_date' => '2026-11-12'], 'INV-L4');
        $nothingLeft = $this->invoice([['product_id' => $product, 'quantity' => 4, 'amount' => '50.00']], ['invoice_date' => '2026-11-13'], 'INV-N4')->result;

        $this->assertSame([InvoiceResult::RESEMBLES_RECEIPT, InvoiceResult::CREATED], [$rest, $nothingLeft]);
    }

    public function test_an_invoice_saved_before_the_goods_came_is_held_when_it_is_approved_after_the_order_received_them(): void
    {
        $product = $this->platformProduct();
        $order = $this->purchaseOrder($this->supplier('Acme Pharma Supply'), [[$product, 10, 12.5]]);
        $saved = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']]);
        $this->assertSame(InvoiceResult::CREATED, $saved->result, 'Nothing was received when it was saved.');

        $this->receiveOnPlatform($order->items->first(), 10);
        app(PurchaseOrderLayerService::class)->apply(since: CarbonImmutable::parse('2000-01-01'));
        try {
            $this->invoices->approve($saved->invoice, adminId: 1);
            $this->fail('The invoice was approved.');
        } catch (InvalidContractChange $refusal) {
            $this->assertStringContainsString('already received', $refusal->getMessage());
        }

        $this->assertSame([OperationalCostInvoice::STATUS_UNDER_REVIEW, $order->items->first()->id], [$saved->invoice->fresh()->status, OperationalCostInvoiceItem::sole()->resembles_purchase_order_item_id]);
        $this->assertSame(['purchase_order'], InventoryCostLayer::pluck('source_type')->all(), 'The goods are layered once.');

        $this->invoices->markNotDuplicate($saved->invoice, adminId: 1);
        $this->invoices->approve($saved->invoice->fresh(), adminId: 1);
        $this->assertSame(['purchase_order', 'purchase_invoice'], InventoryCostLayer::orderBy('id')->pluck('source_type')->all(), 'A person said it is a separate purchase.');
    }

    public function test_the_supplier_the_invoice_names_by_id_is_the_one_a_receipt_is_compared_with_and_the_invoice_keeps_it(): void
    {
        [$product, , $supplier] = $this->layeredReceipt(supplierName: 'Acme Pharma Supply');

        $result = $this->invoice([['product_id' => $product, 'quantity' => 10, 'amount' => '125.00']], ['party_name' => 'ACME (a different spelling)', 'supplier_id' => $supplier]);

        $this->assertSame([InvoiceResult::RESEMBLES_RECEIPT, $supplier], [$result->result, $result->invoice->fresh()->supplier_id]);
    }

    public function test_a_goods_layer_an_invoice_makes_carries_the_invoices_supplier_and_a_service_invoice_is_not_touched(): void
    {
        $product = $this->platformProduct();
        $result = $this->invoice([['product_id' => $product, 'quantity' => 4, 'amount' => '40.00']], ['supplier_id' => 31]);
        $this->invoices->approve($result->invoice, adminId: 1);

        $layer = InventoryCostLayer::sole();
        $this->assertSame([31, 'Acme Pharma Supply', 'purchase_invoice'], [$layer->supplier_id, $layer->supplier_name, $layer->source_type]);

        $service = $this->invoices->create([
            'kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'invoice_number' => 'S-1', 'invoice_date' => '2026-11-30',
            'currency' => 'SAR', 'total' => '12',
        ], [['cost_component' => 'shipping', 'amount' => '12']], adminId: 1);
        $this->assertSame(InvoiceResult::CREATED, $service->result);
        $this->assertNull($service->invoice->fresh()->supplier_id);
        $this->assertSame(1, DB::table('operational_cost_invoices')->whereNotNull('supplier_id')->count());
    }
}
