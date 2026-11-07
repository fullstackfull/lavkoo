@extends('layouts.admin.app')

@use('App\Services\Address\AddressCatalogEditor')

@section('title', translate('address_cities'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" :subtitle="translate('address_cities_subtitle')">
            <x-slot:actions>
                <a class="btn btn-primary btn-sm" href="{{ route('admin.address-system.cities.create') }}">{{ translate('address_add_city') }}</a>
            </x-slot:actions>
        </x-k.page-header>
        @include('admin-views.address.partials._tabs', ['active' => 'cities'])

        <form method="GET" action="{{ route('admin.address-system.cities.index') }}" class="row g-2 align-items-end mb-3">
            <div class="col-md-5">
                <label class="form-label fs-12" for="city-search">{{ translate('search') }}</label>
                <input class="form-control form-control-sm" id="city-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12" for="city-region">{{ translate('address_region') }}</label>
                <select class="form-select form-select-sm" id="city-region" name="region_id">
                    <option value="">{{ translate('address_all_regions') }}</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region->id }}" @selected((int) ($filters['region_id'] ?? 0) === $region->id)>{{ $region->name_ar }} / {{ $region->name_en }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fs-12" for="city-status">{{ translate('status') }}</label>
                <select class="form-select form-select-sm" id="city-status" name="status">
                    <option value="">{{ translate('address_any_status') }}</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ translate('active') }}</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ translate('inactive') }}</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary btn-sm" type="submit">{{ translate('filter') }}</button>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.address-system.cities.index') }}">{{ translate('reset') }}</a>
            </div>
        </form>

        <x-k.card :padded="false">
            @if ($cities->isEmpty())
                <x-k.empty icon="search" :title="translate('address_no_cities_found')" :text="translate('address_no_cities_match_help')" />
            @else
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ translate('address_city') }}</th>
                            <th>{{ translate('address_region') }}</th>
                            <th>{{ translate('address_rank') }}</th>
                            <th>{{ translate('address_coordinates') }}</th>
                            <th>{{ translate('source') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($cities as $city)
                            <tr>
                                <td>{{ $city->id }}</td>
                                <td>{{ $city->name_ar }}<div class="fs-10 text-muted" dir="ltr">{{ $city->name_en }}</div></td>
                                <td>
                                    {{ $city->region?->name_ar }}<div class="fs-10 text-muted" dir="ltr">{{ $city->region?->name_en }}</div>
                                    @if ($city->region && ! $city->region->is_active)<x-k.badge tone="warning">{{ translate('address_region_off') }}</x-k.badge>@endif
                                </td>
                                <td>{{ $city->sort_order }}</td>
                                <td dir="ltr">{{ $city->latitude !== null && $city->longitude !== null ? $city->latitude . ', ' . $city->longitude : '—' }}</td>
                                <td>
                                    <span dir="ltr">{{ $city->source === AddressCatalogEditor::ADMIN_SOURCE ? translate('address_source_admin') : ($city->source ?? '—') }}</span>
                                    <div class="fs-10 text-muted">{{ $city->source_updated_at?->toDateString() }}</div>
                                </td>
                                <td><x-k.badge :tone="$city->is_active ? 'success' : 'neutral'">{{ $city->is_active ? translate('active') : translate('inactive') }}</x-k.badge></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.address-system.cities.edit', $city->id) }}">{{ translate('edit') }}</a>
                                        <form method="POST" action="{{ route('admin.address-system.cities.status', $city->id) }}"
                                              @if ($city->is_active) onsubmit="return confirm(@js(translate('address_switch_off_confirm')))" @endif>@csrf
                                            <input type="hidden" name="active" value="{{ $city->is_active ? 0 : 1 }}">
                                            <button class="btn btn-sm {{ $city->is_active ? 'btn-outline-danger' : 'btn-outline-primary' }}" type="submit">{{ $city->is_active ? translate('address_switch_off') : translate('address_switch_on') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($cities->hasPages())
                    <x-slot:footer>{{ $cities->links() }}</x-slot:footer>
                @endif
            @endif
        </x-k.card>
        @if (filled($filters['q'] ?? null))
            <p class="fs-12 text-muted mt-2">{{ translate('address_search_limited') }}</p>
        @endif
    </div>
@endsection
