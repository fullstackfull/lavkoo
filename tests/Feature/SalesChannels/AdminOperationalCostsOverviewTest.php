<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Http\Controllers\Admin\OperationalCosts\CostFormat;
use App\Http\Controllers\Admin\OperationalCosts\CostLabels;
use App\Models\OperationalCostInvoice;
use App\Models\OperationalCostReconciliation;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Reports\ProductNames;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use Illuminate\Support\Facades\Route;
use Tests\Support\CreatesOperationalCostData;

/** The overview, and the shape every operational-cost page shares: a page that reads nothing still renders, and the routes the sidebar links to exist. */
class AdminOperationalCostsOverviewTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private const MONTH = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    public function test_every_route_the_sidebar_links_to_exists(): void
    {
        foreach (['index', 'contracts.index', 'product-costs.index', 'entries.index', 'invoices.index', 'profit.index', 'reports.index'] as $name) {
            $this->assertTrue(Route::has('admin.operational-costs.' . $name), $name);
        }
    }

    public function test_every_list_page_renders_with_nothing_in_the_books(): void
    {
        foreach (['index', 'contracts.index', 'contracts.create', 'product-costs.index', 'entries.index', 'invoices.index', 'invoices.create', 'profit.index', 'reports.index'] as $name) {
            $html = $this->get(route('admin.operational-costs.' . $name, self::MONTH))->assertOk()->getContent();

            $this->assertNotSame('', trim(strip_tags($html)), $name . ' rendered no text.');
        }
    }

    public function test_the_overview_with_no_sales_shows_nothing_as_a_zero_it_did_not_compute_and_says_what_is_empty(): void
    {
        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('operational_costs_overview')), $html);
        $this->assertStringContainsString(e(translate('no_missing_costs_in_this_period')), $html);
        $this->assertStringContainsString(e(translate('no_sales_in_this_period')), $html);
        $this->assertStringContainsString(e(translate('estimated_and_actual_are_never_added_together_note')), $html);
    }

    public function test_the_kpis_are_the_figures_the_profit_services_compute(): void
    {
        $sale = $this->channelSale('200');
        $this->recordCost(CostState::Actual, '30', CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol', 'accountId' => $sale->account_id]);
        $this->recordCost(CostState::Estimated, '12', CostComponent::Picking, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol', 'accountId' => $sale->account_id]);
        $expected = app(ProfitabilityService::class)->forDocument(app(SaleDocumentReader::class)->forChannelOrder($sale->id));

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertSame('200.00', $expected->revenue->toDisplay());
        foreach ([$expected->revenue, $expected->estimatedCost, $expected->actualCost, $expected->estimatedProfit(), $expected->actualProfit()] as $figure) {
            $this->assertStringContainsString(e(CostFormat::amount($figure, 'SAR')), $html);
        }
        $this->assertSame('42.00', $expected->estimatedCost->toDisplay(), 'The estimate is part of the estimated cost.');
        $this->assertSame('30.00', $expected->actualCost->toDisplay(), 'Only the settled charge is part of the actual cost.');
        $this->assertStringContainsString(e(translate('estimated_cost')), $html);
        $this->assertStringContainsString(e(translate('actual_cost')), $html);
    }

    public function test_the_channel_and_provider_breakdowns_show_each_line_with_its_estimated_and_actual_profit_apart(): void
    {
        $sale = $this->channelSale('200');
        $this->recordCost(CostState::Actual, '30', CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol']);
        $this->recordCost(CostState::Estimated, '12', CostComponent::Picking, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol']);
        $sale->forceFill(['provider' => 'torod_point'])->save();

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('by_sales_channel')), $html);
        $this->assertStringContainsString(e(translate('by_provider')), $html);
        $this->assertMatchesRegularExpression('#' . preg_quote(e(translate('sales_channel_trendyol')), '#') . '</td>\s*<td[^>]*>1</td>\s*<td[^>]*>200\.00</td>#', $html);
        $this->assertStringContainsString('158.00', $html, 'Estimated profit: 200 - 30 - 12.');
        $this->assertStringContainsString('170.00', $html, 'Actual profit: 200 - 30, the picking is still an estimate.');
        $this->assertStringContainsString('Torod Point', $html);
    }

    public function test_a_gap_in_the_costs_is_stated_beside_the_figure_it_qualifies(): void
    {
        $sale = $this->channelSale('100');
        $this->recordCost(CostState::Actual, '40', CostComponent::Cogs, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol']);
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['mappingId' => $sale->id, 'channel' => 'trendyol', 'reason' => 'no_contract']);

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('incomplete')), $html);
        $this->assertStringContainsString('1 ' . e(translate('missing')), $html);
        $this->assertStringNotContainsString(e(translate('complete')) . '<', $html, 'A figure with a missing cost is never called complete.');
    }

    public function test_the_biggest_reasons_for_missing_costs_are_counted_and_linked_to_the_entries(): void
    {
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['reason' => 'no_contract']);
        $this->recordCost(CostState::Missing, null, CostComponent::Picking, '2026-11-05', ['reason' => 'no_contract']);
        $this->recordCost(CostState::Missing, null, CostComponent::Packing, '2026-11-06', ['reason' => 'weight_unknown']);

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('missing_reason_no_contract')), $html);
        $this->assertStringContainsString(e(translate('missing_reason_weight_unknown')), $html);
        $this->assertLessThan(strpos($html, e(translate('missing_reason_weight_unknown'))), strpos($html, e(translate('missing_reason_no_contract'))), 'The reason behind two costs comes before the one behind one.');
        $this->assertStringContainsString(e(route('admin.operational-costs.entries.index', ['view' => 'missing', 'reason' => 'no_contract'] + self::MONTH + ['seller' => '0'])), $html);
    }

    public function test_work_waiting_for_a_person_is_counted_with_a_link_to_it(): void
    {
        $this->recordCost(CostState::Actual, '5');
        $disputed = $this->recordCost(CostState::Actual, '7');
        app(CostLedger::class)->dispute($disputed, 'billed twice');
        OperationalCostInvoice::create([
            'kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'invoice_number' => 'INV-1', 'invoice_date' => '2026-11-01',
            'currency' => 'SAR', 'total' => '10', 'status' => OperationalCostInvoice::STATUS_DRAFT, 'source' => 'manual',
        ]);
        $invoice = OperationalCostInvoice::first();
        OperationalCostReconciliation::create(['invoice_id' => $invoice->id, 'status' => OperationalCostReconciliation::VARIANCE, 'currency' => 'SAR']);

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(route('admin.operational-costs.invoices.index', ['status' => 'unapproved', 'seller' => '0'])), $html);
        $this->assertStringContainsString(e(route('admin.operational-costs.invoices.index', ['differences' => 1, 'seller' => '0'])), $html);
        $this->assertStringContainsString(e(route('admin.operational-costs.entries.index', ['state' => 'disputed', 'from' => '', 'to' => '', 'seller' => '0'])), $html);
    }

    public function test_the_books_of_one_seller_are_not_added_to_the_platforms(): void
    {
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2026-11-05', ['reason' => 'no_contract']);
        $this->recordCost(CostState::Missing, null, CostComponent::Picking, '2026-11-05', ['reason' => 'weight_unknown', 'seller' => 7]);

        $platform = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();
        $seller = $this->get(route('admin.operational-costs.index', self::MONTH + ['seller' => 7]))->assertOk()->getContent();
        $everyone = $this->get(route('admin.operational-costs.index', self::MONTH + ['seller' => 'all']))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('missing_reason_no_contract')), $platform);
        $this->assertStringNotContainsString(e(translate('missing_reason_weight_unknown')), $platform);
        $this->assertStringContainsString(e(translate('missing_reason_weight_unknown')), $seller);
        $this->assertStringNotContainsString(e(translate('missing_reason_no_contract')), $seller);
        $this->assertStringContainsString(e(translate('missing_reason_no_contract')), $everyone);
        $this->assertStringContainsString(e(translate('missing_reason_weight_unknown')), $everyone);
    }

    public function test_unusable_dates_fall_back_to_the_current_month_and_say_so(): void
    {
        $this->travelTo('2026-11-15');

        $html = $this->get(route('admin.operational-costs.index', ['from' => '2026-12-01', 'to' => '2026-11-01']))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('the_dates_were_not_valid_the_current_month_is_shown')), $html);
        $this->assertStringContainsString('value="2026-11-01"', $html);
        $this->assertStringContainsString('value="2026-11-30"', $html);
    }

    public function test_a_report_that_hit_its_limit_says_so_and_the_totals_do_not_pretend_to_be_complete(): void
    {
        $this->channelSale('100');
        $this->channelSale('100');
        $this->channelSale('100');
        $this->app->instance(ProfitReportBuilder::class, new ProfitReportBuilder(
            app(SaleDocumentReader::class), app(ProfitabilityService::class), app(ProfitAggregator::class),
            app(ProductProfitability::class), app(ProductNames::class), documentLimit: 2,
        ));

        $html = $this->get(route('admin.operational-costs.index', self::MONTH))->assertOk()->getContent();

        $this->assertStringContainsString(e(translate('this_report_was_cut_short')), $html);
        $this->assertStringContainsString(e(CostLabels::filled(translate('only_the_first_sales_of_the_period_were_read'), ['limit' => 2])), $html);
    }
}
