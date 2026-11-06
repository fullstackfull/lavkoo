@extends('layouts.admin.app')

@use('App\Enums\OperationalCosts\CostState')
@use('App\Http\Controllers\Admin\OperationalCosts\EntryActions')
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\EntryFilters')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')

@section('title', translate('cost_entries'))

@section('content')
    @php
        $tabs = array_map(static fn (string $view): array => [
            'label' => CostLabels::of('entries_view', $view),
            'href' => route('admin.operational-costs.entries.index', $filters->parameters(['view' => $view, 'class' => null])),
            'active' => $filters->view === $view,
        ], $views);
        $classTab = !in_array($filters->view, [EntryFilters::VIEW_ALL, EntryFilters::VIEW_MISSING], true);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cost_entries')" :subtitle="translate('cost_entries_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.reports.index') }}">{{ translate('cost_reports') }}</a>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.tabs :items="$tabs" class="mb-3" />

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.entries.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="view" value="{{ $filters->view }}">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-component">{{ translate('component') }}</label>
                    <select class="form-select form-select-sm" id="filter-component" name="component">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($components as $costComponent)
                            <option value="{{ $costComponent->value }}" @selected($filters->component === $costComponent)>{{ translate($costComponent->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                @unless ($classTab)
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="filter-class">{{ translate('cost_class') }}</label>
                        <select class="form-select form-select-sm" id="filter-class" name="class">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($classes as $class)
                                <option value="{{ $class->value }}" @selected($filters->costClass === $class)>{{ translate($class->labelKey()) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
                @if ($filters->view !== EntryFilters::VIEW_MISSING)
                    <div class="col-md-2">
                        <label class="form-label fs-12" for="filter-state">{{ translate('state') }}</label>
                        <select class="form-select form-select-sm" id="filter-state" name="state">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($states as $state)
                                <option value="{{ $state->value }}" @selected($filters->state === $state)>{{ translate($state->labelKey()) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-provider">{{ translate('provider') }}</label>
                    <select class="form-select form-select-sm" id="filter-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected($filters->provider === $provider)>{{ CostLabels::provider($provider) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-channel">{{ translate('sales_channel') }}</label>
                    <select class="form-select form-select-sm" id="filter-channel" name="channel">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}" @selected($filters->salesChannel === $channel->value)>{{ translate($channel->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-account">{{ translate('account') }}</label>
                    <select class="form-select form-select-sm" id="filter-account" name="account">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($accounts as $accountId => $accountName)
                            <option value="{{ $accountId }}" @selected($filters->accountId === (int) $accountId)>{{ $accountName }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-subject">{{ translate('subject_type') }}</label>
                    <select class="form-select form-select-sm" id="filter-subject" name="subject">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($subjectTypes as $subjectType)
                            <option value="{{ $subjectType }}" @selected($filters->subjectType === $subjectType)>{{ CostLabels::of('entry_subject', $subjectType) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-from">{{ translate('from') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-from" name="from" value="{{ $filters->from?->toDateString() }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-to">{{ translate('to') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-to" name="to" value="{{ $filters->to?->toDateString() }}">
                </div>
                <div class="col-md-2">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $filters->seller])
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search_by_reference') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters->search }}">
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-history" name="history" value="1" @checked($filters->includeHistory)>
                        <label class="form-check-label fs-12" for="filter-history">{{ translate('include_history_replaced_entries') }}</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.entries.index', ['view' => $filters->view]) }}">{{ translate('reset') }}</a>
                </div>
            </form>
            <p class="fs-12 text-muted mt-2 mb-0">{{ translate('period_date_range_note') }}@if ($filters->from === null && $filters->to === null) {{ translate('every_date_is_shown') }}@endif</p>
        </x-k.card>

        <x-k.card :title="translate('totals_of_the_entries_listed')" class="mb-3">
            @forelse ($summary as $line)
                <div class="d-flex flex-wrap align-items-baseline gap-2 py-1 border-bottom">
                    @include('admin-views.operational-costs.partials._cost-state', ['state' => $line['state']])
                    <span class="k-num">{{ $line['entries'] }} {{ translate('entries') }}</span>
                    @if ($line['state'] === CostState::Missing)
                        <span class="fs-12 text-danger">{{ translate('no_amount_a_missing_cost_is_not_a_zero') }}</span>
                    @else
                        @foreach ($line['totals'] as $totalCurrency => $total)
                            <strong class="k-num">{{ CostFormat::amount($total, $totalCurrency) }}</strong>
                        @endforeach
                        @if ($line['unconverted'] > 0)<span class="fs-12 text-warning">{{ CostLabels::filled(translate('entries_left_out_no_exchange_rate'), ['count' => $line['unconverted']]) }}</span>@endif
                        @if ($line['state'] === CostState::Disputed)<span class="fs-12 text-muted">{{ translate('in_no_total_until_resolved') }}</span>@endif
                        @if ($line['state'] === CostState::Estimated)<span class="fs-12 text-muted">{{ translate('still_waiting_for_the_real_charge') }}</span>@endif
                    @endif
                </div>
            @empty
                <span class="fs-12 text-muted">{{ translate('no_entries_match_these_filters') }}</span>
            @endforelse
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('date') }}</th><th>{{ translate('subject') }}</th><th>{{ translate('component') }}</th><th>{{ translate('provider') }}</th>
                        <th class="k-table__num">{{ translate('amount') }}</th><th class="k-table__num">{{ translate('base_amount') }}</th><th>{{ translate('state') }}</th>
                        <th>{{ translate('source') }}</th><th>{{ translate('contract') }}</th><th>{{ translate('allocation') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($entries as $entry)
                        @php
                            $drill = $entry->external_order_mapping_id !== null ? ['channel_order', $entry->external_order_mapping_id] : ($entry->order_id !== null ? ['order', $entry->order_id] : null);
                        @endphp
                        <tr @class(['text-muted' => !$entry->is_effective])>
                            <td class="fs-12 k-num">{{ $entry->period_date->toDateString() }}</td>
                            <td>
                                @if ($drill)
                                    <a href="{{ route('admin.operational-costs.profit.order', ['type' => $drill[0], 'id' => $drill[1]]) }}">{{ CostLabels::of('entry_subject', $entry->subject_type) }} #{{ $entry->subject_id }}</a>
                                @else
                                    {{ CostLabels::of('entry_subject', $entry->subject_type) }} #{{ $entry->subject_id }}
                                @endif
                                <div class="fs-10 text-muted">{{ CostLabels::channel($entry->sales_channel) }}@if ($entry->external_reference) · {{ $entry->external_reference }}@endif @if ($entry->seller_id !== 0) · @include('admin-views.operational-costs.partials._seller', ['seller' => $entry->seller_id])@endif</div>
                            </td>
                            <td>{{ $entry->component() ? translate($entry->component()->labelKey()) : $entry->cost_component }}</td>
                            <td>{{ CostLabels::provider($entry->provider) }}</td>
                            <td class="k-table__num">@include('admin-views.operational-costs.partials._entry-amount', ['entry' => $entry])</td>
                            <td class="k-table__num k-num">{{ CostFormat::amount($entry->base_amount, $entry->base_currency) }}</td>
                            <td>
                                @include('admin-views.operational-costs.partials._cost-state', ['state' => $entry->state])
                                @unless ($entry->is_effective)
                                    <div class="fs-10">{{ translate('replaced_by') }} @if ($entry->superseded_by_id)<a href="{{ route('admin.operational-costs.entries.show', $entry->superseded_by_id) }}">#{{ $entry->superseded_by_id }}</a>@endif</div>
                                @endunless
                                @if ($entry->approved_at)<div class="fs-10 text-muted">{{ translate('approved') }}</div>@endif
                            </td>
                            <td class="fs-12">{{ CostLabels::of('entry_source', $entry->source) }}</td>
                            <td class="fs-12">
                                @if ($entry->contract_id)
                                    <a href="{{ route('admin.operational-costs.contracts.show', $entry->contract_id) }}">{{ $contracts[$entry->contract_id] ?? '#' . $entry->contract_id }} v{{ $entry->contract_version }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="fs-12">{{ CostLabels::of('allocation_status', $entry->allocation_status) }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.entries.show', $entry->id) }}">{{ translate('view') }}</a>
                                    @if (EntryActions::allows(EntryActions::APPROVE, $entry))
                                        <form method="POST" action="{{ route('admin.operational-costs.entries.approve', $entry->id) }}">@csrf
                                            <button class="btn btn-outline-success btn-sm" type="submit">{{ translate('approve') }}</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-k.empty icon="search" :title="translate('no_cost_entries_here')" :text="translate('no_cost_entries_here_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())
                <x-slot:footer>{{ $entries->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
