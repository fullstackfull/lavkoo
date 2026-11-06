@extends('layouts.admin.app')

@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')

@section('title', translate('add_an_invoice'))

@section('content')
    @php
        $typedLines = array_values((array) old('items', []));
        $rowCount = max(count($typedLines), $blankLines);
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('add_an_invoice')" :subtitle="translate('add_an_invoice_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.invoices.index') }}">{{ translate('back') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">{{ translate('an_invoice_is_kept_as_received_note') }}</div>

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('admin.operational-costs.invoices.store') }}" autocomplete="off">
            @csrf
            <x-k.card :title="translate('the_invoice')" class="mb-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="kind">{{ translate('invoice_kind') }}</label>
                        <select class="form-select" id="kind" name="kind" required>
                            @foreach ($kinds as $kind)
                                <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ CostLabels::of('invoice_kind', $kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="party_type">{{ translate('kind_of_company') }}</label>
                        <select class="form-select" id="party_type" name="party_type" required>
                            <option value="">{{ translate('choose') }}</option>
                            @foreach ($partyTypes as $partyType)
                                <option value="{{ $partyType }}" @selected(old('party_type') === $partyType)>{{ CostLabels::of('party_type', $partyType) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="provider_code">{{ translate('company_code') }}</label>
                        <input class="form-control" id="provider_code" name="provider_code" value="{{ old('provider_code') }}" maxlength="64" required dir="ltr">
                        <div class="fs-12 text-muted">{{ translate('company_code_help') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="party_name">{{ translate('company_name') }}</label>
                        <input class="form-control" id="party_name" name="party_name" value="{{ old('party_name') }}" maxlength="160" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="invoice_number">{{ translate('invoice_number') }}</label>
                        <input class="form-control" id="invoice_number" name="invoice_number" value="{{ old('invoice_number') }}" maxlength="120" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="invoice_date">{{ translate('invoice_date') }}</label>
                        <input class="form-control" type="date" id="invoice_date" name="invoice_date" value="{{ old('invoice_date') }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="period_start">{{ translate('period_start') }}</label>
                        <input class="form-control" type="date" id="period_start" name="period_start" value="{{ old('period_start') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="period_end">{{ translate('period_end') }}</label>
                        <input class="form-control" type="date" id="period_end" name="period_end" value="{{ old('period_end') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="currency">{{ translate('currency') }}</label>
                        <input class="form-control" id="currency" name="currency" value="{{ old('currency') }}" maxlength="8" required dir="ltr">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="subtotal">{{ translate('subtotal') }}</label>
                        <input class="form-control" type="number" step="any" id="subtotal" name="subtotal" value="{{ old('subtotal') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="tax_total">{{ translate('tax_total') }}</label>
                        <input class="form-control" type="number" step="any" id="tax_total" name="tax_total" value="{{ old('tax_total') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="total">{{ translate('total') }}</label>
                        <input class="form-control" type="number" step="any" id="total" name="total" value="{{ old('total') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="inventory_location_id">{{ translate('location_the_goods_arrived_at') }}</label>
                        <select class="form-select" id="inventory_location_id" name="inventory_location_id">
                            <option value="">{{ translate('not_applicable') }}</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) old('inventory_location_id') === (int) $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                        <div class="fs-12 text-muted">{{ translate('a_purchase_invoice_needs_a_location') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="seller_id">{{ translate('seller_id_empty_for_the_platform') }}</label>
                        <input class="form-control" type="number" min="0" step="1" id="seller_id" name="seller_id" value="{{ old('seller_id') }}">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="notes">{{ translate('notes') }}</label>
                        <input class="form-control" id="notes" name="notes" value="{{ old('notes') }}" maxlength="2000">
                    </div>
                </div>
            </x-k.card>

            <x-k.card :title="translate('invoice_lines')" :padded="false" class="mb-3">
                <div class="k-table-wrap table-responsive">
                    <table class="k-table" data-repeat="items">
                        <thead><tr>
                            <th>{{ translate('description') }}</th><th>{{ translate('cost_component') }}</th><th>{{ translate('amount') }}</th><th>{{ translate('quantity') }}</th>
                            <th>{{ translate('line_reference') }}</th><th>{{ translate('service_date') }}</th><th>{{ translate('product_id') }}</th><th>{{ translate('variant_key') }}</th>
                        </tr></thead>
                        <tbody>
                        @for ($index = 0; $index < $rowCount; $index++)
                            @php $line = $typedLines[$index] ?? []; @endphp
                            <tr>
                                <td><input class="form-control form-control-sm" name="items[{{ $index }}][description]" maxlength="500" value="{{ $line['description'] ?? '' }}" aria-label="{{ translate('description') }}"></td>
                                <td>
                                    <select class="form-select form-select-sm" name="items[{{ $index }}][cost_component]" aria-label="{{ translate('cost_component') }}">
                                        <option value="">{{ translate('choose') }}</option>
                                        @foreach ($components as $costComponent)
                                            <option value="{{ $costComponent->value }}" @selected(($line['cost_component'] ?? '') === $costComponent->value)>{{ translate($costComponent->labelKey()) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input class="form-control form-control-sm" type="number" step="any" name="items[{{ $index }}][amount]" value="{{ $line['amount'] ?? '' }}" aria-label="{{ translate('amount') }}"></td>
                                <td><input class="form-control form-control-sm" type="number" min="0" step="any" name="items[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" aria-label="{{ translate('quantity') }}"></td>
                                <td><input class="form-control form-control-sm" name="items[{{ $index }}][external_reference]" maxlength="191" value="{{ $line['external_reference'] ?? '' }}" aria-label="{{ translate('line_reference') }}" dir="ltr"></td>
                                <td><input class="form-control form-control-sm" type="date" name="items[{{ $index }}][service_date]" value="{{ $line['service_date'] ?? '' }}" aria-label="{{ translate('service_date') }}"></td>
                                <td><input class="form-control form-control-sm" type="number" min="1" step="1" name="items[{{ $index }}][product_id]" value="{{ $line['product_id'] ?? '' }}" aria-label="{{ translate('product_id') }}"></td>
                                <td><input class="form-control form-control-sm" name="items[{{ $index }}][variant_key]" maxlength="191" value="{{ $line['variant_key'] ?? '' }}" aria-label="{{ translate('variant_key') }}"></td>
                            </tr>
                        @endfor
                        </tbody>
                    </table>
                </div>
                <x-slot:footer>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-add-row="items">{{ translate('add_a_row') }}</button>
                    <span class="fs-12 text-muted ms-2">{{ translate('a_purchase_line_is_goods_with_a_quantity_and_a_product_id') }}</span>
                </x-slot:footer>
            </x-k.card>

            <x-k.card :title="translate('paste_lines_from_a_spreadsheet')" class="mb-3">
                <label class="form-label fs-12" for="pasted_lines">{{ translate('one_line_per_item') }}: <code dir="ltr">reference;date;component;amount;currency;quantity</code></label>
                <textarea class="form-control" id="pasted_lines" name="pasted_lines" rows="6" dir="ltr" spellcheck="false">{{ old('pasted_lines') }}</textarea>
                <div class="fs-12 text-muted mt-1">{{ translate('pasted_lines_help') }}</div>
            </x-k.card>

            <button class="btn btn-primary" type="submit">{{ translate('save_invoice') }}</button>
            <a class="btn btn-outline-secondary" href="{{ route('admin.operational-costs.invoices.index') }}">{{ translate('cancel') }}</a>
        </form>
    </div>

    @include('admin-views.operational-costs.partials._add-row-script')
@endsection
