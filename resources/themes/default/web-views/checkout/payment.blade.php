@extends('layouts.front-end.app')

@section('title', translate('choose_Payment_Method'))

@push('css_or_js')
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/payment.css') }}">
@endpush

@section('content')
    <div class="container pb-5 mb-2 mb-md-4 rtl px-0 px-md-3 text-align-direction">
        <div class="row mx-max-md-0">
            <section class="col-lg-8 px-max-md-0 mt-4">
                <div class="checkout_details">
                    <div class="px-3 px-md-0">
                        @include('web-views.partials._checkout-steps', ['step' => 3])
                    </div>
                    <div class="card mt-3">
                        <div class="card-body p-0">

                            @if (!$activeMinimumMethods)
                                <div class="d-flex justify-content-center py-3">
                                    <div class="text-center">
                                        <img src="{{ theme_asset(path: 'public/assets/front-end/img/icons/nodata.svg') }}"
                                             alt="" class="mb-4" width="70">
                                        <h5 class="fs-14 text-muted">
                                            {{ translate('payment_methods_are_not_available_at_this_time.') }}</h5>
                                    </div>
                                </div>
                            @else
                                @if (($cashOnDeliveryBtnShow && $cash_on_delivery['status']) || (auth('customer')->check() && $wallet_status == 1))
                                    <div class="gap-2 bg-light py-3 px-3">
                                        <div class="d-flex justify-content-between">
                                            <h5 class="mb-0 text-nowrap">{{ translate('payment_method') }}</h5>
                                            <a href="{{ route('checkout-details') }}"
                                               class="d-flex align-items-center gap-2 text-primary font-weight-bold text-nowrap">
                                                <i class="tio-back-ui fs-12 text-capitalize"></i>
                                                {{ translate('go_back') }}
                                            </a>
                                        </div>
                                    </div>
                                @endif

                                <div class="p-20">
                                    @if (
                                        ($cashOnDeliveryBtnShow && $cash_on_delivery['status']) ||
                                            $digital_payment['status'] == 1 ||
                                            (auth('customer')->check() && $wallet_status == 1))
                                        @if (($cashOnDeliveryBtnShow && $cash_on_delivery['status']) || (auth('customer')->check() && $wallet_status == 1))
                                            <p class="text-capitalize mt-0">
                                                {{ translate('select_a_payment_method_to_proceed') }}</p>

                                            <div class="d-flex flex-sm-nowrap flex-wrap w-100 gap-3 mb-3">
                                                @if ($cashOnDeliveryBtnShow && $cash_on_delivery['status'])
                                                    <div id="cod-for-cart" class="w-100 h-100 cod-for-cart">
                                                        <div class="card cursor-pointer">
                                                            <form action="{{ route('checkout-complete') }}" method="post"
                                                                  class="needs-validation" id="cash_on_delivery_form">
                                                                @csrf
                                                                <label class="m-0 pt-2 pb-1">
                                                                    <input type="hidden" name="payment_method"
                                                                           value="cash_on_delivery" checked>
                                                                    <input type="hidden" class="form-control"
                                                                           name="bring_change_amount"
                                                                           id="bring_change_amount_value">
                                                                    <span
                                                                        class="btn btn-block click-if-alone py-3 d-flex gap-2 align-items-center cursor-pointer">
                                                                        <input type="radio" id="cash_on_delivery"
                                                                               class="custom-radio" checked>
                                                                        <img width="20"
                                                                             src="{{ theme_asset(path: 'public/assets/front-end/img/icons/money.png') }}"
                                                                             alt="">
                                                                        <span class="fs-12">
                                                                            {{ translate('cash_on_Delivery') }}
                                                                        </span>
                                                                    </span>
                                                                </label>
                                                            </form>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if (auth('customer')->check() && $wallet_status == 1)
                                                    <div class="w-100 h-100">
                                                        <div class="card cursor-pointer">
                                                            <div class="btn btn-block click-if-alone d-flex justify-content-between gap-2 align-items-center">
                                                                <div class="d-flex gap-2 align-items-start">
                                                                    <img width="20"
                                                                         src="{{ theme_asset(path: 'public/assets/front-end/img/icons/wallet-sm.png') }}"
                                                                         alt="" />
                                                                    <span class="fs-12 text-start">
                                                                        {{ translate('pay_via_Wallet') }} <br>
                                                                        <span class="fs-18 fw-semibold text-dark">{{ webCurrencyConverter(amount:auth('customer')->user()?->wallet_balance ?? 0 ) }}</span>
                                                                    </span>
                                                                </div>
                                                                <button type="button"  data-toggle="modal"
                                                                data-target="#wallet_submit_button" class="btn btn-outline-primary">
                                                                    {{ translate('APPLY') }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>

                                            @if ($cashOnDeliveryBtnShow && $cash_on_delivery['status'])
                                                <div class="bring_change_amount_section">
                                                    <div class="collapse show mb-10px" id="bring_change_amount"
                                                         data-more="{{ translate('See_More') }}"
                                                         data-less="{{ translate('See_Less') }}">
                                                        <div
                                                            class="bring_change_amount_details row justify-content-start align-items-center rounded-10 g-2 px-3 py-12">
                                                            <div class="col-sm-6">
                                                                <h6 class="fs-12 mb-1 fw-bold">
                                                                    {{ translate('Bring_Change_Instruction') }}
                                                                </h6>
                                                                <p class="mb-0 fs-12 opacity-50">
                                                                    {{ translate('Insert_amount_if_you_need_deliveryman_to_bring') }}
                                                                </p>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <label class="fs-12 fw-bold" for="">
                                                                    {{ translate('Change_Amount') }}
                                                                    ({{ getCurrencySymbol(type: 'web') }})
                                                                </label>
                                                                <input type="text"
                                                                       class="form-control max-w-210px only-integer-input-field"
                                                                       id="bring_change_amount_input"
                                                                       placeholder="{{ translate('Amount') }}">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="text-center mb-10px">
                                                        <a id="bring_change_amount_btn"
                                                           class="btn text-center text-capitalize text--primary fs-12 p-0"
                                                           data-toggle="collapse" href="#bring_change_amount" role="button"
                                                           aria-expanded="false" aria-controls="change_amount">
                                                            {{ translate('See_Less') }}
                                                        </a>
                                                    </div>
                                                </div>
                                            @endif

                                        @endif

                                    @endif

                                    <div class="bg-primary-light rounded p-4 mb-20">
                                        @if (($digital_payment['status'] == 1 && count($payment_gateways_list) > 0) ||
                                                    (isset($offline_payment) && $offline_payment['status'] && count($offline_payment_methods) > 0))
                                            <div class="gap-2 mb-4">
                                                <div class="d-flex justify-content-between">
                                                    <div class="d-flex align-items-end gap-2">
                                                        <h5 class="mb-0 text-nowrap">
                                                            {{ translate('pay_via_online') }}
                                                        </h5>
                                                        <span class="fs-10 text-capitalize mt-1">
                                                            ({{ translate('faster_&_secure_way_to_pay') }})
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($digital_payment['status'] == 1)
                                            <div class="row gx-4">
                                                @foreach ($payment_gateways_list as $payment_gateway)
                                                    @php($additionalData = $payment_gateway['additional_data'] != null ? json_decode($payment_gateway['additional_data']) : [])
                                                        <?php
                                                        $gatewayImgPath = dynamicAsset(path: 'public/assets/back-end/img/modal/payment-methods/' . $payment_gateway->key_name . '.png');
                                                        if ($additionalData != null && $additionalData?->gateway_image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image))) {
                                                            $gatewayImgPath = $additionalData->gateway_image ? dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image) : $gatewayImgPath;
                                                        }
                                                        ?>


                                                    <div class="col-sm-6">
                                                        <form method="post" class="digital_payment"
                                                              id="{{ $payment_gateway->key_name }}_form"
                                                              action="{{ route('customer.web-payment-request') }}">
                                                            @csrf
                                                            <input type="hidden" name="user_id"
                                                                   value="{{ auth('customer')->check() ? auth('customer')->user()->id : session('guest_id') }}">
                                                            <input type="hidden" name="customer_id"
                                                                   value="{{ auth('customer')->check() ? auth('customer')->user()->id : session('guest_id') }}">
                                                            <input type="hidden" name="payment_method"
                                                                   value="{{ $payment_gateway->key_name }}">
                                                            <input type="hidden" name="payment_platform" value="web">

                                                            @if ($payment_gateway->mode == 'live' && isset($payment_gateway->live_values['callback_url']))
                                                                <input type="hidden" name="callback"
                                                                       value="{{ $payment_gateway->live_values['callback_url'] }}">
                                                            @elseif ($payment_gateway->mode == 'test' && isset($payment_gateway->test_values['callback_url']))
                                                                <input type="hidden" name="callback"
                                                                       value="{{ $payment_gateway->test_values['callback_url'] }}">
                                                            @else
                                                                <input type="hidden" name="callback" value="">
                                                            @endif

                                                            <input type="hidden" name="external_redirect_link"
                                                                   value="{{ route('web-payment-success') }}">
                                                            <label
                                                                class="d-flex align-items-center px-0 gap-2 mb-0 form-check py-2 cursor-pointer">
                                                                <input type="radio" id="{{ $payment_gateway->key_name }}"
                                                                       name="online_payment" class="form-check-input custom-radio"
                                                                       value="{{ $payment_gateway->key_name }}">
                                                                <img width="30"
                                                                     src="{{ $gatewayImgPath}}"
                                                                     alt="">
                                                                <span class="text-capitalize form-check-label">
                                                                    @if ($payment_gateway->additional_data && json_decode($payment_gateway->additional_data)->gateway_title != null)
                                                                        {{ json_decode($payment_gateway->additional_data)->gateway_title }}
                                                                    @else
                                                                        {{ str_replace('_', ' ', $payment_gateway->key_name) }}
                                                                    @endif
                                                                </span>
                                                            </label>
                                                        </form>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    @if (isset($offline_payment) && $offline_payment['status'] && count($offline_payment_methods) > 0)
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="bg-primary-light rounded p-4">
                                                    <div
                                                        class="d-flex justify-content-between align-items-center gap-2 position-relative">
                                                        <span class="d-flex align-items-center gap-3">
                                                            <input type="radio" id="pay_offline" name="online_payment"
                                                                   class="custom-radio" value="pay_offline">
                                                            <label for="pay_offline"
                                                                   class="cursor-pointer d-flex align-items-center gap-2 mb-0 text-capitalize">{{ translate('pay_offline') }}</label>
                                                        </span>

                                                        <div data-toggle="tooltip"
                                                             title="{{ translate('for_offline_payment_options,_please_follow_the_steps_below') }}">
                                                            <i class="tio-info text-primary"></i>
                                                        </div>
                                                    </div>

                                                    <div class="mt-4 pay_offline_card d-none">
                                                        <div class="d-flex flex-wrap gap-3">
                                                            @foreach ($offline_payment_methods as $method)
                                                                <button type="button"
                                                                        class="btn btn-light offline_payment_button text-capitalize"
                                                                        id="{{ $method->id }}">{{ $method->method_name }}</button>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>

                            @endif

                        </div>
                    </div>
                </div>
            </section>
            @include('web-views.partials._order-summary')
        </div>
    </div>

    @if (isset($offline_payment) && $offline_payment['status'])
        <div class="modal fade" id="selectPaymentMethod" tabindex="-1" aria-labelledby="selectPaymentMethodLabel"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered  modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0 pb-0">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form action="{{ route('offline-payment-checkout-complete') }}" method="post"
                              class="needs-validation form-loading-button-form">
                            @csrf
                            <div class="d-flex justify-content-center mb-4">
                                <img width="52"
                                     src="{{ theme_asset(path: 'public/assets/front-end/img/select-payment-method.png') }}"
                                     alt="">
                            </div>
                            <p class="fs-14 text-center">
                                {{ translate('pay_your_bill_using_any_of_the_payment_method_below_and_input_the_required_information_in_the_form') }}
                            </p>

                            <select class="form-control mx-xl-5 max-width-661" id="pay_offline_method" name="payment_by"
                                    required>
                                <option value="" disabled>{{ translate('select_Payment_Method') }}</option>
                                @foreach ($offline_payment_methods as $method)
                                    <option value="{{ $method->id }}">{{ translate('payment_Method') }} :
                                        {{ $method->method_name }}</option>
                                @endforeach
                            </select>
                            <div class="" id="payment_method_field">
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (auth('customer')->check() && $wallet_status == 1)
        <div class="modal fade" id="wallet_submit_button" aria-labelledby="exampleModalLongTitle" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLongTitle">{{ translate('wallet_payment') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    @php($customer_balance = auth('customer')->user()->wallet_balance)
                    @php($couponAmount = session()->has('coupon_discount') ? session('coupon_discount') : 0)
                    @php($totalAmount = $amount)
                    @php($remain_balance = $customer_balance - $totalAmount)
                    <form action="{{ route('checkout-complete-wallet') }}" method="post" class="needs-validation">
                        @csrf
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group col-12">
                                    <label for="">{{ translate('your_current_balance') }}</label>
                                    <input class="form-control" type="text"
                                           value="{{ webCurrencyConverter(amount: $customer_balance ?? 0) }}" readonly>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-12">
                                    <label for="">{{ translate('order_amount') }}</label>
                                    <input class="form-control" type="text"
                                           value="{{ webCurrencyConverter(amount: $totalAmount ?? 0) }}" readonly>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-12">
                                    <label for="">{{ translate('remaining_balance') }}</label>
                                    <input class="form-control" type="text"
                                           value="{{ webCurrencyConverter(amount: $remain_balance ?? 0) }}" readonly>
                                    @if ($remain_balance < 0)
                                        <label
                                            class="__color-crimson mt-1">{{ translate('you_do_not_have_sufficient_balance_for_pay_this_order!!') }}</label>
                                    @endif
                                </div>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                    data-dismiss="modal">{{ translate('close') }}</button>
                            {{-- >= 0: a balance exactly equal to the total is payable, the server
                                 accepts it, and > 0 wrongly disabled the button for that case. --}}
                            <button type="submit" class="btn btn--primary"
                                {{ $remain_balance >= 0 ? '' : 'disabled' }}>{{ translate('submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <span id="route-action-checkout-function" data-route="checkout-payment"></span>
@endsection

@push('script')
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/payment.js') }}"></script>
@endpush
