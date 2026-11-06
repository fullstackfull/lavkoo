@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
{{-- Warns that some sales have no cost recorded at all: their profit is only their revenue, which a margin would show as a perfect one. Expects $report (App\Services\OperationalCosts\Reports\ProfitReport). --}}
@php $uncosted = $report->uncostedSales(); @endphp
@if ($uncosted > 0)
    <div class="alert alert-warning fs-12" role="alert">
        <strong>{{ CostLabels::filled(translate('sales_have_no_cost_recorded_at_all'), ['count' => $uncosted]) }}</strong>
        {{ translate('their_profit_is_only_their_revenue_until_their_costs_are_recorded') }}
    </div>
@endif
