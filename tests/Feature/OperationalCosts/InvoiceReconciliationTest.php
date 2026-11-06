<?php

namespace Tests\Feature\OperationalCosts;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\Fulfillment\Support\CurrencyRateSource;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Exceptions\InvalidContractChange;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\ReconciliationService;
use App\Services\OperationalCosts\Shipments\ShipmentCostService;
use App\Services\OperationalCosts\Shipments\ShipmentFactsFactory;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsSalesChannelSchema;
use Tests\Support\FakeCurrencyRates;
use Tests\TestCase;

/** Invoice figures and prices are arbitrary numbers made up for these tests. */
class InvoiceReconciliationTest extends TestCase
{
    use BuildsSalesChannelSchema;

    private InvoiceService $invoices;

    private ReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesChannelSchema();
        $this->app->bind(CurrencyRateSource::class, fn () => new FakeCurrencyRates());
        config(['operational_costs.base_currency' => 'SAR', 'fulfillment.currency' => 'SAR']);
        $this->invoices = app(InvoiceService::class);
        $this->reconciliation = app(ReconciliationService::class);
    }

    private function shipment(int $orderId, string $providerReference): ProviderFulfillment
    {
        DB::table('orders')->insert(['id' => $orderId, 'order_status' => 'confirmed', 'payment_status' => 'paid', 'shipping_address_data' => json_encode(['city' => 'Riyadh']), 'seller_is' => 'admin']);
        $fulfillment = ProviderFulfillment::create([
            'reference' => $orderId . '-TP1', 'order_id' => $orderId, 'provider' => 'torod_point', 'generation' => 1, 'idempotency_key' => hash('sha256', 'k' . $orderId),
            'status' => FulfillmentStatus::Submitted, 'currency' => 'SAR', 'payment_mode' => 'prepaid', 'items_total' => 50, 'total_amount' => 50,
            'provider_reference' => $providerReference, 'submitted_at' => '2026-11-10 10:00:00',
        ]);
        ProviderFulfillmentItem::create([
            'provider_fulfillment_id' => $fulfillment->id, 'order_id' => $orderId, 'order_detail_id' => $orderId * 10, 'product_id' => 50, 'provider' => 'torod_point',
            'name' => 'Item', 'quantity' => 1, 'line_total' => 50, 'weight_kg' => '0.5', 'status' => 'routed',
        ]);

        return $fulfillment;
    }

    private function estimateShipping(ProviderFulfillment $fulfillment, string $amount): void
    {
        $contracts = app(ContractService::class);
        if (!\App\Models\OperationalCostContract::where('provider_code', 'torod_point')->exists()) {
            $contract = $contracts->createDraft(['party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'currency' => 'SAR', 'effective_from' => '2026-01-01'], 1);
            $contracts->addRule($contract, ['service_type' => 'shipping', 'calc_method' => 'per_shipment', 'params' => ['amount' => $amount]], 1);
            $contracts->activate($contract, 1, 'test');
        }
        app(ShipmentCostService::class)->estimate(app(ShipmentFactsFactory::class)->forFulfillment($fulfillment));
    }

    /** @param list<array<string, mixed>> $items */
    private function serviceInvoice(string $number, array $items, string $total, array $overrides = []): InvoiceResult
    {
        return $this->invoices->create($overrides + [
            'kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse',
            'invoice_number' => $number, 'invoice_date' => '2026-11-30', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => $total,
        ], $items, adminId: 1);
    }

    public function test_the_same_invoice_number_from_the_same_company_is_refused_as_a_duplicate(): void
    {
        $first = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '12']], '12');
        $again = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '12']], '12');

        $this->assertSame(InvoiceResult::CREATED, $first->result);
        $this->assertSame(InvoiceResult::DUPLICATE, $again->result);
        $this->assertSame($first->invoice->id, $again->invoice->id);
        $this->assertSame(1, OperationalCostInvoice::count());
    }

    public function test_the_same_file_under_another_number_is_a_duplicate_too(): void
    {
        $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '12']], '12', ['file_hash' => str_repeat('a', 64)]);
        $again = $this->serviceInvoice('INV-2', [['cost_component' => 'shipping', 'amount' => '12']], '12', ['file_hash' => str_repeat('a', 64)]);

        $this->assertSame(InvoiceResult::DUPLICATE, $again->result);
    }

    public function test_an_invoice_that_looks_like_another_waits_for_a_person_and_cannot_be_approved(): void
    {
        $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '12']], '12');
        $twin = $this->serviceInvoice('INV-1B', [['cost_component' => 'shipping', 'amount' => '12']], '12');

        $this->assertSame(InvoiceResult::POSSIBLE_DUPLICATE, $twin->result);
        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $twin->invoice->status);

        try {
            $this->invoices->approve($twin->invoice, 1);
            $this->fail('A possible duplicate was approved.');
        } catch (InvalidContractChange) {
        }

        $this->invoices->markNotDuplicate($twin->invoice, 1);
        $this->assertSame(1, $this->invoices->approve($twin->invoice->fresh(), 1));
    }

    public function test_lines_that_do_not_add_up_to_the_total_are_flagged_for_review(): void
    {
        $result = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '12']], '20');

        $this->assertContains('items_do_not_add_up', $result->warnings);
        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $result->invoice->status);
    }

    public function test_a_line_is_linked_to_its_shipment_only_when_the_reference_identifies_exactly_one(): void
    {
        $one = $this->shipment(2001, 'TP-AAA');
        $this->shipment(2002, 'TP-BBB');

        $result = $this->serviceInvoice('INV-1', [
            ['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA'],
            ['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-UNKNOWN'],
        ], '24');

        $items = $result->invoice->items;
        $this->assertSame($one->id, (int) $items[0]->provider_fulfillment_id);
        $this->assertNull($items[1]->provider_fulfillment_id, 'An unknown reference is left for a person, never guessed.');
    }

    public function test_approving_an_invoice_replaces_the_estimate_with_the_actual_charge_once(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '12');
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '13', 'external_reference' => 'TP-AAA']], '13')->invoice;

        $this->invoices->approve($invoice, 1);
        $this->invoices->approve($invoice->fresh(), 1);

        $effective = OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('cost_component', 'shipping')->where('is_effective', true)->get();
        $this->assertCount(1, $effective);
        $this->assertSame(CostState::Actual, $effective[0]->state);
        $this->assertSame('13.000000', $effective[0]->amount);
        $this->assertSame(2, OperationalCostEntry::where('cost_component', 'shipping')->count(), 'The estimate stays in the history; it just no longer counts.');
    }

    public function test_an_actual_within_the_tolerance_is_matched_and_reconciled(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '101', 'external_reference' => 'TP-AAA']], '101')->invoice;
        $this->invoices->approve($invoice, 1);

        $rows = $this->reconciliation->reconcile($invoice->fresh());

        $this->assertSame(OperationalCostReconciliation::MATCHED, $rows[0]->status);
        $this->assertSame('1.000000', $rows[0]->variance_amount);
        $this->assertSame(CostState::Reconciled, OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('is_effective', true)->first()->state);
    }

    public function test_an_actual_beyond_the_tolerance_is_a_variance_a_person_must_resolve(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-AAA']], '130')->invoice;
        $this->invoices->approve($invoice, 1);

        $row = $this->reconciliation->reconcile($invoice->fresh())[0];

        $this->assertSame(OperationalCostReconciliation::VARIANCE, $row->status);
        $this->assertSame('30.0000', $row->variance_percent);

        $this->reconciliation->resolve($row, 'dispute', 1, 'billed above the contract rate');
        $this->assertSame(CostState::Disputed, OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('is_effective', true)->first()->state);
        $this->assertSame(OperationalCostReconciliation::DISPUTED, $row->fresh()->status);
    }

    public function test_a_decision_already_taken_is_not_undone_by_reconciling_again(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-AAA']], '130')->invoice;
        $this->invoices->approve($invoice, 1);
        $row = $this->reconciliation->reconcile($invoice->fresh())[0];
        $this->reconciliation->resolve($row, 'accept', 1, 'agreed with the provider');

        $again = $this->reconciliation->reconcile($invoice->fresh())[0];

        $this->assertSame(OperationalCostReconciliation::RESOLVED, $again->status);
    }

    public function test_a_charge_nobody_expected_is_an_unmatched_invoice_line_and_an_unbilled_shipment_is_visible(): void
    {
        $billed = $this->shipment(2001, 'TP-AAA');
        $forgotten = $this->shipment(2002, 'TP-BBB');
        $this->estimateShipping($billed, '12');
        $this->estimateShipping($forgotten, '12');
        $invoice = $this->serviceInvoice('INV-1', [
            ['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA'],
            ['cost_component' => 'special_fee', 'amount' => '5', 'description' => 'Extra handling', 'external_reference' => 'TP-AAA'],
        ], '17')->invoice;
        $this->invoices->approve($invoice, 1);

        $rows = $this->reconciliation->reconcile($invoice->fresh());
        $unbilled = $this->reconciliation->unbilledEstimates($invoice->fresh());

        $this->assertSame([OperationalCostReconciliation::MATCHED, OperationalCostReconciliation::UNMATCHED_INVOICE], $rows->pluck('status')->all());
        $this->assertSame([$forgotten->id], $unbilled->pluck('provider_fulfillment_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_line_about_nothing_we_know_is_kept_as_an_unallocated_cost_not_dropped(): void
    {
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'special_fee', 'amount' => '5', 'description' => 'Annual account fee']], '5')->invoice;

        $this->invoices->approve($invoice, 1);

        $entry = OperationalCostEntry::where('cost_component', 'special_fee')->first();
        $this->assertSame('5.000000', $entry->amount);
        $this->assertSame('unallocated', $entry->allocation_status);
        $this->assertSame('invoice_item', $entry->subject_type);
    }

    public function test_an_approved_invoice_cannot_be_voided_or_its_entries_rewritten(): void
    {
        $invoice = $this->serviceInvoice('INV-1', [['cost_component' => 'special_fee', 'amount' => '5']], '5')->invoice;
        $this->invoices->approve($invoice, 1);

        try {
            $this->invoices->void($invoice->fresh(), 1, 'oops');
            $this->fail('An approved invoice was voided.');
        } catch (InvalidContractChange) {
        }

        $this->expectException(LogicException::class);
        $entry = OperationalCostEntry::where('cost_component', 'special_fee')->first();
        app(\App\Services\OperationalCosts\CostLedger::class)->approve($entry, 1);
        $entry->fresh()->forceFill(['amount' => '1'])->save();
    }

    public function test_a_purchase_invoice_creates_cost_layers_and_capitalises_its_freight(): void
    {
        $result = $this->invoices->create([
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'supplier-9', 'party_name' => 'Supplier', 'invoice_number' => 'PUR-1',
            'invoice_date' => '2026-11-05', 'currency' => 'SAR', 'total' => '1050', 'inventory_location_id' => 1,
        ], [
            ['cost_component' => 'cogs', 'amount' => '600', 'quantity' => '10', 'product_id' => 50],
            ['cost_component' => 'cogs', 'amount' => '400', 'quantity' => '20', 'product_id' => 51],
            ['cost_component' => 'inbound_freight', 'amount' => '50', 'description' => 'Freight'],
        ], adminId: 1);

        $this->invoices->approve($result->invoice, 1);

        $first = InventoryCostLayer::where('product_id', 50)->first();
        $second = InventoryCostLayer::where('product_id', 51)->first();
        $this->assertSame('60.000000', $first->unit_cost);
        $this->assertSame('3.000000', $first->landed_unit_cost, '30 of the 50 freight (600 of 1000) over 10 units.');
        $this->assertSame('20.000000', $second->unit_cost);
        $this->assertSame('1.000000', $second->landed_unit_cost, '20 of the 50 freight over 20 units.');
        $this->assertSame(2, InventoryCostLayer::count());
        $this->invoices->approve($result->invoice->fresh(), 1);
        $this->assertSame(2, InventoryCostLayer::count(), 'Approving twice records the goods once.');
    }

    public function test_a_purchase_invoice_without_a_location_cannot_be_approved(): void
    {
        $result = $this->invoices->create([
            'kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'supplier-9', 'party_name' => 'Supplier', 'invoice_number' => 'PUR-2',
            'invoice_date' => '2026-11-05', 'currency' => 'SAR', 'total' => '100',
        ], [['cost_component' => 'cogs', 'amount' => '100', 'quantity' => '5', 'product_id' => 50]], adminId: 1);

        $this->expectException(InvalidContractChange::class);
        $this->invoices->approve($result->invoice, 1);
    }
}
