<?php
    // Billing breakdown card. Rows always shown: Product Price, Shipping Fee, [Tax if > 0], divider, Total.
    // Optional trailing sections (driven by claim state) — enabled only on the purchase_complete view:
    //   $showPaidByRow      → render "Paid by <method>" row when $claimActualPaid > 0
    //   $showDueRow         → render "Due (<method>)" row when $claimDueAmount > 0
    //   $showReturnPolicy   → render the return-policy info badge at the bottom
    $titleWeight      = $titleWeight      ?? 'bold';
    $titleMargin      = $titleWeight === 'semibold' ? 'mb-3' : 'mb-15';
    $showPaidByRow    = $showPaidByRow    ?? false;
    $showDueRow       = $showDueRow       ?? false;
    $showReturnPolicy = $showReturnPolicy ?? false;
?>
<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleMargin }}">{{ translate('Billing Info') }}</div>
    <div class="d-flex flex-column gap-15px">
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Product Price') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimProductPrice) }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Shipping Fee') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct?->shipping_fee ?? 0) }}</h4>
            </div>
        </div>
        @if($auctionProduct?->total_tax_amount > 0)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Tax') }}</div>
                    <span class="title-semidark">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct?->total_tax_amount) }}</h4>
                </div>
            </div>
        @endif
        <div class="border-bottom"></div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Total') }}</div>
                <span class="title-semidark">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimTotalAmount) }}</h4>
            </div>
        </div>
        @if($showPaidByRow && $claimActualPaid > 0)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Paid by') }} {{ translate($claimPaymentMethodLabel) }}</div>
                    <span class="title-semidark">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimActualPaid) }}</h4>
                </div>
            </div>
        @endif
        @if($showDueRow && $claimDueAmount > 0)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xl-130px fs-14 title-semidark">{{ translate('Due') }} ({{ translate($claimPaymentMethodLabel) }})</div>
                    <span class="title-semidark">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $claimDueAmount) }}</h4>
                </div>
            </div>
        @endif
        @if($showReturnPolicy && trim(strip_tags((string) ($auctionProduct?->return_policy ?? ''))) !== '')
            <div class="fs-13 py-2 px-2 badge text-wrap text-start bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                <i class="fi fi-sr-info text-warning"></i>
                {{ translate('Return Policy') }} : {{ $auctionProduct->return_policy }}
            </div>
        @endif
    </div>
</div>
