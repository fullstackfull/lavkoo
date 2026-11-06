@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\InvoiceFilters')

@section('title', translate('invoices_and_reconciliation'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('invoices_and_reconciliation')" :subtitle="translate('invoices_and_reconciliation_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
                <a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.invoices.create') }}">{{ translate('add_an_invoice') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.invoices.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters->search }}" placeholder="{{ translate('invoice_number_or_company') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-provider">{{ translate('company_code') }}</label>
                    <select class="form-select form-select-sm" id="filter-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected($filters->provider === $provider)>{{ $provider }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-status">{{ translate('status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters->status === $status)>{{ $status === InvoiceFilters::UNAPPROVED ? translate('not_approved_yet') : CostLabels::of('invoice_status', $status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-kind">{{ translate('invoice_kind') }}</label>
                    <select class="form-select form-select-sm" id="filter-kind" name="kind">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}" @selected($filters->kind === $kind)>{{ CostLabels::of('invoice_kind', $kind) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $filters->seller])
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-from">{{ translate('invoice_date_from') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-from" name="from" value="{{ $filters->from?->toDateString() }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-to">{{ translate('invoice_date_to') }}</label>
                    <input class="form-control form-control-sm" type="date" id="filter-to" name="to" value="{{ $filters->to?->toDateString() }}">
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-duplicate" name="duplicate" value="1" @checked($filters->possibleDuplicates)>
                        <label class="form-check-label fs-12" for="filter-duplicate">{{ translate('possible_duplicates_only') }}</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-differences" name="differences" value="1" @checked($filters->openDifferences)>
                        <label class="form-check-label fs-12" for="filter-differences">{{ translate('with_open_differences_only') }}</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.invoices.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('invoice_number') }}</th><th>{{ translate('company_name') }}</th><th>{{ translate('invoice_kind') }}</th><th>{{ translate('invoice_date') }}</th>
                        <th>{{ translate('period') }}</th><th class="k-table__num">{{ translate('total') }}</th><th>{{ translate('status') }}</th>
                        <th class="k-table__num">{{ translate('lines') }}</th><th>{{ translate('books_of') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('admin.operational-costs.invoices.show', $invoice->id) }}">{{ $invoice->invoice_number }}</a></td>
                            <td>{{ $invoice->party_name }}<div class="fs-10 text-muted">{{ $invoice->provider_code }}</div></td>
                            <td>{{ CostLabels::of('invoice_kind', $invoice->kind) }}</td>
                            <td class="fs-12 k-num">{{ $invoice->invoice_date->toDateString() }}</td>
                            <td class="fs-12 k-num">@if ($invoice->period_start && $invoice->period_end){{ $invoice->period_start->toDateString() }} → {{ $invoice->period_end->toDateString() }}@else—@endif</td>
                            <td class="k-table__num k-num">{{ CostFormat::amount($invoice->total, $invoice->currency) }}</td>
                            <td>
                                <x-k.badge :tone="CostTones::invoice($invoice->status)">{{ CostLabels::of('invoice_status', $invoice->status) }}</x-k.badge>
                                @if ($invoice->duplicate_of_id)
                                    <div class="fs-10 text-warning">{{ translate('looks_like_invoice') }} <a href="{{ route('admin.operational-costs.invoices.show', $invoice->duplicate_of_id) }}">#{{ $invoice->duplicate_of_id }}</a></div>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $invoice->items_count }}</td>
                            <td class="fs-12">@include('admin-views.operational-costs.partials._seller', ['seller' => $invoice->seller_id])</td>
                            <td><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.invoices.show', $invoice->id) }}">{{ translate('view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-k.empty icon="orders" :title="translate('no_invoices_yet')" :text="translate('no_invoices_yet_help')">
                            <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.invoices.create') }}">{{ translate('add_an_invoice') }}</a></x-slot:action>
                        </x-k.empty></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($invoices->hasPages())
                <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
