@extends('layouts.admin.app')

@section('title', translate('OTP_setup'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('OTP_&_Login_Attempts') }}
            </h2>
        </div>
        @include('admin-views.system-setup.login-settings.partials.login-settings-menu')

        <form action="{{ route('admin.system-setup.login-settings.otp-setup') }}" method="post"
              enctype="multipart/form-data" id="update-settings" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h2>{{ translate('OTP_Setup') }}</h2>
                        <p class="mb-0 fs-12">
                            {{ translate('manage_the_settings_for_how_many_times_a_user_can_try_to_enter_the_otp.') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-xl-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="maximum_otp_hit">{{ translate('maximum_OTP_Hit') }}
                                        <span class="tooltip-icon"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('set_how_many_times_a_user_can_hit_the_wrong_OTPs.').'.'.translate('after_reaching_this_limit_the_user_will_be_blocked_for_a_time') }}"
                                              data-bs-title="{{ translate('set_how_many_times_a_user_can_hit_the_wrong_OTPs.').'.'.translate('after_reaching_this_limit_the_user_will_be_blocked_for_a_time') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <input type="number" min="0" value="{{$maximumOtpHit}}" name="maximum_otp_hit"
                                           data-required-msg="{{ translate('maximum_otp_hit_is_required') }}"
                                           class="form-control" placeholder="{{translate('ex').':'.'5'}}" required>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                @php($firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification') ?? [])
                                <div class="form-group">
                                    <label class="form-label" for="otp_resend_time">
                                        {{translate('OTP_Resend_Time_')}} ({{translate('sec')}})
                                        <span class="tooltip-icon"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              @if ($firebaseOTPVerification && $firebaseOTPVerification['status'])
                                                  aria-label="{{ translate('we_suggest_using_a_minimum_of_60_seconds_to_resend_otp_time_for_firebase_auth.') }}"
                                              data-bs-title="{{ translate('we_suggest_using_a_minimum_of_60_seconds_to_resend_otp_time_for_firebase_auth.') }}"
                                              @else
                                                  aria-label="{{ translate('set_the_time_for_requesting_a_new_OTP') }}"
                                              data-bs-title="{{ translate('set_the_time_for_requesting_a_new_OTP') }}"
                                                @endif
                                                >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <input type="number" min="0" step="0.01" value="{{$otpResendTime}}"
                                           name="otp_resend_time" class="form-control"
                                           data-required-msg="{{ translate('otp_resend_time_is_required') }}"
                                           placeholder="{{translate('ex: 5 ')}}" required>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label"
                                           for="temporary_block_time">{{ translate('temporary_Block_Time') }}
                                        ({{translate('sec')}})
                                        <span class="tooltip-icon"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('Within_this_time_users_can_not_make_OTP_requests_again') }}"
                                              data-bs-title="{{ translate('Within_this_time_users_can_not_make_OTP_requests_again') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <input type="number" min="0" value="{{$temporaryBlockTime}}" step="0.01"
                                           name="temporary_block_time" class="form-control"
                                           data-required-msg="{{ translate('temporary_Block_Time_is_required') }}"
                                           placeholder="{{translate('ex: 120')}}" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h2>{{ translate('Login_Setup') }}</h2>
                        <p class="mb-0 fs-12">
                            {{ translate('manage_the_settings_for_how_many_times_a_user_can_try_to_log_in_to_the_system.') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-xl-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize"
                                           for="maximum_login_hit">{{ translate('maximum_login_hit') }}
                                        <span class="tooltip-icon"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('set_the_maximum_unsuccessful_login_attempts_users_can_make_using_wrong_passwords.') }} {{ translate('after_reaching_this_limit_they_will_be_blocked_for_a_time') }}"
                                              data-bs-title="{{ translate('set_the_maximum_unsuccessful_login_attempts_users_can_make_using_wrong_passwords.') }} {{ translate('after_reaching_this_limit_they_will_be_blocked_for_a_time') }}"
                                        >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <input type="number" min="0" value="{{$maximumLoginHit}}"
                                           placeholder="{{translate('ex: 5')}}"
                                           data-required-msg="{{ translate('maximum_login_hit_is_required') }}"
                                           name="maximum_login_hit" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-xl-4 col-md-6">
                                <div class="form-group">
                                    <label class="form-label text-capitalize" for="maximum_otp_hit">
                                        {{translate('temporary_login_block_time')}} ({{translate('sec')}})
                                        <span class="tooltip-icon"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="right"
                                              aria-label="{{ translate('set_a_time_duration_during_which_users_cannot_log_in_after_reaching_the_Maximum_Login_Hit_limit') }}"
                                              data-bs-title="{{ translate('set_a_time_duration_during_which_users_cannot_log_in_after_reaching_the_Maximum_Login_Hit_limit') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <input type="number" min="0" step="0.01" value="{{$temporaryLoginBlockTime}}"
                                           placeholder="{{translate('ex').':'.'1210'}}"
                                           data-required-msg="{{ translate('temporary_login_block_time_is_required') }}"
                                           name="temporary_login_block_time" class="form-control" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div
                    class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('reset') }}</button>
                    <button type="{{config('app.mode')!='demo'?'submit':'button'}}"
                            class="btn btn-primary px-3 px-sm-4 {{config('app.mode') != 'demo'?'':'call-demo-alert'}}">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
    @include("layouts.admin.partials.offcanvas._otp-setup")
@endsection
