{{-- Profit lines grouped by sales channel or by provider, estimated and actual apart. Expects $title, $lines (list<ProfitLine>), $kind ('channel' or 'provider'), $currency. --}}
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
<x-k.card :title="$title" :padded="false">
    <div class="k-table-wrap table-responsive">
        <table class="k-table">
            <thead><tr>
                <th>{{ $kind === 'channel' ? translate('sales_channel') : translate('provider') }}</th>
                <th class="k-table__num">{{ translate('sales') }}</th>
                <th class="k-table__num">{{ translate('revenue') }} ({{ $currency }})</th>
                <th class="k-table__num">{{ translate('estimated_profit') }} ({{ $currency }})</th>
                <th class="k-table__num">{{ translate('actual_profit') }} ({{ $currency }})</th>
            </tr></thead>
            <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $kind === 'channel' ? CostLabels::channel($line->key) : CostLabels::provider($line->key) }}</td>
                    <td class="k-table__num k-num">{{ $line->orders }}</td>
                    <td class="k-table__num k-num">{{ CostFormat::amount($line->revenue()) }}</td>
                    <td class="k-table__num">@include('admin-views.operational-costs.partials._profit-figure', ['profit' => $line->estimatedProfit(), 'margin' => $line->estimatedMarginPercent(), 'completeness' => CostLabels::lineCompleteness($line, settled: false), 'complete' => $line->complete(settled: false), 'gaps' => $line->gaps()])</td>
                    <td class="k-table__num">@include('admin-views.operational-costs.partials._profit-figure', ['profit' => $line->actualProfit(), 'margin' => $line->actualMarginPercent(), 'completeness' => CostLabels::lineCompleteness($line, settled: true), 'complete' => $line->complete(settled: true), 'gaps' => $line->gaps()])</td>
                </tr>
            @empty
                <tr><td colspan="5"><x-k.empty icon="reports" :title="translate('no_sales_in_this_period')" :text="translate('no_sales_in_this_period_help')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-k.card>
