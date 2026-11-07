@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
{{-- Says that a report stopped at its limit, so that a total is never taken for the whole period's. Expects $report (App\Services\OperationalCosts\Reports\ProfitReport). --}}
@if ($report->truncated)
    <div class="alert alert-warning fs-12" role="alert">
        <strong>{{ translate('this_report_was_cut_short') }}</strong>
        {{ CostLabels::filled(translate('only_the_first_sales_of_the_period_were_read'), ['limit' => $report->limit]) }} {{ translate('narrow_the_dates_or_the_filters_to_see_the_rest') }}
    </div>
@endif
