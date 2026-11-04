@extends('layouts.admin.app')

@section('title', translate('general_Settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <div class="card mb-3 mb-sm-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8 col-xl-9">
                        @if ($businessSetting['maintenance_mode'])
                            <h3 class="fs-18">{{ translate('Maintenance_Mode') }}</h3>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <p class="mb-0 fs-12">
                                    {{ translate('your_maintenance_mode_is_activated') }}

                                    @if ($selectedMaintenanceDuration['maintenance_duration'] != 'until_change')
                                        {{ translate('from ') }}<strong>{{ $maintenanceStartDate->format('m/d/Y, h:i A') }}</strong>
                                        to <strong>{{ $maintenanceEndDate->format('m/d/Y, h:i A') }}</strong>.
                                    @endif
                                </p>
                                <a class="btn btn-outline-primary icon-btn edit maintenance-mode-show" href="#">
                                    <i class="fi fi-sr-pencil"></i>
                                </a>
                            </div>
                        @else
                            <h3 class="fs-18">{{ translate('Maintenance_Mode') }}</h3>
                            <p class="mb-0 fs-12">
                                {{ translate('turn_on_the_maintenance_mode_will_temporarily_deactivate_your_selected_systems_as_of_your_chosen_date_and_time.') }}
                            </p>
                        @endif

                        @if ($businessSetting['maintenance_mode'] && count($maintenanceSystemSetup) > 0)
                                <?php
                                $businessMode = getWebConfig(name: 'business_mode');
                                $totalSystemInMaintenance = 0;
                                if (array_key_exists('user_app', $maintenanceSystemSetup) && $maintenanceSystemSetup['user_app']) {
                                    $totalSystemInMaintenance++;
                                }
                                if (array_key_exists('user_website', $maintenanceSystemSetup) && $maintenanceSystemSetup['user_website']) {
                                    $totalSystemInMaintenance++;
                                }
                                if ($businessMode == 'multi' && array_key_exists('vendor_app', $maintenanceSystemSetup) && $maintenanceSystemSetup['vendor_app']) {
                                    $totalSystemInMaintenance++;
                                }
                                if ($businessMode == 'multi' && array_key_exists('vendor_panel', $maintenanceSystemSetup) && $maintenanceSystemSetup['vendor_panel']) {
                                    $totalSystemInMaintenance++;
                                }
                                if (array_key_exists('deliveryman_app', $maintenanceSystemSetup) && $maintenanceSystemSetup['deliveryman_app']) {
                                    $totalSystemInMaintenance++;
                                }
                                ?>

                            <div class="d-flex flex-wrap gap-3 mt-3 align-items-center">
                                <h5 class="mb-0">
                                    {{ translate('maintenance_mode_is_activated_for_the_selected_systems') }}
                                </h5>
                                <ul class="selected-systems d-flex gap-4 flex-wrap bg-soft-dark px-lg-5 px-3 py-1 mb-0 rounded">
                                    @if (
                                        ($businessMode == 'multi' && $totalSystemInMaintenance == 5) ||
                                            ($businessMode == 'single' && $totalSystemInMaintenance == 3))
                                        <li>{{ translate('All_Systems') }}</li>
                                    @else
                                        @foreach ($maintenanceSystemSetup as $maintenanceSystemKey => $system)
                                            @if ($system)
                                                <li>{{ ucwords(str_replace('_', ' ', $maintenanceSystemKey)) }}</li>
                                            @endif
                                        @endforeach
                                    @endif
                                </ul>
                            </div>
                        @endif

                    </div>

                    <div class="col-md-4 col-xl-3">
                        <div class="mt-3 mt-md-0">
                            <label
                                class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                                <span class="fw-medium text-dark">{{ translate('Maintenance_Mode') }}</span>
                                <label class="switcher">
                                    @if(!$businessSetting['maintenance_mode'])
                                        <input type="checkbox"
                                               id="maintenanceModeSwitch"
                                               data-status="off"
                                               class="switcher_input maintenance-mode-show"
                                               data-warning="{{ translate('do_you_want_to_turn_off_the_maintenance_mode') }}?"
                                               data-route="{{ route('admin.business-settings.maintenance-mode') }}"
                                        >
                                    @else
                                        <input type="checkbox"
                                               data-status="on"
                                               class="switcher_input"
                                               data-warning="{{ translate('do_you_want_to_turn_off_the_maintenance_mode') }}?"
                                               data-route="{{ route('admin.business-settings.maintenance-mode') }}"
                                               id="maintenanceModeSwitch" checked>
                                    @endif

                                    <span class="switcher_control"></span>
                                </label>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form action="{{ route('admin.business-settings.web-config.update') }}" method="POST" enctype="multipart/form-data" class="get-checked-required-field form-advance-inputs-validation form-advance-file-validation form-advance-validation non-ajax-form-validate" novalidate>
            @csrf

            <div class="card mb-3 mb-sm-4">
                <div class="card-header">
                    <h3 class="fs-18">{{ translate('Basic_Information') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('here_you_setup_your_all_business_information') }}.
                    </p>
                </div>
                <div class="card-body">
                    <div class="row gy-3">
                        <div class="{{ getWebConfig('map_api_status') == 1 ? 'col-xxl-8' : 'col-xl-12' }}">
                            <div class="row g-4">
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Company_Name') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" name="company_name" class="form-control" placeholder="{{ translate('type_your_company_name') }}" value="{{ $businessSetting['company_name'] }}" required="" data-required-msg="{{ translate('company_name_is_required') }}">
                                    </div>
                                </div>
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Email') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input type="email" name="company_email" class="form-control" placeholder="{{ translate('Type_your_email') }}" value="{{ $businessSetting['company_email'] }}" required="" data-required-msg="{{ translate('company_email_is_required') }}">
                                    </div>
                                </div>
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Phone') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input class="form-control" type="tel" name="company_phone"
                                            value="{{ $businessSetting['company_phone'] }}"
                                            placeholder="{{ translate('01xxxxxxxx') }}">
                                    </div>
                                </div>

                                @php($countryCode = getWebConfig(name: 'country_code'))
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Country') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <select class="custom-select" id="country" name="country_code"
                                            data-placeholder="{{ translate('Select_from_dropdown') }}">
                                            <option></option>
                                            @foreach (COUNTRIES as $country)
                                                <option value="{{ $country['code'] }}"
                                                    {{ $countryCode ? ($countryCode == $country['code'] ? 'selected' : '') : '' }}>
                                                    {{ $country['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-6 col-md-6">
                                    @php($timeZone = getWebConfig(name: 'timezone'))
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Time_Zone') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <select class="custom-select" name="timezone"
                                                data-placeholder="{{ translate('Select_from_dropdown') }}">
                                            <option></option>
                                            @foreach (App\Enums\GlobalConstant::TIMEZONE_ARRAY as $timeZoneArray)
                                                <option value="{{ $timeZoneArray['value'] }}"
                                                    {{ $timeZone ? ($timeZone == $timeZoneArray['value'] ? 'selected' : '') : '' }}>
                                                    {{ $timeZoneArray['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label" for="pagination_limit">
                                            {{ translate('pagination') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                  aria-label="{{ translate('this_number_indicates_how_much_data_will_be_shown_in_the_list_or_table') }}"
                                                  data-bs-title="{{ translate('this_number_indicates_how_much_data_will_be_shown_in_the_list_or_table') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <input type="text" name="pagination_limit" class="form-control"
                                               placeholder="{{ '25' }}" id="pagination_limit" data-required-msg="{{ translate('pagination_limit_field_is_required') }}"
                                               value="{{ $businessSetting['pagination_limit'] }}" required step="1" min="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Address') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                aria-label="Enter your address" data-bs-title="{{ translate('enter_your_business_address,_or_simply_tap_the_map_to_pinpoint_your_location._the_address_field_will_automatically_update.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <textarea class="form-control" name="shop_address" id="shop-address" rows="1" data-required-msg="{{ translate('shop_address_is_required') }}"
                                            placeholder="{{ translate('Ex : House#38, Road#04, Demo City') }}" required>{{ $businessSetting['shop_address'] }}</textarea>
                                    </div>
                                </div>
                                {{-- Legal identifiers. Optional: each one appears in the storefront
                                     footer only when it is filled in, so a shop that has no VAT
                                     number simply does not show that badge. --}}
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label" for="company-registration-no">{{ translate('commercial_registration_number') }}</label>
                                        <input type="text" class="form-control" id="company-registration-no"
                                               name="company_registration_no" maxlength="40"
                                               value="{{ getWebConfig(name: 'company_registration_no') }}"
                                               placeholder="{{ translate('optional') }}">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label" for="company-vat-no">{{ translate('vat_number') }}</label>
                                        <input type="text" class="form-control" id="company-vat-no"
                                               name="company_vat_no" maxlength="40"
                                               value="{{ getWebConfig(name: 'company_vat_no') }}"
                                               placeholder="{{ translate('optional') }}">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label" for="company-platform-no">{{ translate('business_platform_number') }}</label>
                                        <input type="text" class="form-control" id="company-platform-no"
                                               name="company_platform_no" maxlength="40"
                                               value="{{ getWebConfig(name: 'company_platform_no') }}"
                                               placeholder="{{ translate('optional') }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-4 {{ getWebConfig('map_api_status') == 1 ? '' : 'd-none' }}">
                            <div class="form-group position-relative h-100">
                                <input id="map-pac-input" class="form-control max-w-180 mt-1 position-absolute"
                                    title="{{ translate('search_your_location_here') }}" type="text"
                                    placeholder="{{ translate('search_here') }}" />
                                <div class="rounded w-100 h-100 min-h-260" id="location-map-canvas"></div>

                                <div class="latitude-longitude-container">
                                    <span class="latitude-longitude-item">
                                        <span>{{ 'Lat' }} :</span>
                                         <span id="get-default-latitude" data-latitude="{{ $businessSetting['default_location']['lat'] ?? '-33.8688' }}">{{ $businessSetting['default_location']['lat'] ?? '-33.8688' }}</span>
                                    </span>
                                    <span class="latitude-longitude-item">
                                        <span>{{ 'Long' }} : </span>
                                        <span id="get-default-longitude" data-longitude="{{ $businessSetting['default_location']['lng'] ?? '151.2195' }}">{{ $businessSetting['default_location']['lng'] ?? '151.2195' }}</span>
                                    </span>
                                    <input type="hidden" id="latitude" name="latitude" value="{{ $businessSetting['default_location']['lat'] ?? '-33.8688' }}">
                                    <input type="hidden" id="longitude" name="longitude" value="{{ $businessSetting['default_location']['lng'] ?? '151.2195' }}">
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div
                                class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-lightbulb-on text-info"></i>
                                <span>{{ translate('for_the_address_setup_you_can_simply_drag_the_map_to_pick_for_the_perfect') }}
                                    <span class="fw-semibold">{{ translate('Lat') }}({{ translate('Latitude') }}) &
                                        {{ translate('Long') }}({{ translate('Longitude') }})
                                    </span> {{ translate('value') }}.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3 mb-sm-4">
                <div class="card-header">
                    <h3 class="fs-18">{{ translate('General_Setup') }}</h3>

                    <p class="mb-0 fs-12">
                        {{ translate('here_you_can_manage_currency_settings_to_match_with_your_business_criteria') }}
                    </p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-sm-20 gap-3">
                        <div class="card card-sm shadow-1">
                            <div class="card-header">
                                <h3>{{ translate('Currency_Setup') }}</h3>
                                <p class="mb-0 fs-12">
                                    {{ translate('here_you_can_manage_currency_settings_to_match_with_your_business_criteria') }}
                                </p>
                            </div>
                            <div class="card-body">
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    @if(!$businessSetting['gateway_currency_support'])
                                        <div class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
                                            <i class="fi fi-sr-triangle-warning text-danger"></i>
                                            <span>
                                                {{ translate('currently_no_payment_gateway_supported_for_' . $systemCurrency->name . ' currency.') }}
                                                {{ translate('select_at_least_one__gateway_that_support_' . $systemCurrency->name . '_to_digital_payment_work_properly.') }}
                                            </span>
                                        </div>
                                    @endif

                                    <div class="row g-4 mb-3">
                                        <div class="col-xl-4 col-md-6">
                                            <div class="form-group">
                                                <label class="form-label"
                                                    for="">{{ translate('Select_Currency') }}  <span class="text-danger">*</span></label>
                                                <select name="currency_id" class="custom-select"
                                                    data-placeholder="{{ translate('Select_from_dropdown') }}">
                                                    <option></option>
                                                    @foreach ($CurrencyList as $item)
                                                        <option value="{{ $item->id }}"
                                                            {{ $item->id == $businessSetting['system_default_currency'] ? 'selected' : '' }}>
                                                            {{ $item->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-xl-4 col-md-6">
                                            <div class="form-group">
                                                <label class="form-label" for="">
                                                    {{ translate('Currency_Position') }}
                                                    <span class="text-danger">*</span>
                                                </label>

                                                <div class="border rounded px-3 py-1">
                                                    <div class="row">
                                                        <div class="col-6">
                                                            <div class="form-check d-flex align-items-center gap-1">
                                                                <input class="form-check-input radio--input m-0" type="radio"
                                                                       name="currency_symbol_position" id="currency_position_left"
                                                                       value="left"
                                                                    {{ $businessSetting['currency_symbol_position'] == 'left' ? 'checked' : '' }}>

                                                                <label class="form-check-label" for="currency_position_left">
                                                                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode(type: 'default')) }})
                                                                    {{ translate('left') }}
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div class="form-check d-flex align-items-center gap-1">
                                                                <input class="form-check-input radio--input m-0" type="radio"
                                                                       name="currency_symbol_position" id="currency_position_right"
                                                                       value="right"
                                                                    {{ $businessSetting['currency_symbol_position'] == 'right' ? 'checked' : '' }}>
                                                                <label class="form-check-label" for="currency_position_right">
                                                                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode(type: 'default')) }})
                                                                    {{ translate('right') }}
                                                                </label>

                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                        <div class="col-xl-4 col-md-6">
                                            <div class="form-group">
                                                <label class="form-label"
                                                    for="">{{ translate('Digit_After_Decimal_Point') }} <span class="text-danger">*</span>
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                        data-bs-placement="right"
                                                        aria-label="{{ translate('Enter_digit_after_decimal_point') }}"
                                                        data-bs-title="{{ translate('Enter_digit_after_decimal_point') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <input type="number"
                                                    value="{{ $businessSetting['decimal_point_settings'] }}" required data-required-msg="{{ translate('decimal_point_settings_is_required') }}"
                                                    name="decimal_point_settings" class="form-control" min="0"
                                                    placeholder="{{ translate('ex: 2') }}">

                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-lightbulb-on text-info"></i>
                                        <span>
                                           {{ translate('you_can_also_set_up_your_default_and_multiple_currency_from') }}
                                            <a href="{{ route('admin.system-setup.currency.view') }}" target="_blank"
                                                class="text-decoration-underline fw-semibold">
                                                {{ translate('Currency_Setup') }}
                                            </a>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card card-sm shadow-1">
                            <div class="card-header">
                                <h3>{{ translate('Business_Model_Setup') }}</h3>
                                <p class="mb-0 fs-12">
                                    {{ translate('here_you_can_setup_which_type_of_business_model_you_want_to_sell_your_products_you_can_choose_only_one_option_at_a_time.') }}
                                </p>
                            </div>
                            <div class="card-body">
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    <div class="form-group mb-20">
                                        <label class="form-label mb-3"
                                            for="">{{ translate('Select_Business_Model') }} <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                aria-label="{{ translate('to_activate_the_preferred_business_model_for_the_system,_please_select_the_given_option_below') }}"
                                                data-bs-title="{{ translate('to_activate_the_preferred_business_model_for_the_system,_please_select_the_given_option_below') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <div class="bg-white p-3 rounded">
                                            <div class="row g-4">
                                                <div class="col-xl-6 col-md-6">
                                                    <div class="form-check d-flex gap-3">
                                                        <input class="form-check-input radio--input radio--input_lg"
                                                            type="radio" name="business_mode" id="business-mode-single-vendor"
                                                            value="single"
                                                            {{ $businessSetting['business_mode'] == 'single' ? 'checked' : '' }}>
                                                        <div class="flex-grow-1">
                                                            <label for="business-mode-single-vendor"
                                                                class="form-label text-dark fw-semibold mb-1">
                                                                {{ translate('Single_Vendor') }}
                                                            </label>
                                                            <p class="fs-12 mb-3">
                                                                {{ translate('sell_products_exclusively_from_your_in-house_shops_only') }}
                                                            </p>
                                                            <div
                                                                class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                                <i class="fi fi-sr-info text-warning fs-16"></i>
                                                                <span>

                                                                    {{ translate('you_can_change_setup_of_your_shop_from_here') }}
                                                                    <br>
                                                                    <a href="{{ route('admin.business-settings.inhouse-shop') }}" target="_blank" class="text-decoration-underline fw-semibold">
                                                                        {{ translate('In_house_Shop_Settings') }}
                                                                    </a>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-xl-6 col-md-6">
                                                    <div class="form-check d-flex gap-3">
                                                        <input class="form-check-input radio--input radio--input_lg"
                                                            type="radio" value="multi" name="business_mode"
                                                            id="business-mode-multi-vendor"
                                                            {{ $businessSetting['business_mode'] == 'multi' ? 'checked' : '' }}>
                                                        <div class="flex-grow-1">
                                                            <label for="business-mode-multi-vendor"
                                                                class="form-label text-dark fw-semibold mb-1">
                                                                {{ translate('Multi_Vendor') }}
                                                            </label>
                                                            <p class="fs-12 mb-3">
                                                                {{ translate('alongside_your_store_multiple_vendor_can_register_and_open_their_store') }}
                                                            </p>

                                                            <div
                                                                class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                                <i class="fi fi-sr-lightbulb-on text-info fs-16"></i>
                                                                <span>
                                                                    {{ translate('after_turn_on_you_can_setup_more_setting_for_other_vendors_from_vendors_settings') }}.
                                                                    <br>
                                                                    <a href="{{ route('admin.business-settings.vendor-settings.index') }}" target="_blank"
                                                                        class="text-decoration-underline fw-semibold">
                                                                        {{ translate('Vendor_Settings') }}
                                                                    </a>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group business-mode-multi-vendor-commission {{ $businessSetting['business_mode'] == 'single' ? 'd-none' : '' }}">
                                        <label class="form-label mb-3"
                                            for="">{{ translate('default_commission') }}
                                            <span class="text-danger">*</span>
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                aria-label="{{ translate('Enter_default_commission') }}"
                                                data-bs-title="{{ translate('Enter_default_commission') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        @php($commission=getWebConfig('sales_commission'))
                                        <input type="number" class="form-control" name="sales_commission" value="{{ $commission ?? 0 }}" min="0" max="100"
                                            placeholder="{{ translate('ex: 2') }}" required="" step="any" data-required-msg="{{ translate('sales_commission_is_required') }}">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card card-sm shadow-1">
                            <div class="card-header">
                                <h3>{{ translate('Payment_Options') }}</h3>
                                <p class="mb-0 fs-12">
                                    {{ translate('enable_preferred_payment_methods_to_make_payments_from_customer_app_and_website') }}
                                </p>
                            </div>
                            <div class="card-body">
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="row g-4">
                                                <div class="col-xl-4 col-md-6">
                                                    <div class="form-check d-flex gap-3">
                                                        <input class="form-check-input checkbox--input" type="checkbox" name="cash_on_delivery"
                                                            id="cash_on_delivery" value="1" {{ $cashOnDelivery['status'] == 1 ? 'checked' : '' }}>
                                                        <div class="flex-grow-1">
                                                            <label for="cash_on_delivery"
                                                                class="form-label text-dark fw-semibold mb-1 user-select-none">
                                                                {{ translate('cash_on_delivery') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                                {{ translate('enabling_cash_on_delivery') }} ({{ translate('COD') }}) {{ translate('will_make_it_available_as_a_payment_option_for_customers_during_the_checkout_process') }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-xl-4 col-md-6">
                                                    <div class="form-check d-flex gap-3">
                                                        <input class="form-check-input checkbox--input" type="checkbox" name="digital_payment"
                                                            id="digital_payment" value="1"  {{ $digitalPayment['status'] ? 'checked' : '' }}>
                                                        <div class="flex-grow-1">
                                                            <label for="digital_payment"
                                                                class="form-label text-dark fw-semibold mb-1 user-select-none d-flex gap-1 align-items-center">
                                                                {{ translate('digital_payment') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                                {{ translate('enabling_digital_payment_will_make_it_available_as_a_payment_option_for_customers_during_the_checkout_process') }}.
                                                                <a href="{{ route('admin.third-party.payment-method.index') }}" target="_blank"
                                                                    class="text-decoration-underline fw-semibold">{{ translate('Digital_payment_methods') }}</a>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-xl-4 col-md-6">
                                                    <div class="form-check d-flex gap-3">
                                                        <input class="form-check-input checkbox--input" type="checkbox" name="offline_payment"
                                                            id="offline_payment" value="1" {{ $offlinePayment['status'] == 1 ? 'checked' : '' }}>
                                                        <div class="flex-grow-1">
                                                            <label for="offline_payment"
                                                                class="form-label text-dark fw-semibold mb-1 user-select-none">
                                                                {{ translate('offline_payment') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                                {{ translate('enabling_offline_payment_will_make_it_available_as_a_payment_option_for_customers_during_the_checkout_process') }}.
                                                                <a href="{{ route('admin.third-party.offline-payment-method.index') }}" target="_blank"
                                                                    class="text-decoration-underline fw-semibold">{{ translate('Offline_payment_methods') }}</a>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card card-sm shadow-1">
                            <div class="card-header">
                                <h3>{{ translate('Copyright') }} & {{ translate('Cookies_Text') }}</h3>
                                <p class="mb-0 fs-12">
                                    {{ translate('add_the_necessary_texts_to_display_in_required_sections') }}
                                </p>
                            </div>
                            <div class="card-body">
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    <div class="row gy-3">
                                        <div class="col-xl-6 col-md-6">
                                            <div class="form-group">
                                                <label class="form-label"
                                                    for="company_copyright_text">{{ translate('Copyright_Text') }}
                                                    <span class="text-danger">*</span>
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                        data-bs-placement="right" data-bs-html="true"
                                                        aria-label="Enter copyright text"
                                                        data-bs-title="
                                                        <div class='text-start'>{{ translate('Write_the_statement_to_inform_that_this_is_protected_by_copyright_law') }}</div>
                                                        ">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <textarea class="form-control" name="company_copyright_text" rows="2" placeholder="{{ translate('Type_about_the_description') }}" data-maxlength="100" required data-required-msg="{{ translate('company_copyright_text_is_required') }}">{{ $businessSetting['company_copyright_text'] }}</textarea>
                                                <div class="d-flex justify-content-end">
                                                    <span class="text-body-light"></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-xl-6 col-md-6">
                                            <div class="form-group">
                                                <div class="float-end">
                                                    <label class="switcher" for="cookie-setting-status">
                                                        <input
                                                            class="switcher_input custom-modal-plugin"
                                                            type="checkbox" value="1" name="cookie_status"
                                                            id="cookie-setting-status"
                                                            {{ isset($cookieSetting) && $cookieSetting['status'] == 1 ? 'checked':'' }}
                                                            data-modal-type="input-change"
                                                            data-modal-form="#smtp-mail-config-form"
                                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/cookie-on.png') }}"
                                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/cookie-off.png') }}"
                                                            data-on-title="{{ translate('by_Turning_ON_Cookie_Settings')}}"
                                                            data-off-title="{{ translate('by_Turning_OFF_Cookie_Settings')}}"
                                                            data-on-message="<p>{{ translate('if_you_disable_it_customers_cannot_see_Cookie_Settings_in_frontend')}}</p>"
                                                            data-off-message="<p>{{ translate('if_you_enable_it_customers_will_see_Cookie_Settings_in_frontend')}}</p>">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </div>
                                                <label class="form-label"
                                                    for="company_copyright_text">{{ translate('Cookies_Text') }}
                                                    <span class="text-danger">*</span>
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                        data-bs-placement="right" data-bs-html="true"
                                                        aria-label="Enter copyright text"
                                                        data-bs-title="
                                                        <div class='text-start'>{{ translate('setup_the_content_that_you_want_to_display_to_the_customer_as_cookies_text_in_the_customer_app_and_the_website') }}</div>
                                                        ">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <textarea class="form-control" name="cookie_text" rows="2" placeholder="{{ translate('type_about_the_description') }}" data-maxlength="100">{{ isset($cookieSetting) ? $cookieSetting['cookie_text'] : ''}}</textarea>
                                                <div class="d-flex justify-content-end">
                                                    <span class="text-body-light">{{ '0/100' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>

        @include("admin-views.business-settings.partials._maintenance-mode-modal")

    </div>

    @include("layouts.admin.partials.offcanvas._general-setup")
@endsection

@push('script')
    @if(getWebConfig('map_api_status') == 1)
        <script src="https://maps.googleapis.com/maps/api/js?key={{ getWebConfig('map_api_key') }}&callback=googleMapInitialize&loading=async&libraries=places&v=3.56"
            defer>
        </script>
    @endif
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/maintenance-mode-setting.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/business-general-setting.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/general-page.js') }}"></script>
@endpush
