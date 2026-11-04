<div class="modal fade" id="modal-participate-entry-info" tabindex="-1" aria-labelledby="modal-participate-entry-infoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content p-3 rounded-4">
            <div class="modal-header border-0 justify-content-center">
                <h1 class="modal-title fs-18 fw-semibold title-clr text-center">
                    {{ translate('Pay Entry Fee to Bid') }}
                </h1>
                <button type="button" class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fi fi-rr-cross-small"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="light-box p-4 text-center rounded">
                    <div class="box p-lg-1">
                        <h3 class="fw-bold mb-2">
                            {{ webCurrencyConverter(amount: $entryFeeAmount) }}
                        </h3>
                        <p class="fs-13 pragraph-clr mb-0">
                            {{ translate('Complete the entry fee payment to continue bidding.') }}
                            {{ translate('Entry fee is not refundable.') }}
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-center border-0">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-participate-entry-payment">
                    {{ translate('Pay Entry Fee') }}
                </button>
            </div>
        </div>
    </div>
</div>

<form action="{{ route('auction.participate.payment') }}" method="POST" id="auction-entry-fee-form">
    @csrf
    <input type="hidden" name="auction_product_id" value="{{ $auctionProduct->id }}">
    <input type="hidden" name="payment_platform" value="web">
    @if(isset($entry_fee_redirect))
        <input type="hidden" name="external_redirect_link" value="{{ $entry_fee_redirect }}">
    @else
        <input type="hidden" name="external_redirect_link" value="{{ route('auction.participate.payment.success', ['slug' => $auctionProduct->slug]) }}">
    @endif

    <input type="radio" name="payment_method" id="payment_method_wallet" value="wallet" class="d-none">

    <div class="modal fade" id="modal-participate-entry-payment" tabindex="-1" aria-labelledby="modal-participate-entry-paymentLebel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0 justify-content-between pe-4">
                <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#modal-participate-entry-info" href="#">
                    <i class="fi fi-rr-angle-left fs-12"></i>
                    {{ translate('Go Back')}}
                </a>
                <h1 class="modal-title fs-18 fw-semibold title-clr text-center pe-4">
                    {{ translate('Choose Payment Method') }}
                </h1>
                <div></div>
                <button type="button" class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fi fi-rr-cross-small"></i>
                </button>
            </div>
            @php
                $walletBalance = Auth::guard('customer')->check() ? (float) Auth::guard('customer')->user()->wallet_balance : 0;
                $walletRemainingBalance = max(0, $walletBalance - $entryFeeAmount);
                $walletCoversAll = $walletBalance >= $entryFeeAmount;
                $amountPaidByWallet = min($walletBalance, $entryFeeAmount);
            @endphp
            <div class="modal-body pt-1 mx-sm-3">
                <div class="modal-body-scrolling">
                    <div class="mb-20 text-center">
                        <p class="fs-13 title-semidark mb-1">
                            {{ translate('Auction Entry Fee') }}
                        </p>
                        <h3 class="fw-bold fs-20 mb-0">
                            {{ webCurrencyConverter(amount: $entryFeeAmount) }}
                        </h3>
                    </div>

                    @if(Auth::guard('customer')->check() && getWebConfig(name: 'wallet_status') == 1)
                        @if($walletBalance > 0)
                            <div class="border rounded p-3 d-flex align-items-center justify-content-between mb-20" id="wallet-balance-section">
                                <div class="">
                                    <p class="fs-10 title-semidark mb-1">
                                        {{ translate('Wallet Balance') }}
                                    </p>
                                    <h3 class="fw-bold fs-18 mb-0">
                                        {{ webCurrencyConverter(amount: $walletBalance) }}
                                    </h3>
                                </div>
                                <button type="button" id="apply-wallet-btn" class="btn btn-outline-primary fw-semibold fs-12 py-2">
                                    {{ translate('Apply') }}
                                </button>
                            </div>

                            <div class="border rounded p-3 d-flex align-items-center justify-content-between mb-20 d-none" id="wallet-applied-section">
                                <div class="">
                                    <p class="fs-10 title-semidark mb-1">
                                        {{ translate('Wallet Remaining Balance') }}
                                    </p>
                                    <h3 class="fw-bold d-flex align-items-center gap-2px fs-18 mb-0">
                                        {{ webCurrencyConverter(amount: $walletRemainingBalance) }}
                                        <span class="text-primary fs-12 fw-normal">({{ translate('Applied') }})</span>
                                        <input type="hidden" name="payment_method" value="wallet">
                                    </h3>
                                </div>
                                <button type="button" id="remove-wallet-btn" class="btn p-0 m-0 outline-0 fs-24 text-danger">
                                    <i class="fi fi-rr-cross-small"></i>
                                </button>
                            </div>

                            <div class="mb-20 d-none" id="paid-by-wallet-section">
                                <div class="border light-box rounded p-3 d-flex align-items-center justify-content-between">
                                    <div class="">
                                        <label class="fw-bold fs-14 title-clr mb-0">{{ translate('Paid By Wallet') }}</label>
                                    </div>
                                    <h4 class="fs-20 fw-semibold title-clr mb-0">
                                        {{ webCurrencyConverter(amount: $amountPaidByWallet) }}
                                    </h4>
                                </div>
                                @if(!$walletCoversAll)
                                    <p class="text-center fs-14 text-danger mb-0 mt-10px">* {{ translate('Please select an option to pay the rest of the amount') }}</p>
                                @endif
                            </div>
                        @else
                            <div class="border rounded p-3 d-flex align-items-center justify-content-between mb-20 opacity-50"
                                 data-bs-toggle="tooltip"
                                 title="{{ translate('Insufficient_wallet_balance') }}">
                                <div class="d-flex gap-2 align-items-center">
                                    <img width="20"
                                         src="{{ theme_asset(path: 'public/assets/front-end/auction/images/icons/wallet.svg') }}"
                                         alt="">
                                    <span class="fs-14 fw-semibold title-clr">{{ translate('Pay_via_Wallet') }}</span>
                                </div>
                                <span class="fs-12 text-danger">{{ translate('Insufficient_balance') }}</span>
                            </div>
                        @endif
                    @endif

                    @if(isset($offline_payment) && $offline_payment['status'] && count($offline_payment_methods) > 0)
                        <div class="border rounded p-3 mb-20">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span class="d-flex align-items-center gap-3">
                                    <input type="radio" id="auction_pay_offline" name="payment_method"
                                           class="form-check-input form-check-input_theme" value="offline_payment">
                                    <label for="auction_pay_offline" class="fw-bold fs-14 title-clr mb-0">
                                        {{ translate('Pay Offline') }}
                                    </label>
                                </span>
                                <div data-toggle="tooltip" title="{{ translate('for_offline_payment_options,_please_follow_the_steps_below') }}">
                                    <i class="fi fi-rr-info text-primary"></i>
                                </div>
                            </div>
                            <div class="mt-3 auction-pay-offline-card d-none">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($offline_payment_methods as $method)
                                        <button type="button"
                                                class="btn btn-light auction-offline-payment-btn text-capitalize"
                                                data-method-id="{{ $method->id }}"
                                                data-amount="{{ $entryFeeAmount }}">
                                            {{ $method->method_name }}
                                        </button>
                                    @endforeach
                                </div>
                                <div id="auction_payment_method_field"></div>
                            </div>
                        </div>
                    @endif

                    @if(isset($digital_payment) && $digital_payment['status'] == 1 && count($payment_gateways_list) > 0)
                        <div class="border rounded p-3">
                            <div class="d-flex flex-column gap-xxl-4 gap-3">
                                <h6 class="fw-bold fs-14 title-clr mb-0">{{ translate('Pay Via Online') }}</h6>
                                @foreach($payment_gateways_list as $payment_gateway)
                                    @php
                                        $additionalData = $payment_gateway['additional_data'] != null ? json_decode($payment_gateway['additional_data']) : [];
                                        $gatewayImgPath = dynamicAsset(path: 'public/assets/back-end/img/modal/payment-methods/' . $payment_gateway->key_name . '.png');
                                        if ($additionalData != null && $additionalData?->gateway_image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image))) {
                                            $gatewayImgPath = $additionalData->gateway_image ? dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $additionalData->gateway_image) : $gatewayImgPath;
                                        }
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <label class="d-flex align-items-center gap-1 fs-13 title-clr" for="{{ $payment_gateway->key_name }}">
                                            <img src="{{ $gatewayImgPath }}" alt="" width="30">
                                            <span class="text-capitalize">
                                                @if($payment_gateway->additional_data && json_decode($payment_gateway->additional_data)->gateway_title != null)
                                                    {{ json_decode($payment_gateway->additional_data)->gateway_title }}
                                                @else
                                                    {{ str_replace('_', ' ', $payment_gateway->key_name) }}
                                                @endif
                                            </span>
                                        </label>
                                        <div class="form-check">
                                            <input class="form-check-input form-check-input_theme" type="radio" name="payment_method" id="{{ $payment_gateway->key_name }}" value="{{ $payment_gateway->key_name }}">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer shadow-sm justify-content-center border-0">
                <button type="submit" id="auction-entry-process-btn" class="btn btn-primary w-100" disabled>{{ translate('Process') }}</button>
            </div>
        </div>
    </div>
</div>
</form>

<form action="{{ route('auction.participate.payment') }}" method="POST" id="auction-offline-payment-form">
    @csrf
    <div class="modal fade" id="modal-auction-offline-payment" tabindex="-1" aria-labelledby="modal-auction-offline-paymentLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered  modal-dialog-scrollable modal-lg">
            <div class="modal-content rounded-4">
                <div class="modal-header border-0 justify-content-between pe-4">
                    <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                    href="#" id="auction-offline-go-back">
                        <i class="fi fi-rr-angle-left fs-12"></i>
                        {{ translate('Go Back') }}
                    </a>
                    <h1 class="modal-title fs-18 fw-semibold title-clr text-center">
                        {{ translate('Offline Payment') }}
                    </h1>
                    <div></div>
                    <button type="button" class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fi fi-rr-cross-small"></i>
                    </button>
                </div>
                <div class="modal-body pt-1 mx-sm-3">
                    <input type="hidden" name="auction_product_id" value="{{ $auctionProduct->id }}">
                    <input type="hidden" name="payment_platform" value="web">
                    <input type="hidden" name="external_redirect_link" value="{{ route('auction.participate.payment.success', ['slug' => $auctionProduct->slug]) }}">
                    <input type="hidden" name="payment_method" value="offline_payment">
                    <div id="auction_offline_payment_field"></div>
                </div>
                <div class="modal-footer justify-content-center border-0">
                    <button type="submit" class="btn btn-primary w-100">{{ translate('Confirm Payment') }}</button>
                </div>
            </div>
        </div>
    </div>
</form>


<span id="auction-entry-i18n" class="d-none"
      data-processing="{{ translate('Processing') }}"
      data-default-error="{{ translate('Something went wrong. Please try again.') }}"
      data-select-payment-method="{{ translate('Please select a payment method.') }}"></span>
