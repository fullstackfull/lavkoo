@extends('layouts.admin.app')

@section('title', translate('vendors'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form action="{{ route('admin.business-settings.vendor-settings.update-vendor-settings') }}" method="post" id="update-vendor-settings-form">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="mb-1">{{ translate('General_Setup') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('complete_the_basic_settings_for_vendors') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            <div class="col-xl-6 col-md-6">
                                <div
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('active_Pos_For_Vendor') }}
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('if_enabled_pos_will_be_available_on_the_vendor_panel') }}
                                        </span>
                                    </span>
                                    @php($sellerPos = getWebConfig('seller_pos'))
                                    <label class="switcher" for="vendor-pos">
                                        <input
                                            class="switcher_input "
                                            type="checkbox" value="1" name="seller_pos"
                                            id="vendor-pos"
                                            {{ $sellerPos == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/pos-seller-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/pos-seller-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_POS_for_Vendor') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_POS_for_Vendor') }}"
                                            data-on-message="<p>{{ translate('if_enabled_POS_option_will_be_available_on_the_vendor_panel_and_vendor_app') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_POS_option_will_be_hidden_from_the_Vendor_Panel_and_vendor_app') }}</p>">

                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <div
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('enable_vendor_registration') }}
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('enabling_this_option_allows_users_to_send_requests_to_become_registered') }}
                                        </span>
                                    </span>
                                    @php($vendorRegistration = getWebConfig('seller_registration'))

                                    <label class="switcher" for="vendor-registration">
                                        <input
                                            class="switcher_input "
                                            type="checkbox" value="1" name="seller_registration"
                                            id="vendor-registration"
                                            {{ $vendorRegistration == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-modal-form="#update-vendor-settings-form"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/self-registrations-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/self-registrations-off.png') }}"
                                            data-on-title = "{{ translate('want_to_Turn_ON_Self_Registration') . '?' }}"
                                            data-off-title = "{{ translate('want_to_Turn_OFF_Self_Registration') . '?' }}"
                                            data-on-message = "<p>{{ translate('Enabling_this_option_allows_vendors_to_send_requests_to_become_registered') }}</p>"
                                            data-off-message = "<p>{{ translate('Disabling_this_option_will_not_allow_vendors_to_send_requests_to_become_registered.') }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('minimum_order_amount') }}
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('if_enabled_vendors_can_set_minimum_order_amount_for_their_orders') }}
                                        </span>
                                    </span>

                                    @php($minimumOrderAmountBySeller = getWebConfig('minimum_order_amount_by_seller'))
                                    <label class="switcher" for="minimum-order-amount-by-vendor">
                                        <input
                                            class="switcher_input "
                                            type="checkbox" value="1" name="minimum_order_amount_by_seller"
                                            id="minimum-order-amount-by-vendor"
                                            {{ $minimumOrderAmountBySeller == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/minimum-order-amount-feature-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/minimum-order-amount-feature-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_the_Set_Minimum_Order_Amount_option') . '?' }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_the_Set_Minimum_Order_Amount_option') . '?' }}"
                                            data-on-message="<p>{{ translate('if_enabled_Vendors_can_set_minimum_order_amount_for_their_stores_by_themselves') . '.' }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_Vendors_cannot_set_the_minimum_order_amount_for_their_store_and_the_admin_will_set_it') . '.' }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('vendor_can_reply_on_review') }}
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('enable_this_option_to_allow_vendors_to_reply_to_customer_reviews') }}
                                        </span>
                                    </span>

                                    @php($vendorReviewReplyStatus = getWebConfig('vendor_review_reply_status') ?? 0)
                                    <label class="switcher" for="vendor-review-reply-status">
                                        <input
                                            class="switcher_input "
                                            type="checkbox" value="1" name="vendor_review_reply_status"
                                            id="vendor-review-reply-status"
                                            {{ $vendorReviewReplyStatus == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-review-reply-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-review-reply-off.png') }}"
                                            data-on-title="{{ translate('By_Turning_On_Vendor_Review_Reply_Option') . '?' }}"
                                            data-off-title="{{ translate('By_Turning_Off_Vendor_Review_Reply_Option') . '?' }}"
                                            data-on-message="<p>{{ translate('If_you_turn_on_this_seller_will_be_able_to_reply_of_review') . '.' }}</p>"
                                            data-off-message="<p>{{ translate('If_you_turn_off_this_seller_will_not_be_able_to_reply_of_review') . '.' }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('Vendor_can_edit_order') }}
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('enable_this_option_to_allow_vendors_can_be_edit_order') }}
                                        </span>
                                    </span>

                                    @php($vendorCanEditOrderStatus = getWebConfig('vendor_can_edit_order') ?? 0)
                                    <label class="switcher" for="vendor-can-edit-order">
                                        <input
                                            class="switcher_input "
                                            type="checkbox" value="1" name="vendor_can_edit_order"
                                            id="vendor-can-edit-order"
                                            {{ $vendorCanEditOrderStatus == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-review-reply-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-review-reply-off.png') }}"
                                            data-on-title="{{ translate('By_Turning_On_Vendor_Edit_Order_Option') . '?' }}"
                                            data-off-title="{{ translate('By_Turning_Off_Vendor_Edit_Order_Option') . '?' }}"
                                            data-on-message="<p>{{ translate('If_you_turn_on_this_vendor_will_be_able_to_edit_his_orders') . '.' }}</p>"
                                            data-off-message="<p>{{ translate('If_you_turn_off_this_vendor_will_not_be_able_to_edit_his_orders') . '.' }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction'))
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-12 p-sm-3 user-select-none h-100 bg-white">
                                    <span class="d-flex flex-column">
                                        <span class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                            {{ translate('active_auction_for_vendor') }}
                                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                    data-bs-title=" {{ translate('once_this_feature_is_turned_off,_vendors_cannot_create_new_auctions.') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </span>
                                        <span class="mb-0 fs-12">
                                            {{ translate('enable_to_allow_vendors_to_create_auctions.') }}
                                        </span>
                                    </span>

                                    @php($activeAuctionForVendor = getWebConfig('active_auction_for_vendor') ?? 0)
                                    <label class="switcher" for="active-auction-for-vendor"
                                           @if(getWebConfig(name: 'auction_feature_status') != 1)
                                            data-bs-toggle="tooltip" data-bs-placement="right"
                                            data-bs-title="{{ translate('Please enable the auction feature first to allow vendors to create auctions.') }}"
                                           @endif>
                                        <input
                                            class="switcher_input"
                                            type="checkbox" value="1" name="active_auction_for_vendor"
                                            id="active-auction-for-vendor"
                                            {{ getWebConfig(name: 'auction_feature_status') == 1 && $activeAuctionForVendor == 1 ? 'checked' : '' }}
                                            @if(getWebConfig(name: 'auction_feature_status') != 1)
                                                disabled
                                            @endif
                                            data-modal-type="input-change"
                                            data-on-title="{{ translate('want_to_Turn_ON_Auction_for_Vendor') . '?' }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Auction_for_Vendor') . '?' }}"
                                            data-on-message="<p>{{ translate('if_enabled_vendors_will_be_able_to_create_auctions') . '.' }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_vendors_will_not_be_able_to_create_new_auctions') . '.' }}</p>"
                                            data-on-button-text="{{ translate('turn_on') }}"
                                            data-off-button-text="{{ translate('turn_off') }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="mb-1 text-capitalize">{{ translate('forget_password_setup') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('setup_how_vendors_can_recover_their_forgotten_passwords') }}.
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="form-group mb-20px">
                            <label class="form-label mb-3 text-capitalize" for="">
                                {{ translate('select_verification_option') }}
                            </label>
                            <div class="bg-white p-3 rounded">
                                <div class="row g-4">
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input class="form-check-input radio--input radio--input_lg" type="radio"
                                                name="vendor_forgot_password_method" id="verification_by_email"
                                                value="email"
                                                {{ getWebconfig(name: 'vendor_forgot_password_method') == 'email' ? 'checked' : '' }}>
                                            <div class="flex-grow-1">
                                                <label for="verification_by_email"
                                                    class="form-label text-dark fw-semibold mb-1">
                                                    {{ translate('Email') }}
                                                </label>
                                                <p class="fs-12 mb-2">
                                                    {{ translate('vendor_will_recover_his_password_though_email') }}
                                                </p>
                                                <div
                                                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                    <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                                    <span>
                                                        {{ translate('to_configure_email_for_this_system_visit') }}
                                                        <a href="{{ route('admin.third-party.mail.index') }}" target="_blank"
                                                            class="text-decoration-underline fw-semibold">{{ translate('Email_Configuration') }}</a>
                                                        {{ translate('page') }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input class="form-check-input radio--input radio--input_lg" type="radio"
                                                value="phone" name="vendor_forgot_password_method"
                                                id="verification_by_phone"
                                                {{ getWebconfig(name: 'vendor_forgot_password_method') == 'phone' ? 'checked' : '' }}>
                                            <div class="flex-grow-1">
                                                <label for="verification_by_phone"
                                                    class="form-label text-dark fw-semibold mb-1">
                                                    {{ translate('Phone') }} ({{ translate('OTP') }})
                                                </label>
                                                <p class="fs-12 mb-2">
                                                    {{ translate('vendor_will_recover_his_password_though_otp') }}
                                                </p>
                                                <div
                                                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                                    <i class="fi fi-sr-bulb text-warning fs-16"></i>
                                                    <span>
                                                        {{ translate('to_configure_phone_for_this_system_visit') }}
                                                        <a href="{{ route('admin.third-party.sms-module') }}" target="_blank"
                                                            class="text-decoration-underline fw-semibold">
                                                            {{ translate('OTP_Configuration') }}
                                                        </a>
                                                        {{ translate('page') }}
                                                    </span>
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
    </div>
    @include("layouts.admin.partials.offcanvas._seller-settings")
@endsection
