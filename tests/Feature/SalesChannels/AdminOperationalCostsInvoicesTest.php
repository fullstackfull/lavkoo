<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\Fulfillment\FulfillmentStatus;
use App\Enums\OperationalCosts\CostState;
use App\Models\InventoryCostLayer;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Models\ProviderFulfillment;
use App\Models\ProviderFulfillmentItem;
use App\Services\OperationalCosts\ContractService;
use App\Services\OperationalCosts\Invoices\InvoiceResult;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Invoices\ReconciliationService;
use App\Services\OperationalCosts\Shipments\ShipmentCostService;
use App\Services\OperationalCosts\Shipments\ShipmentFactsFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesOperationalCostData;

/** Invoices: kept as received, never approved twice, compared with the estimates line by line, and a difference left to a person until a person decides. */
class AdminOperationalCostsInvoicesTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fulfillment.currency' => 'SAR']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = [], ?array $items = null): array
    {
        return $overrides + [
            'kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'invoice_number' => 'INV-1',
            'invoice_date' => '2026-11-30', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => '25',
            'items' => $items ?? [['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA'], ['cost_component' => 'special_fee', 'amount' => '13', 'description' => 'Extra handling']],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function savedInvoice(string $number = 'INV-1', string $total = '25', ?array $items = null, array $overrides = []): OperationalCostInvoice
    {
        $items ??= [['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA'], ['cost_component' => 'special_fee', 'amount' => '13', 'description' => 'Extra handling']];

        return app(InvoiceService::class)->create($overrides + [
            'kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'invoice_number' => $number,
            'invoice_date' => '2026-11-30', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'currency' => 'SAR', 'total' => $total,
        ], $items, adminId: 1)->invoice;
    }

    private function showUrl(OperationalCostInvoice $invoice): string
    {
        return route('admin.operational-costs.invoices.show', $invoice->id);
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
        $this->activeContract('torod_point', $amount);
        app(ShipmentCostService::class)->estimate(app(ShipmentFactsFactory::class)->forFulfillment($fulfillment));
    }

    public function test_the_list_with_no_invoices_offers_the_way_to_add_one(): void
    {
        $html = $this->get(route('admin.operational-costs.invoices.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('no_invoices_yet')), $html);
        $this->assertStringContainsString(route('admin.operational-costs.invoices.create'), $html);
    }

    public function test_the_list_filters_by_company_status_kind_duplicates_differences_dates_search_and_books(): void
    {
        $approved = $this->savedInvoice('INV-APPROVED', '25');
        app(InvoiceService::class)->approve($approved, 1);
        $twin = $this->savedInvoice('INV-TWIN', '25');
        $other = $this->savedInvoice('INV-M5', '10', [['cost_component' => 'shipping', 'amount' => '10']], ['provider_code' => 'm5azn', 'party_name' => 'Supplier M5', 'invoice_date' => '2026-10-15', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        $sellers = $this->savedInvoice('INV-SELLER', '7', [['cost_component' => 'shipping', 'amount' => '7']], ['provider_code' => 'amazon', 'party_name' => 'Marketplace']);
        $sellers->forceFill(['seller_id' => 9])->save();
        OperationalCostReconciliation::create(['invoice_id' => $approved->id, 'status' => OperationalCostReconciliation::VARIANCE, 'currency' => 'SAR']);
        $list = fn (array $query = []): string => $this->get(route('admin.operational-costs.invoices.index', $query))->assertOk()->getContent();

        $all = $list();
        foreach (['INV-APPROVED', 'INV-TWIN', 'INV-M5'] as $number) {
            $this->assertStringContainsString($number, $all);
        }
        $this->assertStringNotContainsString('INV-SELLER', $all, 'A seller\'s invoice is not in the platform\'s list.');
        $this->assertStringContainsString('INV-SELLER', $list(['seller' => 9]));
        $this->assertStringContainsString('INV-SELLER', $list(['seller' => 'all']));

        $unapproved = $list(['status' => 'unapproved']);
        $this->assertStringContainsString('INV-TWIN', $unapproved);
        $this->assertStringContainsString('INV-M5', $unapproved);
        $this->assertStringNotContainsString('INV-APPROVED', $unapproved);
        $this->assertStringContainsString('INV-APPROVED', $list(['status' => 'approved']));
        $this->assertStringNotContainsString('INV-APPROVED', $list(['provider' => 'm5azn']));
        $this->assertStringContainsString('INV-M5', $list(['provider' => 'm5azn']));
        $this->assertStringContainsString('INV-TWIN', $list(['duplicate' => 1]));
        $this->assertStringNotContainsString('INV-M5', $list(['duplicate' => 1]));
        $this->assertStringContainsString('INV-APPROVED', $list(['differences' => 1]));
        $this->assertStringNotContainsString('INV-TWIN', $list(['differences' => 1]));
        $this->assertStringContainsString('INV-M5', $list(['from' => '2026-10-01', 'to' => '2026-10-31']));
        $this->assertStringNotContainsString('INV-APPROVED', $list(['from' => '2026-10-01', 'to' => '2026-10-31']));
        $this->assertStringContainsString('INV-M5', $list(['q' => 'Supplier M5']));
        $this->assertStringNotContainsString('INV-TWIN', $list(['q' => 'Supplier M5']));
        $this->assertStringContainsString('INV-M5', $list(['kind' => 'service', 'q' => 'INV-M5']));
        $this->assertStringNotContainsString('INV-M5', $list(['kind' => 'purchase']));
        $this->assertStringContainsString(e(translate('looks_like_invoice')), $all);
        $list(['status' => ['x'], 'q' => ['y'], 'from' => 'nope', 'seller' => ['z']]);
    }

    public function test_the_form_for_a_new_invoice_has_blank_lines_and_no_figure_filled_in(): void
    {
        $html = $this->get(route('admin.operational-costs.invoices.create'))->assertOk()->getContent();

        $this->assertSame(8, substr_count($html, 'name="items[') / 8, 'Eight blank lines, eight columns each.');
        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $numberFields);
        foreach ($numberFields[0] as $field) {
            $this->assertDoesNotMatchRegularExpression('/value="[^"]+"/', $field);
        }
        $this->assertStringContainsString('reference;date;component;amount;currency;quantity', $html);
        $this->assertStringContainsString(route('admin.operational-costs.invoices.store'), $html);
    }

    public function test_an_invoice_typed_in_is_kept_exactly_as_received_with_its_lines(): void
    {
        $response = $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['notes' => 'Monthly statement']));

        $invoice = OperationalCostInvoice::firstOrFail();
        $response->assertRedirect($this->showUrl($invoice));
        $this->assertSame('service', $invoice->kind);
        $this->assertSame('torod_point', $invoice->provider_code);
        $this->assertSame('25.000000', $invoice->total);
        $this->assertSame('SAR', $invoice->currency);
        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame('manual', $invoice->source);
        $this->assertSame(1, $invoice->created_by);
        $this->assertSame(0, $invoice->seller_id);
        $this->assertSame(['12.000000', '13.000000'], $invoice->items->pluck('amount')->all());
        $this->assertSame(['shipping', 'special_fee'], $invoice->items->pluck('cost_component')->all());
        $this->assertSame('TP-AAA', $invoice->items[0]->external_reference);
        $this->assertSame(0, OperationalCostEntry::count(), 'Typing an invoice in writes no cost: only approving it does.');
        $this->assertContains(translate('invoice_saved'), $this->flashedToasts());
    }

    public function test_lines_pasted_from_a_spreadsheet_are_read_and_added_after_the_typed_ones(): void
    {
        $pasted = "reference;date;component;amount;currency;quantity\nTP-BBB;2026-11-10;shipping;7.5;SAR;1\n\nTP-CCC\t2026-11-11\tpicking\t2.25\t\t3\n";

        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['total' => '34.75', 'pasted_lines' => $pasted]));

        $invoice = OperationalCostInvoice::firstOrFail();
        $this->assertCount(4, $invoice->items);
        $this->assertSame(['TP-AAA', null, 'TP-BBB', 'TP-CCC'], $invoice->items->pluck('external_reference')->all());
        $this->assertSame('7.500000', $invoice->items[2]->amount);
        $this->assertSame('2026-11-10', $invoice->items[2]->service_date->toDateString());
        $this->assertSame('3.000000', $invoice->items[3]->quantity);
        $this->assertSame('picking', $invoice->items[3]->cost_component);
        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $invoice->status, 'Lines that add up to the total need no review.');
    }

    public function test_a_pasted_line_that_cannot_be_read_is_named_and_nothing_is_written(): void
    {
        $form = route('admin.operational-costs.invoices.create');
        $pasted = "TP-1;2026-11-10;shipping;7.5;SAR;1\nTP-2;2026-11-10;shipping;seven;SAR;1\nTP-3;2026-02-31;shipping;1;SAR;1\nTP-4;2026-11-10;flying;1;SAR;1\nTP-5;2026-11-10";

        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload(['pasted_lines' => $pasted]))->assertRedirect($form)->assertSessionHasErrors('pasted_lines');

        $messages = session('errors')->get('pasted_lines');
        $this->assertCount(4, $messages);
        $this->assertStringContainsString(translate('pasted_line') . ' 2: ' . translate('line_error_invalid_amount'), $messages[0]);
        $this->assertStringContainsString(translate('pasted_line') . ' 3: ' . translate('line_error_invalid_date'), $messages[1]);
        $this->assertStringContainsString(translate('pasted_line') . ' 4: ' . translate('line_error_unknown_component'), $messages[2]);
        $this->assertStringContainsString(translate('pasted_line') . ' 5: ' . translate('line_error_too_few_columns'), $messages[3]);
        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_money_that_is_not_a_plain_decimal_is_refused_in_the_total_and_in_the_lines(): void
    {
        $form = route('admin.operational-costs.invoices.create');

        foreach (['abc', '1e3', '12,5', '1.0000001', ''] as $bad) {
            $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload(['total' => $bad]))->assertSessionHasErrors('total');
        }
        foreach (['abc', '1e3', '12,5', '5 SAR'] as $bad) {
            $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => 'shipping', 'amount' => $bad]]))->assertSessionHasErrors('items.0.amount');
        }
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload(['subtotal' => 'x', 'tax_total' => 'y']))->assertSessionHasErrors(['subtotal', 'tax_total']);
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => 'shipping', 'amount' => '1', 'quantity' => '-2']]))->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_a_credit_is_a_negative_amount_and_is_allowed(): void
    {
        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['invoice_number' => 'CN-1', 'total' => '-4'], [['cost_component' => 'shipping', 'amount' => '-4', 'description' => 'Credit note']]));

        $this->assertSame('-4.000000', OperationalCostInvoice::firstOrFail()->total);
    }

    public function test_the_header_and_the_lines_are_checked(): void
    {
        $form = route('admin.operational-costs.invoices.create');

        foreach ([
            ['kind' => 'gift'], ['party_type' => 'friend'], ['provider_code' => 'Has Spaces'], ['party_name' => ''], ['invoice_number' => ''], ['invoice_date' => 'soon'],
            ['currency' => 'S'], ['period_start' => '2026-11-30', 'period_end' => '2026-11-01'], ['inventory_location_id' => '999'], ['seller_id' => '-3'],
        ] as $bad) {
            $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload($bad))->assertSessionHasErrors();
        }
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => '', 'amount' => '5']]))->assertSessionHasErrors('items.0.cost_component');
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => 'flying', 'amount' => '5']]))->assertSessionHasErrors('items.0.cost_component');
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => 'shipping', 'amount' => '5', 'product_id' => '999']]))->assertSessionHasErrors('items.0.product_id');
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], []))->assertSessionHasErrors('items');
        $this->from($form)->post(route('admin.operational-costs.invoices.store'), $this->payload([], [['cost_component' => 'shipping', 'amount' => '', 'description' => 'no amount']]))->assertSessionHasErrors('items.0.amount');

        $this->assertSame(0, OperationalCostInvoice::count());
    }

    public function test_the_same_company_and_number_again_is_refused_as_a_duplicate_and_nothing_is_written(): void
    {
        $first = $this->savedInvoice('INV-1');

        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['invoice_number' => 'INV-1', 'total' => '99'], [['cost_component' => 'shipping', 'amount' => '99']]))->assertRedirect($this->showUrl($first));

        $this->assertSame(1, OperationalCostInvoice::count());
        $this->assertSame('25.000000', $first->fresh()->total, 'The invoice that exists is not rewritten by the second one.');
        $this->assertContains(translate('invoice_already_exists_nothing_was_saved'), $this->flashedToasts());
        $this->assertSame(InvoiceResult::DUPLICATE, app(InvoiceService::class)->create($this->payload(), $this->payload()['items'], 1)->result);
    }

    public function test_the_same_number_of_another_company_or_another_sellers_books_is_not_a_duplicate(): void
    {
        $this->savedInvoice('INV-1');

        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['provider_code' => 'm5azn', 'total' => '25']));
        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['seller_id' => '9', 'total' => '26'], [['cost_component' => 'shipping', 'amount' => '26']]));

        $this->assertSame(3, OperationalCostInvoice::count());
        $this->assertSame([0, 0, 9], OperationalCostInvoice::orderBy('id')->pluck('seller_id')->all());
    }

    public function test_an_invoice_that_looks_like_another_waits_for_a_person_and_cannot_be_approved_until_one_looks(): void
    {
        $first = $this->savedInvoice('INV-1');

        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['invoice_number' => 'INV-1B']));
        $twin = OperationalCostInvoice::where('invoice_number', 'INV-1B')->firstOrFail();

        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $twin->status);
        $this->assertSame($first->id, $twin->duplicate_of_id);
        $this->assertContains(translate('invoice_saved_but_looks_like_another'), $this->flashedToasts());

        $page = $this->get($this->showUrl($twin))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('this_invoice_looks_like_another')), $page);
        $this->assertStringContainsString($this->showUrl($first), $page);
        $this->assertStringContainsString(route('admin.operational-costs.invoices.not-duplicate', $twin->id), $page);
        $this->assertMatchesRegularExpression('#<button[^>]*disabled[^>]*>\s*' . preg_quote(e(translate('approve_invoice')), '#') . '#', $page, 'The approve button is off while the twin is unexplained.');

        $this->from($this->showUrl($twin))->post(route('admin.operational-costs.invoices.approve', $twin->id));
        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $twin->fresh()->status);
        $this->assertStringContainsString('looks like invoice #' . $first->id, implode(' ', $this->flashedToasts()));

        $this->from($this->showUrl($twin))->post(route('admin.operational-costs.invoices.not-duplicate', $twin->id))->assertRedirect($this->showUrl($twin));
        $this->assertNull($twin->fresh()->duplicate_of_id);
        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $twin->fresh()->status);
        $this->assertContains(translate('invoice_marked_not_a_duplicate'), $this->flashedToasts());

        $this->from($this->showUrl($twin))->post(route('admin.operational-costs.invoices.approve', $twin->id));
        $this->assertSame(OperationalCostInvoice::STATUS_APPROVED, $twin->fresh()->status);
    }

    public function test_lines_that_do_not_add_up_to_the_invoice_are_held_for_review_and_the_page_says_so(): void
    {
        $this->post(route('admin.operational-costs.invoices.store'), $this->payload(['total' => '40']));
        $invoice = OperationalCostInvoice::firstOrFail();

        $this->assertSame(OperationalCostInvoice::STATUS_UNDER_REVIEW, $invoice->status);
        $page = $this->get($this->showUrl($invoice))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('the_lines_do_not_add_up_to_the_invoice')), $page);
        $this->assertStringContainsString('25.00 SAR', $page);
        $this->assertStringContainsString('40.00 SAR', $page);
    }

    public function test_the_page_shows_how_each_line_was_linked_and_flags_the_ones_that_were_not(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $invoice = $this->savedInvoice('INV-1', '25', [
            ['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA'],
            ['cost_component' => 'shipping', 'amount' => '13', 'external_reference' => 'TP-UNKNOWN'],
        ]);

        $html = $this->get($this->showUrl($invoice))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.fulfillment-providers.fulfillments.show', $shipment->id), $html);
        $this->assertStringContainsString($shipment->reference, $html);
        $this->assertStringContainsString(e(translate('not_linked')), $html);
        $this->assertStringContainsString(e(translate('the_reference_did_not_identify_exactly_one_shipment_or_order')), $html);
        $this->assertStringContainsString('TP-UNKNOWN', $html);
    }

    public function test_approving_turns_the_lines_into_costs_once_and_the_page_links_to_them(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '12');
        $invoice = $this->savedInvoice('INV-1', '25');
        $url = route('admin.operational-costs.invoices.approve', $invoice->id);

        $this->from($this->showUrl($invoice))->post($url)->assertRedirect($this->showUrl($invoice));
        $this->from($this->showUrl($invoice))->post($url);

        $this->assertSame(OperationalCostInvoice::STATUS_APPROVED, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->approved_by);
        $effective = OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('cost_component', 'shipping')->where('is_effective', true)->get();
        $this->assertCount(1, $effective, 'The charge replaced the estimate of its service.');
        $this->assertSame(CostState::Actual, $effective[0]->state);
        $this->assertSame('12.000000', $effective[0]->amount);
        $this->assertSame(1, OperationalCostEntry::where('cost_component', 'special_fee')->count(), 'Approving twice records the lines once.');
        $this->assertContains(translate('invoice_already_approved'), $this->flashedToasts());

        $page = $this->get($this->showUrl($invoice))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('invoice_status_approved')), $page);
        $this->assertStringContainsString(route('admin.operational-costs.entries.show', $effective[0]->id), $page);
        $this->assertStringContainsString(e(translate('an_approved_invoice_is_never_edited_or_voided_note')), $page);
        $this->assertStringNotContainsString(route('admin.operational-costs.invoices.approve', $invoice->id), $page);
        $this->assertStringNotContainsString(route('admin.operational-costs.invoices.void', $invoice->id), $page);
    }

    public function test_an_invoice_is_voided_with_a_reason_and_an_approved_one_never_is(): void
    {
        $draft = $this->savedInvoice('INV-1');
        $approved = $this->savedInvoice('INV-2', '7', [['cost_component' => 'shipping', 'amount' => '7']], ['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
        app(InvoiceService::class)->approve($approved, 1);

        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.invoices.void', $draft->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(OperationalCostInvoice::STATUS_DRAFT, $draft->fresh()->status);

        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.invoices.void', $draft->id), ['reason' => 'entered twice by mistake'])->assertRedirect($this->showUrl($draft));
        $this->assertSame(OperationalCostInvoice::STATUS_VOID, $draft->fresh()->status);
        $this->assertStringContainsString('entered twice by mistake', $draft->fresh()->notes);
        $this->assertStringContainsString(e(translate('this_invoice_is_void_and_counts_for_nothing')), $this->get($this->showUrl($draft))->getContent());

        $this->from($this->showUrl($approved))->post(route('admin.operational-costs.invoices.void', $approved->id), ['reason' => 'changed my mind']);
        $this->assertSame(OperationalCostInvoice::STATUS_APPROVED, $approved->fresh()->status);
        $this->assertContains('An approved invoice is not edited or voided: record a credit note or reverse its entries.', $this->flashedToasts());

        $this->from($this->showUrl($draft))->post(route('admin.operational-costs.invoices.approve', $draft->id));
        $this->assertSame(OperationalCostInvoice::STATUS_VOID, $draft->fresh()->status, 'A void invoice can not be approved.');
        $this->assertContains('A void invoice cannot be approved.', $this->flashedToasts());
    }

    public function test_a_purchase_invoice_needs_a_location_and_becomes_cost_layers_when_approved(): void
    {
        DB::table('products')->insert([['id' => 50, 'name' => 'Vitamin C', 'added_by' => 'admin', 'product_type' => 'physical'], ['id' => 51, 'name' => 'Panadol', 'added_by' => 'admin', 'product_type' => 'physical']]);
        $location = $this->stockLocation();
        $purchase = $this->payload(['kind' => 'purchase', 'party_type' => 'supplier', 'provider_code' => 'supplier-9', 'party_name' => 'Supplier', 'invoice_number' => 'PUR-1', 'total' => '1050', 'period_start' => null, 'period_end' => null], [
            ['cost_component' => 'cogs', 'amount' => '600', 'quantity' => '10', 'product_id' => '50'],
            ['cost_component' => 'cogs', 'amount' => '400', 'quantity' => '20', 'product_id' => '51'],
            ['cost_component' => 'inbound_freight', 'amount' => '50', 'description' => 'Freight'],
        ]);

        $this->from(route('admin.operational-costs.invoices.create'))->post(route('admin.operational-costs.invoices.store'), $purchase)->assertSessionHasErrors('inventory_location_id');
        $this->assertSame(0, OperationalCostInvoice::count());

        $this->post(route('admin.operational-costs.invoices.store'), $purchase + ['inventory_location_id' => $location->id]);
        $invoice = OperationalCostInvoice::firstOrFail();
        $this->assertSame($location->id, $invoice->inventory_location_id);
        $this->assertStringContainsString(e(translate('no_cost_layers_until_the_invoice_is_approved')), $this->get($this->showUrl($invoice))->getContent());

        $this->post(route('admin.operational-costs.invoices.approve', $invoice->id));

        $first = InventoryCostLayer::where('product_id', 50)->firstOrFail();
        $this->assertSame('60.000000', $first->unit_cost);
        $this->assertSame('3.000000', $first->landed_unit_cost);
        $page = $this->get($this->showUrl($invoice))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.operational-costs.product-costs.show', $first->id), $page);
        $this->assertStringContainsString('60.00 SAR', $page);
        $this->assertStringNotContainsString(route('admin.operational-costs.invoices.reconcile', $invoice->id), $page, 'Goods are not reconciled against shipping estimates.');
    }

    public function test_reconciling_needs_an_approved_invoice(): void
    {
        $invoice = $this->savedInvoice('INV-1');

        $this->from($this->showUrl($invoice))->post(route('admin.operational-costs.invoices.reconcile', $invoice->id));

        $this->assertContains(translate('approve_the_invoice_before_reconciling_it'), $this->flashedToasts());
        $this->assertSame(0, OperationalCostReconciliation::count());
        $this->assertStringContainsString(e(translate('approve_the_invoice_first')), $this->get($this->showUrl($invoice))->getContent());
    }

    public function test_the_reconciliation_shows_billed_against_estimated_the_difference_and_the_tolerance(): void
    {
        $matched = $this->shipment(2001, 'TP-AAA');
        $off = $this->shipment(2002, 'TP-BBB');
        $this->estimateShipping($matched, '100');
        app(ShipmentCostService::class)->estimate(app(ShipmentFactsFactory::class)->forFulfillment($off));
        $invoice = $this->savedInvoice('INV-1', '231', [
            ['cost_component' => 'shipping', 'amount' => '101', 'external_reference' => 'TP-AAA'],
            ['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-BBB'],
        ]);
        app(InvoiceService::class)->approve($invoice, 1);

        $this->from($this->showUrl($invoice))->post(route('admin.operational-costs.invoices.reconcile', $invoice->id));
        $toasts = $this->flashedToasts();
        $html = $this->get($this->showUrl($invoice))->assertOk()->getContent();

        $rows = OperationalCostReconciliation::orderBy('invoice_item_id')->get();
        $this->assertSame([OperationalCostReconciliation::MATCHED, OperationalCostReconciliation::VARIANCE], $rows->pluck('status')->all());
        $this->assertStringContainsString(e(translate('reconciliation_status_matched')), $html);
        $this->assertStringContainsString(e(translate('reconciliation_status_variance')), $html);
        $this->assertStringContainsString('101.00 SAR', $html, 'Billed.');
        $this->assertStringContainsString('1.00 SAR', $html, 'The difference of the matched line.');
        $this->assertStringContainsString('30.00%', $html, 'The difference of the other, in percent.');
        $this->assertStringContainsString('2.00%', $html, 'The tolerance in force.');
        $this->assertStringContainsString(route('admin.operational-costs.invoices.resolve', [$invoice->id, $rows[1]->id]), $html, 'Only the difference beyond the tolerance waits for a person.');
        $this->assertStringNotContainsString(route('admin.operational-costs.invoices.resolve', [$invoice->id, $rows[0]->id]), $html);
        $this->assertContains(translate('invoice_lines_compared_with_their_estimates') . ': 2', $toasts);
    }

    public function test_a_difference_is_resolved_by_a_person_with_a_note_and_only_once(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->savedInvoice('INV-1', '130', [['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-AAA']]);
        app(InvoiceService::class)->approve($invoice, 1);
        $row = app(ReconciliationService::class)->reconcile($invoice->fresh())[0];
        $url = route('admin.operational-costs.invoices.resolve', [$invoice->id, $row->id]);

        $this->from($this->showUrl($invoice))->post($url, ['resolution' => 'accept', 'note' => ''])->assertSessionHasErrors('note');
        $this->from($this->showUrl($invoice))->post($url, ['resolution' => 'forgive', 'note' => 'x'])->assertSessionHasErrors('resolution');
        $this->assertSame(OperationalCostReconciliation::VARIANCE, $row->fresh()->status);

        $this->from($this->showUrl($invoice))->post($url, ['resolution' => 'dispute', 'note' => 'billed above the contract rate'])->assertRedirect($this->showUrl($invoice));

        $this->assertSame(OperationalCostReconciliation::DISPUTED, $row->fresh()->status);
        $this->assertSame('dispute', $row->fresh()->resolution);
        $this->assertSame(1, $row->fresh()->resolved_by);
        $this->assertSame(CostState::Disputed, OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('is_effective', true)->firstOrFail()->state);
        $this->assertContains(translate('difference_resolved'), $this->flashedToasts());

        $page = $this->get($this->showUrl($invoice))->getContent();
        $this->assertStringNotContainsString($url, $page, 'A decision is not asked for twice.');
        $this->assertStringContainsString(e(translate('reconciliation_resolution_dispute')), $page);
        $this->assertStringContainsString('billed above the contract rate', $page);

        $this->from($this->showUrl($invoice))->post($url, ['resolution' => 'accept', 'note' => 'changed my mind']);
        $this->assertContains(translate('this_difference_is_already_decided'), $this->flashedToasts());
        $this->assertSame('dispute', $row->fresh()->resolution);
    }

    public function test_accepting_a_difference_reconciles_the_charge(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->savedInvoice('INV-1', '130', [['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-AAA']]);
        app(InvoiceService::class)->approve($invoice, 1);
        $row = app(ReconciliationService::class)->reconcile($invoice->fresh())[0];

        $this->from($this->showUrl($invoice))->post(route('admin.operational-costs.invoices.resolve', [$invoice->id, $row->id]), ['resolution' => 'accept', 'note' => 'agreed with the provider']);

        $this->assertSame(OperationalCostReconciliation::RESOLVED, $row->fresh()->status);
        $this->assertSame(CostState::Reconciled, OperationalCostEntry::where('provider_fulfillment_id', $shipment->id)->where('is_effective', true)->firstOrFail()->state);
    }

    public function test_a_row_of_another_invoice_is_not_reachable_through_this_one(): void
    {
        $shipment = $this->shipment(2001, 'TP-AAA');
        $this->estimateShipping($shipment, '100');
        $invoice = $this->savedInvoice('INV-1', '130', [['cost_component' => 'shipping', 'amount' => '130', 'external_reference' => 'TP-AAA']]);
        app(InvoiceService::class)->approve($invoice, 1);
        $row = app(ReconciliationService::class)->reconcile($invoice->fresh())[0];
        $other = $this->savedInvoice('INV-OTHER', '5', [['cost_component' => 'shipping', 'amount' => '5']]);

        $this->post(route('admin.operational-costs.invoices.resolve', [$other->id, $row->id]), ['resolution' => 'accept', 'note' => 'x'])->assertNotFound();
        $this->assertSame(OperationalCostReconciliation::VARIANCE, $row->fresh()->status);
    }

    public function test_estimates_of_the_period_that_no_line_charged_are_listed(): void
    {
        $billed = $this->shipment(2001, 'TP-AAA');
        $forgotten = $this->shipment(2002, 'TP-BBB');
        $this->estimateShipping($billed, '12');
        app(ShipmentCostService::class)->estimate(app(ShipmentFactsFactory::class)->forFulfillment($forgotten));
        $invoice = $this->savedInvoice('INV-1', '12', [['cost_component' => 'shipping', 'amount' => '12', 'external_reference' => 'TP-AAA']]);
        app(InvoiceService::class)->approve($invoice, 1);

        $html = $this->get($this->showUrl($invoice))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('estimates_in_the_period_that_no_line_charged')), $html);
        $estimate = OperationalCostEntry::where('provider_fulfillment_id', $forgotten->id)->firstOrFail();
        $this->assertStringContainsString(route('admin.operational-costs.entries.show', $estimate->id), $html);
        $this->assertSame(1, substr_count($html, 'entries/' . $estimate->id . '"'), 'Listed once.');
    }

    public function test_unknown_invoices_are_not_found_and_the_actions_are_posts(): void
    {
        $this->get(route('admin.operational-costs.invoices.show', 999))->assertNotFound();
        $this->post(route('admin.operational-costs.invoices.approve', 999))->assertNotFound();
        $this->post(route('admin.operational-costs.invoices.void', 999), ['reason' => 'x'])->assertNotFound();
        $this->get('/admin/operational-costs/invoices/1/approve')->assertStatus(405);
    }
}
