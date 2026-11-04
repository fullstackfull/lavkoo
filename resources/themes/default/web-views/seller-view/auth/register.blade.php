@extends('layouts.front-end.app')

@section('title', translate('vendor_Apply'))
@push('css_or_js')
    <link href="{{ theme_asset(path: 'public/assets/back-end/css/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ theme_asset(path: 'public/assets/back-end/css/croppie.css') }}" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet"
        href="{{ theme_asset(path: 'public/assets/front-end/plugin/intl-tel-input/css/intlTelInput.css') }}">
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/daterangepicker.css') }}">
@endpush


@section('content')
    <form id="seller-registration" action="{{ route('vendor.auth.registration.add') }}" method="POST" class="form-advance-validation form-advance-file-validation"
        enctype="multipart/form-data">
        @csrf
        <div class="py-5">
            <div class="first-el">
                <section>
                    <div class="container">
                        <div class="create-an-account p-3 p-sm-4">
                            <img src="{{ theme_asset('assets/img/media/form-bg.png') }}" alt=""
                                class="create-an-account-bg-img">
                            <div class="row">
                                @include('web-views.seller-view.auth.partial.header')
                                <div class="col-lg-8">
                                    <div class="bg-white p-3 p-sm-4 rounded">
                                        <h4 class="mb-4 text text-capitalize">{{ translate('create_an_account') }}</h4>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <div class="mb-4">
                                                    <label for="email">
                                                        {{ translate('email') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="text-danger fs-12 mail-error"></span>
                                                    </label>
                                                    <input class="form-control" type="email" id="email" name="email"
                                                        placeholder="{{ translate('Ex: example@gmail.com') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-4">
                                                    <label for="tel">
                                                        {{ translate('phone') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="text-danger fs-12 phone-error"></span>
                                                    </label>
                                                    <div>
                                                        <input
                                                            class="form-control form-control-user phone-input-with-country-picker"
                                                            type="tel" name="phone"
                                                            placeholder="{{ translate('enter_phone_number') }}" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-4">
                                                    <label for="password">
                                                        {{ translate('password') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="text-danger fs-12 password-error"></span>
                                                    </label>
                                                    <div class="password-toggle rtl">
                                                        <input class="form-control text-align-direction password-check"
                                                            name="password" type="password" id="password"
                                                            placeholder="{{ translate('minimum_8_characters_long') }}"
                                                            required>
                                                        <label class="password-toggle-btn">
                                                            <input class="custom-control-input" type="checkbox"><i
                                                                class="tio-hidden password-toggle-indicator"></i>
                                                            <span class="sr-only">{{ translate('show_password') }} </span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="mb-4">
                                                    <label for="password" class="text-capitalize">
                                                        {{ translate('confirm_password') }}
                                                        <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="password-toggle rtl">
                                                        <input class="form-control text-align-direction"
                                                            name="confirm_password" type="password" id="confirm_password"
                                                            placeholder="{{ translate('confirm_password') }}" required>
                                                        <label class="password-toggle-btn">
                                                            <input class="custom-control-input " type="checkbox"><i
                                                                class="tio-hidden password-toggle-indicator"></i><span
                                                                class="sr-only">{{ translate('show_password') }} </span>
                                                        </label>
                                                    </div>
                                                    <small class="text-danger confirm-password-error"></small>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="d-flex justify-content-end">
                                                    <button type="button"
                                                        class="btn btn--primary proceed-to-next-btn text-capitalize">{{ translate('proceed_to_next') }}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                @include('web-views.seller-view.auth.partial.why-with-us')
                @include('web-views.seller-view.auth.partial.business-process')
                @include('web-views.seller-view.auth.partial.download-app')
                @include('web-views.seller-view.auth.partial.faq')
            </div>
            @include('web-views.seller-view.auth.partial.vendor-information-form')
        </div>
    </form>


    <div class="modal fade registration-success-modal" tabindex="-1" aria-labelledby="toggle-modal" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow-lg">
                <div class="modal-header border-0 pb-0 d-flex justify-content-end">
                    <button type="button" class="btn-close border-0" data-dismiss="modal" aria-label="Close"><i
                            class="tio-clear"></i></button>
                </div>
                <div class="modal-body px-4 px-sm-5 pt-0">
                    <div class="d-flex flex-column align-items-center text-center gap-2 mb-2">
                        <img src="{{ theme_asset(path: 'public/assets/front-end/img/congratulations.png') }}"
                            width="70" class="mb-3 mb-20" alt="">
                        <h5 class="modal-title">{{ translate('congratulations') }}</h5>
                        <div class="text-center">
                            {{ translate('your_registration_is_successful') . ', ' . translate('please-wait_for_admin_approval') . '.' . translate(' you_will_get_a_mail_soon') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <span id="get-confirm-and-cancel-button-text" data-sure ="{{ translate('are_you_sure') . '?' }}"
        data-message="{{ translate('want_to_apply_as_a_vendor') . '?' }}" data-confirm="{{ translate('yes') }}"
        data-cancel="{{ translate('no') }}"></span>
    <span id="proceed-to-next-validation-message" data-mail-error="{{ translate('please_enter_your_email') . '.' }}"
        data-phone-error="{{ translate('please_enter_your_phone_number') . '.' }}"
        data-valid-mail="{{ translate('please_enter_a_valid_email_address') . '.' }}"
        data-enter-password="{{ translate('please_enter_your_password') . '.' }}"
        data-enter-confirm-password="{{ translate('please_enter_your_confirm_password') . '.' }}"
        data-password-not-match="{{ translate('passwords_do_not_match') . '.' }}">
    </span>
@endsection

@push('script')
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/vendor-registration.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/min/moment.min.js"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/daterangepicker.min.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/file-upload/pdf.min.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/file-upload/pdf-worker.min.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/file-upload/multiple-document-upload.js') }}"></script>
    <script>
        $(document).ready(function() {
            $(".js-daterangepicker_single-date-with-placeholder").daterangepicker({
                singleDatePicker: true,
                autoUpdateInput: false,
                locale: {
                    cancelLabel: 'Clear'
                }
            });

            $(".js-daterangepicker_single-date-with-placeholder").on('apply.daterangepicker', function(ev, picker) {
                $(this).val(picker.startDate.format('MM/DD/YYYY'));
            });

            $(".js-daterangepicker_single-date-with-placeholder").on('cancel.daterangepicker', function(ev,
                picker) {
                $(this).val('');
            });

            $('#vendor-apply-submit').on('click', function(e) {
                e.preventDefault();
                submitRegistration();
            });

        });

        function checkPasswordMatch() {
            const password = $('#password').val();
            const confirmPassword = $('#confirm_password').val();

            if (confirmPassword.length > 0 && password !== confirmPassword) {
                $('.confirm-password-error').text('Password and confirm password does not match.');
            } else {
                $('.confirm-password-error').text('');
            }
        }
        $('#user_password, #confirm_password').on('keyup change', function () {
            checkPasswordMatch();
        });
    </script>
@endpush

@push('phone_input_assets')
    @include('layouts.front-end.partials._phone-input-assets')
@endpush
