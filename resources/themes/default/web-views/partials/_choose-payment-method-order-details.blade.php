@php($customer_balance = auth('customer')->user()?->wallet_balance ?? 0)
@php($couponAmount = session()->has('coupon_discount') ? session('coupon_discount') : 0)
@php($totalAmount = $order['order_amount'] - $couponAmount)
@php($remain_balance = $customer_balance - $order['edit_due_amount'])
@php($walletInsufficient = ($customer_balance ?? 0) < ($order['edit_due_amount'] ?? 0))
@php($isPhysicalProduct = $order->details()->whereHas('product', fn ($q) => $q->where('product_type', 'physical'))->exists())
<div class="modal fade z-1049 order-choose-payment-method-modal" id="choosePaymentMethodModal-{{ $order['id'] }}"
     tabindex="-1"
     aria-labelledby="choosePaymentMethodModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="cash-on-delivery-section">
                <form action="{{ route('customer.customer-order-edit-pay-amount') }}" method="POST"
                      class="needs-validation" id="cash_on_delivery_form">
                    @csrf
                    <input type="hidden" name="payment_method" value="cash_on_delivery" checked>
                    <input type="hidden" name="order_id" value="{{ $order['id'] }}">
                    <input type="hidden" name="payment_platform" value="web">
                    <input type="hidden" name="external_redirect_link" value="{{ route('web-payment-success') }}">
                    <div class="modal-header border-0 px-2 pt-2 pb-0 d-block">
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn bg-light text-muted fs-12 btn-circle p-0 lh-1"
                                    style="--size: 32px;" data-dismiss="modal" aria-label="Close">
                                <i class="fi fi-sr-cross d-flex"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-body pt-0">
                        <div>
                            <div class="text-center mb-3">
                                <h4 class="fs-18 fw-bold text-center mb-2">
                                    {{ translate('Choose_Payment_Method') }}
                                </h4>
                                <h6 class="text-muted mb-2">{{ translate('Due Bill') }}</h6>
                                <h3 class="fs-22">{{ webCurrencyConverter(amount: $order?->edit_due_amount ?? 0 ) }}</h3>
                            </div>

                            <div class="d-flex flex-sm-nowrap flex-wrap w-100 gap-3 mb-3">
                                @if($isPhysicalProduct && $cashOnDeliveryStatus)
                                    <div class="w-100 h-100">
                                        <div type="button" id="cod-for-cart"
                                             class="card cursor-pointer payment-method-active cod-for-cart">
                                            <label class="m-0">
                                                <span
                                                    class="btn btn-block click-if-alone d-flex gap-2 align-items-center">
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
                                @if(getWebConfig(name: 'wallet_status') == 1)
                                    <div class="w-100 h-100">
                                        <div type="button"
                                             class="card pay-via-wallet {{ $walletInsufficient ? 'wallet-disabled' : '' }}" {!! $walletInsufficient? 'data-toggle="tooltip" data-placement="top" title="' . translate('Insufficient wallet balance') . '"'  : '' !!}>
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
                                        class="bring_change_amount_details row justify-content-start align-items-center rounded-10 g-2 px-3 py-12">
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
                            @if(getWebConfig(name: 'wallet_status') == 1)
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
                            @if(getWebConfig(name: 'digital_payment')['status'])
                                <div class="bg-white border rounded p-3 mb-20">
                                    <h5 class="fs-14 mb-0 text-nowrap fw-semibold text-dark">
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
                                                       class="cursor-pointer d-flex align-items-center gap-2 mb-0 text-capitalize fw-semibold text-dark">{{ translate('Pay_offline') }}</label>
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
                    </div>
                    <div class="modal-footer">
                        <div class="d-flex justify-content-center align-items-center w-100">
                            <button type="submit"
                                    class="btn btn--primary w-100 payment-proceed-btn">{{ translate('Proceed') }}</button>
                        </div>
                    </div>
                </form>
            </div>
            @if (isset($offlinePaymentMethods) && $offlinePaymentStatus['status'])
                <div class="offline-payment-section offline-payment-section-loader d-none">
                    <div class="modal-header border-0 px-2 pt-2 pb-0">
                        <div class="w-100 d-flex justify-content-between align-items-start">
                            <button type="button"
                                    class="btn btn-link text-primary fw-semibold fs-18 lh-1 back-to-order back-to-cod">
                                <i class="fi fi-sr-angle-left"></i> {{ translate('Go_Back') }}
                            </button>
                            <button type="button" class="btn bg-light text-muted fs-12 btn-circle p-0 lh-1"
                                    style="--size: 32px;" data-dismiss="modal" aria-label="Close">
                                <i class="fi fi-sr-cross d-flex"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-body pt-0">
                        <form action="{{ route('customer.customer-order-edit-pay-amount') }}" method="post"
                              class="needs-validation form-loading-button-form">
                            @csrf
                            <input type="hidden" name="payment_method" value="offline">
                            <input type="hidden" name="order_id" value="{{ $order['id'] }}">
                            <input type="hidden" name="payment_platform" value="web">
                            <div class="d-flex justify-content-center mb-2">
                                <img width="52"
                                     src="{{ theme_asset(path: 'public/assets/front-end/img/select-payment-method.png') }}"
                                     alt="">
                            </div>
                            <p class="fs-14 text-center">
                                {{ translate('pay_your_bill_using_any_of_the_payment_method_below_and_input_the_required_information_in_the_form') }}
                            </p>

                            <select class="form-control mx-xl-5 max-width-661 pay_offline_method"
                                    id="pay_offline_method_{{ $order['id'] }}"
                                    name="payment_by"
                                    data-edit-due="{{ $order['edit_due_amount'] }}"
                                    data-order-id="{{ $order['id'] }}"
                                    required>
                                <option value="" disabled selected>{{ translate('select_Payment_Method') }}</option>
                                @foreach ($offlinePaymentMethods as $method)
                                    <option value="{{ $method->id }}">{{ translate('payment_Method') }} :
                                        {{ $method->method_name }}</option>
                                @endforeach
                            </select>
                            <div id="payment_method_field_{{ $order['id'] }}"
                                 class="payment-method-content-loader"></div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
