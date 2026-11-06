@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Services\OperationalCosts\Reports\ProfitFilters')
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope')

@section('title', translate('cost_reports'))

@section('content')
    @php
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cost_reports')" :subtitle="translate('cost_reports_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('exports_note_utf8_excel_and_formulas') }}</div>

        <div class="row g-3">
            <div class="col-xl-6">
                <x-k.card :title="CostLabels::of('report_title', 'entries')">
                    <p class="fs-12 text-muted">{{ CostLabels::of('report_help', 'entries') }}</p>
                    <form method="GET" action="{{ route('admin.operational-costs.reports.export', 'entries') }}" class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-from">{{ translate('from') }}</label>
                            <input class="form-control form-control-sm" type="date" id="entries-from" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-to">{{ translate('to') }}</label>
                            <input class="form-control form-control-sm" type="date" id="entries-to" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-component">{{ translate('component') }}</label>
                            <select class="form-select form-select-sm" id="entries-component" name="component">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($components as $component)
                                    <option value="{{ $component->value }}">{{ translate($component->labelKey()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-state">{{ translate('state') }}</label>
                            <select class="form-select form-select-sm" id="entries-state" name="state">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($states as $state)
                                    <option value="{{ $state->value }}">{{ translate($state->labelKey()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-provider">{{ translate('provider') }}</label>
                            <select class="form-select form-select-sm" id="entries-provider" name="provider">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider }}">{{ CostLabels::provider($provider) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="entries-channel">{{ translate('sales_channel') }}</label>
                            <select class="form-select form-select-sm" id="entries-channel" name="channel">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($channels as $channel)
                                    <option value="{{ $channel->value }}">{{ translate($channel->labelKey()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => SellerScope::PLATFORM])
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="entries-history" name="history" value="1">
                                <label class="form-check-label fs-12" for="entries-history">{{ translate('include_history_replaced_entries') }}</label>
                            </div>
                        </div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ translate('download_csv') }}</button></div>
                    </form>
                </x-k.card>
            </div>

            <div class="col-xl-6">
                <x-k.card :title="CostLabels::of('report_title', 'missing')" class="mb-3">
                    <p class="fs-12 text-muted">{{ CostLabels::of('report_help', 'missing') }}</p>
                    <form method="GET" action="{{ route('admin.operational-costs.reports.export', 'missing') }}" class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="missing-from">{{ translate('from') }}</label>
                            <input class="form-control form-control-sm" type="date" id="missing-from" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="missing-to">{{ translate('to') }}</label>
                            <input class="form-control form-control-sm" type="date" id="missing-to" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="missing-provider">{{ translate('provider') }}</label>
                            <select class="form-select form-select-sm" id="missing-provider" name="provider">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider }}">{{ CostLabels::provider($provider) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="missing-seller">{{ translate('books_of') }}</label>
                            <select class="form-select form-select-sm" id="missing-seller" name="seller">
                                @foreach ($sellers as $sellerOption)
                                    <option value="{{ $sellerOption }}">{{ SellerScope::label($sellerOption) }}</option>
                                @endforeach
                                <option value="{{ SellerScope::ALL }}">{{ translate('all_sellers') }}</option>
                            </select>
                        </div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ translate('download_csv') }}</button></div>
                    </form>
                </x-k.card>

                <x-k.card :title="CostLabels::of('report_title', 'invoices')">
                    <p class="fs-12 text-muted">{{ CostLabels::of('report_help', 'invoices') }}</p>
                    <form method="GET" action="{{ route('admin.operational-costs.reports.export', 'invoices') }}" class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="invoices-from">{{ translate('invoice_date_from') }}</label>
                            <input class="form-control form-control-sm" type="date" id="invoices-from" name="from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="invoices-to">{{ translate('invoice_date_to') }}</label>
                            <input class="form-control form-control-sm" type="date" id="invoices-to" name="to">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="invoices-provider">{{ translate('company_code') }}</label>
                            <select class="form-select form-select-sm" id="invoices-provider" name="provider">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($invoiceProviders as $provider)
                                    <option value="{{ $provider }}">{{ $provider }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fs-12" for="invoices-status">{{ translate('status') }}</label>
                            <select class="form-select form-select-sm" id="invoices-status" name="status">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($invoiceStatuses as $status)
                                    <option value="{{ $status }}">{{ $status === 'unapproved' ? translate('not_approved_yet') : CostLabels::of('invoice_status', $status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ translate('download_csv') }}</button></div>
                    </form>
                </x-k.card>
            </div>

            <div class="col-12">
                <x-k.card :title="CostLabels::of('report_title', 'profit')">
                    <p class="fs-12 text-muted">{{ CostLabels::of('report_help', 'profit') }}</p>
                    <form method="GET" action="{{ route('admin.operational-costs.reports.export', 'profit') }}" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-from">{{ translate('from') }}</label>
                            <input class="form-control form-control-sm" type="date" id="profit-from" name="from" value="{{ $from }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-to">{{ translate('to') }}</label>
                            <input class="form-control form-control-sm" type="date" id="profit-to" name="to" value="{{ $to }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-dimension">{{ translate('group_by') }}</label>
                            <select class="form-select form-select-sm" id="profit-dimension" name="dimension">
                                @foreach ($dimensions as $dimension)
                                    <option value="{{ $dimension }}">{{ CostLabels::of('profit_dimension', $dimension) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-channel">{{ translate('sales_channel') }}</label>
                            <select class="form-select form-select-sm" id="profit-channel" name="channel">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($channels as $channel)
                                    <option value="{{ $channel->value }}">{{ translate($channel->labelKey()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-account">{{ translate('account') }}</label>
                            <select class="form-select form-select-sm" id="profit-account" name="account">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($accounts as $accountId => $accountName)
                                    <option value="{{ $accountId }}">{{ $accountName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fs-12" for="profit-provider">{{ translate('provider') }}</label>
                            <select class="form-select form-select-sm" id="profit-provider" name="provider">
                                <option value="">{{ translate('all') }}</option>
                                @foreach ($fulfillmentProviders as $provider)
                                    <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                                @endforeach
                                <option value="{{ ProfitFilters::NO_PROVIDER }}">{{ translate('no_provider') }}</option>
                            </select>
                        </div>
                        <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">{{ translate('download_csv') }}</button></div>
                    </form>
                </x-k.card>
            </div>
        </div>
    </div>
@endsection
