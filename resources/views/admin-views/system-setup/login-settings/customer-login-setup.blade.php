@extends('layouts.admin.app')

@section('title', translate('Login_Settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('customer_login') }}
            </h2>
        </div>
        @include('admin-views.system-setup.login-settings.partials.login-settings-menu')

        <form action="{{ route('admin.system-setup.login-settings.customer-login-setup') }}" method="post"
              enctype="multipart/form-data" id="customer-login-setup-update">
            @csrf

            <div class="card mb-3">
                <div class="card-header py-3">
                    <h2>{{ translate('Login_Setup') }}</h2>
                    <p class="mb-0 fs-12 text-capitalize">
                        {{ translate('set_up_the_login_options_for_customer_access_to_the_system.') }}
                    </p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-sm-20 gap-3">
                        <div class="card card-sm shadow-1">
                            <div class="card-body">
                                <div class="pb-12 pb-sm-20">
                                    <h3>{{ translate('choose_how_to_login') }}</h3>
                                    <p class="mb-0 fs-12">
                                        {{ translate('the_option_you_select_customer_will_have_the_option_to_login_customer_app_&_websites') }}
                                    </p>
                                </div>
                                <div class="pb-12 pb-sm-20">
                                    <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                        <span>
                                            {{ translate('at_least_one_login_method_must_remain_active_for_the_customer.') }}
                                            {{ translate('otherwise_they_will_be_unable_to_log_in_to_the_system') }}
                                        </span>
                                    </div>
                                </div>
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    <div class="bg-white p-3 rounded border">
                                        <div class="row g-4">
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-check d-flex gap-10">
                                                    <input class="form-check-input checkbox--input checkbox--input-lg login-option-type" type="checkbox"
                                                        name="manual_login"
                                                        id="customer-manual-login"
                                                        value="1"
                                                        {{ $loginOptions['manual_login'] ? 'checked' : '' }}
                                                    >
                                                    <div class="flex-grow-1">
                                                        <label for="customer-manual-login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                            {{ translate('Manual_Login') }}
                                                        </label>
                                                        <p class="fs-12 mb-0">
                                                            {{ translate('by_enabling_manual_login,_customers_will_get_the_option_to_create_an_account_and_log_in_using_the_necessary_credentials_&_password_in_the_app_&_website') }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-check d-flex gap-10">
                                                   <input type="checkbox"
                                                            name="otp_login"
                                                            id="customer-otp-login"
                                                            value="1"
                                                            class="form-check-input checkbox--input checkbox--input-lg {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                          data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                            data-key="otp-login"
                                                            {{ $loginOptions['otp_login'] ? 'checked' : '' }}
                                                        >
                                                    <div class="flex-grow-1">
                                                        <label for="customer-otp-login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                            {{ translate('OTP_Login') }}
                                                        </label>
                                                        <p class="fs-12 mb-0">
                                                           {{ translate('by_enabling_manual_login,__with_otp_login_customers_can_log_in_using_their_phone_number_without_password_to_enable_this_feature') }} <a href="{{ route('admin.third-party.sms-module') }}" target="_blank" class="text-decoration-underline text-capitalize">{{ translate('configure_sms_Setup') }}</a> {{ translate('Here') }}.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-check d-flex gap-10">
                                                    <input class="form-check-input checkbox--input checkbox--input-lg login-option-type" type="checkbox"
                                                        name="social_login"
                                                        id="customer-social-login"
                                                        value="1"
                                                        {{ $loginOptions['social_login'] ? 'checked' : '' }}
                                                    >
                                                    <div class="flex-grow-1">
                                                        <label for="customer-social-login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                            {{ translate('Social_Media_Login') }}
                                                        </label>
                                                        <p class="fs-12 mb-0">
                                                            {{ translate('with_social_login_customers_can_log_in_using_social_media_accounts_to_enable_this_feature') }}
                                                            <a  href="{{ route('admin.third-party.social-login.view') }}" target="_blank" class="text-decoration-underline">{{ translate('configure_social_media_setup') }}</a>
                                                            {{ translate('here') }}.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card card-sm shadow-1 social-media-login-container {{ $loginOptions['social_login'] ? '' : 'd--none' }}">
                            <div class="card-body">
                                <div class="pb-12 pb-sm-20 d-flex flex-wrap align-items-center justify-content-between gap-3">
                                    <div>
                                        <h3>{{ translate('social_media_login_setup') }}</h3>
                                        <p class="mb-0 fs-12">
                                           {{ translate('the_option_you_select_customer_will_have_the_option_to_login_customer_app_&_websites') }}
                                        </p>
                                    </div>
                                    <a href="{{ route('admin.third-party.social-login.view') }}" class="text-decoration-underline" target="_blank">
                                        {{ translate('connect_3rd_party_login_system_from_here') }}
                                    </a>
                                </div>
                                <div class="pb-12 pb-sm-20">
                                    <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                        <span>
                                            {{ translate('Choose_which_social_login_options_you_want_to_offer_your_customers_for_accessing_the_app_and_website.') }}
                                        </span>
                                    </div>
                                </div>
                                <div class="p-12 p-sm-20 bg-section rounded">
                                    <div class="bg-white p-3 rounded border">
                                        <div class="row g-4">
                                            <div class="col-xl-4 col-md-6">
                                                <div
                                                    @if(!$configStatus['google'])
                                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                                    data-bs-title="{{ translate('google_login_is_currently_disabled_from_configure_3rd_party_social_login_options.') }}"
                                                    @endif
                                                >
                                                    <div class="form-check d-flex gap-10 {{ $configStatus['google'] ? '' : 'disabled' }}">
                                                        <input type="checkbox"
                                                            name="google_login"
                                                            data-status="{{ $configStatus['google'] ? 'true' : 'false' }}"
                                                            id="google_login"
                                                            value="1"
                                                            class="form-check-input checkbox--input checkbox--input-lg {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                            data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                            data-key="google"
                                                            {{ $socialMediaLoginOptions['google'] && $configStatus['google'] ? 'checked' : '' }}
                                                        >
                                                        <div class="flex-grow-1">
                                                            <label for="google_login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                                {{ translate('Google') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                                {{ translate('enabling_google_login_customers_can_log_in_to_the_site_using_their_existing_gmail_credentials') }}.
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div
                                                    @if(!$configStatus['facebook'])
                                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                                        data-bs-title="{{ translate('facebook_login_is_currently_disabled_from_configure_3rd_party_social_login_options.') }}"
                                                    @endif
                                                >
                                                    <div class="form-check d-flex gap-10 {{ $configStatus['facebook'] ? '' : 'disabled' }}">
                                                        <input type="checkbox"
                                                            name="facebook_login"
                                                            data-status="{{ $configStatus['facebook'] ? 'true' : 'false' }}"
                                                            id="facebook_login"
                                                            value="1"
                                                            class="form-check-input checkbox--input checkbox--input-lg {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                            data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                            data-key="facebook"
                                                            {{ $socialMediaLoginOptions['facebook'] && $configStatus['facebook'] ? 'checked' : '' }}
                                                        >
                                                        <div class="flex-grow-1">
                                                            <label for="facebook_login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                                {{ translate('Facebook') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                               {{ translate('enabling_facebook_login_customers_can_log_in_to_the_site_using_their_existing_facebook_credentials') }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div
                                                    @if(!$configStatus['apple'])
                                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                                    data-bs-title="{{ translate('apple_login_is_currently_disabled_from_configure_3rd_party_social_login_options.') }}"
                                                    @endif
                                                >
                                                    <div class="form-check d-flex gap-10 {{ $configStatus['apple'] ? '' : 'disabled' }}">
                                                        <input type="checkbox"
                                                            name="apple_login"
                                                            data-status="{{ $configStatus['apple'] ? 'true' : 'false' }}"
                                                            id="apple_login"
                                                            value="1"
                                                            class="form-check-input checkbox--input checkbox--input-lg {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                            data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                            data-key="apple"
                                                            {{ $socialMediaLoginOptions['apple'] && $configStatus['apple'] ? 'checked' : '' }}
                                                        >
                                                        <div class="flex-grow-1">
                                                            <label for="apple_login" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                                {{ translate('Apple') }}
                                                            </label>
                                                            <p class="fs-12 mb-0">
                                                                {{ translate('enabling_apple_login_customers_can_log_in_to_the_site_using_their_existing_apple__login_credentials_only_for_apple_devices') }}
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
            </div>

            <div class="card mb-20">
                <div class="card-header py-3">
                    <h2>{{ translate('Verification') }}</h2>
                    <p class="mb-0 fs-12 text-capitalize">
                        {{ translate('the_option_you_select_from_below_will_need_to_verify_by_customer_from_customer_app/website') }}
                    </p>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-sm-20 gap-3">
                        <div class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                            <i class="fi fi-sr-bulb text-warning fs-16"></i>
                            <span>
                                {{ translate('at_least_one_login_option_must_remain_active_for_verification._otherwise_you_will_be_unable_to_select_&_save') }}.
                            </span>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="bg-white p-3 rounded border">
                                <div class="row g-4">
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-check d-flex gap-10">
                                            <input type="checkbox"
                                                    name="email_verification"
                                                    id="email-verification"
                                                    value="1"
                                                    class="form-check-input checkbox--input checkbox--input-lg  {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                    data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                    data-key="email"
                                                {{ $emailVerification ? 'checked' : '' }}
                                            >
                                            <div class="flex-grow-1">
                                                <label for="email-verification" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                    {{ translate('Email_Verification') }}
                                                </label>
                                                <p class="fs-12 mb-0">
                                                   {{ translate('if_email_verification_is_on,_customers_must_verify_their_email_during_sign-up_to_ensure_a_secure_login_process.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-check d-flex gap-10">
                                            <input type="checkbox"
                                                    name="phone_verification"
                                                   id="phone_verification"
                                                   value="1"
                                                   class="form-check-input checkbox--input checkbox--input-lg  {{ showDemoModeInputValue(value: 'social-media-status-checkbox') }} {{ getDemoModeFormButton(type: 'class') }}"
                                                   data-route="{{ route('admin.system-setup.login-settings.config-status-validation') }}"
                                                   data-key="otp"
                                                {{ $phoneVerification ? 'checked' : '' }}
                                            >

                                            <div class="flex-grow-1">
                                                <label for="phone_verification" class="form-check-label text-dark fw-semibold mb-1 user-select-none cursor-pointer">
                                                    {{ translate('Phone_Number_Verification') }}
                                                </label>
                                                <p class="fs-12 mb-0">
                                                    {{ translate('if_phone_number_verification_is_on,_customers_must_verify their_phone_number_with_an_otp_to_complete_the_signup_process.') }}
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

            <div class="d-flex justify-content-end trans3 action-btn-wrapper-container">
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

    <div class="modal fade" id="customerLoginConfigValidation" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 p-2 rounded-circle" data-bs-dismiss="modal" aria-label="Close">
                        <i class="tio-clear"></i>
                    </button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">

                </div>
            </div>
        </div>
    </div>

    <span id="customer-login-setup-validation-msg"
          data-title="{{ translate("no_login_option_selected") }}!"
          data-text="{{ translate("please_select_at_least_one_login_option.") }}"
          data-ok="{{ translate("ok") }}"
    ></span>
    <span class="select-google-or-facebook"
          data-text="{{ translate("please_select_at_least_one_between_Google_or_Facebook.") }}"
          data-text-two="{{ translate("please_select_at_least_one_between_Google_or_Facebook_or_Apple.") }}"
    ></span>

    <div class="modal fade" id="googleModal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{  dynamicAsset(path: 'public/assets/new/back-end/img/modal/google-logo.png') }}" width="60" class="mb-20" alt="">
                        <h2 class="modal-title mb-3">{{ translate('Aet_up_google_configuration_first') }}</h2>
                        <div class="text-center">
                            {{ translate('It_looks_like_your_sms_configuration_is_not_set_up_yet._to_enable_the_otp_system,_please_set_up_the_sms_configuration_first.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="#" class="btn btn-primary max-w-250 flex-grow-1"
                            onclick="closeModalAndRedirect(this)">
                            {{ translate('Go_to_google_configuration') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="facebookModal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{  dynamicAsset(path: 'public/assets/new/back-end/img/modal/facebook-logo.png') }}" width="60" class="mb-20" alt="">
                        <h2 class="modal-title mb-3">{{ translate('Set_up_facebook_configuration_first') }}</h2>
                        <div class="text-center">
                           {{ translate('It_looks_like_your_facebook_login_configuration_is_not_set_up_yet._to_enable_the_facebook_login_option,_please_set_up_the_facebook_configuration_first.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="#" class="btn btn-primary max-w-250 flex-grow-1"
                            onclick="closeModalAndRedirect(this)">
                            {{ translate('Go to Facebook Configuration') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="appleModal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0 btn-circle bg-section2 shadow-none"
                        data-bs-dismiss="modal" aria-label="Close">
                    </button>
                </div>
                <div class="modal-body px-20 py-0 mb-30">
                    <div class="d-flex flex-column align-items-center text-center mb-30">
                        <img src="{{  dynamicAsset(path: 'public/assets/new/back-end/img/modal/apple-logo.png') }}" width="60" class="mb-20" alt="">
                        <h2 class="modal-title mb-3">{{ translate('Set_up_apple_configuration_first') }}</h2>
                        <div class="text-center">
                          {{ translate('It_looks_like_your_apple_id_login_configuration_is_not_set_up_yet._to_enable_the_apple_id_login_option,_please_set_up_the_apple_id_configuration_first.') }}
                        </div>
                    </div>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="#" class="btn btn-primary max-w-250 flex-grow-1"
                            onclick="closeModalAndRedirect(this)">
                            {{ translate('Go to Apple Configuration') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._customer-login")
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/customer-login-setup.js')}}"></script>
@endpush
