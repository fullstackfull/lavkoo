@extends('layouts.admin.app')

@section('title', translate('google_map_apis'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <form action="{{ config('app.mode') != 'demo' ? route('admin.third-party.map-api') : 'javascript:' }}"
            method="POST" enctype="multipart/form-data" id="google-map-api-status-form" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
            @csrf

            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-2">
                <i class="fi fi-sr-lightbulb-on text-info"></i>
                <span>
                    {{ translate('client_key_should_have_enable_map') }} <span class="fw-semibold">{{ translate('javascript_API') }}</span> {{ translate('and_you_can_restrict_it_with_http_refer') }}<span class="fw-semibold">{{ translate('Server_Key') }}</span> {{ translate('should_have_enable_place_api_key_and_you_can_restrict_it_with_ip_you_can_use_same_api_for_both_field_without_any_restrictions') }}.
                </span>
            </div>
            <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
                <i class="fi fi-sr-info text-warning"></i>
                <span>
                    {{ translate('without_configuring_this_section_map_functionality_will_not_work_properly_thusthe_whole_system_will_not_work_as_it_planned') }}
                </span>
            </div>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex gap-3 justify-content-between align-items-center flex-wrap mb-3">
                        <div>
                            <h2>{{ translate('Google_Map_API') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('fill-up_google_apis_credentials_to_setup_&_active_google_map_integration_to_your_system') }}.
                            </p>
                        </div>
                        <div class="d-flex gap-3 align-items-center">
                            @if(getWebConfig('map_api_status') == 1)
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mapViewModal">
                                <i class="fi fi-sr-region-pin"></i>
                                {{ translate('Test_Map_View') }}
                            </button>
                            @endif

                            <label class="switcher mx-auto" for="map-api-status-id">
                                <input
                                    class="switcher_input custom-modal-plugin"
                                    type="checkbox" value="1" name="status"
                                    id="map-api-status-id"
                                    {{ $mapAPIStatus && $mapAPIStatus['value'] == 1 ? 'checked':''}}
                                    data-modal-type="input-change-form"
                                    data-modal-form="#google-map-api-status-form"
                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delivery-available-country-on.png') }}"
                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/delivery-available-country-off.png') }}"
                                    data-on-title = "{{translate('want_to_turn_ON_map').'?'}}"
                                    data-off-title = "{{translate('want_to_turn_OFF_map').'?'}}"
                                    data-on-message = "<p>{{translate('if_enabled,map_will_be_available_in_the_system')}}</p>"
                                    data-off-message = "<p>{{translate('if_enabled,map_will_be_hidden_from_the_system')}}</p>"
                                    data-on-button-text="{{ translate('turn_on') }}"
                                    data-off-button-text="{{ translate('turn_off') }}">
                                <span class="switcher_control"></span>
                            </label>
                        </div>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{translate('map_api_key').'('.translate('client').')'}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your site key" data-bs-title="Enter your site key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" placeholder="{{translate('map_api_key'.'('.translate('client').')')}}"
                                       class="form-control" name="map_api_key"
                                           data-required-msg="{{translate('map_api_key_field_is_required')}}"
                                       value="{{ showDemoModeInputValue(value: $mapAPIKey) }}" required>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{translate('map_api_key')}} ({{translate('server')}})
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="Enter your secret key" data-bs-title="Enter your secret key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" placeholder="{{translate('map_api_key')}} ({{translate('server')}})"
                                        class="form-control" name="map_api_key_server"
                                           data-required-msg="{{translate('map_api_key_field_is_required')}}"
                                        value="{{ showDemoModeInputValue(value: $mapAPIKeyServer) }}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="{{ getDemoModeFormButton(type: 'button') }}"
                            class="btn btn-primary px-3 px-sm-4 {{ getDemoModeFormButton(type: 'class') }}">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>

        </form>
    </div>

    @if(getWebConfig('map_api_status') == 1)
        <div class="modal fade" id="mapViewModal" tabindex="-1" aria-labelledby="mapViewModal" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 px-4 py-3 d-flex justify-content-between">
                    <h2 class="m-0">{{ translate('Map_View') }}</h2>
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-4 py-0 mb-4 overflow-y-scroll">
                    <div class="form-group position-relative h-100 aspect-ratio-4-2">
                        <input id="map-pac-input" class="form-control max-w-180 mt-1 position-absolute"
                               title="{{ translate('search_your_location_here') }}" type="text"
                               placeholder="{{ translate('search_here') }}" />
                        <div class="rounded w-100 h-100 min-h-260" id="location-map-canvas"></div>

                        <div class="latitude-longitude-container">
                            <span class="latitude-longitude-item">
                                {{ 'Lat' }} : <span id="get-default-latitude" data-latitude="{{ $default_location['lat'] ?? '-33.8688' }}">{{ $default_location['lat'] ?? '-33.8688' }}</span>
                            </span>
                            <span class="latitude-longitude-item">
                                {{ 'Long' }} : <span id="get-default-longitude" data-longitude="{{ $default_location['lng'] ?? '151.2195' }}">{{ $default_location['lng'] ?? '151.2195' }}</span>
                            </span>
                            <input type="hidden" id="latitude" name="latitude" value="{{ $default_location['lat'] ?? '-33.8688' }}">
                            <input type="hidden" id="longitude" name="longitude" value="{{ $default_location['lng'] ?? '151.2195' }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    @include("layouts.admin.partials.offcanvas._google-map-api")
@endsection

@push('script')
    @if(getWebConfig('map_api_status') == 1)
        <script src="https://maps.googleapis.com/maps/api/js?key={{ getWebConfig('map_api_key') }}&callback=googleMapInitialize&loading=async&libraries=places&v=3.56"
                defer>
        </script>
    @endif
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/general-page.js') }}"></script>
@endpush
