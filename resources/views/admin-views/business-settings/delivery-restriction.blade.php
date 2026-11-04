@extends('layouts.admin.app')

@section('title', translate('Delivery_Zone'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('Business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-column gap-20">
                            <div class="d-flex justify-content-between align-items-start gap-3 user-select-none">
                                <div>
                                    <h2 class="fw-medium mb-1">
                                        {{ translate('delivery_Available_Country') }}
                                    </h2>
                                    <p class="mb-0 fs-12">
                                        {{ translate('if_you_enable_this,_you_will_be_able_to_select_multiple_countries_for_product_delivery.') }}
                                    </p>
                                </div>
                                <form action="{{ route('admin.business-settings.delivery-zone.country-restriction-status-change') }}" method="post" id="delivery-available-country-status-form" class="">
                                    @csrf
                                    <label class="switcher mx-auto" for="delivery-available-country-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="delivery-available-country-status"
                                            {{ isset($countryRestrictionStatus?->value) && $countryRestrictionStatus?->value == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#delivery-available-country-status-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delivery-available-country-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delivery-available-country-off.png') }}"
                                            data-on-title = "{{ translate('want_to_Turn_ON_Delivery_Available_Country') . '?' }}"
                                            data-off-title = "{{ translate('want_to_Turn_OFF_Delivery_Available_Country') . '?' }}"
                                            data-on-message = "<p>{{ translate('if_enabled_the_admin_or_vendor_can_deliver_orders_to_the_selected_countries') }}</p>"
                                            data-off-message = "<p>{{ translate('if_disabled_there_will_be_no_delivery_restrictions_for_admin_or_vendors') }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            </div>
                            <div
                                class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-info text-warning"></i>
                                <span>
                                    <strong>{{ translate('warning') }}:</strong>
                                    {{ translate('if_a_country_name_is_not_entered,_it_will_not_appear_as_an_option_for_shipping_in_that_country.') }}
                                </span>
                            </div>
                            <div class="p-20 bg-section rounded">
                                <form action="{{ route('admin.business-settings.delivery-zone.add-delivery-country') }}"
                                    method="post">
                                    @csrf
                                    <div class="form-group">
                                        <label class="form-label">{{ translate('Country') }}</label>
                                        <select {{ isset($countryRestrictionStatus?->value) && $countryRestrictionStatus?->value != 1 ? "disabled" : ""}} class="custom-select multiple-select2" name="country_code[]"
                                            id="choice_attributes" multiple="multiple" data-placeholder="{{ translate('Select_Country') }}">
                                            <option></option>
                                            @foreach ($countries as $country)
                                                @if(!in_array($country['code'], $storedCountryCode))
                                                    <option value="{{ $country['code'] }}">
                                                        {{ $country['name'] }}
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="text-end mt-4">
                                        <button type="submit"
                                            class="btn btn-primary px-20 ">{{ translate('save') }}</button>
                                    </div>
                                </form>

                            </div>
                            <div class="card shadow-1">
                                <div class="card-body">
                                    <div class="d-flex flex-column gap-20">
                                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                                            <h4>{{ translate('Country_List') }}
                                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ count($storedCountries) }}</span>
                                            </h4>
                                            <form action="{{ route('admin.business-settings.delivery-zone.index') }}" method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                                @csrf
                                                <div class="input-group">
                                                    <input type="search" class="form-control" placeholder="{{ translate('Search_Country') }}" name="search_country" value="{{ requestString('search_country') }}">
                                                    <div class="input-group-append search-submit">
                                                        <button type="submit">
                                                            <i class="fi fi-rr-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="k-table-wrap">
                                            <table class="k-table">
                                                <thead>
                                                    <tr>
                                                        <th>{{ translate('sl') }}</th>
                                                        <th class="text-center">{{ translate('country_Name') }}</th>
                                                        <th class="text-center">{{ translate('action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($storedCountries as $key => $storedCountry)
                                                        <tr>
                                                            <td>{{ $storedCountries->firstItem() + $key }}</td>
                                                            @foreach($countries as $country)
                                                                @if($storedCountry->country_code == $country['code'])
                                                                    <td class="text-center">{{ $country['name'] }}</td>
                                                                @endif
                                                            @endforeach
                                                            <td>
                                                                <div class="d-flex justify-content-center gap-2">
                                                                    <a class="btn btn-outline-danger icon-btn delete-data"
                                                                        href="javascript:"
                                                                        title="{{ translate('delete') }}"
                                                                        data-id="country-{{ $storedCountry->id }}">
                                                                        <i class="fi fi-rr-trash"></i>
                                                                    </a>
                                                                    <form action="{{ route('admin.business-settings.delivery-zone.delivery-country-delete', ['id' => $storedCountry->id]) }}" method="post" id="country-{{ $storedCountry->id }}">
                                                                        @csrf @method('delete')
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="k-table-wrap">
                                            <div class="d-flex justify-content-lg-end">
                                                {{ $storedCountries->links() }}
                                            </div>
                                        </div>
                                        @if (count($storedCountries) == 0)
                                            @include(
                                                'layouts.admin.partials._empty-state',
                                                ['text' => 'no_country_found'],
                                                ['image' => 'default']
                                            )
                                        @endif
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                {{-- Whether customers are ASKED for a postcode at all. Separate from the delivery
                     restriction below, which limits where you deliver. --}}
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3 user-select-none">
                            <div>
                                <h2 class="fw-medium mb-1">{{ translate('ask_customers_for_a_zip_code') }}</h2>
                                <p class="mb-0 fs-12">
                                    {{ translate('if_disabled_the_zip_field_is_hidden_everywhere_and_never_required') }}
                                </p>
                            </div>
                            <form action="{{ route('admin.business-settings.delivery-zone.zipcode-field-status-change') }}"
                                  method="post" id="zip-code-field-status-form" class="no-reload-form">
                                @csrf
                                <label class="switcher mx-auto" for="zip-code-field-status">
                                    <input class="switcher_input custom-modal-plugin" type="checkbox" value="1" name="status"
                                           id="zip-code-field-status"
                                           {{ $zipCodeFieldStatus ? 'checked' : '' }}
                                           data-modal-type="input-change-form"
                                           data-modal-form="#zip-code-field-status-form"
                                           data-on-title="{{ translate('want_to_Turn_ON_Zip_Code_Field') }}"
                                           data-off-title="{{ translate('want_to_Turn_OFF_Zip_Code_Field') }}"
                                           data-on-message="<p>{{ translate('if_disabled_the_zip_field_is_hidden_everywhere_and_never_required') }}</p>"
                                           data-off-message="<p>{{ translate('if_disabled_the_zip_field_is_hidden_everywhere_and_never_required') }}</p>"
                                           data-on-button-text="{{ translate('turn_on') }}"
                                           data-off-button-text="{{ translate('turn_off') }}">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-column gap-20">
                            <div class="d-flex justify-content-between align-items-start gap-3 user-select-none">
                                <div>
                                    <h2 class="fw-medium mb-1">
                                        {{ translate('delivery_Available_ZIP_Code_Area') }}
                                    </h2>
                                    <p class="mb-0 fs-12">
                                        {{ translate('if_enabled_the_zip_code_areas_will_be_available_for_delivery.') }}
                                    </p>
                                </div>
                                <form
                                    action="{{ route('admin.business-settings.delivery-zone.zipcode-restriction-status-change') }}"
                                    method="post" id="delivery-available-zip-code-status-form" class="no-reload-form">
                                    @csrf
                                    <label class="switcher mx-auto" for="delivery-available-zip-code-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="delivery-available-zip-code-status"
                                            {{ isset($zipCodeAreaRestrictionStatus) && $zipCodeAreaRestrictionStatus->value == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#delivery-available-zip-code-status-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/zip-code-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/zip-code-off.png') }}"
                                            data-on-title = "{{ translate('want_to_Turn_ON_Delivery_Available_Zip_Code_Area') . '?' }}"
                                            data-off-title = "{{ translate('want_to_Turn_OFF_Delivery_Available_Zip_Code_Area') . '?' }}"
                                            data-on-message = "<p>{{ translate('if_enabled_deliveries_will_be_available_only_in_the_added_zip_code_areas') }}</p>"
                                            data-off-message = "<p>{{ translate('if_disabled_there_will_be_no_delivery_restrictions_based_on_zip_code_areas') }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </form>
                            </div>
                            <div
                                class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-info text-warning"></i>
                                <span>
                                    {{ translate('if_you_do_not_enter_a_specific') }}
                                    <span class="fw-semibold">{{ translate('ZIP_Code') }}</span> {{ translate('from_a_country') }},{{ translate('that_area_would_not_be_available_for_delivery.') }}
                                </span>
                            </div>
                            <div class="p-20 bg-section rounded">
                                <form action="{{ route('admin.business-settings.delivery-zone.add-zip-code') }}"
                                    method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                                    @csrf
                                    <div class="form-group">
                                        <label class="form-label">{{ translate('zip_code') }}</label>
                                        <input type="text" class="form-control bootstrap-tags-input" name="zipcode" placeholder="{{ translate('enter_zip_code') }}"   data-required-msg="{{ translate('zip_code_is_required') }}" required>

                                    </div>
                                    <div class="text-end mt-4">
                                        <button type="submit"
                                            class="btn btn-primary px-20 zip_code">{{ translate('save') }}</button>
                                    </div>
                                </form>

                            </div>
                            <div class="card shadow-1">
                                <div class="card-body">
                                    <div class="d-flex flex-column gap-20">
                                        <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                                            <h4>{{ translate('Zip_Code_List') }}
                                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ count($storedZip) }}</span>
                                            </h4>
                                            <form action="{{ route('admin.business-settings.delivery-zone.index') }}" method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                                @csrf
                                                <div class="input-group">
                                                    <input type="search" class="form-control" placeholder="{{ translate('Search_ZIP_Code') }}" name="search_zip_code" value="{{ requestString('search_zip_code') }}">
                                                    <div class="input-group-append search-submit">
                                                        <button type="submit">
                                                            <i class="fi fi-rr-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="k-table-wrap">
                                            <table class="k-table">
                                                <thead>
                                                    <tr>
                                                        <th>{{ translate('sl') }}</th>
                                                        <th class="text-center">{{ translate('zip_code') }}</th>
                                                        <th class="text-center">{{ translate('action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($storedZip as $key => $zip)
                                                        <tr>
                                                            <td>{{ $storedZip->firstItem() + $key }}</td>
                                                            <td class="text-center">{{ $zip->zipcode }}</td>
                                                            <td>
                                                                <div class="d-flex justify-content-center gap-2">
                                                                    <a class="btn btn-outline-danger icon-btn delete-data"
                                                                        href="javascript:"
                                                                        title="{{ translate('delete') }}"
                                                                        data-id="zip-{{ $zip->id }}">
                                                                        <i class="fi fi-rr-trash"></i>
                                                                    </a>
                                                                    <form
                                                                        action="{{ route('admin.business-settings.delivery-zone.zip-code-delete', ['id' => $zip->id]) }}"
                                                                        method="post" id="zip-{{ $zip->id }}">
                                                                        @csrf @method('delete')
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="k-table-wrap">
                                            <div class="d-flex justify-content-lg-end">
                                                {{ $storedZip->links() }}
                                            </div>
                                        </div>
                                        @if (count($storedZip) == 0)
                                            @include(
                                                'layouts.admin.partials._empty-state',
                                                ['text' => 'no_zip_code_found'],
                                                ['image' => 'default']
                                            )
                                        @endif
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._delivery-restriction")
@endsection
