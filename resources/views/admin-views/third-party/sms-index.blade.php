@extends('layouts.admin.app')

@section('title', translate('SMS_configuration'))

@push('css_or_js')
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-2">
            <i class="fi fi-sr-lightbulb-on text-info"></i>
            <span>
                {{ translate('this_sms_gateway_will_work_for') }}
                <a href="{{ route('admin.system-setup.login-settings.customer-login-setup') }}" target="_blank"
                   class="text-decoration-underline fw-semibold">
                    {{ translate('OTP_verification') }}
                </a> {{ translate('or') }}
                <a href="{{ route('admin.push-notification.index') }}" target="_blank"
                   class="text-decoration-underline fw-semibold">
                    {{ translate('Notification') }}
                </a> {{ translate('through_SMS') }}
            </span>
        </div>

        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mb-3">
            <i class="fi fi-sr-info text-warning"></i>
            <span>
                {{ translate('please_recheck_if_you_have_put_all_the_data_correctly_or_contact_your_sms_gateway_provider_for_assistance.') }}
            </span>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="h-100 d-flex flex-column justify-content-between gap-4">
                            <div class="">
                                <h2>{{ translate('SMS_Configuration') }}</h2>
                                <p class="fs-12 mb-0">
                                    {{ translate('choose_the_sms_model_you_want_to_use_for_otp_&_other_sms') }}
                                </p>
                            </div>
                            @if(!($firebaseOtpVerification['status'] == 1 || count(collect($smsGateways)->where('is_active', 1)) > 0))
                                <div
                                    class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-triangle-warning text-danger"></i>
                                    <span>
                                    {{ translate('3rd_party_is_not_set_up_yet_please_configure_it_first_to_ensure_it_works_properly') }}.
                                </span>
                                </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="form-group mb-20px">
                                <div class="mb-3 d-flex gap-3 flex-wrap justify-content-between align-items-center">
                                    <label class="form-label mb-0" for="">{{ translate('Select_SMS_Configuration_Model') }}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                              aria-label="{{ translate('Select_SMS_Configuration_Model') }}" data-bs-title="{{ translate('Select_SMS_Configuration_Model') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <button class="btn btn-outline-primary" data-bs-toggle="modal"
                                            data-bs-target="#send-mail-confirmation-modal">
                                        <i class="fi fi-sr-paper-plane"></i>
                                        {{translate('send_test_SMS')}}
                                    </button>
                                </div>
                                <div class="bg-white p-3 rounded">
                                    <div class="row g-4">
                                        <div class="col-xl-6 col-md-6">
                                            <div class="form-check d-flex gap-3">
                                                <form action="{{ route('admin.third-party.firebase-configuration.update') }}" class=""
                                                      method="post" id="sms-configuration-third-party-form">
                                                    @csrf
                                                    <input type="hidden" name="web_api_key" value="{{ $firebaseOtpVerification['web_api_key'] }}">
                                                    <input
                                                        class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                        type="radio" value="0" name="status"
                                                        id=""
                                                        {{ $firebaseOtpVerification['status'] == 0 ? 'checked' : '' }}
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#sms-configuration-third-party-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                                        data-on-title="{{ translate('are_you_sure_you_want_to_use_third_party_gateway_for_sms') }} ?"
                                                        data-off-title="{{ translate('are_you_sure_you_want_to_use_third_party_gateway_for_sms') }} ?"
                                                        data-on-button-text="{{ translate('turn_on') }}"
                                                        data-off-button-text="{{ translate('turn_off') }}">
                                                </form>
                                                <div class="flex-grow-1">
                                                    <label for="verification_by_email"
                                                           class="form-label text-dark fw-semibold mb-1">
                                                        {{ translate('3rd_Party') }}
                                                    </label>
                                                    <p class="fs-12 mb-3">
                                                        {{ translate('you_have_to_setup_a_sms_module_from_below_fist_to_active_this_feature') }}
                                                    </p>

                                                </div>
                                            </div>
                                        </div>







 @php
    $zenderSettings = \App\Models\ZenderSetting::first();
@endphp

<div class="col-xl-6 col-md-6">
    <div class="card h-100">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-3">
                <div>
                    <h3 class="mb-1 text-capitalize">{{ translate('Lynomia_OTP') }}</h3>
                    <p class="mb-0 fs-12 text-capitalize">
                        {{ translate('Setup_necessary') }} 
                        <a href="{{ route('admin.zender.settings') }}" target="_blank" class="fw-semibold text-decoration-underline">
                            {{ translate('Lynomia_OTP_Configurations') }}
                        </a>.
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <form action="{{ route('admin.zender.settings.toggle') }}" method="post" id="sms-configuration-lynomia-form">
                        @csrf
                        <!-- استخدام Checkbox بدلاً من Radio لضمان حفظ الحالة -->
                        <label class="switcher mx-auto" for="lynomia_otp_switch">
                            <input
                                class="switcher_input custom-modal-plugin"
                                type="checkbox"
                                value="1"
                                name="enabled"
                                id="lynomia_otp_switch"
                                {{ ($zenderSettings && $zenderSettings->enabled) ? 'checked' : '' }}
                                data-modal-type="input-change-form"
                                data-modal-form="#sms-configuration-lynomia-form"
                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                data-on-title="{{ translate('are_you_sure_you_want_to_use_lynomia_otp_for_sms') }} ?"
                                data-off-title="{{ translate('are_you_sure_you_want_to_disable_lynomia_otp') }} ?"
                                data-on-button-text="{{ translate('turn_on') }}"
                                data-off-button-text="{{ translate('turn_off') }}">
                            <span class="switcher_control"></span>
                        </label>
                    </form>
                </div>
            </div>
        </div>
        <!-- عرض حالة التفعيل الحالية بشكل واضح -->
        <div class="card-footer bg-transparent border-top-0">
            @if($zenderSettings && $zenderSettings->enabled)
                <span class="badge bg-success">{{ translate('Active') }}</span>
            @else
                <span class="badge bg-secondary">{{ translate('Inactive') }}</span>
            @endif
        </div>
    </div>
</div>








                                        <div class="col-xl-6 col-md-6">
                                            <div class="form-check d-flex gap-3">
                                                <form action="{{ route('admin.third-party.firebase-configuration.update') }}" class=""
                                                      method="post" id="sms-configuration-firebase-form">
                                                    @csrf
                                                    <input type="hidden" name="web_api_key" value="{{ $firebaseOtpVerification['web_api_key'] }}">
                                                    <input
                                                        class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                        type="radio" value="1" name="status"
                                                        id=""
                                                        {{ $firebaseOtpVerification['status'] == 1 ? 'checked' : '' }}
                                                        data-modal-type="input-change-form"
                                                        data-modal-form="#sms-configuration-firebase-form"
                                                        data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                                        data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/config-status-change.png') }}"
                                                        data-on-title="{{ translate('are_you_sure_you_want_to_use_firebase_for_sms') }} ?"
                                                        data-off-title="{{ translate('are_you_sure_you_want_to_use_firebase_for_sms') }} ?"
                                                        data-on-button-text="{{ translate('turn_on') }}"
                                                        data-off-button-text="{{ translate('turn_off') }}">
                                                </form>
                                                <div class="flex-grow-1">
                                                    <label for="verification_by_phone"
                                                           class="form-label text-dark fw-semibold mb-1">
                                                        {{ translate('Firebase_OTP') }}
                                                    </label>
                                                    <p class="fs-12 mb-3">
                                                        {{ translate('Setup_necessary') }} <a
                                                            href="{{ route('admin.third-party.firebase-configuration.authentication') }}"
                                                            target="_blank"
                                                            class="fw-semibold text-decoration-underline">{{ translate('Firebase_Configurations') }}</a>.
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
            </div>
        </div>

        <div class="row g-3">
            @foreach($smsGateways as $key => $smsConfig)
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <form action="{{route('admin.third-party.addon-sms-set')}}" method="POST"
                                  id="sms-{{$smsConfig['key_name']}}-form" enctype="multipart/form-data" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
                                @csrf @method('PUT')
                                    <?php
                                    $imgPath = 'sms/' . $smsConfig['key_name'] . '.png';
                                    ?>
                                <div class="view-details-container">
                                    <div class="d-flex justify-content-between align-items-center gap-3">
                                        <div>
                                            <h3 class="mb-1 text-capitalize">
                                                {{ str_replace('_', ' ', $smsConfig['key_name'])}}
                                            </h3>
                                            <p class="mb-0 fs-12 text-capitalize">
                                                {{ translate('setup') }} {{ str_replace('_', ' ', $smsConfig['key_name'])}} {{ translate('_as_sms_gateway') }}
                                            </p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="javascript:"
                                               class="fs-12 fw-semibold d-flex align-items-end view-btn ">
                                                {{ translate('View') }}
                                                <i class="fi fi-rr-arrow-small-down fs-16 trans3"></i>
                                            </a>
                                            <label class="switcher mx-auto" for="{{$smsConfig['key_name']}}">
                                                <input
                                                    class="switcher_input custom-modal-plugin"
                                                    type="checkbox" value="1" name="status"
                                                    id="{{$smsConfig['key_name']}}"
                                                    {{ $smsConfig['is_active'] == 1 ? 'checked' : '' }}
                                                    data-modal-type="input-change-form"
                                                    data-modal-form="#sms-{{$smsConfig['key_name']}}-form"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/'. $imgPath) }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/' .$imgPath) }}"
                                                    data-on-title="{{translate('want_to_Turn_ON_').' '.ucwords(str_replace('_',' ',$smsConfig['key_name'])).' '.translate('_as_the_SMS_Gateway').'?'}}"
                                                    data-off-title="{{translate('want_to_Turn_OFF_').' '.ucwords(str_replace('_',' ',$smsConfig['key_name'])).' '.translate('_as_the_SMS_Gateway').'?'}}"
                                                    data-on-message="<p>{{translate('if_enabled_system_can_use_this_SMS_Gateway')}}</p>"
                                                    data-off-message="<p>{{translate('if_disabled_system_cannot_use_this_SMS_Gateway')}}</p>"
                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="view-details mt-3 mt-sm-4">
                                        <div class="p-12 p-sm-20 bg-section rounded">
                                            <input name="gateway" value="{{$smsConfig['key_name']}}" class="d-none">
                                            <input name="mode" value="live" class="d-none">
                                            @php($skip=['gateway','mode','status'])
                                            @foreach($smsConfig['live_values'] as $keyName => $value)
                                                @if(!in_array($keyName, $skip))
                                                    <div class="form-group">
                                                        <label for=""
                                                               class="form-label">{{ucwords(str_replace('_',' ',$keyName))}}
                                                            <span class="text-danger">*</span>
                                                        </label>
                                                        <input type="text" class="form-control"
                                                               name="{{$keyName}}"
                                                               placeholder="{{ucwords(str_replace('_',' ',$keyName))}}"
                                                               value="{{ showDemoModeInputValue(value: $value) }}"  data-required-msg="{{ translate($keyName).translate('_field_is_required')}}" required>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                        <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                                            <button type="reset" class="btn btn-secondary w-120 px-4">
                                                {{ translate('reset') }}
                                            </button>
                                            <button type="{{ getDemoModeFormButton(type: 'button') }}"
                                                    class="btn btn-primary w-120 px-4 {{ getDemoModeFormButton(type: 'class') }}">
                                                {{ translate('save') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="modal fade" id="send-mail-confirmation-modal" tabindex="-1"
     aria-labelledby="send-mail-confirmation-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                            data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <form action="{{ route('admin.third-party.send-test-sms') }}" method="post">
                        @csrf
                        <div class="d-flex flex-column gap-sm-20 gap-3">
                            <div>
                                <h3>{{ translate('send_test_sms') }}</h3>
                                <p class="fs-12 mb-0">
                                    {{ translate('insert_a_valid_phone_number_to_get_sms') }}
                                </p>
                            </div>
                            <div
                                class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                <i class="fi fi-sr-lightbulb-on text-info"></i>
                                <span>
                                    {{ translate('_is_configured_for_sms_please_test_to_ensure_you_are_receiving_sms_messages_correctly') }}.
                                </span>
                            </div>
                            <div class="p-12 p-sm-20 bg-section rounded d-flex flex-wrap gap-2 justify-content-end flex-column">
                                <div>
                                    <div class="form-group">
                                        <label class="form-label" for="">{{ translate('Phone') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <input class="form-control" type="tel" name="phone"
                                               value=""
                                               placeholder="{{ translate('01xxxxxxxx') }}">

                                        @if($firebaseOtpVerification && $firebaseOtpVerification['status'])
                                            <div class="generate-firebase-auth-recaptcha" id="firebase-auth-recaptcha-{{ rand(111, 999) }}"></div>
                                        @endif
                                    </div>
                                </div>
                               <div>
                                   <button type="submit"  class="btn btn-primary px-4 min-w-120 h-40">
                                       {{ translate('Send_SMS') }}
                                   </button>
                               </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._3rd-party-sms-setup")

    <span id="payment-gateway-published-status" data-status="{{ $paymentGatewayPublishedStatus == 1 }}"></span>
    ></span>

@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/third-party/sms-setup.js') }}"></script>
@endpush