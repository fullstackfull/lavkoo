<?php
    $titleWeight = $titleWeight ?? 'bold';
    $titleMargin = $titleWeight === 'semibold' ? 'mb-3' : 'mb-15';
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleMargin }}">{{ translate('Payment Info') }}</div>
    <div class="d-flex flex-column gap-15px">
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Payment Status') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 {{ $claimPaymentStatusClass }} fw-semibold">{{ $claimPaymentStatusLabel }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Payment Method') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ translate($claimPaymentMethodLabel) }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Paid Amount') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimActualPaid) }}</h4>
            </div>
        </div>
        @if($claimDueAmount > 0)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Due Amount') }}</div>
                    <span class="title-semidark">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimDueAmount) }}</h4>
                </div>
            </div>
        @endif
    </div>
</div>
