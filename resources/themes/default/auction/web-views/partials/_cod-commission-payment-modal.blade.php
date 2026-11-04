@php
    $codCommissionAmount  = $auctionProduct?->admin_commission ?? 0;
    $codWalletBalance     = (float) (Auth::guard('customer')->user()?->wallet_balance ?? 0);
    $codWalletCoversAll   = $codWalletBalance >= $codCommissionAmount;
    $codWalletRemaining   = max(0, $codWalletBalance - $codCommissionAmount);
    $codWalletPaid        = min($codWalletBalance, $codCommissionAmount);
    $codWalletInsufficient = $codWalletBalance < $codCommissionAmount;
@endphp

<div class="modal fade" id="auction-cod-commission-modal" tabindex="-1"
     aria-labelledby="auctionCodCommissionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-4">

            <div class="modal-header border-0 justify-content-center pe-4">
                <h1 class="modal-title fs-18 fw-semibold title-clr text-center" id="auctionCodCommissionModalLabel">
                    {{ translate('Choose_Payment_Method') }}
                </h1>
                <button type="button"
                        class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px"
                        data-bs-dismiss="modal" aria-label="{{ translate('Close') }}">
                    <i class="fi fi-rr-cross-small"></i>
                </button>
            </div>

            <div class="modal-body pt-1 mx-sm-3">
                <div class="modal-body-scrolling">

                    <div class="mb-20 text-center">
                        <p class="fs-13 title-semidark mb-1">{{ translate('Pay_commission') }}</p>
                        <h3 class="fw-bold fs-20 mb-0">
                            {{ webCurrencyConverter(amount: $auctionProduct?->admin_commission) }}
                        </h3>
                    </div>

                    @if(Auth::guard('customer')->check() && getWebConfig(name: 'wallet_status') == 1)

                        @if($codWalletCoversAll && $codWalletBalance > 0)
                            {{-- Wallet covers full amount: show apply/remove toggle --}}
                            <div class="border rounded p-3 d-flex align-items-center justify-content-between mb-20"
                                 id="cod-commission-wallet-balance-section">
                                <div>
                                    <p class="fs-10 title-semidark mb-1">{{ translate('Wallet_Balance') }}</p>
                                    <h3 class="fw-bold fs-18 mb-0">{{ webCurrencyConverter(amount: $codWalletBalance) }}</h3>
                                </div>
                                <button type="button" id="cod-commission-apply-wallet-btn"
                                        class="btn btn-outline-primary fw-semibold fs-12 py-2">
                                    {{ translate('Apply') }}
                                </button>
                            </div>

                            <div class="border rounded p-3 d-flex align-items-center justify-content-between mb-20 d-none"
                                 id="cod-commission-wallet-applied-section">
                                <div>
                                    <p class="fs-10 title-semidark mb-1">{{ translate('Wallet_Remaining_Balance') }}</p>
                                    <h3 class="fw-bold d-flex align-items-center gap-2px fs-18 mb-0">
                                        {{ webCurrencyConverter(amount: $codWalletRemaining) }}
                                        <span class="text-primary fs-12 fw-normal">({{ translate('Applied') }})</span>
                                    </h3>
                                </div>
                                <button type="button" id="cod-commission-remove-wallet-btn"
                                        class="btn p-0 m-0 outline-0 fs-24 text-danger">
                                    <i class="fi fi-rr-cross-small"></i>
                                </button>
                            </div>

                            <div class="mb-20 d-none" id="cod-commission-paid-by-wallet-section">
                                <div class="border light-box rounded p-3 d-flex align-items-center justify-content-between">
                                    <label class="fw-bold fs-14 title-clr mb-0">{{ translate('Paid_By_Wallet') }}</label>
                                    <h4 class="fs-20 fw-semibold title-clr mb-0">
                                        {{ webCurrencyConverter(amount: $codWalletPaid) }}
                                    </h4>
                                </div>
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

                    @if(isset($offline_payment) && $offline_payment['status'] && $offline_payment_methods->isNotEmpty())
                        <div class="border rounded p-3 mb-20">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <span class="d-flex align-items-center gap-3">
                                    <input type="radio" id="cod-commission-pay-offline"
                                           name="cod_commission_payment_choice"
                                           class="form-check-input form-check-input_theme"
                                           value="offline_payment">
                                    <label for="cod-commission-pay-offline"
                                           class="fw-bold fs-14 title-clr mb-0 cursor-pointer">
                                        {{ translate('Pay_Offline') }}
                                    </label>
                                </span>
                                <div data-toggle="tooltip"
                                     title="{{ translate('for_offline_payment_options,_please_follow_the_steps_below') }}">
                                    <i class="fi fi-rr-info text-primary"></i>
                                </div>
                            </div>
                            <div class="mt-3 cod-commission-offline-methods d-none">
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($offline_payment_methods as $offlineMethod)
                                        <button type="button"
                                                class="btn btn-light cod-commission-offline-btn text-capitalize"
                                                data-method-id="{{ $offlineMethod->id }}"
                                                data-amount="{{ $codCommissionAmount }}">
                                            {{ $offlineMethod->method_name }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    @if(isset($digital_payment) && $digital_payment['status'] == 1 && count($payment_gateways_list) > 0)
                        <div class="border rounded p-3">
                            <div class="d-flex flex-column gap-xxl-4 gap-3">
                                <h6 class="fw-bold fs-14 title-clr mb-0">{{ translate('Pay_Via_Online') }}</h6>
                                @foreach($payment_gateways_list as $gateway)
                                    @php
                                        $gwExtra    = $gateway['additional_data'] ? json_decode($gateway['additional_data']) : null;
                                        $gwImgPath  = dynamicAsset(path: 'public/assets/back-end/img/modal/payment-methods/' . $gateway->key_name . '.png');
                                        if ($gwExtra?->gateway_image && file_exists(base_path('storage/app/public/payment_modules/gateway_image/' . $gwExtra->gateway_image))) {
                                            $gwImgPath = dynamicStorage(path: 'storage/app/public/payment_modules/gateway_image/' . $gwExtra->gateway_image);
                                        }
                                        $gwLabel = $gwExtra?->gateway_title ?: str_replace('_', ' ', $gateway->key_name);
                                    @endphp
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <label class="d-flex align-items-center gap-1 fs-13 title-clr"
                                               for="cod_commission_gw_{{ $loop->index }}">
                                            <img src="{{ $gwImgPath }}" alt="{{ $gwLabel }}" width="30">
                                            <span class="text-capitalize">{{ $gwLabel }}</span>
                                        </label>
                                        <div class="form-check">
                                            <input class="form-check-input form-check-input_theme cod-commission-gateway-radio"
                                                   type="radio"
                                                   name="cod_commission_payment_choice"
                                                   id="cod_commission_gw_{{ $loop->index }}"
                                                   value="{{ $gateway->key_name }}"
                                                   data-gateway-key="{{ $gateway->key_name }}">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>
            </div>

            <div class="modal-footer shadow-sm justify-content-center border-0">
                <button type="button" id="cod-commission-proceed-btn"
                        class="btn btn-primary w-100" disabled>
                    {{ translate('Proceed') }}
                </button>
            </div>

        </div>
    </div>
</div>

<form action="{{ route('auction.cod-commission.pay') }}" method="POST" id="cod-commission-offline-form">
    @csrf
    <input type="hidden" name="auction_product_id" value="{{ $auctionProduct->id ?? '' }}">
    <input type="hidden" name="payment_method"     value="offline_payment">
    <input type="hidden" name="payment_platform"   value="web">
    <input type="hidden" name="external_redirect_link"
           value="{{ isset($auctionProduct) ? (auth('customer')->check() && auth('customer')->id() == $auctionProduct?->owner_id && $auctionProduct?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $auctionProduct->slug]) : route('auction.profile-view.product-details', ['slug' => $auctionProduct->slug])) : '' }}">

    <div class="modal fade" id="auction-cod-commission-offline-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content rounded-4">
                <div class="modal-header border-0 justify-content-between pe-4">
                    <button type="button" class="d-flex align-items-center border-0 bg-transparent text-nowrap gap-1
                                                 text-capitalize fs-14 view-all-text text-primary p-0 cursor-pointer"
                            id="cod-commission-offline-go-back">
                        <i class="fi fi-rr-angle-left fs-12"></i>
                        {{ translate('Go_Back') }}
                    </button>
                    <h1 class="modal-title fs-18 fw-semibold title-clr text-center">
                        {{ translate('Offline_Payment') }}
                    </h1>
                    <div></div>
                    <button type="button"
                            class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px"
                            data-bs-dismiss="modal" aria-label="{{ translate('Close') }}">
                        <i class="fi fi-rr-cross-small"></i>
                    </button>
                </div>
                <div class="modal-body pt-1 mx-sm-3">
                    <div id="cod-commission-offline-fields"></div>
                </div>
                <div class="modal-footer justify-content-center border-0">
                    <button type="submit" class="btn btn-primary w-100">
                        {{ translate('Confirm_Payment') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<form action="{{ route('auction.cod-commission.pay') }}" method="POST" id="cod-commission-payment-form">
    @csrf
    <input type="hidden" name="auction_product_id" value="{{ $auctionProduct->id ?? '' }}">
    <input type="hidden" name="payment_method"     id="cod-commission-payment-method-input" value="">
    <input type="hidden" name="payment_platform"   value="web">
    <input type="hidden" name="external_redirect_link"
           value="{{ isset($auctionProduct) ? (auth('customer')->check() && auth('customer')->id() == $auctionProduct?->owner_id && $auctionProduct?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $auctionProduct->slug]) : route('auction.profile-view.product-details', ['slug' => $auctionProduct->slug])) : '' }}">
</form>

<script>
(function () {
    const mainModalEl         = document.getElementById('auction-cod-commission-modal');
    const offlineModalEl      = document.getElementById('auction-cod-commission-offline-modal');
    const proceedBtn          = document.getElementById('cod-commission-proceed-btn');
    const offlineRadio        = document.getElementById('cod-commission-pay-offline');
    const offlineMethodsWrap  = document.querySelector('.cod-commission-offline-methods');
    const gatewayRadios       = document.querySelectorAll('.cod-commission-gateway-radio');
    const paymentMethodInput  = document.getElementById('cod-commission-payment-method-input');
    const paymentForm         = document.getElementById('cod-commission-payment-form');
    const offlineForm         = document.getElementById('cod-commission-offline-form');
    const offlineFieldsEl     = document.getElementById('cod-commission-offline-fields');
    const goBackBtn           = document.getElementById('cod-commission-offline-go-back');

    // Wallet apply / remove
    const applyWalletBtn       = document.getElementById('cod-commission-apply-wallet-btn');
    const removeWalletBtn      = document.getElementById('cod-commission-remove-wallet-btn');
    const walletBalanceSection = document.getElementById('cod-commission-wallet-balance-section');
    const walletAppliedSection = document.getElementById('cod-commission-wallet-applied-section');
    const paidByWalletSection  = document.getElementById('cod-commission-paid-by-wallet-section');

    if (applyWalletBtn) {
        applyWalletBtn.addEventListener('click', function () {
            walletBalanceSection.classList.add('d-none');
            walletAppliedSection.classList.remove('d-none');
            paidByWalletSection.classList.remove('d-none');
            if (paymentMethodInput) paymentMethodInput.value = 'wallet';
            if (proceedBtn) proceedBtn.disabled = false;
        });
    }

    if (removeWalletBtn) {
        removeWalletBtn.addEventListener('click', function () {
            walletAppliedSection.classList.add('d-none');
            paidByWalletSection.classList.add('d-none');
            walletBalanceSection.classList.remove('d-none');
            if (paymentMethodInput) paymentMethodInput.value = '';
            if (proceedBtn) proceedBtn.disabled = true;
        });
    }

    // Gateway radio selected
    gatewayRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (offlineMethodsWrap) offlineMethodsWrap.classList.add('d-none');
            if (paymentMethodInput) paymentMethodInput.value = this.value;
            if (proceedBtn) proceedBtn.disabled = false;
        });
    });

    // Offline radio selected — expand method list, disable proceed until method chosen
    if (offlineRadio && offlineMethodsWrap) {
        offlineRadio.addEventListener('change', function () {
            if (this.checked) {
                offlineMethodsWrap.classList.remove('d-none');
                if (paymentMethodInput) paymentMethodInput.value = '';
                if (proceedBtn) proceedBtn.disabled = true;
            }
        });
    }

    // Offline method button → fetch fields, switch to sub-modal
    document.querySelectorAll('.cod-commission-offline-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const methodId   = this.dataset.methodId;
            const amount     = this.dataset.amount;
            const routeUrl   = document.getElementById('route-pay-offline-method-list')?.dataset?.url;

            if (offlineFieldsEl) offlineFieldsEl.innerHTML = '';

            if (routeUrl && methodId) {
                $.ajax({
                    url: routeUrl + '?method_id=' + methodId + '&edit_due_amount=' + encodeURIComponent(amount),
                    type: 'GET',
                    success: function (response) {
                        if (offlineFieldsEl) offlineFieldsEl.innerHTML = response?.methodHtml || '';

                        const bsMain = bootstrap.Modal.getInstance(mainModalEl);
                        if (bsMain) {
                            mainModalEl.addEventListener('hidden.bs.modal', function openOffline() {
                                mainModalEl.removeEventListener('hidden.bs.modal', openOffline);
                                new bootstrap.Modal(offlineModalEl).show();
                            }, { once: true });
                            bsMain.hide();
                        }
                    }
                });
            }
        });
    });

    // Go back — close offline sub-modal, reopen main modal
    if (goBackBtn) {
        goBackBtn.addEventListener('click', function () {
            const bsOffline = bootstrap.Modal.getInstance(offlineModalEl);
            if (bsOffline) {
                offlineModalEl.addEventListener('hidden.bs.modal', function reopenMain() {
                    offlineModalEl.removeEventListener('hidden.bs.modal', reopenMain);
                    new bootstrap.Modal(mainModalEl).show();
                }, { once: true });
                bsOffline.hide();
            }
        });
    }

    // Proceed button — submit the correct form
    if (proceedBtn) {
        proceedBtn.addEventListener('click', function () {
            const method = paymentMethodInput ? paymentMethodInput.value : '';
            if (!method) return;
            if (paymentForm) paymentForm.submit();
        });
    }
})();
</script>
