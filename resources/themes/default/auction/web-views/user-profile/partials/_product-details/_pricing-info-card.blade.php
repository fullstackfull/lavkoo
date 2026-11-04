<?php
    // taxMode controls how the Tax row renders:
    //   'always'          → row always rendered, value is amount → names → N/A (pending approval card)
    //   'amount-only'     → row only when total_tax_amount > 0, value is amount only
    //   'amount-or-names' → row when amount > 0 OR tax names exist; value is amount when > 0, else names
    $titleWeight   = $titleWeight   ?? 'bold';
    $labelMin      = $labelMin      ?? 'minmax-sm-120px';
    $showShipping  = $showShipping  ?? false;
    $showMinBid    = $showMinBid    ?? false;
    $minBidAmount  = $minBidAmount  ?? null;
    $taxMode       = $taxMode       ?? 'amount-or-names';
    $spanClass     = $titleWeight === 'semibold' ? '' : 'title-semidark';
    $titleMargin   = $titleWeight === 'semibold' ? 'mb-3' : 'mb-15';
    $totalTax      = $auctionProduct?->total_tax_amount ?? 0;
    $hasTaxNames   = !empty($taxNamesText ?? null);
    $renderTaxRow  = match($taxMode) {
        'always'       => true,
        'amount-only'  => $totalTax > 0,
        default        => ($totalTax > 0) || $hasTaxNames,
    };
    $showMaxDecrement  ??= true;
    $showMinBidAmount  ??= true;
?>

<div class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-{{ $titleWeight }} title-clr {{ $titleMargin }}">{{ translate('Pricing Info') }}</div>
    <div class="d-flex flex-column gap-15px">
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Starting Price') }}</div>
                <span class="{{ $spanClass }}">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct?->starting_price ?? 0) }}</h4>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100">
            <div class="d-flex align-items-center justify-content-between">
                <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Min Increment') }}</div>
                <span class="{{ $spanClass }}">:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct?->minimum_increment_amount ?? 0) }}</h4>
            </div>
        </div>

        @if($showMaxDecrement && !is_null($auctionProduct?->maximum_decrement_amount))
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Max Decrement') }}</div>
                    <span class="{{ $spanClass }}">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct?->maximum_decrement_amount) }}
                    </h4>
                </div>
            </div>
        @endif

        @if($showMinBidAmount)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Min Bid Amount') }}</div>
                    <span class="{{ $spanClass }}">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">
                        {{ webCurrencyConverter(amount: $minBidAmount) }}
                    </h4>
                </div>
            </div>
        @endif

        @if($showMinBid)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Min Bid Amount') }}</div>
                    <span class="{{ $spanClass }}">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $minBidAmount ?? 0) }}</h4>
                </div>
            </div>
        @endif
        @if($showShipping && $auctionProduct?->shipping_fee)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="{{ $labelMin }} fs-14 title-semidark">{{ translate('Shipping Fee') }}</div>
                    <span class="{{ $spanClass }}">:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold">{{ webCurrencyConverter(amount: $auctionProduct?->shipping_fee) }}</h4>
                </div>
            </div>
        @endif

        @if(!empty($auctionProduct?->taxVats))
            @foreach($auctionProduct?->taxVats as $auctionTaxKey => $auctionTaxVats)
                @if(!empty($auctionTaxVats?->tax?->name))
                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="{{ $labelMin }} fs-14 title-semidark">
                                {{ $auctionTaxVats?->tax?->name }}
                            </div>
                            <span class="{{ $spanClass }}">:</span>
                        </div>
                        <div class="info-option2">
                            <h4 class="fs-14 m-0 title-clr fw-semibold">
                                {{ $auctionTaxVats?->tax?->tax_rate }}%
                            </h4>
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>
</div>
