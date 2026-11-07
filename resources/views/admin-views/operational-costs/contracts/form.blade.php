@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')

@section('title', $contract ? translate('edit_contract_draft') : translate('new_contract'))

@section('content')
    @php
        $action = $contract ? route('admin.operational-costs.contracts.update', $contract->id) : route('admin.operational-costs.contracts.store');
        $expected = (array) old('expected_components', $contract?->settings['expected_components'] ?? []);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="$contract ? translate('edit_contract_draft') : translate('new_contract')" :subtitle="translate('a_contract_starts_as_a_draft_with_no_price_in_it_note')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ $contract ? route('admin.operational-costs.contracts.show', $contract->id) : route('admin.operational-costs.contracts.index') }}">{{ translate('back') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @include('admin-views.operational-costs.partials._module-state')

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ $action }}" autocomplete="off">
            @csrf
            <x-k.card :title="translate('who_the_contract_is_with')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="party_type">{{ translate('kind_of_company') }}</label>
                        <select class="form-select" id="party_type" name="party_type" required>
                            <option value="">{{ translate('choose') }}</option>
                            @foreach ($partyTypes as $partyType)
                                <option value="{{ $partyType }}" @selected(old('party_type', $contract?->party_type) === $partyType)>{{ CostLabels::of('party_type', $partyType) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="provider_code">{{ translate('company_code') }}</label>
                        <input class="form-control" id="provider_code" name="provider_code" value="{{ old('provider_code', $contract?->provider_code) }}" maxlength="64" required list="provider-codes" dir="ltr">
                        <datalist id="provider-codes">
                            @foreach ($providerCodes as $providerCode)<option value="{{ $providerCode }}"></option>@endforeach
                        </datalist>
                        <div class="fs-12 text-muted">{{ translate('company_code_help') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="party_name">{{ translate('company_name') }}</label>
                        <input class="form-control" id="party_name" name="party_name" value="{{ old('party_name', $contract?->party_name) }}" maxlength="160" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reference">{{ translate('contract_reference') }}</label>
                        <input class="form-control" id="reference" name="reference" value="{{ old('reference', $contract?->reference) }}" maxlength="120">
                    </div>
                    @unless ($contract)
                        <div class="col-md-4">
                            <label class="form-label" for="seller_id">{{ translate('seller_id_empty_for_the_platform') }}</label>
                            <input class="form-control" type="number" min="0" step="1" id="seller_id" name="seller_id" value="{{ old('seller_id') }}">
                            <div class="fs-12 text-muted">{{ translate('seller_id_help') }}</div>
                        </div>
                    @endunless
                </div>
            </x-k.card>

            <x-k.card :title="translate('currency_and_validity')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="currency">{{ translate('currency') }}</label>
                        <input class="form-control" id="currency" name="currency" value="{{ old('currency', $contract?->currency) }}" maxlength="8" required list="currency-codes" dir="ltr">
                        <datalist id="currency-codes">
                            @foreach ($currencyCodes as $currencyCode)<option value="{{ $currencyCode }}"></option>@endforeach
                        </datalist>
                        <div class="fs-12 text-muted">{{ translate('contract_currency_help') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="effective_from">{{ translate('valid_from') }}</label>
                        <input class="form-control" type="date" id="effective_from" name="effective_from" value="{{ old('effective_from', $contract?->effective_from->toDateString()) }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="effective_to">{{ translate('valid_to') }}</label>
                        <input class="form-control" type="date" id="effective_to" name="effective_to" value="{{ old('effective_to', $contract?->effective_to?->toDateString()) }}">
                        <div class="fs-12 text-muted">{{ translate('leave_empty_for_a_contract_with_no_end_date') }}</div>
                    </div>
                </div>
            </x-k.card>

            <x-k.card :title="translate('what_the_contract_is_expected_to_price')" class="mb-3">
                <p class="fs-12 text-muted">{{ translate('expected_services_help') }}</p>
                <div class="row g-2">
                    @foreach ($components as $costComponent)
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="expected-{{ $costComponent->value }}" name="expected_components[]" value="{{ $costComponent->value }}" @checked(in_array($costComponent->value, $expected, true))>
                                <label class="form-check-label fs-12" for="expected-{{ $costComponent->value }}">{{ translate($costComponent->labelKey()) }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
                <hr>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="tolerance_percent">{{ translate('reconciliation_tolerance_percent') }}</label>
                        <input class="form-control" type="number" min="0" max="999" step="any" id="tolerance_percent" name="tolerance_percent" value="{{ old('tolerance_percent', $contract?->settings['tolerance_percent'] ?? null) }}">
                        <div class="fs-12 text-muted">{{ translate('reconciliation_tolerance_help') }}: {{ config('operational_costs.reconciliation.tolerance_percent') }}%</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">{{ translate('notes') }}</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes', $contract?->notes) }}</textarea>
                    </div>
                </div>
            </x-k.card>

            <button class="btn btn-primary" type="submit">{{ $contract ? translate('save_draft') : translate('create_draft_contract') }}</button>
        </form>
    </div>
@endsection
