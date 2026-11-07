<?php

namespace Tests\Feature\SalesChannels;

use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ExternalOrderLine;
use App\Models\ExternalOrderMapping;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\OperationalCostReturnDecision;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAuditLogTable;
use Tests\Support\CreatesOperationalCostData;
use Tests\Support\CreatesReturnableSales;

/**
 * The one action on returned units, from the screens a person works on: the order page of the sales channels, the profit page of the sale and the list of returns nobody has decided
 * about. It changes the stock layers and the ledger, so the operational-costs permission guards it wherever its form is shown, and the screens show the figures without a form to
 * whoever lacks it. The permission middleware is left on in every test of this class: the master admin passes it, the two narrower roles do not pass everywhere.
 */
class AdminReturnsTest extends AdminSalesChannelsTestCase
{
    use BuildsAuditLogTable;
    use CreatesOperationalCostData;
    use CreatesReturnableSales;

    private InventoryLocation $warehouse;

    protected function middlewareToDisable(): array
    {
        return array_values(array_filter(parent::middlewareToDisable(), static fn (string $class): bool => $class !== ModulePermissionMiddleware::class));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAuditLogTable();
        $this->warehouse = $this->stockLocation('Own warehouse', type: InventoryLocation::TYPE_OWN, provider: null);
    }

    private function sale(string $status = 'shipped'): ExternalOrderLine
    {
        return $this->returnableSale($this->trendyolAccount(), $this->warehouse, status: $status);
    }

    /** @return array<string, mixed> */
    private function decision(array $overrides = []): array
    {
        return $overrides + ['claim' => 'CLM-1', 'units_restocked' => 2, 'units_written_off' => 0, 'location_id' => $this->warehouse->id, 'note' => ''];
    }

    private function decide(ExternalOrderLine $line, array $form, ?ExternalOrderMapping $from = null): TestResponse
    {
        return $this->from(route('admin.sales-channels.orders.show', $from ?? $line->order))->post(route('admin.operational-costs.returns.decide', $line->id), $form);
    }

    private function orderPage(ExternalOrderLine $line): string
    {
        return $this->get(route('admin.sales-channels.orders.show', $line->order))->assertOk()->getContent();
    }

    // ---- order page ------------------------------------------------------------------------------------------------------

    public function test_the_order_page_shows_the_units_a_claim_brought_back_with_a_form_to_put_them_on_the_shelf_or_write_them_off(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $html = $this->orderPage($line);

        $this->assertStringContainsString('data-return-line="' . $line->id . '"', $html);
        $this->assertStringContainsString(e(translate('units_awaiting_a_restock_decision')) . ': 2', $html);
        $this->assertStringContainsString('action="' . route('admin.operational-costs.returns.decide', $line->id) . '"', $html);
        $this->assertStringContainsString('<input type="hidden" name="claim" value="CLM-1">', $html);
        foreach (['units_restocked', 'units_written_off', 'location_id', 'note'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html);
        }
        $this->assertMatchesRegularExpression('/<option value="' . $this->warehouse->id . '" selected>Own warehouse<\/option>/', $html, 'the line\'s own location is offered, chosen');
        $this->assertStringContainsString(e(translate('record_the_return_decision')), $html);
    }

    public function test_an_order_whose_goods_did_not_ship_has_nothing_to_return(): void
    {
        $this->assertStringNotContainsString('data-return-line', $this->orderPage($this->sale(status: 'new')));
    }

    public function test_a_shipped_order_can_have_a_return_recorded_by_a_person(): void
    {
        $line = $this->sale(status: 'shipped');

        $html = $this->orderPage($line);

        $this->assertStringContainsString('data-return-line="' . $line->id . '"', $html);
        $this->assertStringContainsString('name="token"', $html, 'a return a person records carries a token, so a double submit is one decision');
        $this->assertStringNotContainsString('name="claim"', $html);
        $this->assertStringContainsString(e(translate('return_received')), $html);
    }

    public function test_a_line_with_a_claim_offers_the_claim_only_and_no_return_of_ones_own(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 1]);

        $html = $this->orderPage($line);

        $this->assertSame(1, substr_count($html, 'data-return-form'));
        $this->assertStringNotContainsString('name="token"', $html);
    }

    public function test_a_decided_return_is_listed_as_decided_and_its_form_is_gone(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $this->decide($line, $this->decision(['units_restocked' => 1, 'units_written_off' => 1]));

        $html = $this->orderPage($line);

        $this->assertStringContainsString(e(translate('decided')), $html);
        $this->assertStringNotContainsString('name="claim"', $html);
        $this->assertStringNotContainsString(e(translate('units_awaiting_a_restock_decision')), $html);
    }

    // ---- deciding --------------------------------------------------------------------------------------------------------

    public function test_putting_the_units_back_records_one_decision_a_layer_and_an_audit_line_and_says_so(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $this->decide($line, $this->decision(['note' => 'checked at the counter']))->assertRedirect(route('admin.sales-channels.orders.show', $line->order))->assertSessionHasNoErrors();

        $decision = OperationalCostReturnDecision::firstOrFail();
        $this->assertSame(['claim:CLM-1', 2, 0, false, 1, 'checked at the counter'], [$decision->return_event, $decision->units_restocked, $decision->units_written_off, $decision->automatic, $decision->decided_by, $decision->note]);
        $this->assertSame(2, (int) InventoryCostLayer::where('source_type', 'return')->sum('quantity_received'));
        $this->assertToastContains(translate('return_decision_recorded'));
        $audit = AuditLog::where('action', 'operational_cost.return_decided')->firstOrFail();
        $this->assertSame([1, 'claim:CLM-1'], [$audit->context['by'], $audit->context['return_event']]);
    }

    public function test_posting_the_same_decision_again_changes_nothing_and_says_so(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $this->decide($line, $this->decision());
        $this->forgetToasts();

        $this->decide($line, $this->decision());

        $this->assertSame(1, OperationalCostReturnDecision::count());
        $this->assertSame(1, InventoryCostLayer::where('source_type', 'return')->count());
        $this->assertSame(1, AuditLog::where('action', 'operational_cost.return_decided')->count());
        $this->assertToastContains(translate('return_already_decided_nothing_changed'));
    }

    public function test_a_decision_the_rules_refuse_changes_nothing_and_says_why(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $this->decide($line, $this->decision(['units_restocked' => 1]))->assertRedirect();

        $this->assertToastContains(CostLabels::of('return_refused', 'claim_units_must_all_be_decided'));
        $this->assertSame(0, OperationalCostReturnDecision::count());
        $this->assertSame(0, InventoryCostLayer::where('source_type', 'return')->count());
    }

    public function test_a_form_with_impossible_numbers_is_sent_back_with_its_errors_before_anything_is_decided(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $this->decide($line, $this->decision(['units_restocked' => -1]))->assertSessionHasErrors('units_restocked');
        $this->decide($line, $this->decision(['units_written_off' => 'many']))->assertSessionHasErrors('units_written_off');
        $this->decide($line, ['claim' => 'CLM-1'])->assertSessionHasErrors(['units_restocked', 'units_written_off']);

        $this->assertSame(0, OperationalCostReturnDecision::count());
    }

    public function test_a_person_records_a_return_with_the_token_of_the_form_and_a_double_submit_is_one_decision(): void
    {
        $line = $this->sale();
        $form = ['token' => 'form-token-1', 'units_restocked' => 1, 'units_written_off' => 0, 'location_id' => $this->warehouse->id];

        $this->decide($line, $form);
        $this->decide($line, $form);
        $this->decide($line, ['token' => 'form-token-2'] + $form);

        $this->assertSame(2, OperationalCostReturnDecision::count(), 'One per token.');
        $this->assertSame(2, (int) OperationalCostReturnDecision::sum('units_restocked'));
        $this->assertSame(['manual:form-token-1', 'manual:form-token-2'], OperationalCostReturnDecision::orderBy('id')->pluck('return_event')->all());
    }

    public function test_a_decision_about_a_line_that_does_not_exist_is_not_found(): void
    {
        $this->post(route('admin.operational-costs.returns.decide', 99999), $this->decision())->assertNotFound();
    }

    // ---- the list, the overview and the orders filter ------------------------------------------------------------------------

    public function test_the_list_shows_what_awaits_a_decision_with_a_link_to_the_order_and_the_sale_and_empties_as_it_is_decided(): void
    {
        $empty = $this->get(route('admin.operational-costs.returns.index'))->assertOk()->getContent();
        $this->assertStringContainsString(e(translate('no_returns_awaiting_a_decision')), $empty);

        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $html = $this->get(route('admin.operational-costs.returns.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-return-line="' . $line->id . '"', $html);
        $this->assertStringContainsString('10234567890', $html);
        $this->assertStringContainsString(route('admin.sales-channels.orders.show', $line->order), $html);
        $this->assertStringContainsString(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $line->order->id]), $html);

        $this->decide($line, $this->decision());
        $this->assertStringContainsString(e(translate('no_returns_awaiting_a_decision')), $this->get(route('admin.operational-costs.returns.index'))->assertOk()->getContent());
    }

    public function test_the_overview_counts_the_lines_awaiting_a_decision_and_links_to_them(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $html = $this->get(route('admin.operational-costs.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('returned_units_awaiting_a_decision')), $html);
        $this->assertStringContainsString(route('admin.operational-costs.returns.index'), $html);
        $this->assertSame(1, ExternalOrderLine::awaitingReturnDecision()->count());

        $this->decide($line, $this->decision());
        $this->assertSame(0, ExternalOrderLine::awaitingReturnDecision()->count());
    }

    public function test_the_orders_list_filters_to_the_orders_awaiting_a_restock_decision(): void
    {
        $waiting = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $other = ExternalOrderMapping::create([
            'account_id' => $waiting->order->account_id, 'sales_channel' => 'trendyol', 'seller_id' => 0, 'external_order_id' => '555000111', 'external_package_id' => '777', 'status' => 'delivered',
            'currency' => 'SAR', 'purchased_at' => '2026-11-04 09:00:00',
        ]);
        ExternalOrderLine::create(['mapping_id' => $other->id, 'external_line_id' => 'L9', 'sku' => 'SKU-9', 'quantity' => 1, 'quantity_returned' => 0, 'line_total' => '10', 'currency' => 'SAR']);

        $all = $this->get(route('admin.sales-channels.orders.index'))->assertOk()->getContent();
        $filtered = $this->get(route('admin.sales-channels.orders.index', ['returns' => 'awaiting']))->assertOk()->getContent();

        $this->assertStringContainsString('555000111', $all);
        $this->assertStringContainsString('10234567890', $filtered);
        $this->assertStringNotContainsString('555000111', $filtered);
        $this->assertMatchesRegularExpression('/<input[^>]*id="filter-returns"[^>]*checked/', $filtered);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="filter-returns"[^>]*checked/', $all);

        $this->decide($waiting, $this->decision());
        $this->assertStringNotContainsString('10234567890', $this->get(route('admin.sales-channels.orders.index', ['returns' => 'awaiting']))->assertOk()->getContent(), 'decided: no longer awaiting');
    }

    public function test_the_profit_page_of_the_sale_carries_the_same_panel_and_the_cost_of_goods_follows_the_decision(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 1]);
        $page = fn (): string => $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $line->order->id]))->assertOk()->getContent();

        $before = $page();
        $this->assertStringContainsString('data-return-line="' . $line->id . '"', $before);
        $this->assertStringContainsString('30.00', $before, 'The goods of three units cost 30.00 until the returned one is decided.');

        $this->from(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $line->order->id]))
            ->post(route('admin.operational-costs.returns.decide', $line->id), $this->decision(['units_restocked' => 1]))->assertRedirect();

        $after = $page();
        $this->assertStringContainsString('20.00', $after, 'One unit is back on the shelf: its cost of goods is reversed.');
        $this->assertStringNotContainsString('name="claim"', $after);
    }

    public function test_a_storefront_sale_has_no_return_panel_on_its_profit_page(): void
    {
        $orderId = $this->storefrontSale();

        $html = $this->get(route('admin.operational-costs.profit.order', ['type' => 'order', 'id' => $orderId]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-return-line', $html, 'Store orders are not part of the restock decision.');
    }

    // ---- permissions -------------------------------------------------------------------------------------------------------

    public function test_a_role_with_only_sales_channels_sees_the_return_without_a_form_and_cannot_decide(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $this->actingAs(Admin::findOrFail(4), 'admin');

        $html = $this->orderPage($line);

        $this->assertStringContainsString('data-return-line="' . $line->id . '"', $html, 'The figures are on the order page.');
        $this->assertStringContainsString(e(translate('units_awaiting_a_restock_decision')), $html);
        $this->assertStringNotContainsString('data-return-form', $html);
        $this->assertStringNotContainsString(route('admin.operational-costs.returns.decide', $line->id), $html);

        $this->decide($line, $this->decision())->assertRedirect(route('admin.dashboard.index'));
        $this->get(route('admin.operational-costs.returns.index'))->assertRedirect(route('admin.dashboard.index'));
        $this->assertSame(0, OperationalCostReturnDecision::count());
        $this->assertSame(0, InventoryCostLayer::where('source_type', 'return')->count());
    }

    public function test_a_role_with_only_operational_costs_decides_from_the_returns_list_and_the_profit_page_but_has_no_order_page(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);
        $this->actingAs(Admin::findOrFail(5), 'admin');

        $this->get(route('admin.operational-costs.returns.index'))->assertOk()->assertSee('data-return-form', false);
        $this->get(route('admin.operational-costs.profit.order', ['type' => 'channel_order', 'id' => $line->order->id]))->assertOk()->assertSee('data-return-form', false);
        $this->get(route('admin.sales-channels.orders.show', $line->order))->assertRedirect(route('admin.dashboard.index'));

        $this->post(route('admin.operational-costs.returns.decide', $line->id), $this->decision())->assertRedirect();

        $decision = OperationalCostReturnDecision::firstOrFail();
        $this->assertSame([5, false], [$decision->decided_by, $decision->automatic], 'The decision carries the admin who made it.');
    }

    public function test_the_master_admin_holds_both_permissions_and_decides(): void
    {
        $line = $this->acceptedReturn($this->sale(), ['CLM-1' => 2]);

        $this->assertStringContainsString('data-return-form', $this->orderPage($line));
        $this->decide($line, $this->decision())->assertSessionHasNoErrors();

        $this->assertSame(1, OperationalCostReturnDecision::count());
    }
}
