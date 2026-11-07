@extends('layouts.admin.app')

@section('title', translate('address_regions'))

@section('content')
    <div class="content container-fluid">
        <x-k.page-header :title="translate('address_system')" :subtitle="translate('address_regions_subtitle')" />
        @include('admin-views.address.partials._tabs', ['active' => 'regions'])

        <x-k.card :padded="false">
            <div class="k-table-wrap">
                <table class="k-table">
                    <thead>
                    <tr>
                        <th>{{ translate('code') }}</th>
                        <th>{{ translate('address_name_ar') }}</th>
                        <th>{{ translate('address_name_en') }}</th>
                        <th>{{ translate('address_cities') }}</th>
                        <th>{{ translate('status') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($regions as $region)
                        <tr>
                            <td><code dir="ltr">{{ $region->code }}</code></td>
                            <td>
                                <form id="region-{{ $region->id }}" method="POST" action="{{ route('admin.address-system.regions.update', $region->id) }}">@csrf</form>
                                <input class="form-control form-control-sm" form="region-{{ $region->id }}" name="name_ar" value="{{ $region->name_ar }}" maxlength="191" required aria-label="{{ translate('address_name_ar') }}">
                            </td>
                            <td>
                                <input class="form-control form-control-sm" dir="ltr" form="region-{{ $region->id }}" name="name_en" value="{{ $region->name_en }}" maxlength="191" required aria-label="{{ translate('address_name_en') }}">
                            </td>
                            <td>{{ $region->active_cities_count }} / {{ $region->cities_count }}</td>
                            <td><x-k.badge :tone="$region->is_active ? 'success' : 'neutral'">{{ $region->is_active ? translate('active') : translate('inactive') }}</x-k.badge></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <button class="btn btn-primary btn-sm" type="submit" form="region-{{ $region->id }}">{{ translate('save') }}</button>
                                    <form method="POST" action="{{ route('admin.address-system.regions.status', $region->id) }}"
                                          @if ($region->is_active) onsubmit="return confirm(@js(translate('address_switch_off_confirm')))" @endif>@csrf
                                        <input type="hidden" name="active" value="{{ $region->is_active ? 0 : 1 }}">
                                        <button class="btn btn-sm {{ $region->is_active ? 'btn-outline-danger' : 'btn-outline-primary' }}" type="submit">{{ $region->is_active ? translate('address_switch_off') : translate('address_switch_on') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-k.card>
        <p class="fs-12 text-muted mt-2">{{ translate('address_region_inactive_note') }}</p>
    </div>
@endsection
