@extends('layouts.admin.app')

@section('title', translate('order_settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form action="{{ route('admin.business-settings.order-settings.update-order-settings') }}" method="post"
              enctype="multipart/form-data">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3>{{ translate('General_Setup') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('Complete_the_necessary_setup_for_order_process_completion') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            @php($orderVerification = getWebConfig('order_verification'))
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-start gap-3 border rounded p-3 user-select-none h-100 bg-white">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('Order_Delivery_Verification') }}</h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('customers_receive_a_verification_code_after_placing_an_order_deliveryman_must_get_the_code_to_verify_from_the_customer_when_deliver_the_order') }}
                                        </p>
                                    </span>

                                    <label class="switcher" for="order-verification-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="order_verification"
                                            id="order-verification-status"
                                            {{ $orderVerification == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/order-verifications-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/order-verifications-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Order_Delivery_Verification') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Order_Delivery_Verification') }}"
                                            data-on-message="<p>{{ translate('if_enabled_deliverymen_must_verify_the_order_deliveries_by_collecting_the_OTP_from_customers') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_deliverymen_do_not_need_to_verify_the_order_deliveries') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            @php($minimumOrderAmountStatus = getWebConfig('minimum_order_amount_status'))
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between flex-column gap-3 border rounded p-3 user-select-none h-100 bg-white">
                                   <div class="d-flex justify-content-between align-items-start gap-3">
                                        <span>
                                            <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('Vendor_Can_Set_Minimum_Order_Amount') }}
                                            </h5>
                                            <p class="mb-0 fs-12">
                                                {{ translate('set_a_certain_amount_below_that_customer_can_not_place_any_order_for_others_vendor_and_in-house_vendor') }}
                                            </p>
                                        </span>

                                        <label class="switcher" for="minimum-order-amount-status">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="minimum_order_amount_status"
                                                id="minimum-order-amount-status"
                                                {{ $minimumOrderAmountStatus == 1 ? 'checked' : '' }}
                                                data-modal-type="input-change"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/minimum-order-amount-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/minimum-order-amount-off.png') }}"
                                                data-on-title="{{ translate('want_to_turn_on_Vendor_can_set_minimum_order_amount') }}?"
                                                data-off-title="{{ translate('want_to_turn_off_Vendor_can_set_minimum_order_amount') }}?"
                                                data-on-message="<p>{{ translate('turning_on_the_option,_the_vendor_can_set_up_the_minimum_order_amount_for_their_shop_product.') }}</p>"
                                                data-off-message="<p>{{ translate('turning_off_the_option,_the_vendor_can_not_set_up_the_minimum_order_amount_for_their_shop_product.') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                   </div>
                                    <div
                                            class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-12 align-items-center">
                                            <i class="fi fi-sr-bulb text-info fs-16"></i>
                                            <span>{{ translate('for_your_shop_setup_from_this_page') }}
                                                <a href="{{ route('admin.business-settings.inhouse-shop') }}"
                                                   target="_blank" class="text-decoration-underline fw-semibold">
                                                    {{ translate('in_house_shop') }}
                                                </a>.
                                            </span>
                                        </div>
                                </label>
                            </div>
                            @php($billingInputByCustomer = getWebConfig('billing_input_by_customer'))
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-start gap-3 border rounded p-3 user-select-none h-100 bg-white">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('Show_Billing_Address') }}</h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('If_billing_address_is_off,_customer_is_unable_to_order_only_digital_product') }}.
                                        </p>
                                    </span>

                                    <label class="switcher" for="billing-input-by-customer-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="billing_input_by_customer"
                                            id="billing-input-by-customer-status"
                                            {{ $billingInputByCustomer == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/billing-address-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/billing-address-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Billing_Address_in_Checkout') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Billing_Address_in_Checkout') }}"
                                            data-on-message="<p>{{ translate('if_enabled_the_billing_address_will_be_shown_on_the_checkout_page') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_the_billing_address_will_be_hidden_from_the_checkout_page') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            @php($guestCheckout = getWebConfig('guest_checkout'))
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="d-flex justify-content-between align-items-start gap-3 border rounded p-3 user-select-none h-100 bg-white">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">{{ translate('Guest_Checkout') }}</h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('if_enabled_this_option_customers_can_complete_order_checkout_process_without_the_need_to_create_or_log_in_to_an_account') }}.
                                        </p>
                                    </span>
                                    <label class="switcher" for="guest-checkout-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="guest_checkout"
                                            id="guest-checkout-status"
                                            {{ $guestCheckout == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/guest-checkout-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/guest-checkout-off.png') }}"
                                            data-on-title="{{ translate('by_Turning_ON_Guest_Checkout_Mode') }}"
                                            data-off-title="{{ translate('by_Turning_Off_Guest_Checkout_Mode') }}"
                                            data-on-message="<p>{{ translate('user_can_place_order_without_login') }}</p>"
                                            data-off-message="<p>{{ translate('user_cannot_place_order_without_login') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>

                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3>{{ translate('Setup_Free_Delivery') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('configure_free_delivery_offer_for_orders_that_meet_a_specific_order_value') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4">
                            @php($freeDelivery = getWebConfig('free_delivery_status'))
                            <div class="col-12">
                                <label
                                    class="d-flex justify-content-between align-items-center gap-3 border rounded p-3 user-select-none bg-white">
                                    <span>
                                        <h5 class="fw-medium text-dark fs-14 mb-1">
                                            {{ translate('turn_On') }} / {{ translate('Off_Free_Delivery') }}
                                        </h5>
                                        <p class="mb-0 fs-12">
                                            {{ translate('if_enabled_free_delivery_will_be_available_when_customers_order_over_a_certain_amount.') }}
                                        </p>
                                    </span>

                                    <label class="switcher" for="free-delivery-status">
                                        <input
                                            class="switcher_input custom-modal-plugin"
                                            type="checkbox" value="1" name="free_delivery_status"
                                            id="free-delivery-status"
                                            {{ $freeDelivery == 1 ? 'checked' : '' }}
                                            data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/free-delivery-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/free-delivery-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Free_Delivery') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Free_Delivery') }}"
                                            data-on-message="<p>{{ translate('if_enabled_the_free_delivery_feature_will_be_shown_from_the_system') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_the_free_delivery_feature_will_be_hidden_from_the_system') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                            </div>
                            @php($freeDeliveryResponsibility = getWebConfig('free_delivery_responsibility'))
                            <div class="col-lg-6">
                                <div class="form-group">
                                    <label class="form-label" for="free_delivery_responsibility">
                                        {{ translate('Free_Delivery_Responsibility') }}
                                    </label>
                                    <div
                                        class="min-h-40 d-flex align-items-sm-center flex-column flex-sm-row gap-sm-5 border rounded mb-2 px-3 py-1 bg-white" id="free-delivery-responsibility"
                                        data-default="{{ $freeDeliveryResponsibility }}"
                                    >
                                        <div class="form-check d-flex gap-2 my-2">
                                            <input class="form-check-input radio--input" type="radio" value="admin"
                                                   name="free_delivery_responsibility"
                                                   id="free-delivery-responsible-admin"
                                                {{ $freeDeliveryResponsibility == 'admin' ? 'checked' : '' }}>
                                            <label class="form-check-label" for="free-delivery-responsible-admin">
                                                {{ translate('Admin_Responsibility') }}
                                            </label>
                                        </div>
                                        <div class="form-check d-flex gap-2 my-2">
                                            <input class="form-check-input radio--input" type="radio" value="seller"
                                                   name="free_delivery_responsibility"
                                                   id="free-delivery-responsible-vendor"
                                                {{ $freeDeliveryResponsibility == 'seller' ? 'checked' : '' }}>
                                            <label class="form-check-label" for="free-delivery-responsible-vendor">
                                                {{ translate('Vendors_Responsibility') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @php($freeDeliveryOverAmountSeller = getWebConfig('free_delivery_over_amount_seller'))
                            <div class="col-lg-6"
                                 style="{{ $freeDeliveryResponsibility == 'seller' ? 'display:none' : '' }}"
                                 id="free-delivery-over-amount-admin-area">
                                <div class="form-group">
                                    <label class="form-label" for="free_delivery_over_amount_vendor">
                                        {{ translate('Free_Delivery_Over') }}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                        <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                              data-bs-title="{{ translate('free_delivery_over_amount_for_every_vendor_if_they_do_not_set_any_range_yet') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </label>
                                    <input type="number" class="form-control" min="0"
                                           name="free_delivery_over_amount_seller" id="free_delivery_over_amount_vendor"
                                           placeholder="{{ translate('ex') . ': ' . '10' }}"
                                           value="{{ usdToDefaultCurrency($freeDeliveryOverAmountSeller) ?? 0 }}">
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
                    <button type="submit" id="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>

    </div>
    @include("layouts.admin.partials.offcanvas._order-settings")
@endsection

@push('script')
    <script
        src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/business-settings/order-settings.js') }}"></script>
@endpush
