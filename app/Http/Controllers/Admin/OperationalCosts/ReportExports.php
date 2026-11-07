<?php

namespace App\Http\Controllers\Admin\OperationalCosts;

use App\Http\Controllers\Admin\OperationalCosts\Filters\EntryFilters;
use App\Http\Controllers\Admin\OperationalCosts\Filters\InvoiceFilters;
use App\Http\Controllers\Admin\OperationalCosts\Filters\ProfitInput;
use App\Models\OperationalCostEntry;
use App\Models\OperationalCostInvoice;
use App\Services\OperationalCosts\Profit\ProfitResult;
use App\Services\OperationalCosts\Reports\ProfitLine;
use App\Services\OperationalCosts\Reports\ProfitReport;
use App\Services\OperationalCosts\Reports\ProfitReportBuilder;
use App\Services\OperationalCosts\Reports\ProfitTable;
use App\Services\OperationalCosts\Support\FxConverter;
use App\Services\OperationalCosts\Support\Money;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV exports: cost entries, missing costs, profit along one dimension, invoices.
 *
 * Each takes the filters of the page it belongs to, so a file holds exactly what the page listed. Amounts are written as the exact decimals they are, a cost
 * that is missing has an empty amount (never a 0), and a file that was cut at the row limit says so on its last line.
 */
class ReportExports
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly CsvDownload $download,
        private readonly ProfitReportBuilder $reports,
        private readonly FxConverter $fx,
    ) {
    }

    public function entries(Request $request): StreamedResponse
    {
        return $this->entryFile($request, 'cost-entries', null);
    }

    public function missing(Request $request): StreamedResponse
    {
        return $this->entryFile($request, 'missing-costs', EntryFilters::VIEW_MISSING);
    }

    public function invoices(Request $request): StreamedResponse
    {
        $filters = InvoiceFilters::fromRequest($request);

        return $this->download->stream(
            $this->filename('invoices', $filters->from, $filters->to),
            [
                translate('id'), translate('invoice_number'), translate('company_code'), translate('company_name'), translate('invoice_kind'), translate('status'),
                translate('invoice_date'), translate('period_start'), translate('period_end'), translate('currency'), translate('subtotal'), translate('tax_total'),
                translate('total'), translate('possible_duplicate_of'), translate('approved_at'), translate('seller'),
            ],
            $this->download->limited($this->invoiceRows($filters), translate('export_stops_here_more_rows_exist')),
        );
    }

    public function profit(Request $request): StreamedResponse
    {
        $input = ProfitInput::fromRequest($request);
        $report = $this->reports->build($input->filters(), $input->dimension);

        return $this->download->stream(
            $this->filename('profit-by-' . $input->dimension, $input->period->from, $input->period->to),
            [
                translate('key'), translate('name'), translate('date'), translate('sales_channel'), translate('provider'), translate('state'), translate('orders'), translate('units'),
                translate('revenue'), translate('estimated_cost'), translate('actual_cost'), translate('disputed_cost'), translate('estimated_profit'), translate('estimated_margin'),
                translate('actual_profit'), translate('actual_margin'), translate('missing_costs'), translate('pending_actual_costs'),
                translate('sales_with_gaps_estimated'), translate('sales_with_gaps_actual'), translate('currency'),
                translate('disputed_costs_count'), translate('incomplete_no_exchange_rate'), translate('unconverted_revenue_amounts'), translate('unconverted_cost_entries'),
                translate('currencies_without_a_rate'),
            ],
            $this->profitRows($this->reports->table($report, $input->dimension), $report),
        );
    }

    private function entryFile(Request $request, string $name, ?string $forcedView): StreamedResponse
    {
        $filters = EntryFilters::fromRequest($request, $forcedView);

        return $this->download->stream(
            $this->filename($name, $filters->from, $filters->to),
            [
                translate('id'), translate('date'), translate('seller'), translate('subject_type'), translate('subject_id'), translate('order'), translate('channel_order'),
                translate('sales_channel'), translate('account'), translate('provider'), translate('component'), translate('cost_class'), translate('state'), translate('effective'),
                translate('amount'), translate('currency'), translate('base_amount'), translate('base_currency'), translate('missing_reason'), translate('source'),
                translate('contract'), translate('contract_version'), translate('allocation'), translate('reference'), translate('approved_at'), translate('note'),
            ],
            $this->download->limited($this->entryRows($filters), translate('export_stops_here_more_rows_exist')),
        );
    }

    /** @return Generator<int, list<mixed>> */
    private function entryRows(EntryFilters $filters): Generator
    {
        foreach ($filters->query()->lazyById(self::CHUNK) as $entry) {
            /** @var OperationalCostEntry $entry */
            yield [
                $entry->id, $entry->period_date->toDateString(), $entry->seller_id, $entry->subject_type, $entry->subject_id, $entry->order_id, $entry->external_order_mapping_id,
                $entry->sales_channel, $entry->account_id, $entry->provider, $entry->cost_component, $entry->cost_class, $entry->state->value, $entry->is_effective,
                Money::ofNullable($entry->amount), $entry->currency, Money::ofNullable($entry->base_amount), $entry->base_currency, $entry->missing_reason, $entry->source,
                $entry->contract_id, $entry->contract_version, $entry->allocation_status, $entry->external_reference, $entry->approved_at, $entry->note,
            ];
        }
    }

    /** @return Generator<int, list<mixed>> */
    private function invoiceRows(InvoiceFilters $filters): Generator
    {
        foreach ($filters->query()->lazyById(self::CHUNK) as $invoice) {
            /** @var OperationalCostInvoice $invoice */
            yield [
                $invoice->id, $invoice->invoice_number, $invoice->provider_code, $invoice->party_name, $invoice->kind, $invoice->status,
                $invoice->invoice_date->toDateString(), $invoice->period_start?->toDateString(), $invoice->period_end?->toDateString(), $invoice->currency,
                Money::ofNullable($invoice->subtotal), Money::ofNullable($invoice->tax_total), Money::ofNullable($invoice->total),
                $invoice->duplicate_of_id, $invoice->approved_at, $invoice->seller_id,
            ];
        }
    }

    /** @return Generator<int, list<mixed>> */
    private function profitRows(ProfitTable $table, ProfitReport $report): Generator
    {
        $currency = $this->fx->baseCurrency();
        foreach ($table->lines as $line) {
            yield $this->profitRow($line, $currency);
        }
        if (!$table->totals->gaps->isEmpty()) {
            yield [CostLabels::filled(translate('profit_report_has_amounts_without_an_exchange_rate'), ['currencies' => implode(', ', $table->totals->gaps->currencies())])];
        }
        if ($report->truncated) {
            yield [translate('profit_report_cut_short_at_the_sales_limit')];
        }
    }

    /** @return list<mixed> */
    private function profitRow(ProfitLine $line, string $currency): array
    {
        $sale = $line->source instanceof ProfitResult ? $line->source : null;

        return [
            $line->key, $line->label, $sale?->occurredOn, $sale?->salesChannel, $sale?->provider, $sale?->state, $line->orders, $line->units,
            $line->revenue(), $line->estimatedCost(), $line->actualCost(), $line->disputedCost(), $line->estimatedProfit(), $line->estimatedMarginPercent(),
            $line->actualProfit(), $line->actualMarginPercent(), $line->missing, $line->pending, $line->incompleteEstimated, $line->incompleteActual, $currency,
            $line->disputedCount(), !$line->gaps()->isEmpty(), $line->gaps()->revenueCount(), $line->gaps()->costCount(), implode(' ', $line->gaps()->currencies()),
        ];
    }

    private function filename(string $name, ?CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        return 'operational-costs-' . $name . '-' . ($from?->toDateString() ?? 'all') . '-' . ($to?->toDateString() ?? 'all') . '.csv';
    }
}
