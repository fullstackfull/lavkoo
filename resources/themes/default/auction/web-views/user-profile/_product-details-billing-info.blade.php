<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr">
        {{ translate('Billing info') }}
    </div>
    <div>

        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Product Price') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $auctionProduct?->winningBid?->bid_amount) }}
                </h4>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Shipping Fee') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $auctionProduct?->shipping_fee) }}
                </h4>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Tax') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $auctionProduct?->total_tax_amount ?? 0) }}
                </h4>
            </div>
        </div>

        @php
            $billingTotal = (float)($auctionProduct?->winningBid?->bid_amount ?? 0)
                          + (float)($auctionProduct?->shipping_fee ?? 0)
                          + (float)($auctionProduct?->total_tax_amount ?? 0);
        @endphp

        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Total') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $billingTotal) }}
                </h4>
            </div>
        </div>

    </div>
</div>
