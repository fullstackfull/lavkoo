@if($claimPaymentMethod === 'cash_on_delivery')
    <?php
        $codTx     = $codCommissionTransaction ?? null;
        $codTxStatus = $codTx?->payment_status;
    ?>

    @if($codTxStatus === \Modules\Auction\app\Enums\PaymentStatus::PAID)
        {{-- Commission paid — show success result card --}}
        <div class="p-15px card border-0 shadow-sm rounded text-center">
            <div class="mb-2">
                <i class="fi fi-rr-check-circle fs-28 text-success"></i>
            </div>
            <h4 class="fs-16 mb-1 text-success fw-semibold">
                {{ translate('Commission_Paid') }}
            </h4>
            <h4 class="fs-16 mb-1 title-clr fw-semibold">
                {{ webCurrencyConverter(amount: $deliveredBreakdown->commissionAmount) }}
            </h4>
            <p class="fs-12 title-clr mb-0">
                {{ translate('Your_commission_payment_has_been_completed_successfully.') }}
            </p>
        </div>
    @elseif($codTxStatus === \Modules\Auction\app\Enums\PaymentStatus::PENDING)
        {{-- Offline payment submitted, awaiting admin verification --}}
        <div class="p-15px card border-0 shadow-sm rounded text-center">
            <div class="mb-2">
                <i class="fi fi-rr-clock fs-28 text-warning"></i>
            </div>
            <h4 class="fs-16 mb-1 text-warning fw-semibold">
                {{ translate('Payment_Under_Verification') }}
            </h4>
            <h4 class="fs-16 mb-1 title-clr fw-semibold">
                {{ webCurrencyConverter(amount: $auctionProduct?->admin_commission) }}
            </h4>
            <p class="fs-12 title-clr mb-0">
                {{ translate('Your_commission_payment_is_pending_admin_verification.') }}
            </p>
        </div>
    @else
        <div class="p-15px card border-0 shadow-sm rounded text-center">
            <h4 class="fs-16 mb-15 text-danger fw-semibold">
                {{ translate('Amount to pay Admin') }}
            </h4>
            <h4 class="fs-16 mb-1 title-clr fw-semibold">
                {{ webCurrencyConverter(amount: $auctionProduct?->admin_commission) }}
            </h4>
            <p class="fs-12 title-clr">
                {{ translate('Your auction has ended successfully. Please complete the commission payment to close this transaction.') }}
            </p>
            <button type="button"
                    class="btn btn-primary w-100 max-w-180px mx-auto"
                    data-bs-toggle="modal"
                    data-bs-target="#auction-cod-commission-modal">
                {{ translate('Pay Now') }}
            </button>
        </div>
    @endif
@else
    {{-- Wallet / Digital / Offline: admin owes customer — state-based withdraw panel --}}
    <?php $wr = $customerWithdrawRequest ?? null; ?>

    @if($wr && $wr->status === \Modules\Auction\app\Enums\WithdrawStatus::APPROVED)
        {{-- APPROVED: Approve Note + Payment Received + Withdraw Info --}}
        <div class="p-15px card border-0 shadow-sm rounded">
            @if(!empty($wr->transaction_note))
                <div class="badge py-3 px-3 bg-success w-100 text-start bg-opacity-10 rounded mb-15">
                    <h6 class="fs-13 fw-semibold title-clr mb-1">{{ translate('Approve_Note') }}</h6>
                    <p class="fs-12 mb-0 text-success fw-normal text-wrap">{{ $wr->transaction_note }}</p>
                </div>
            @endif
            <div class="text-center mb-15">
                <h4 class="fs-16 mb-1 text-success fw-semibold">
                    {{ translate('Payment_Received_from_Admin') }}
                </h4>
                <h4 class="fs-16 mb-1 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $wr->amount) }}
                </h4>
                <p class="fs-12 title-clr mb-0">
                    {{ translate('The admin has successfully completed the payment.') }}
                </p>
            </div>
            <div class="border-bottom mb-15"></div>
            <h6 class="fs-13 fw-semibold title-clr mb-2">{{ translate('Withdraw_info') }}</h6>
            @include('auction.web-views.user-profile.partials._product-details._withdraw-info-fields', [
                'wr'          => $wr,
                'statusClass' => 'text-success',
            ])
        </div>

    @elseif($wr && $wr->status === \Modules\Auction\app\Enums\WithdrawStatus::PENDING)
        <div class="p-15px card border-0 shadow-sm rounded">
            <div class="text-center mb-15">
                <h4 class="fs-16 mb-1 text-danger fw-semibold">
                    {{ translate('Amount Pay By Admin') }}
                </h4>
                <h4 class="fs-16 mb-1 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $deliveredBreakdown->vendorReceivable) }}
                </h4>
                <p class="fs-12 title-clr mb-0">
                    {{ translate('The auction earnings will be transferred after deducting the admin commission.') }}
                </p>
            </div>
            <div class="border-bottom mb-15"></div>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="fs-13 fw-semibold title-clr mb-0">{{ translate('Withdraw_info') }}</h6>
                <button type="button"
                    class="btn btn-sm btn-outline-secondary p-1 lh-1"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#auction-customer-withdraw-offcanvas">
                    <i class="fi fi-rr-pencil fs-12"></i>
                </button>
            </div>
            @include('auction.web-views.user-profile.partials._product-details._withdraw-info-fields', [
                'wr'          => $wr,
                'statusClass' => '',
            ])
        </div>

    @elseif($wr && in_array($wr->status, \Modules\Auction\app\Enums\WithdrawStatus::DENIED, true))
        <div class="p-15px card border-0 shadow-sm rounded">
            @if(!empty($wr->transaction_note))
                <div class="badge py-3 px-3 bg-danger w-100 text-start bg-opacity-10 rounded mb-15">
                    <h6 class="fs-13 fw-semibold title-clr mb-1">{{ translate('Denied_Note') }}</h6>
                    <p class="fs-12 mb-0 text-danger fw-normal text-wrap">{{ $wr->transaction_note }}</p>
                </div>
            @endif
            <div class="text-center mb-15">
                <h4 class="fs-16 mb-1 text-danger fw-semibold">
                    {{ translate('Amount_pay_by_Admin') }}
                </h4>
                <h4 class="fs-16 mb-1 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $deliveredBreakdown->vendorReceivable) }}
                </h4>
                <p class="fs-12 title-clr mb-0">
                    {{ translate('The auction earnings will be transferred after deducting the admin commission.') }}
                </p>
            </div>
            <div class="border-bottom mb-15"></div>
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="fs-13 fw-semibold title-clr mb-0">{{ translate('Withdraw_info') }}</h6>
                <button type="button"
                    class="btn btn-sm btn-outline-secondary p-1 lh-1"
                    data-bs-toggle="offcanvas"
                    data-bs-target="#auction-customer-withdraw-offcanvas">
                    <i class="fi fi-rr-pencil fs-12"></i>
                </button>
            </div>
            @include('auction.web-views.user-profile.partials._product-details._withdraw-info-fields', [
                'wr'          => $wr,
                'statusClass' => 'text-danger',
            ])
        </div>
    @else
        {{-- NO REQUEST YET: show amount + Request Withdraw button --}}
        <div class="p-15px card border-0 shadow-sm rounded text-center">
            <h4 class="fs-16 mb-15 text-success fw-semibold">
                {{ translate('Amount_pay_by_Admin') }}
            </h4>
            <h4 class="fs-16 mb-1 title-clr fw-semibold">
                {{ webCurrencyConverter(amount: $deliveredBreakdown->vendorReceivable) }}
            </h4>
            <p class="fs-12 title-clr">
                {{ translate('The auction earnings will be transferred after deducting the admin commission.') }}
            </p>
            <button type="button"
                class="btn btn-primary btn-sm px-4 mx-auto d-inline-flex align-items-center gap-1"
                data-bs-toggle="offcanvas"
                data-bs-target="#auction-customer-withdraw-offcanvas">
                <i class="fi fi-rr-wallet fs-12"></i>
                {{ translate('Request_Withdraw') }}
            </button>
        </div>
    @endif
@endif
