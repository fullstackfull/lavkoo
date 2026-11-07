@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')
@use('App\Http\Controllers\Admin\OperationalCosts\Filters\SellerScope')
@use('App\Enums\OperationalCosts\ContractStatus')
@use('App\Services\OperationalCosts\ContractService')

@section('title', translate('cost_contracts'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('cost_contracts')" :subtitle="translate('cost_contracts_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
                <a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.contracts.create') }}">{{ translate('new_contract') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        <div class="alert alert-info fs-12" role="note">{{ translate('a_contract_starts_as_a_draft_with_no_price_in_it_note') }}</div>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.contracts.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] }}" placeholder="{{ translate('company_reference_or_code') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-party-type">{{ translate('kind_of_company') }}</label>
                    <select class="form-select form-select-sm" id="filter-party-type" name="party_type">
                        <option value="">{{ translate('all') }}</option>
                        @foreach (ContractService::PARTY_TYPES as $partyType)
                            <option value="{{ $partyType }}" @selected($filters['party_type'] === $partyType)>{{ CostLabels::of('party_type', $partyType) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-provider">{{ translate('company_code') }}</label>
                    <select class="form-select form-select-sm" id="filter-provider" name="provider">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fs-12" for="filter-status">{{ translate('status') }}</label>
                    <select class="form-select form-select-sm" id="filter-status" name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach (ContractStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ translate($status->labelKey()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $filters['seller']])
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                </div>
            </form>
        </x-k.card>

        <x-k.card :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('company_name') }}</th><th>{{ translate('kind_of_company') }}</th><th>{{ translate('version') }}</th><th>{{ translate('status') }}</th>
                        <th>{{ translate('currency') }}</th><th>{{ translate('validity') }}</th><th class="k-table__num">{{ translate('price_rules') }}</th><th>{{ translate('books_of') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($contracts as $contract)
                        <tr>
                            <td>
                                <a href="{{ route('admin.operational-costs.contracts.show', $contract->id) }}">{{ $contract->party_name }}</a>
                                <div class="fs-10 text-muted">{{ $contract->provider_code }}@if ($contract->reference) · {{ $contract->reference }}@endif</div>
                            </td>
                            <td>{{ CostLabels::of('party_type', $contract->party_type) }}</td>
                            <td class="k-num">v{{ $contract->version }}</td>
                            <td><x-k.badge :tone="CostTones::contract($contract->status)">{{ translate($contract->status->labelKey()) }}</x-k.badge></td>
                            <td>{{ $contract->currency }}</td>
                            <td class="fs-12 k-num">{{ $contract->effective_from->toDateString() }} → {{ $contract->effective_to?->toDateString() ?? translate('open_ended') }}</td>
                            <td class="k-table__num k-num">{{ $contract->rules_count }}</td>
                            <td class="fs-12">@include('admin-views.operational-costs.partials._seller', ['seller' => $contract->seller_id])</td>
                            <td><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.contracts.show', $contract->id) }}">{{ translate('view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-k.empty icon="catalog" :title="translate('no_contracts_yet')" :text="translate('no_contracts_yet_help')">
                            <x-slot:action><a class="btn btn-primary btn-sm" href="{{ route('admin.operational-costs.contracts.create') }}">{{ translate('new_contract') }}</a></x-slot:action>
                        </x-k.empty></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($contracts->hasPages())
                <x-slot:footer>{{ $contracts->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection
