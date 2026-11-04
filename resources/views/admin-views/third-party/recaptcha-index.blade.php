@extends('layouts.admin.app')

@section('title', translate('reCaptcha_Setup'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
              {{ translate('3rd_Party') }} - {{ translate('Other_Configurations') }}
            </h2>
        </div>
        @include('admin-views.third-party._third-party-others-menu')

        <form action="{{ env('APP_MODE') != 'demo' ? route('admin.third-party.captcha') : 'javascript:' }}"
              method="POST" id="recaptcha-status-form" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate>
            @csrf
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8 col-xl-9">
                            <h2>{{ translate('ReCAPTCHA') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('if_you_turn_this_feature_on_users_need_to_verify_them_through_the_recaptcha') }}
                                .
                            </p>
                        </div>
                        @if(isset($config))
                            @php($config = (array)json_decode($config['value']))
                        @endif
                        <div class="col-md-4 col-xl-3">
                            <div class="mt-3 mt-md-0">
                                <div
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                                    <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                                    <label class="switcher " for="recaptcha-id">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="status"
                                            id="recaptcha-id"
                                            {{ $config['status'] == 1? 'checked' : '' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#recaptcha-status-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-on.svg') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-off.svg') }}"
                                            data-on-title="{{ translate('are_you_sure_to_turn_on_the_recaptcha_setup') }}?"
                                            data-off-title="{{ translate('are_you_sure_to_turn_off_the_recaptcha_setup') }}?"
                                            data-on-message="<p>{{ translate('turning_on_the_recaptcha_will_help_for_protect_your_sites_from_spam') }}</p>"
                                            data-off-message="<p>{{ translate('turning_off_the_recaptcha_will_lower_your_sites_spam_protection.') }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded-8 mb-3 version-content">
                <div class="d-flex gap-2 align-items-center justify-content-between mb-2">
                    <div class="d-flex gap-2 align-items-center">
                        <i class="fi fi-sr-info text-info"></i>
                        <div>
                            <h4 class="fw-medium mb-1">{{ translate('v3_version_is_available_now_must_setup_for_recaptcha_v3') }}</h4>
                            <span>{{ translate('you_must_setup_for_v3_version_otherwise_the_default_recaptcha_will_be_displayed_automatically') }}</span>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="btn rounded-circle ratio-1 btn-xs btn-light p-2 cross-button">
                            <i class="fi fi-rr-cross"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex gap-3 justify-content-between align-items-center flex-wrap mb-3">
                        <div>
                            <h2>{{ translate('google_recaptcha_credentials') }}</h2>
                            <p class="mb-0 fs-12">
                                {{ translate('fillup_google_recaptcha_credentials_to_setup_&_active_this_feature_properly') }}
                                .
                            </p>
                        </div>
                        <div>
                            <a data-bs-toggle="modal" href="#getRecaptchaCredentials"
                               class="fs-12 text-capitalize">{{ translate('how_to_get_credential') }}</a>
                        </div>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{translate('site_Key')}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                              aria-label="Enter your site key" data-bs-title="Enter your site key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="site_key"
                                           placeholder="Enter your site key"
                                           data-required-msg="{{translate('site_key_field_is_required')}}"
                                           value="{{env('APP_MODE')!='demo'?$config['site_key']??"":''}}" required>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label" for="">{{translate('secret_key')}}
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                              aria-label="Enter your secret key" data-bs-title="Enter your secret key">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="text" class="form-control" name="secret_key"
                                           placeholder="Enter your secret key"
                                           data-required-msg="{{translate('secret_field_is_required')}}"
                                           value="{{env('APP_MODE')!='demo'?$config['secret_key']??"":''}}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end flex-wrap gap-3 mt-4">
                        <button type="reset" class="btn btn-secondary w-120 px-4">{{translate('reset')}}</button>
                        <button type="{{env('APP_MODE')!='demo'?'submit':'button'}}"
                                class="btn btn-primary w-120 px-4 {{env('APP_MODE')!= 'demo'? '' : 'call-demo-alert'}}"
                        >{{translate('save')}}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal fade" id="getRecaptchaCredentials" tabindex="-1" aria-labelledby="getInformationModal"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0" data-bs-dismiss="modal" aria-label="Close"><i
                            class="tio-clear"></i></button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">
                    <div class="swiper instruction-carousel pb-3">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="d-flex flex-column align-items-center gap-2">
                                    <img width="80"
                                         src="{{dynamicAsset(path: 'public/assets/new/back-end/img/modal/google-analytics.png')}}"
                                         alt="">
                                    <h4 class="lh-md mb-3 text-capitalize">{{translate('google_recaptcha_instructions')}}</h4>
                                    <ol class="pl-4 instructions-list">
                                        <li>
                                            {{translate('to_get_site_key_and_secret_key_Go_to_the_Credentials_page')}}
                                            (<a href="https://www.google.com/recaptcha/admin/create"
                                                target="_blank">{{translate('click_here')}}</a>)
                                        </li>
                                        <li>{{translate('add_a_label_(Ex:_abc_company)')}}</li>
                                        <li>{{translate('select_reCAPTCHA_v3_as_ReCAPTCHA_Type')}}</li>
                                        <li>{{translate('select_sub_type').':'.translate('im_not_a_robot_checkbox')}}</li>
                                        <li>{{translate('add_Domain_(For_ex:_demo.6amtech.com)')}}</li>
                                        <li>{{translate('check_in_Accept_the_reCAPTCHA_Terms_of_Service')}}</li>
                                        <li>{{translate('press_Submit')}}</li>
                                        <li>{{translate('copy_Site_Key_and_Secret_Key,_Paste_in_the_input_filed_below_and_Save').'.'}}</li>
                                    </ol>
                                    <div class="d-flex justify-content-center mt-4">
                                        <button type="button" class="btn btn-primary px-5"
                                                data-bs-dismiss="modal">{{translate('got_it')}}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._3rd-party-recaptcha-setup")
@endsection

@push('script')
    <script>
        'use strict';

        $(document).ready(function () {
            $('.cross-button').click(function () {
                $('.version-content').hide();
            });
        });
    </script>
@endpush
