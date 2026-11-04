@extends('layouts.admin.app')

@section('title', translate('delivery_Man_Settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <div class="card mb-3 mb-sm-20">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8 col-xl-9">
                        <div>
                            <h3 class="fs-18">{{ translate('Proof_of_Delivery') }}</h3>
                            <p class="mb-0 fs-12">
                                {{ translate('this_option_allow_the_deliveryman_to_upload_a_picture_as_a_proof_of_delivery') }}
                            </p>
                        </div>
                    </div>
                    <div class="col-md-4 col-xl-3">
                        <div class="mt-3 mt-md-0">
                            <form action="{{ route('admin.business-settings.delivery-man-settings.upload-picture') }}"
                                  method="post"
                                  enctype="multipart/form-data" id="upload_picture_on_delivery-form"
                                  data-id="upload_picture_on_delivery-form">
                                @csrf
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                                    <span class="fw-medium text-dark">{{ translate('upload_Picture') }}</span>
                                    <label class="switcher" for="upload_picture_on_delivery">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="upload_picture_on_delivery"
                                            id="upload_picture_on_delivery"
                                            {{ ($data && $data->value == 1) ? 'checked':'' }}
                                            data-modal-type="input-change-form"
                                            data-modal-form="#upload_picture_on_delivery-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/upload-picture-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/upload-picture-off.png') }}"
                                            data-on-title="{{ translate('by_Turning_ON_Picture_Upload_on_Delivery')}}"
                                            data-off-title="{{ translate('by_Turning_OFF_Picture_Upload_on_Delivery')}}"
                                            data-on-message="<p>{{ translate('if_enabled_deliverymen_can_upload_picture_at_the_order_deliveries_time')}}</p>"
                                            data-off-message="<p>{{ translate('if_enabled_deliverymen_can_not_upload_picture_at_the_order_deliveries_time')}}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <form action="{{ route('admin.business-settings.delivery-man-settings.update') }}" method="post"
              enctype="multipart/form-data" id="add_fund">
            @csrf
            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3>{{ translate('Forget_Password_Setup') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('setup_how_deliveryman_can_recover_their_forgotten_passwords.') }}.
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="form-group">
                            <label class="form-label mb-3" for="">
                                {{ translate('Select_Verification_Option') }}
                            </label>
                            <div class="bg-white p-3 rounded">
                                <div class="row g-4">
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input class="form-check-input radio--input radio--input_lg" type="radio"
                                                   value="email" name="deliveryman_forgot_password_method"
                                                   id="verification_by_email"
                                                {{ getWebconfig(name: 'deliveryman_forgot_password_method') == 'email' ? 'checked' : '' }}>
                                            <div class="flex-grow-1">
                                                <label for="verification_by_email"
                                                       class="form-label text-dark fw-semibold mb-1">
                                                    {{ translate('Email') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('Delivery_man_will_recover_his_password_though_email_') }}
                                                </p>
                                                <div
                                                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                    <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                                    <span>
                                                        {{ translate('to_configure_email_for_this_system_visit') }}
                                                        <a href="{{ route('admin.third-party.mail.index') }}"
                                                           target="_blank"
                                                           class="text-decoration-underline fw-semibold">{{ translate('Email_Configuration') }}</a>
                                                        {{ translate('page') }}.
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input class="form-check-input radio--input radio--input_lg" type="radio"
                                                value="phone" name="deliveryman_forgot_password_method"
                                                id="verification_by_phone"
                                                {{ !getWebconfig(name: 'deliveryman_forgot_password_method') || getWebconfig(name: 'deliveryman_forgot_password_method') === 'phone' ? 'checked' : '' }}
                                            >
                                            <div class="flex-grow-1">
                                                <label for="verification_by_phone"
                                                       class="form-label text-dark fw-semibold mb-1">
                                                    {{ translate('Phone') }} ({{ translate('OTP') }})
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('Delivery_man_will_recover_his_password_though_otp') }}
                                                </p>
                                                <div
                                                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                    <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                                    <span>
                                                        {{ translate('to_configure_phone_for_this_system_visit') }}
                                                        <a href="{{ route('admin.third-party.sms-module') }}"
                                                           target="_blank"
                                                           class="text-decoration-underline fw-semibold">{{ translate('OTP_Configuration') }}</a>
                                                        {{ translate('page') }}.
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                        <div
                            class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                            <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                                {{ translate('reset') }}
                            </button>
                            <button type="submit" class="btn btn-primary px-3 px-sm-4">
                                <i class="fi fi-sr-disk"></i>
                                {{ translate('save_information') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    @include("layouts.admin.partials.offcanvas._deliveryman-settings")
@endsection
