@extends('layouts.admin.app')

@use('App\Models\InventoryCostLayer')
@use('App\Http\Controllers\Admin\OperationalCosts\CostFormat')
@use('App\Http\Controllers\Admin\OperationalCosts\CostLabels')
@use('App\Http\Controllers\Admin\OperationalCosts\CostTones')

@section('title', translate('product_costs'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('product_costs')" :subtitle="translate('product_costs_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.index') }}">{{ translate('operational_costs_overview') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-info fs-12" role="note">
            <p class="mb-1"><strong>{{ translate('a_purchase_is_stock_not_an_expense') }}</strong> {{ translate('a_purchase_is_stock_not_an_expense_help') }}</p>
            <p class="mb-0"><strong>{{ translate('moving_stock_is_a_transfer_not_a_sale') }}</strong> {{ translate('moving_stock_is_a_transfer_not_a_sale_help') }}</p>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="k-stats mb-3">
            <x-k.stat :label="translate('units_in_stock_at_cost')" :value="$stock['units']" :caption="translate('open_layers_of_the_list_below')" />
            <x-k.stat :label="translate('stock_value_at_cost')" :value="CostFormat::amount($stock['value'], $currency)" :caption="translate('landed_cost_included')" />
        </div>

        <x-k.card :title="translate('record_a_purchase_cost')" class="mb-3">
            <p class="fs-12 text-muted">{{ translate('record_a_purchase_cost_help') }}</p>
            <form method="POST" action="{{ route('admin.operational-costs.product-costs.store') }}" autocomplete="off" class="row g-3">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="location_id">{{ translate('location') }}</label>
                    <select class="form-select" id="location_id" name="location_id" required>
                        <option value="">{{ translate('choose') }}</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) old('location_id') === (int) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="product-search">{{ translate('find_a_product') }}</label>
                    <input class="form-control" type="search" id="product-search" data-product-search data-url="{{ route('admin.operational-costs.product-costs.product-search') }}" placeholder="{{ translate('name_code_or_barcode') }}">
                    <ul class="list-group mt-1" id="product-results" hidden></ul>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="product_id">{{ translate('product_id') }}</label>
                    <input class="form-control" type="number" min="1" step="1" id="product_id" name="product_id" value="{{ old('product_id') }}" required>
                    <div class="fs-12 text-muted" id="product-chosen" aria-live="polite"></div>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="variant_key">{{ translate('variant_key') }}</label>
                    <input class="form-control" id="variant_key" name="variant_key" maxlength="191" value="{{ old('variant_key') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="quantity">{{ translate('quantity') }}</label>
                    <input class="form-control" type="number" min="1" step="1" id="quantity" name="quantity" value="{{ old('quantity') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="unit_cost">{{ translate('unit_cost') }}</label>
                    <input class="form-control" type="number" min="0" step="any" id="unit_cost" name="unit_cost" value="{{ old('unit_cost') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="landed_unit_cost">{{ translate('landed_cost_per_unit') }}</label>
                    <input class="form-control" type="number" min="0" step="any" id="landed_unit_cost" name="landed_unit_cost" value="{{ old('landed_unit_cost') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="currency">{{ translate('currency') }}</label>
                    <input class="form-control" id="currency" name="currency" maxlength="8" value="{{ old('currency') }}" required list="currency-codes" dir="ltr">
                    <datalist id="currency-codes">
                        @foreach ($currencyCodes as $currencyCode)<option value="{{ $currencyCode }}"></option>@endforeach
                    </datalist>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="received_at">{{ translate('received_on') }}</label>
                    <input class="form-control" type="date" id="received_at" name="received_at" value="{{ old('received_at', now()->toDateString()) }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="source_type">{{ translate('source') }}</label>
                    <select class="form-select" id="source_type" name="source_type" required>
                        @foreach ($sourceTypes as $sourceType)
                            <option value="{{ $sourceType }}" @selected(old('source_type') === $sourceType)>{{ CostLabels::of('layer_source', $sourceType) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="reference">{{ translate('reference') }}</label>
                    <input class="form-control" id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" required>
                    <div class="fs-12 text-muted">{{ translate('purchase_reference_help') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="supplier_name">{{ translate('supplier') }}</label>
                    <input class="form-control" id="supplier_name" name="supplier_name" maxlength="160" value="{{ old('supplier_name') }}">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">{{ translate('record_purchase_cost') }}</button>
                </div>
            </form>
        </x-k.card>

        <x-k.card class="mb-3">
            <form method="GET" action="{{ route('admin.operational-costs.product-costs.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-q">{{ translate('search') }}</label>
                    <input class="form-control form-control-sm" id="filter-q" name="q" value="{{ $filters['q'] }}" placeholder="{{ translate('product_sku_or_reference') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12" for="filter-location">{{ translate('location') }}</label>
                    <select class="form-select form-select-sm" id="filter-location" name="location">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected($filters['location'] === (int) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    @include('admin-views.operational-costs.partials._seller-filter', ['sellers' => $sellers, 'selected' => $filters['seller']])
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-open" name="open" value="1" @checked($filters['open'])>
                        <label class="form-check-label fs-12" for="filter-open">{{ translate('open_layers_only') }}</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.operational-costs.product-costs.index') }}">{{ translate('reset') }}</a>
                </div>
            </form>
        </x-k.card>

        <x-k.card :title="translate('cost_layers')" :padded="false">
            <div class="k-table-wrap table-responsive">
                <table class="k-table">
                    <thead><tr>
                        <th>{{ translate('received') }}</th><th>{{ translate('product') }}</th><th>{{ translate('location') }}</th><th>{{ translate('source') }}</th>
                        <th class="k-table__num">{{ translate('quantity_remaining_of_received') }}</th><th class="k-table__num">{{ translate('unit_cost') }}</th>
                        <th>{{ translate('paid_in') }}</th><th>{{ translate('status') }}</th><th>{{ translate('books_of') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($layers as $layer)
                        <tr>
                            <td class="fs-12 k-num">{{ $layer->received_at?->format('Y-m-d') }}</td>
                            <td>
                                <a href="{{ route('admin.operational-costs.product-costs.show', $layer->id) }}">{{ $productNames[$layer->product_id] ?? '#' . $layer->product_id }}</a>
                                <div class="fs-10 text-muted">#{{ $layer->product_id }}@if ($layer->variant_key !== '') · {{ $layer->variant_key }}@endif @if ($layer->sku) · {{ $layer->sku }}@endif</div>
                            </td>
                            <td>{{ $locationNames[$layer->inventory_location_id] ?? '#' . $layer->inventory_location_id }}</td>
                            <td>
                                {{ CostLabels::of('layer_source', $layer->source_type) }}
                                <div class="fs-10 text-muted">{{ $layer->source_ref ?? '—' }}@if ($layer->supplier_name) · {{ $layer->supplier_name }}@endif</div>
                            </td>
                            <td class="k-table__num k-num">{{ $layer->quantity_remaining }} / {{ $layer->quantity_received }}</td>
                            <td class="k-table__num k-num">
                                {{ CostFormat::amount($layer->unit_cost, $layer->currency) }}
                                @if ((float) $layer->landed_unit_cost > 0)<div class="fs-10 text-muted">+ {{ CostFormat::amount($layer->landed_unit_cost, $layer->currency) }} {{ translate('landed') }}</div>@endif
                            </td>
                            <td class="fs-12 k-num">
                                @if ($layer->original_currency)
                                    {{ CostFormat::amount($layer->original_unit_cost, $layer->original_currency) }}
                                    <div class="fs-10 text-muted">{{ translate('rate') }} {{ CostFormat::figure($layer->fx_rate) }}</div>
                                @else
                                    <span class="text-muted">{{ translate('reporting_currency') }}</span>
                                @endif
                            </td>
                            <td><x-k.badge :tone="CostTones::layer($layer->status)">{{ CostLabels::of('layer_status', $layer->status) }}</x-k.badge></td>
                            <td class="fs-12">@include('admin-views.operational-costs.partials._seller', ['seller' => $layer->seller_id])</td>
                            <td><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.operational-costs.product-costs.show', $layer->id) }}">{{ translate('view') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-k.empty icon="catalog" :title="translate('no_cost_layers_yet')" :text="translate('no_cost_layers_yet_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($layers->hasPages())
                <x-slot:footer>{{ $layers->links() }}</x-slot:footer>
            @endif
        </x-k.card>
    </div>
@endsection

@push('script')
    <script>
        (function () {
            var input = document.querySelector('[data-product-search]');
            if (!input) { return; }
            var results = document.getElementById('product-results');
            var idField = document.getElementById('product_id');
            var chosen = document.getElementById('product-chosen');
            var timer = null;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                if (input.value.trim().length < 2) { results.hidden = true; return; }
                timer = setTimeout(function () {
                    fetch(input.dataset.url + '?q=' + encodeURIComponent(input.value.trim()), {headers: {'Accept': 'application/json'}})
                        .then(function (response) { return response.json(); })
                        .then(function (products) {
                            results.textContent = '';
                            products.forEach(function (product) {
                                var item = document.createElement('li');
                                var button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action fs-12';
                                button.textContent = '#' + product.id + ' ' + product.name + (product.code ? ' · ' + product.code : '');
                                button.addEventListener('click', function () {
                                    idField.value = product.id;
                                    chosen.textContent = product.name;
                                    results.hidden = true;
                                });
                                item.appendChild(button);
                                results.appendChild(item);
                            });
                            results.hidden = products.length === 0;
                        });
                }, 250);
            });
        })();
    </script>
@endpush
