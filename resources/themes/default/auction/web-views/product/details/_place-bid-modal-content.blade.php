@if(isset($auctionProduct) && isset($applicableBidAmounts))
@php
    $minIncrement = (float) $auctionProduct?->minimum_increment_amount;
    $rollbackAvailable = !empty($canRollback);
    $minimumRollbackBid = (float) ($minimumAllowedRollbackBid ?? 0);
    $myCurrentBidAmount = (float) ($auctionProduct?->myBid?->bid_amount ?? 0);

    $nextRaiseAmount = $auctionProduct?->current_highest_bid_amount <= 0
        ? (float) $auctionProduct?->starting_price
        : (float) $auctionProduct?->current_highest_bid_amount + $minIncrement;

    $minimumRequired = $rollbackAvailable ? $minimumRollbackBid : $nextRaiseAmount;
    $defaultValue = collect($applicableBidAmounts)->isNotEmpty()
        ? (float) collect($applicableBidAmounts)->first()
        : $nextRaiseAmount;
    $submitUrl = route('auction.bids.place');
    $submitLabel = translate('Place Bid');
    $submitInvalidMsg = translate('Please enter a valid bid amount.');
@endphp
<div class="modal-header border-0 justify-content-center pe-4 pb-0 mb-3">
    <button type="button" class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px" data-bs-dismiss="modal" aria-label="Close">
        <i class="fi fi-rr-cross-small"></i>
    </button>
</div>
<div class="modal-body pt-0 px-3 px-sm-4">
    @if($auctionProduct?->current_highest_bid_amount > 0)
        <div class="box mb-20">
            <h3 class="fs-20 fw-bold mb-2">
                {{ webCurrencyConverter(amount: $auctionProduct?->current_highest_bid_amount) }}
            </h3>
            <p class="fs-14 pragraph-clr mb-0">
                {{ translate('Highest Bid') }}
            </p>
        </div>
    @endif

    <div class="light-box p-2 p-sm-4 text-center rounded">
        <div class="box">
            <h3 class="mb-10px fs-14 fw-medium">
                {{ translate('Enter an amount higher than the current bid') }}
            </h3>
            <div class="border bg-white rounded-1 max-w-230px mx-auto p-2 d-flex align-items-center justify-content-between">
                <button type="button" class="btn btn-secondary text-danger d-center fs-16 fw-normal p-1 w-30px h-30px min-w-30px rounded place-bid-decrement-btn"
                        data-amount="{{ webCurrencyConverterOnlyDigit(amount: $minIncrement) }}">
                    <i class="fi fi-sr-arrow-small-down"></i>
                </button>

                <div class="max-w-90px mx-auto">
                    <input type="text" class="form-control border-0 text-center outline-0 place-bid-amount-input"
                           placeholder="{{ webCurrencyConverter(amount: $minimumRequired) }}"
                           min="{{ webCurrencyConverterOnlyDigit(amount: $minimumRequired) }}"
                           value="{{ !is_null($defaultValue) ? webCurrencyConverterOnlyDigit(amount: $defaultValue) : '' }}"
                           inputmode="decimal"
                           oninput="this.value=this.value.replace(/[^0-9.]/g,'')">
                </div>
                <button type="button" class="btn btn-secondary text-success d-center fs-16 fw-normal p-1 w-30px h-30px min-w-30px rounded place-bid-increment-btn"
                        data-amount="{{ webCurrencyConverterOnlyDigit(amount: $minIncrement) }}">
                    <i class="fi fi-sr-arrow-small-up"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-center align-items-center flex-wrap gap-xl-3 gap-2 mt-20">
        @foreach($applicableBidAmounts as $key => $applicableBidAmount)
            <button type="button" class="btn btn-secondary minimum-bid-btn place-bid-preset-btn fs-12 fw-normal py-2 px-12px rounded-pill {{ $key == 0 ? 'active' : '' }}"
                    data-amount="{{ webCurrencyConverterOnlyDigit(amount: $applicableBidAmount) }}">
                {{ webCurrencyConverter(amount: $applicableBidAmount) }}
            </button>
        @endforeach
    </div>

    @if($rollbackAvailable)
        <div class="mt-3 place-bid-rollback-info" style="display: none;">
            <div class="py-2 px-2 badge bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1 flex-wrap text-wrap text-start lh-base">
                <i class="fi fi-sr-info text-warning"></i>
                {{ translate('You are about to lower your winning bid to the minimum allowed amount') }}
                <strong>{{ webCurrencyConverter(amount: $minimumRollbackBid) }}</strong>.
                {{ translate('Rollback is one-time only and cannot be undone.') }}
            </div>
        </div>
    @endif
</div>
<div class="modal-footer justify-content-center border-0">
    <button type="button" class="btn btn-sm btn-primary d-flex align-items-center gap-2 place-bid-submit-btn"
            data-url="{{ $submitUrl }}"
            data-auction-product-id="{{ $auctionProduct->id }}"
            data-label="{{ $submitLabel }}"
            data-update-label="{{ translate('Update Bid') }}"
            data-invalid-amount-message="{{ $submitInvalidMsg }}"
            data-rollback-available="{{ $rollbackAvailable ? '1' : '0' }}"
            data-error-message="{{ translate('Something went wrong. Please try again.') }}">
        <i class="fi fi-sr-auction"></i>
        <span class="place-bid-submit-label">{{ $submitLabel }}</span>
    </button>
</div>
@endif
