<?php

namespace Tests\Feature\SalesChannels;

use App\Enums\OperationalCosts\CostComponent;
use App\Enums\OperationalCosts\CostState;
use App\Models\ExternalOrderMapping;
use App\Services\OperationalCosts\CostLedger;
use App\Services\OperationalCosts\Invoices\InvoiceService;
use App\Services\OperationalCosts\Profit\ProductProfitability;
use App\Services\OperationalCosts\Profit\ProfitAggregator;
use App\Services\OperationalCosts\Profit\ProfitabilityService;
use App\Services\OperationalCosts\Reports\CsvExporter;
use App\Services\OperationalCosts\Reports\ProductNames;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Sales\SaleDocumentReader;
use App\Services\OperationalCosts\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesOperationalCostData;

/** The exports: the file Excel opens for Arabic, that cannot run a formula, and that holds exactly the rows the page listed. */
class AdminOperationalCostsReportsTest extends AdminPagesTestCase
{
    use CreatesOperationalCostData;

    private const NOVEMBER = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fulfillment.currency' => 'SAR']);
    }

    /** @return array{0: string, 1: list<list<string>>} the file as sent, and its rows (heading first) */
    private function download(string $report, array $query = []): array
    {
        // The exports are rate limited (that has a test of its own); a test that reads several files is not.
        Cache::flush();
        $response = $this->get(route('admin.operational-costs.reports.export', ['report' => $report] + $query + self::NOVEMBER))->assertOk();
        $csv = $response->streamedContent();

        return [$csv, array_map('str_getcsv', explode("\r\n", rtrim(substr($csv, strlen(CsvExporter::BYTE_ORDER_MARK)), "\r\n")))];
    }

    /** @param list<list<string>> $rows */
    private function column(array $rows, string $heading): array
    {
        $index = array_search(translate($heading), $rows[0], true);
        $this->assertNotFalse($index, "No column {$heading}.");

        return array_column(array_slice($rows, 1), $index);
    }

    public function test_the_page_lists_the_four_exports_with_the_current_month_and_nothing_preselected(): void
    {
        $this->travelTo('2026-11-15');

        $html = $this->get(route('admin.operational-costs.reports.index'))->assertOk()->getContent();

        foreach (['entries', 'missing', 'profit', 'invoices'] as $report) {
            $this->assertStringContainsString(route('admin.operational-costs.reports.export', $report), $html);
            $this->assertStringContainsString(e(translate('report_title_' . $report)), $html);
            $this->assertStringContainsString(e(translate('report_help_' . $report)), $html);
        }
        $this->assertStringContainsString('value="2026-11-01"', $html);
        $this->assertStringContainsString('value="2026-11-30"', $html);
        $this->assertStringContainsString(e(translate('exports_note_utf8_excel_and_formulas')), $html);
        $this->assertSame(1, substr_count($html, ' selected'), 'Only the platform\'s books are preselected: no component, state, provider or channel is.');
    }

    public function test_an_export_is_a_utf8_csv_download_with_a_byte_order_mark(): void
    {
        $this->recordCost(CostState::Actual, '5', CostComponent::Shipping, '2026-11-05');

        $response = $this->get(route('admin.operational-costs.reports.export', ['report' => 'entries'] + self::NOVEMBER))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=operational-costs-cost-entries-2026-11-01-2026-11-30.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("\r\n", $csv, 'Rows end the way Excel on Windows expects.');
    }

    public function test_the_entries_export_has_exact_decimals_and_leaves_a_missing_amount_empty(): void
    {
        $this->recordCost(CostState::Actual, '12.345678', CostComponent::Shipping, '2026-11-05', ['reference' => 'ROW-ACTUAL']);
        $this->recordCost(CostState::Missing, null, CostComponent::Picking, '2026-11-06', ['reference' => 'ROW-MISSING', 'reason' => 'weight_unknown']);

        [, $rows] = $this->download('entries');

        $byReference = array_combine($this->column($rows, 'reference'), array_keys($this->column($rows, 'reference')));
        $amounts = $this->column($rows, 'amount');
        $this->assertSame('12.345678', $amounts[$byReference['ROW-ACTUAL']], 'The exact decimal, not a rounded display.');
        $this->assertSame('', $amounts[$byReference['ROW-MISSING']], 'A missing cost has no amount: never a 0.');
        $this->assertSame('weight_unknown', $this->column($rows, 'missing_reason')[$byReference['ROW-MISSING']]);
        $this->assertSame('missing', $this->column($rows, 'state')[$byReference['ROW-MISSING']]);
        $this->assertSame('SAR', $this->column($rows, 'base_currency')[$byReference['ROW-ACTUAL']]);
        $this->assertSame('', $this->column($rows, 'base_amount')[$byReference['ROW-MISSING']]);
        $this->assertSame(['1', '1'], $this->column($rows, 'effective'));
    }

    public function test_a_cell_a_spreadsheet_would_run_as_a_formula_is_neutralised_and_a_negative_amount_stays_a_number(): void
    {
        $original = $this->recordCost(CostState::Actual, '5', CostComponent::Shipping, '2026-11-05', ['reference' => '=HYPERLINK("http://evil.test","click")', 'note' => '@SUM(A1)']);
        $this->recordCost(CostState::Actual, '6', CostComponent::Picking, '2026-11-05', ['reference' => '+1+1', 'note' => '-2+3']);
        $this->recordCost(CostState::Actual, '7', CostComponent::Packing, '2026-11-05', ['reference' => "\t=cmd", 'note' => 'فاتورة مكررة']);
        app(CostLedger::class)->reverse($original, 1, 'wrong order');

        [$csv, $rows] = $this->download('entries');

        $references = $this->column($rows, 'reference');
        $notes = $this->column($rows, 'note');
        $this->assertContains("'=HYPERLINK(\"http://evil.test\",\"click\")", $references);
        $this->assertContains("'+1+1", $references);
        $this->assertContains("'\t=cmd", $references);
        $this->assertContains("'@SUM(A1)", $notes);
        $this->assertContains("'-2+3", $notes);
        $this->assertContains('فاتورة مكررة', $notes, 'Arabic is written as it is.');
        foreach (array_merge($references, $notes) as $cell) {
            $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell, 'No text cell starts like a formula: ' . $cell);
        }
        $this->assertContains('-5.000000', $this->column($rows, 'amount'), 'A reversal is a negative number, not a quoted text.');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_the_entries_export_holds_exactly_the_rows_the_page_filters_select(): void
    {
        $this->recordCost(CostState::Actual, '1', CostComponent::Shipping, '2026-11-05', ['reference' => 'KEEP-1', 'provider' => 'torod_point', 'channel' => 'trendyol']);
        $this->recordCost(CostState::Estimated, '2', CostComponent::Picking, '2026-11-06', ['reference' => 'KEEP-2', 'provider' => 'm5azn', 'channel' => 'amazon']);
        $this->recordCost(CostState::Actual, '3', CostComponent::Shipping, '2026-10-06', ['reference' => 'OUT-OF-RANGE']);
        $this->recordCost(CostState::Actual, '4', CostComponent::Shipping, '2026-11-07', ['reference' => 'OTHER-SELLER', 'seller' => 9]);
        $estimate = $this->recordCost(CostState::Estimated, '5', CostComponent::Packing, '2026-11-08', ['subjectId' => 77, 'identity' => 'est', 'reference' => 'REPLACED']);
        $this->recordCost(CostState::Actual, '6', CostComponent::Packing, '2026-11-08', ['subjectId' => 77, 'identity' => 'act', 'reference' => 'REPLACEMENT']);
        $references = fn (array $query = []): array => $this->column($this->download('entries', $query)[1], 'reference');

        $this->assertEqualsCanonicalizing(['KEEP-1', 'KEEP-2', 'REPLACEMENT'], $references());
        $this->assertEqualsCanonicalizing(['KEEP-1', 'REPLACEMENT'], $references(['provider' => 'torod_point']));
        $this->assertEqualsCanonicalizing(['KEEP-2'], $references(['channel' => 'amazon']));
        $this->assertEqualsCanonicalizing(['KEEP-2'], $references(['state' => 'estimated', 'component' => 'picking']));
        $this->assertEqualsCanonicalizing(['KEEP-1', 'KEEP-2', 'OUT-OF-RANGE', 'REPLACEMENT'], $references(['from' => '', 'to' => '']));
        $this->assertEqualsCanonicalizing(['OTHER-SELLER'], $references(['seller' => 9]));
        $this->assertEqualsCanonicalizing(['KEEP-1', 'KEEP-2', 'REPLACED', 'REPLACEMENT'], $references(['history' => 1]));
        $this->assertNotNull($estimate->fresh()->superseded_by_id);
    }

    public function test_the_missing_costs_export_lists_only_missing_costs_with_their_reasons_and_every_old_one_by_default(): void
    {
        $this->travelTo('2026-11-15');
        $this->recordCost(CostState::Missing, null, CostComponent::Shipping, '2025-02-01', ['reference' => 'OLD-GAP', 'reason' => 'no_contract']);
        $this->recordCost(CostState::Missing, null, CostComponent::Picking, '2026-11-05', ['reference' => 'NEW-GAP', 'reason' => 'weight_unknown']);
        $this->recordCost(CostState::Actual, '5', CostComponent::Packing, '2026-11-05', ['reference' => 'NOT-A-GAP']);

        $response = $this->get(route('admin.operational-costs.reports.export', 'missing'))->assertOk();
        $rows = array_map('str_getcsv', explode("\r\n", rtrim(substr($response->streamedContent(), 3), "\r\n")));

        $this->assertEqualsCanonicalizing(['OLD-GAP', 'NEW-GAP'], $this->column($rows, 'reference'));
        $this->assertEqualsCanonicalizing(['', ''], $this->column($rows, 'amount'));
        $this->assertEqualsCanonicalizing(['no_contract', 'weight_unknown'], $this->column($rows, 'missing_reason'));
        $this->assertStringContainsString('missing-costs-all-all', $response->headers->get('Content-Disposition'));
        $this->assertEqualsCanonicalizing(['NEW-GAP'], $this->column($this->download('missing', ['from' => '2026-11-01', 'to' => '2026-11-30', 'state' => 'actual'])[1], 'reference'), 'The missing-cost export is about missing costs whatever else the form said.');
    }

    public function test_the_invoices_export_has_the_headers_exact_totals_and_the_page_s_filters(): void
    {
        $service = app(InvoiceService::class);
        $service->create(['kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'torod_point', 'party_name' => 'Warehouse', 'invoice_number' => '=INV-1', 'invoice_date' => '2026-11-30', 'currency' => 'SAR', 'total' => '12.5'], [['cost_component' => 'shipping', 'amount' => '12.5']], 1);
        $approved = $service->create(['kind' => 'service', 'party_type' => 'fulfillment_provider', 'provider_code' => 'm5azn', 'party_name' => 'Supplier', 'invoice_number' => 'INV-2', 'invoice_date' => '2026-10-15', 'currency' => 'SAR', 'total' => '7'], [['cost_component' => 'shipping', 'amount' => '7']], 1)->invoice;
        $service->approve($approved, 1);

        [, $all] = $this->download('invoices', ['from' => '', 'to' => '']);
        $this->assertEqualsCanonicalizing(["'=INV-1", 'INV-2'], $this->column($all, 'invoice_number'));
        $this->assertEqualsCanonicalizing(['12.500000', '7.000000'], $this->column($all, 'total'));
        $this->assertEqualsCanonicalizing(['draft', 'approved'], $this->column($all, 'status'));

        $this->assertSame(['INV-2'], $this->column($this->download('invoices', ['status' => 'approved', 'from' => '', 'to' => ''])[1], 'invoice_number'));
        $this->assertSame(["'=INV-1"], $this->column($this->download('invoices', ['from' => '2026-11-01', 'to' => '2026-11-30'])[1], 'invoice_number'));
        $this->assertSame(['INV-2'], $this->column($this->download('invoices', ['provider' => 'm5azn', 'from' => '', 'to' => ''])[1], 'invoice_number'));
    }

    public function test_the_profit_export_is_the_profit_services_own_lines_for_each_dimension(): void
    {
        $trendyol = $this->channelSale('200', 'trendyol', '2026-11-05 09:00:00');
        $amazon = $this->channelSale('100', 'amazon', '2026-11-08 12:00:00', productId: 51);
        $this->recordCost(CostState::Actual, '60', CostComponent::Cogs, '2026-11-05', ['mappingId' => $trendyol->id, 'channel' => 'trendyol', 'productId' => 50]);
        $this->recordCost(CostState::Actual, '40', CostComponent::Cogs, '2026-11-08', ['mappingId' => $amazon->id, 'channel' => 'amazon', 'productId' => 51]);
        $this->recordCost(CostState::Actual, '30', CostComponent::Shipping, '2026-11-05', ['mappingId' => $trendyol->id, 'channel' => 'trendyol', 'productId' => 50]);
        $this->recordCost(CostState::Missing, null, CostComponent::MarketplaceCommission, '2026-11-08', ['mappingId' => $amazon->id, 'channel' => 'amazon', 'productId' => 51, 'reason' => 'no_contract']);
        DB::table('products')->insert([['id' => 50, 'name' => '=Vitamin', 'added_by' => 'admin', 'product_type' => 'physical'], ['id' => 51, 'name' => 'Panadol', 'added_by' => 'admin', 'product_type' => 'physical']]);
        $reader = app(SaleDocumentReader::class);
        $documents = [$reader->forChannelOrder($trendyol->id), $reader->forChannelOrder($amazon->id)];
        $results = iterator_to_array(app(ProfitabilityService::class)->forDocuments($documents), false);

        [, $byChannel] = $this->download('profit', ['dimension' => 'channel']);
        $channels = app(ProfitAggregator::class)->group($results, 'channel');
        $this->assertSame(array_keys($channels), $this->column($byChannel, 'key'));
        $this->assertSame(array_values(array_map(static fn ($row): string => $row->revenue->toStorage(), $channels)), $this->column($byChannel, 'revenue'));
        $this->assertSame(array_values(array_map(static fn ($row): string => $row->actualProfit()->toStorage(), $channels)), $this->column($byChannel, 'actual_profit'));
        $this->assertSame(['1', '0'], $this->column($byChannel, 'sales_with_gaps_estimated'), 'Amazon has a gap, Trendyol has none.');
        $this->assertSame(array_fill(0, 2, 'SAR'), $this->column($byChannel, 'currency'));

        [, $byProduct] = $this->download('profit', ['dimension' => 'product']);
        $products = app(ProductProfitability::class)->rows($documents);
        $this->assertSame(array_map('strval', array_keys($products)), $this->column($byProduct, 'key'));
        $this->assertContains("'=Vitamin", $this->column($byProduct, 'name'), 'A product name that starts like a formula is neutralised.');
        $this->assertSame(array_values(array_map(static fn ($row): string => $row->revenue->toStorage(), $products)), $this->column($byProduct, 'revenue'));

        [, $byOrder] = $this->download('profit', ['dimension' => 'order', 'channel' => 'trendyol']);
        $this->assertCount(1, array_slice($byOrder, 1));
        $this->assertSame([$trendyol->external_order_id . '/' . $trendyol->external_package_id], $this->column($byOrder, 'name'));
        $this->assertSame(['trendyol'], $this->column($byOrder, 'sales_channel'));
        $this->assertSame(['2026-11-05'], $this->column($byOrder, 'date'));
        $this->assertSame(['channel_order:' . $trendyol->id], $this->column($byOrder, 'key'));
    }

    public function test_the_profit_export_leaves_the_cost_and_profit_of_a_sale_nobody_recorded_a_cost_for_empty(): void
    {
        $sale = $this->channelSale('80', 'trendyol', '2026-11-12 10:00:00');

        [, $rows] = $this->download('profit', ['dimension' => 'order']);

        $line = array_values(array_filter(array_slice($rows, 1), static fn (array $row): bool => $row[0] === 'channel_order:' . $sale->id));
        $this->assertCount(1, $line);
        $columns = array_flip($rows[0]);
        foreach (['estimated_cost', 'actual_cost', 'estimated_profit', 'estimated_margin', 'actual_profit', 'actual_margin'] as $heading) {
            $this->assertSame('', $line[0][$columns[translate($heading)]], $heading . ' is not known, so it is not a zero.');
        }
        $this->assertSame(Money::of('80')->toStorage(), $line[0][$columns[translate('revenue')]], 'Its revenue is known.');
        $this->assertSame('1', $line[0][$columns[translate('sales_with_gaps_estimated')]], 'And the sale is counted as one with a gap.');
        $this->assertSame('1', $line[0][$columns[translate('sales_with_gaps_actual')]]);
    }

    public function test_the_profit_export_says_on_its_last_line_when_the_sales_read_were_limited(): void
    {
        $this->channelSale('100');
        $this->channelSale('100');
        $this->channelSale('100');
        $this->app->instance(ProfitReportBuilder::class, new ProfitReportBuilder(
            app(SaleDocumentReader::class), app(ProfitabilityService::class), app(ProfitAggregator::class), app(ProductProfitability::class), app(ProductNames::class), documentLimit: 2,
        ));

        [, $rows] = $this->download('profit', ['dimension' => 'order']);

        $this->assertCount(2 + 1 + 1, $rows, 'A heading, the two sales that were read, and the notice.');
        $this->assertSame([translate('profit_report_cut_short_at_the_sales_limit')], end($rows));
    }

    public function test_the_profit_export_of_the_profit_page_and_of_the_reports_page_are_the_same_file(): void
    {
        $this->channelSale('100');

        $fromReports = $this->get(route('admin.operational-costs.reports.export', ['report' => 'profit', 'dimension' => 'channel'] + self::NOVEMBER))->assertOk()->streamedContent();
        $fromProfit = $this->get(route('admin.operational-costs.profit.export', ['dimension' => 'channel'] + self::NOVEMBER))->assertOk()->streamedContent();

        $this->assertSame($fromReports, $fromProfit);
    }

    public function test_exports_are_rate_limited_and_an_unknown_report_is_not_found(): void
    {
        $this->get('/admin/operational-costs/reports/everything')->assertNotFound();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->get(route('admin.operational-costs.reports.export', ['report' => 'entries'] + self::NOVEMBER))->assertOk()->streamedContent();
        }

        $this->get(route('admin.operational-costs.reports.export', ['report' => 'entries'] + self::NOVEMBER))->assertStatus(429);
    }
}
