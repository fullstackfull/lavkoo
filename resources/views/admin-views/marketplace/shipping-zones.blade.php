@extends('layouts.admin.app')

@section('title', translate('shipping_zones'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-shipping-fast"></i> {{ translate('shipping_zones') }}</h2>
            <p class="mb-0 fs-12">{{ translate('price_shipping_by_destination_and_weight_where_no_zone_matches_the_existing_flat_shipping_applies') }}.</p>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h5 class="mb-1">{{ translate('charge_zone_shipping_at_web_checkout') }}</h5>
                    <p class="mb-0 fs-12 text-muted">{{ translate('when_on_web_checkout_charges_the_zone_rate_for_the_destination_falling_back_to_the_chosen_method_where_no_zone_matches_off_by_default') }}.</p>
                </div>
                <form action="{{ route('admin.marketplace.shipping-zones.toggle') }}" method="post" class="d-flex align-items-center gap-2">
                    @csrf
                    <input type="hidden" name="status" value="{{ $zoneShippingEnabled ? 0 : 1 }}">
                    <span class="k-badge {{ $zoneShippingEnabled ? 'k-badge--success' : '' }}">
                        {{ $zoneShippingEnabled ? translate('enabled') : translate('disabled') }}
                    </span>
                    <button class="btn btn-sm {{ $zoneShippingEnabled ? 'btn-outline-danger' : 'btn-primary' }}">
                        {{ $zoneShippingEnabled ? translate('turn_off') : translate('turn_on') }}
                    </button>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_zone') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.shipping-zones.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-12"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="191"></div>
                            <div class="col-12"><label class="form-label fs-12">{{ translate('countries_comma_separated') }}</label>
                                <input type="text" name="countries" class="form-control form-control-sm" placeholder="Syria, Lebanon — {{ translate('blank_means_rest_of_world') }}"></div>
                            <div class="col-12"><label class="form-label fs-12">{{ translate('regions_comma_separated') }} <span class="text-muted">({{ translate('optional') }})</span></label>
                                <input type="text" name="regions" class="form-control form-control-sm" placeholder="Latakia, Tartus"></div>
                            <div class="col-4"><label class="form-label fs-12">{{ translate('base_cost') }} *</label>
                                <input type="number" step="0.01" name="base_cost" class="form-control form-control-sm" min="0" value="0" required></div>
                            <div class="col-4"><label class="form-label fs-12">{{ translate('per_kg') }}</label>
                                <input type="number" step="0.01" name="per_kg_cost" class="form-control form-control-sm" min="0" value="0"></div>
                            <div class="col-4"><label class="form-label fs-12">{{ translate('free_over') }}</label>
                                <input type="number" step="0.01" name="free_over" class="form-control form-control-sm" min="0" placeholder="—"></div>
                            <div class="col-6"><label class="form-label fs-12">{{ translate('priority') }}</label>
                                <input type="number" name="priority" class="form-control form-control-sm" min="0" value="10"></div>
                            <div class="col-6 d-flex align-items-end justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('save') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('rate_preview') }}</h5></div>
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <div class="col-6"><input type="text" name="country" value="{{ $preview['input']['country'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('country') }}"></div>
                            <div class="col-6"><input type="text" name="region" value="{{ $preview['input']['region'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('region') }}"></div>
                            <div class="col-6"><input type="number" step="0.01" name="weight" value="{{ $preview['input']['weight'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('weight') }} (kg)"></div>
                            <div class="col-6"><input type="number" step="0.01" name="order_value" value="{{ $preview['input']['order_value'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('order_value') }}"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-outline-primary btn-sm">{{ translate('preview') }}</button></div>
                        </form>
                        @if ($preview)
                            <hr class="my-2">
                            @if ($preview['result'])
                                <div class="fs-13">{{ translate('zone') }}: <strong>{{ $preview['result']['zone']->name }}</strong></div>
                                <div class="h4 mb-0">
                                    @if ($preview['result']['free'])
                                        <span class="text-success">{{ translate('free') }}</span>
                                    @else
                                        {{ number_format($preview['result']['cost'], 2) }}
                                    @endif
                                </div>
                            @else
                                <div class="fs-12 text-muted">{{ translate('no_zone_matches_this_destination_flat_shipping_would_apply') }}.</div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('all_zones') }}</h5></div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead><tr>
                                    <th>{{ translate('name') }}</th><th>{{ translate('countries') }}</th>
                                    <th class="text-end">{{ translate('base_cost') }}</th><th class="text-end">{{ translate('per_kg') }}</th>
                                    <th class="text-end">{{ translate('free_over') }}</th><th>{{ translate('status') }}</th><th></th>
                                </tr></thead>
                                <tbody>
                                @forelse ($zones as $z)
                                    <tr>
                                        <td>{{ $z->name }}
                                            @if(!empty($z->regions))<div class="fs-10 text-muted">{{ implode(', ', $z->regions) }}</div>@endif
                                            <span class="fs-10 text-muted">{{ translate('priority') }} {{ $z->priority }}</span>
                                        </td>
                                        <td class="fs-12">{{ !empty($z->countries) ? implode(', ', $z->countries) : translate('rest_of_world') }}</td>
                                        <td class="text-end">{{ number_format((float) $z->base_cost, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $z->per_kg_cost, 2) }}</td>
                                        <td class="text-end">{{ $z->free_over !== null ? number_format((float) $z->free_over, 2) : '—' }}</td>
                                        <td><span class="k-badge k-badge--{{ $z->status === 'active' ? 'success' : 'secondary' }}">{{ translate($z->status) }}</span></td>
                                        <td class="text-end">
                                            <form action="{{ route('admin.marketplace.shipping-zones.destroy', $z->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_shipping_zones_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
