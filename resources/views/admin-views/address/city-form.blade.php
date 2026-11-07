@extends('layouts.admin.app')

@use('App\Services\Address\AddressCatalogEditor')

@section('title', $city->exists ? translate('address_edit_city') : translate('address_add_city'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="$city->exists ? translate('address_edit_city') : translate('address_add_city')" :subtitle="translate('address_city_form_help')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.address-system.cities.index') }}">{{ translate('cancel') }}</a>
            </x-slot:actions>
        </x-k.page-header>
        @include('admin-views.address.partials._tabs', ['active' => 'cities'])

        <x-k.card>
            <form method="POST" action="{{ $city->exists ? route('admin.address-system.cities.update', $city->id) : route('admin.address-system.cities.store') }}" class="row g-3">@csrf
                <div class="col-md-6">
                    <label class="form-label" for="city-region">{{ translate('address_region') }}</label>
                    <select class="form-select" id="city-region" name="region_id" required>
                        <option value="">{{ translate('address_choose_region') }}</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}" @selected((int) old('region_id', $city->region_id) === $region->id)>{{ $region->name_ar }} / {{ $region->name_en }}@if (! $region->is_active) ({{ translate('inactive') }})@endif</option>
                        @endforeach
                    </select>
                    @if ($city->exists)<div class="form-text">{{ translate('address_city_moved_note') }}</div>@endif
                </div>
                <div class="col-md-6"></div>
                <div class="col-md-6">
                    <label class="form-label" for="city-name-ar">{{ translate('address_name_ar') }}</label>
                    <input class="form-control" id="city-name-ar" name="name_ar" value="{{ old('name_ar', $city->name_ar) }}" maxlength="191" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="city-name-en">{{ translate('address_name_en') }}</label>
                    <input class="form-control" id="city-name-en" name="name_en" dir="ltr" value="{{ old('name_en', $city->name_en) }}" maxlength="191" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="city-latitude">{{ translate('address_latitude') }}</label>
                    <input class="form-control" id="city-latitude" name="latitude" dir="ltr" inputmode="decimal" value="{{ old('latitude', $city->latitude) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="city-longitude">{{ translate('address_longitude') }}</label>
                    <input class="form-control" id="city-longitude" name="longitude" dir="ltr" inputmode="decimal" value="{{ old('longitude', $city->longitude) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="city-rank">{{ translate('address_rank') }}</label>
                    <input class="form-control" id="city-rank" name="sort_order" dir="ltr" type="number" min="0" step="1" value="{{ old('sort_order', $city->sort_order ?? 0) }}">
                    <div class="form-text">{{ translate('address_rank_help') }}</div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">{{ translate('save') }}</button>
                </div>
            </form>

            @if ($city->exists)
                <hr>
                <div class="pair-list fs-12">
                    <div><span class="key">#</span><span>:</span><span class="value">{{ $city->id }}</span></div>
                    <div><span class="key">{{ translate('source') }}</span><span>:</span><span class="value" dir="ltr">{{ $city->source === AddressCatalogEditor::ADMIN_SOURCE ? translate('address_source_admin') : ($city->source ?? '—') }}</span></div>
                    <div><span class="key">{{ translate('address_source_date') }}</span><span>:</span><span class="value">{{ $city->source_updated_at?->toDateString() ?? '—' }}</span></div>
                    <div><span class="key">{{ translate('status') }}</span><span>:</span><span class="value"><x-k.badge :tone="$city->is_active ? 'success' : 'neutral'">{{ $city->is_active ? translate('active') : translate('inactive') }}</x-k.badge></span></div>
                </div>
                <form method="POST" action="{{ route('admin.address-system.cities.status', $city->id) }}" class="mt-3"
                      @if ($city->is_active) onsubmit="return confirm(@js(translate('address_switch_off_confirm')))" @endif>@csrf
                    <input type="hidden" name="active" value="{{ $city->is_active ? 0 : 1 }}">
                    <button class="btn btn-sm {{ $city->is_active ? 'btn-outline-danger' : 'btn-outline-primary' }}" type="submit">{{ $city->is_active ? translate('address_switch_off') : translate('address_switch_on') }}</button>
                </form>
            @endif
        </x-k.card>
    </div>
@endsection
