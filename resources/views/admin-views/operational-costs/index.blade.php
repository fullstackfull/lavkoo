@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope')

@section('title', translate('operational_costs_overview'))

@section('content')
    @php
        $range = ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), 'seller' => SellerScope::parameter($seller)];
        $money = static fn ($amount): string => CostFormat::amount($amount, $currency);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('operational_costs_overview')" :subtitle="translate('operational_costs_overview_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.profit.index', $range) }}">{{ translate('profitability') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.reports.index') }}">{{ translate('cost_reports') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        @if ($period->wasInvalid)
            <div class="alert alert-warning fs-12" role="alert">{{ translate('the_dates_were_not_valid_the_current_month_is_shown') }}</div>
        @endif

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-from">{{ translate('from') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-from" name="from" value="{{ $period->from->toDateString() }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-to">{{ translate('to') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-to" name="to" value="{{ $period->to->toDateString() }}" required>
                </div>
                <div class="col-md-3">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $seller])
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <div class="alert alert-info fs-12" role="note">{{ translate('estimated_and_actual_are_never_added_together_note') }}</div>

        @include('admin-views.operational-costs.partials._truncated-notice', ['report' => $report])
        @include('admin-views.operational-costs.partials._uncosted-notice', ['report' => $report])

        <h2 class="fs-14 fw-semibold mb-2">{{ translate('profit_in_this_period') }}</h2>
        @php
            $gapText = $totals->gaps->isEmpty() ? null : translate('incomplete') . ': ' . CostLabels::noExchangeRate($totals->gaps);
            $withGaps = static fn (string $caption): string => implode(' · ', array_filter([$caption, $gapText], static fn (?string $part): bool => $part !== null && $part !== ''));
        @endphp
        @if (! $totals->gaps->isEmpty())
            <div class="alert alert-warning fs-12" role="alert" data-profit-gap="exchange-rate">
                <strong>{{ translate('this_figure_is_less_than_certain') }}</strong>
                {{ CostLabels::filled(translate('profit_no_exchange_rate_note'), ['currencies' => implode(', ', $totals->gaps->currencies())]) }}
            </div>
        @endif
        <div class="k-stats mb-3">
            <x-k.stat :label="translate('recognised_revenue')" :value="$money($totals->revenueKnown() ? $totals->revenue : null)"
                      :caption="$withGaps($totals->orders . ' ' . translate('sales'))" />
            <x-k.stat :label="translate('estimated_cost')" :value="$money($totals->estimatedCost)"
                      :caption="$withGaps(translate('estimated_cost_includes_estimates') . ' · ' . CostLabels::completeness($totals->incompleteEstimated, $totals->missingCosts))" />
            <x-k.stat :label="translate('actual_cost')" :value="$money($totals->actualCost)"
                      :caption="$withGaps(translate('actual_cost_is_settled_charges_only') . ' · ' . CostLabels::completeness($totals->incompleteActual, $totals->missingCosts))" />
            <x-k.stat :label="translate('estimated_profit')" :value="$money($totals->estimatedProfit())"
                      :caption="$withGaps(translate('margin') . ': ' . CostFormat::percent($totals->estimatedMarginPercent()) . ' · ' . CostLabels::completeness($totals->incompleteEstimated, $totals->missingCosts))" />
            <x-k.stat :label="translate('actual_profit')" :value="$money($totals->actualProfit())"
                      :caption="$withGaps(translate('margin') . ': ' . CostFormat::percent($totals->actualMarginPercent()) . ' · ' . CostLabels::completeness($totals->incompleteActual, $totals->missingCosts))" />
        </div>
        @if (!$totals->disputedCost->isZero())
            <p class="fs-12 text-muted">{{ translate('disputed_costs_are_in_neither_figure') }}: <span class="k-num">{{ $money($totals->disputedCost) }}</span></p>
        @endif

        <h2 class="fs-14 fw-semibold mb-2">{{ translate('what_needs_attention') }}</h2>
        <div class="k-stats mb-3">
            <x-k.stat :label="translate('missing_costs')" :value="$missingCount"
                      :caption="translate('in_this_period')"
                      :href="route('admin.operational-costs.entries.index', ['view' => 'missing'] + $range)" />
            <x-k.stat :label="translate('unapproved_invoices')" :value="$unapprovedInvoices"
                      :caption="translate('all_periods')"
                      :href="route('admin.operational-costs.invoices.index', ['status' => 'unapproved', 'seller' => $range['seller']])" />
            <x-k.stat :label="translate('open_reconciliation_differences')" :value="$openDifferences"
                      :caption="translate('all_periods')"
                      :href="route('admin.operational-costs.invoices.index', ['differences' => 1, 'seller' => $range['seller']])" />
            <x-k.stat :label="translate('disputed_entries')" :value="$disputedEntries"
                      :caption="translate('all_periods')"
                      :href="route('admin.operational-costs.entries.index', ['state' => 'disputed', 'from' => '', 'to' => '', 'seller' => $range['seller']])" />
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-6">
                <x-k.card :title="translate('biggest_reasons_costs_are_missing')" :padded="false">
                    <div class="k-table-wrap table-responsive">
                        <table class="k-table">
                            <thead><tr><th>{{ translate('reason') }}</th><th class="k-table__num">{{ translate('costs') }}</th></tr></thead>
                            <tbody>
                            @forelse ($missingReasons as $row)
                                <tr>
                                    <td>
                                        @if ($row->missing_reason)
                                            <a href="{{ route('admin.operational-costs.entries.index', ['view' => 'missing', 'reason' => $row->missing_reason] + $range) }}">{{ CostLabels::of('missing_reason', $row->missing_reason) }}</a>
                                        @else
                                            {{ translate('no_reason_recorded') }}
                                        @endif
                                    </td>
                                    <td class="k-table__num k-num">{{ $row->total }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2"><x-k.empty icon="check" :title="translate('no_missing_costs_in_this_period')" :text="translate('no_missing_costs_in_this_period_help')" /></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-k.card>
            </div>
            <div class="col-xl-6">
                <x-k.card :title="translate('quick_links')">
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.contracts.index') }}">{{ translate('cost_contracts') }}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.product-costs.index') }}">{{ translate('product_costs') }}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.entries.index', $range) }}">{{ translate('cost_entries') }}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.invoices.index') }}">{{ translate('invoices') }}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.invoices.create') }}">{{ translate('add_an_invoice') }}</a>
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.profit.index', $range) }}">{{ translate('profitability') }}</a>
                    </div>
                </x-k.card>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-6">
                @include('admin-views.operational-costs.partials._profit-breakdown', ['title' => translate('by_sales_channel'), 'lines' => $byChannel, 'kind' => 'channel', 'currency' => $currency])
            </div>
            <div class="col-xl-6">
                @include('admin-views.operational-costs.partials._profit-breakdown', ['title' => translate('by_provider'), 'lines' => $byProvider, 'kind' => 'provider', 'currency' => $currency])
            </div>
        </div>
    </div>
@endsection
