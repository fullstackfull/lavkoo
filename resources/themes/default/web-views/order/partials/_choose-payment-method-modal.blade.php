@php($customer_balance = auth('customer')->user()?->wallet_balance ?? 0)
@php($couponAmount = session()->has('coupon_discount') ? session('coupon_discount') : 0)
@php($totalAmount = $order['order_amount'] - $couponAmount)
@php($remain_balance = $customer_balance - $order['edit_due_amount'])
@php($walletInsufficient = ($customer_balance ?? 0) < ($order['edit_due_amount'] ?? 0))
<form action="{{ route('customer.customer-order-edit-pay-amount') }}" method="POST"
      class="needs-validation" id="cash_on_delivery_form">
    @csrf
    <div class="d-flex justify-content-start">
        <button type="button" class="btn btn-link text-primary p-0 fw-semibold fs-16 lh-1 back-to-order">
            <i class="fi fi-sr-angle-left"></i> {{ translate('Go_Back') }}
        </button>
    </div>
    <div>
        <div class="text-center mb-3">
            <h4 class="fs-18 fw-bold text-center mb-2">{{ translate('Choos_Payment_Method') }}</h4>
            <h6 class="text-muted mb-2">{{ translate('Due Bill') }}</h6>
            <h3 class="fs-22">{{ webCurrencyConverter(amount: $order?->edit_due_amount ?? 0 ) }}</h3>
        </div>

        <div class="d-flex flex-sm-nowrap flex-wrap w-100 gap-3 mb-3">
            @if($isPhysicalProduct && $cashOnDeliveryStatus)
                <div class="w-100 h-100">
                    <div type="button" id="cod-for-cart"
                         class="card cursor-pointer payment-method-active cod-for-cart">
                        <label class="m-0">
                            <input type="hidden" name="payment_method" value="cash_on_delivery"
                                   checked="">
                            <span
                                class="btn btn-block click-if-alone d-flex gap-2 align-items-center">
                                    <input type="hidden" id="cash_on_delivery" class="custom-radio" checked="">
                                    <img width="20"
                                         src="{{theme_asset(path: 'public/assets/front-end/img/icons/money.png')}}"
                                         alt="">
                                    <span class="fs-14 fw-semibold">
                                       {{ translate('Cash_on_Delivery') }}
                                    </span>
                                </span>
                        </label>
                    </div>
                </div>
            @endif
            @if(getWebConfig(name: 'wallet_status') == 1 && $order['is_guest'] != 1  && auth('customer')->check() && $order['customer_id'] == auth('customer')->id())
                <div class="w-100 h-100">
                    <div
                        class="card cursor-pointer pay-via-wallet ">
                        <div
                            class="btn btn-block click-if-alone d-flex justify-content-between gap-2 align-items-center">
                            <div class="d-flex gap-2 align-items-start">
                                <img width="20"
                                     src="{{theme_asset(path: 'public/assets/front-end/img/icons/wallet-sm.png')}}"
                                     alt="">
                                <span class="fs-14 fw-semibold">
                            {{ translate('Pay_via_Wallet') }}
                            </span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="bring_change_amount_section">
            <div class="collapse show mb-10px" id="bring_change_amount" data-more="See More"
                 data-less="See Less">
                <div
                    class="bring_change_amount_details row justify-content-start align-items-center rounded-10 g-2 px-3 py-12 m-0">
                    <div class="col-12">
                        <label class="fs-12 fw-bold mb-1" for="">
                            {{ translate('Change Amount') }} ($)
                        </label>
                        <p class="text-muted fs-12 mb-2">{{ translate('insert_amount_of_you_need_deliveryman_to_bring') }}</p>
                        <input type="text" class="form-control only-integer-input-field"
                               name="bring_change_amount_input"
                               id="bring_change_amount_input" placeholder="Amount">
                    </div>
                </div>
            </div>
            <div class="text-center mb-10px">
                <a id="bring_change_amount_btn"
                   class="btn text-center text-capitalize text--primary fs-12 p-0"
                   data-toggle="collapse" href="#bring_change_amount" role="button"
                   aria-expanded="false"
                   aria-controls="change_amount">
                    {{ translate('See_Less') }}
                </a>
            </div>
        </div>
        @if ($walletStatus == 1)
            <div id="wallet-info-section" class="full-width mx-auto fs-12 text-primary d-none wallet-info-section">
                <div class="mb-3 text-center">
                    {{ translate('You’re paying') }}
                    <strong>{{ webCurrencyConverter(amount: $order['edit_due_amount'] ?? 0) }}</strong>
                    {{ translate('from your wallet.') }}
                    {{ translate('Remaining wallet balance') }}
                    <strong>
                        {{ webCurrencyConverter(amount: $remain_balance ?? 0) }}
                    </strong>
                </div>
            </div>
        @endif
        @if($digitalPayment)
            <div class="bg-white border rounded p-3 mb-20">
                <h5 class="fs-14 mb-0 text-nowrap text-dark fw-semibold">
                    {{ translate('Pay_via_online') }}
                </h5>

                <div class="row g-2 mt-4">
                    @foreach ($paymentGatewayList as $payment_gateway)
                        @php($additionalData = $payment_gateway['additional_data'] != null ? json_decode($payment_gateway['additional_data']) : [])
                            <?php
                            $gatewayImgPath = dynamicAsset(path: 'public/assets/back-end/img/modal/payment-methods/' . $payment_gateway->key_name . '.png');
                            if ($additionalData != null && $additionalData?->gateway_image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image))) {
                                $gatewayImgPath = $additionalData->gateway_image ? dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image) : $gatewayImgPath;
                            }
                            ?>
                        <div class="col-12">


                            <input type="hidden" name="external_redirect_link"
                                   value="{{ route('web-payment-success') }}">
                            <input type="hidden" name="payment_platform"
                                   value="web">
                            <input type="hidden" value="{{ $order['id'] }}" name="order_id">
                            <label
                                class="d-flex align-items-center justify-content-between px-0 gap-2 mb-0 form-check py-2 cursor-pointer">
                                <div class="flex-grow-1">
                                    <img width="30"
                                         class="max-w-100px img-fit"
                                         src="{{ $gatewayImgPath }}"
                                         alt="">
                                    <span class="text-capitalize form-check-label">
                                        @if ($payment_gateway->additional_data && json_decode($payment_gateway->additional_data)->gateway_title != null)
                                            {{ json_decode($payment_gateway->additional_data)->gateway_title }}
                                        @else
                                            {{ str_replace('_', ' ', $payment_gateway->key_name) }}
                                        @endif
                                    </span>
                                </div>
                                <input type="radio" id="{{ $payment_gateway->key_name }}"
                                       name="payment_method"
                                       class="form-check-input custom-radio flex-shrink-0"
                                       value="{{ $payment_gateway->key_name }}">
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if (isset($offlinePaymentMethods) && $offlinePaymentStatus['status'])
            <div class="row g-3">
                <div class="col-12">
                    <div class="bg-white border rounded p-3">
                        <div
                            class="d-flex justify-content-between align-items-center gap-2 position-relative">
                            <label for="pay_offline"
                                   class="cursor-pointer d-flex align-items-center gap-2 mb-0 text-capitalize text-dark fw-semibold">{{ translate('Pay_offline') }}</label>
                            <input type="radio" id="pay_offline" name="online_payment"
                                   class="custom-radio pay_offline"
                                   value="pay_offline">
                        </div>

                        <div class="mt-4 pay_offline_card d-none">
                            <div class="d-flex flex-wrap gap-3">
                                @foreach ($offlinePaymentMethods as $method)
                                    <button type="button"
                                            class="btn btn-outline-primary border text-dark offline_payment_method_button text-capitalize"
                                            id="{{ $method->id }}"
                                            data-order-id="{{ $order['id'] }}"
                                            data-edit-due-amount="{{ $order['edit_due_amount'] }}"
                                    >
                                        {{ $method->method_name }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
    <div class="d-flex justify-content-center align-items-center w-100 mt-4">
        <button type="submit" class="btn btn--primary w-100 payment-proceed-btn">{{ translate('Proceed') }}</button>
    </div>
</form>


