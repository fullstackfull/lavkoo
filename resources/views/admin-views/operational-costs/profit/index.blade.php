@extends('layouts.admin.app')

@use('App\Enums\Fulfillment\ProviderCode')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Services\OperationalCosts\Profit\ProfitAggregator')
@use('App\Services\OperationalCosts\Reports\ProfitFilters')
@use('App\Services\OperationalCosts\Reports\ProfitReportBuilder')

@section('title', translate('profitability'))

@section('content')
    @php
        $isProduct = $input->dimension === ProfitReportBuilder::DIMENSION_PRODUCT;
        $isOrder = $input->dimension === ProfitReportBuilder::DIMENSION_ORDER;
        $money = static fn ($amount): string => CostFormat::amount($amount, $currency);
        $figure = static fn ($amount): string => CostFormat::amount($amount);
        $totals = $table->totals;
        $tabs = array_map(static fn (string $dimension): array => [
            'label' => CostLabels::of('profit_dimension', $dimension),
            'href' => route('admin.operational-costs.profit.index', $input->parameters(['dimension' => $dimension])),
            'active' => $input->dimension === $dimension,
        ], $dimensions);
        $sortLink = static fn (string $sort): string => route('admin.operational-costs.profit.index', $input->parameters([
            'sort' => $sort,
            'direction' => $input->sort === $sort && !$input->ascending ? 'asc' : 'desc',
        ]));
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('profitability')" :subtitle="translate('profitability_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.profit.export', $input->parameters()) }}">{{ translate('export_csv') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        @if ($input->period->wasInvalid)
            <div class="alert alert-warning fs-12" role="alert">{{ translate('the_dates_were_not_valid_the_current_month_is_shown') }}</div>
        @endif

        <x-k.tabs :items="$tabs" class="mb-3" />

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.profit.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="dimension" value="{{ $input->dimension }}">
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-from">{{ translate('from') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-from" name="from" value="{{ $input->period->from->toDateString() }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-to">{{ translate('to') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-to" name="to" value="{{ $input->period->to->toDateString() }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-channel">{{ translate('sales_channel') }}</label>
                    <select class="form-select form-select-sm" id="filter-channel" name="channel">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}" @selected($input->channel === $channel->value)>{{ translate($channel->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-account">{{ translate('account') }}</label>
                    <select class="form-select form-select-sm" id="filter-account" name="account">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($accounts as $accountId => $accountName)
                            <option value="{{ $accountId }}" @selected($input->accountId === (int) $accountId)>{{ $accountName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-provider">{{ translate('provider') }}</label>
                    <select class="form-select form-select-sm" id="filter-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->value }}" @selected($input->provider === $provider->value)>{{ $provider->label() }}</option>
                        @endforeach
                        <option value="{{ ProfitFilters::NO_PROVIDER }}" @selected($input->provider === ProfitFilters::NO_PROVIDER)>{{ translate('no_provider') }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $input->seller])
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.profit.index', ['dimension' => $input->dimension]) }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        @include('admin-views.operational-costs.partials._truncated-notice', ['report' => $report])
        @include('admin-views.operational-costs.partials._uncosted-notice', ['report' => $report])
        @if (count($table->lines) > $lineLimit)
            <div class="alert alert-warning fs-12" role="alert">{{ CostLabels::filled(translate('only_the_first_lines_are_listed_the_totals_count_all_of_them'), ['shown' => $lineLimit, 'total' => count($table->lines)]) }}</div>
        @endif

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
            <x-k.stat :label="translate('recognised_revenue')" :value="$money($totals->revenueKnown() ? $totals->revenue : null)" :caption="$withGaps($isProduct ? '' : $totals->orders . ' ' . translate('sales'))" />
            <x-k.stat :label="translate('estimated_cost')" :value="$money($totals->estimatedCost)" :caption="$withGaps(translate('estimated_cost_includes_estimates') . ' · ' . CostLabels::completeness($totals->incompleteEstimated, $totals->missingCosts))" />
            <x-k.stat :label="translate('actual_cost')" :value="$money($totals->actualCost)" :caption="$withGaps(translate('actual_cost_is_settled_charges_only') . ' · ' . CostLabels::completeness($totals->incompleteActual, $totals->missingCosts))" />
            <x-k.stat :label="translate('estimated_profit')" :value="$money($totals->estimatedProfit())" :caption="$withGaps(translate('margin') . ': ' . CostFormat::percent($totals->estimatedMarginPercent()))" />
            <x-k.stat :label="translate('actual_profit')" :value="$money($totals->actualProfit())" :caption="$withGaps(translate('margin') . ': ' . CostFormat::percent($totals->actualMarginPercent()))" />
        </div>
        @if (!$totals->disputedCost->isZero())
            <p class="fs-12 text-muted">{{ translate('disputed_costs_are_in_neither_figure') }}: <span class="k-num">{{ $money($totals->disputedCost) }}</span></p>
        @endif
        @if ($isProduct)
            <div class="alert alert-info fs-12" role="note">{{ translate('product_profit_counts_the_costs_the_ledger_holds_note') }}</div>
        @endif

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ CostLabels::of('profit_dimension', $input->dimension) }}</th>
                        <th class="k-table__num">{{ $isProduct ? translate('units') : translate('sales') }}</th>
                        <th class="k-table__num"><a href="{{ $sortLink('revenue') }}">{{ translate('revenue') }}</a> ({{ $currency }})</th>
                        <th class="k-table__num">{{ translate('estimated_cost') }} ({{ $currency }})</th>
                        <th class="k-table__num">{{ translate('actual_cost') }} ({{ $currency }})</th>
                        <th class="k-table__num"><a href="{{ $sortLink('estimated_profit') }}">{{ translate('estimated_profit') }}</a> ({{ $currency }})</th>
                        <th class="k-table__num"><a href="{{ $sortLink('actual_profit') }}">{{ translate('actual_profit') }}</a> ({{ $currency }})</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <td>
                                @if ($isOrder)
                                    <a href="{{ route('admin.operational-costs.profit.order', ['type' => $line->subjectType(), 'id' => $line->subjectId()]) }}">{{ $line->label }}</a>
                                    <div class="fs-10 text-muted">{{ $line->source->occurredOn }} · {{ CostLabels::channel($line->source->salesChannel) }} · {{ CostLabels::of('sale_state', $line->source->state) }}</div>
                                @elseif ($isProduct)
                                    @if ($line->key === 'unallocated')
                                        {{ translate('costs_that_could_not_be_split_between_products') }}
                                    @else
                                        {{ $line->label !== '' ? $line->label : translate('product') }} <span class="text-muted">#{{ $line->key }}</span>
                                    @endif
                                @elseif ($input->dimension === ProfitAggregator::BY_CHANNEL)
                                    {{ CostLabels::channel($line->key) }}
                                @else
                                    {{ CostLabels::provider($line->key) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $isProduct ? $line->units : $line->orders }}</td>
                            <td class="k-table__num k-num">{{ $figure($line->revenue()) }}</td>
                            <td class="k-table__num k-num">{{ $figure($line->estimatedCost()) }}</td>
                            <td class="k-table__num k-num">{{ $figure($line->actualCost()) }}</td>
                            @if ($isProduct)
                                <td class="k-table__num">
                                    <span class="k-num">{{ $figure($line->estimatedProfit()) }}</span>
                                    <div class="fs-10 text-muted k-num">{{ CostFormat::percent($line->estimatedMarginPercent()) }}</div>
                                    @if ($line->missing > 0)<x-k.badge tone="warning" :dot="false">{{ translate('incomplete') }}: {{ $line->missing }} {{ translate('missing') }}</x-k.badge>@endif
                                    @include('admin-views.operational-costs.partials._conversion-gaps', ['gaps' => $line->gaps()])
                                </td>
                                <td class="k-table__num">
                                    <span class="k-num">{{ $figure($line->actualProfit()) }}</span>
                                    <div class="fs-10 text-muted k-num">{{ CostFormat::percent($line->actualMarginPercent()) }}</div>
                                    @if ($line->missing > 0)<x-k.badge tone="warning" :dot="false">{{ translate('incomplete') }}: {{ $line->missing }} {{ translate('missing') }}</x-k.badge>@endif
                                    @include('admin-views.operational-costs.partials._conversion-gaps', ['gaps' => $line->gaps()])
                                </td>
                            @else
                                <td class="k-table__num">@include('admin-views.operational-costs.partials._profit-figure', ['profit' => $line->estimatedProfit(), 'margin' => $line->estimatedMarginPercent(), 'completeness' => $line->costed ? CostLabels::lineCompleteness($line, settled: false) : translate('nothing_recorded'), 'complete' => $line->incompleteEstimated === 0, 'gaps' => $line->gaps()])</td>
                                <td class="k-table__num">@include('admin-views.operational-costs.partials._profit-figure', ['profit' => $line->actualProfit(), 'margin' => $line->actualMarginPercent(), 'completeness' => $line->costed ? CostLabels::lineCompleteness($line, settled: true) : translate('nothing_recorded'), 'complete' => $line->incompleteActual === 0, 'gaps' => $line->gaps()])</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-k.empty icon="reports" :title="translate('no_sales_in_this_period')" :text="translate('no_sales_in_this_period_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>
    </div>
@endsection
