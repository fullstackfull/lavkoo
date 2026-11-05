@extends('layouts.admin.app')

@section('title', translate('torod_product_matching'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('torod_product_matching')" :subtitle="translate('torod_product_matching_subtitle')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.fulfillment-providers.show', 'torod-point') }}">{{ translate('settings') }}</a>
                <form method="POST" action="{{ route('admin.fulfillment-providers.sync', ['torod-point', 'products']) }}" class="d-inline">@csrf
                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('sync_warehouse_products_now') }}</button></form>
            </x-slot:actions>
        </x-k.page-header>

        <div class="alert alert-warning fs-12">{{ translate('torod_matching_rules') }}</div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach (['unmatched', 'matched', 'all'] as $tab)
                <a class="btn btn-sm {{ $status === $tab ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.fulfillment-providers.torod.matching', ['status' => $tab]) }}">{{ translate('matching_tab_' . $tab) }}</a>
            @endforeach
            <span class="ms-auto fs-12 text-muted align-self-center">{{ translate('warehouse_products_staged') }}: {{ $counts['staged'] }} · {{ translate('mapped') }}: {{ $counts['mapped'] }}</span>
        </div>

        <x-k.card :title="translate('warehouse_products')" :padded="false" class="mb-3">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr><th>{{ translate('warehouse_product') }}</th><th>{{ translate('stock_reported') }}</th><th>{{ translate('suggestions') }}</th><th>{{ translate('approve_match') }}</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $row)
                        @php($item = $row['item'])
                        <tr>
                            <td>{{ $item->name }}<div class="fs-10 text-muted">{{ translate('warehouse_id') }} {{ $item->external_id }} · {{ $item->sku }} @if ($item->barcode)· {{ $item->barcode }}@endif</div></td>
                            <td>{{ $item->stock ?? '—' }}<div class="fs-10 text-muted">{{ translate('stock_unconfirmed_field') }}</div></td>
                            <td>
                                @forelse ($row['suggestions'] as $suggestion)
                                    <div class="fs-12">#{{ $suggestion['product_id'] }} {{ $suggestion['name'] }}
                                        @foreach ($suggestion['reasons'] as $reason)<x-k.badge tone="info">{{ translate('match_reason_' . $reason) }}</x-k.badge>@endforeach</div>
                                @empty
                                    <span class="text-muted fs-12">{{ $row['matched'] ? translate('already_matched') : translate('no_suggestions') }}</span>
                                @endforelse
                            </td>
                            <td>
                                @unless ($row['matched'])
                                    <form method="POST" action="{{ route('admin.fulfillment-providers.torod.approve') }}" class="d-flex flex-column gap-1">@csrf
                                        <input type="hidden" name="catalog_item_id" value="{{ $item->id }}">
                                        <input class="form-control form-control-sm" type="number" min="1" name="product_id" placeholder="{{ translate('store_product_id') }}"
                                               value="{{ $row['suggestions'][0]['product_id'] ?? '' }}" required>
                                        <input class="form-control form-control-sm" type="text" name="variant_key" placeholder="{{ translate('variant_key_optional') }}">
                                        <label class="fs-10 mb-0"><input type="checkbox" name="assign_to_provider" value="1"> {{ translate('also_assign_product_to_torod_point') }}</label>
                                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('approve_match') }}</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-k.empty icon="box" :title="translate('no_warehouse_products_staged')" :text="translate('no_warehouse_products_staged_help')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($paginator->hasPages())
                <x-slot:footer>{{ $paginator->links() }}</x-slot:footer>
            @endif
        </x-k.card>

        <x-k.card :title="translate('approved_mappings')" :padded="false" class="mb-3">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead><tr><th>{{ translate('store_product') }}</th><th>{{ translate('warehouse_id') }}</th><th>{{ translate('owner') }}</th><th>{{ translate('stock') }}</th><th>{{ translate('status') }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($mapped as $mapping)
                        @php($product = $products[$mapping->product_id] ?? null)
                        <tr>
                            <td>{{ $product->name ?? '#' . $mapping->product_id }}<div class="fs-10 text-muted">{{ $mapping->variant_key !== '' ? $mapping->variant_key . ' · ' : '' }}{{ $mapping->external_sku }}</div></td>
                            <td>{{ $mapping->external_id }}</td>
                            <td>{{ ($product->fulfillment_provider ?? null) === 'torod_point' ? translate('torod_point') : translate('not_assigned_to_torod_point') }}</td>
                            <td>
                                {{ $mapping->provider_stock ?? '—' }}<div class="fs-10 text-muted">{{ translate('stock_source_' . $mapping->stock_source->value) }}</div>
                                <form method="POST" action="{{ route('admin.fulfillment-providers.torod.stock') }}" class="d-flex gap-1 mt-1">@csrf
                                    <input type="hidden" name="mapping_id" value="{{ $mapping->id }}">
                                    <input class="form-control form-control-sm" style="width:90px" type="number" min="0" name="quantity" placeholder="{{ translate('manual_stock') }}">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('save') }}</button></form>
                            </td>
                            <td><x-k.badge :tone="$mapping->status->value === 'approved' ? 'success' : 'neutral'">{{ translate('mapping_status_' . $mapping->status->value) }}</x-k.badge></td>
                            <td>@if ($mapping->status->value === 'approved')
                                    <form method="POST" action="{{ route('admin.fulfillment-providers.torod.retire', $mapping->id) }}" onsubmit="return confirm(@js(translate('are_you_sure')))">@csrf
                                        <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('retire') }}</button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">{{ translate('no_mappings_yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-k.card>

        <div class="row g-3">
            <div class="col-lg-6">
                <x-k.card :title="translate('city_mapping')">
                    <p class="fs-12 text-muted">{{ translate('city_mapping_help') }}</p>
                    @if ($unmappedCities !== [])
                        <div class="mb-2 fs-12"><strong>{{ translate('cities_waiting_for_a_match') }}:</strong>
                            @foreach ($unmappedCities as $city)<x-k.badge tone="warning">{{ $city }}</x-k.badge>@endforeach</div>
                    @endif
                    <form method="POST" action="{{ route('admin.fulfillment-providers.torod.alias') }}" class="row g-2">@csrf
                        <div class="col-md-5"><input class="form-control form-control-sm" name="alias" placeholder="{{ translate('city_as_customers_write_it') }}" required></div>
                        <div class="col-md-4"><input class="form-control form-control-sm" name="external_city_id" placeholder="{{ translate('torod_city_id') }}" required list="city-ids"></div>
                        <div class="col-md-3"><button class="btn btn-primary btn-sm w-100" type="submit">{{ translate('save') }}</button></div>
                    </form>
                    <datalist id="city-ids"></datalist>
                    <input class="form-control form-control-sm mt-2" id="city-search" placeholder="{{ translate('search_torod_cities') }}" data-url="{{ route('admin.fulfillment-providers.torod.cities.search') }}">
                    <div class="fs-12 mt-1" id="city-results"></div>
                </x-k.card>
            </div>
            <div class="col-lg-6">
                <x-k.card :title="translate('push_products_to_warehouse')">
                    <p class="fs-12 text-muted">{{ translate('push_products_help') }}</p>
                    <form method="POST" action="{{ route('admin.fulfillment-providers.torod.push') }}" class="d-flex gap-2">@csrf
                        <input class="form-control form-control-sm" name="product_ids[]" type="number" min="1" placeholder="{{ translate('store_product_id') }}" required>
                        <button class="btn btn-outline-primary btn-sm" type="submit" @disabled(!$config->bool('push_products_enabled'))>{{ translate('push') }}</button>
                    </form>
                    @unless ($config->bool('push_products_enabled'))<div class="fs-12 text-muted mt-1">{{ translate('push_products_switched_off') }}</div>@endunless
                </x-k.card>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        (function () {
            var input = document.getElementById('city-search');
            if (!input) { return; }
            var timer = null;
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetch(input.dataset.url + '?q=' + encodeURIComponent(input.value), {headers: {'Accept': 'application/json'}})
                        .then(function (response) { return response.json(); })
                        .then(function (cities) {
                            var box = document.getElementById('city-results');
                            box.textContent = cities.map(function (city) { return city.id + ' — ' + city.name; }).join('  |  ');
                        });
                }, 250);
            });
        })();
    </script>
@endpush
