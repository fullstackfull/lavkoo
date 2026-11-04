<div id="profile-auction-info-container" class="p-15px card border-0 shadow-sm rounded">
    <div class="fs-14 fw-semibold title-clr">{{ translate('Auction Info') }}</div>
    <div>
        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Starting Price') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $auctionProduct->starting_price) }}
                </h4>
            </div>
        </div>
        @if(
            $auctionProduct?->auction_current_status !== \Modules\Auction\app\Enums\AuctionStatus::UPCOMING
            && !$auctionProduct?->is_canceled
            && !in_array($auctionProduct?->approval_status, [
                \Modules\Auction\app\Enums\ApprovalStatus::PENDING,
                \Modules\Auction\app\Enums\ApprovalStatus::REJECTED,
            ])
        )
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Highest Bid') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount) }}
                    </h4>
                </div>
            </div>
        @endif

        @if(auth('customer')->id() != $auctionProduct?->owner_id && $auctionProduct?->myBid)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('My Bid') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct?->myBid?->bid_amount) }}
                    </h4>
                </div>
            </div>
        @endif

        <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
            <div class="d-flex align-items-center justify-content-between">
                <div class="minmax-xs-100px fs-14 title-semidark">
                    {{ translate('Total Viewed') }}
                </div>
                <span>:</span>
            </div>
            <div class="info-option2">
                <h4 class="fs-16 m-0 title-clr fw-semibold">
                    {{ formatCompactNumber($auctionProduct->total_views) }}
                </h4>
            </div>
        </div>
        @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING)
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Total Participate') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ formatCompactNumber($auctionProduct->total_participants ?? 0) }}
                    </h4>
                </div>
            </div>
        @else
            <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="minmax-xs-100px fs-14 title-semidark">
                        {{ translate('Total Bid') }}
                    </div>
                    <span>:</span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-16 m-0 title-clr fw-semibold">
                        {{ formatCompactNumber($auctionProduct->total_bids) }}
                    </h4>
                </div>
            </div>
        @endif
    </div>
    @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING && !$isOwner && $auctionProduct?->myParticipation !== null)
        <div class="mt-3 bg-success bg-opacity-10 rounded p-12px d-flex align-items-center gap-2">
            <i class="fi fi-rr-info text-success fs-14"></i>
            <span class="fs-13 title-semidark">{{ translate("You’ve Participated this auction. Bidding will start soon.") }}</span>
        </div>
    @endif

    @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::LIVE && !$isOwner)
        @if($auctionProduct?->myBid)
            <div class="mt-3 flex-xl-nowrap flex-wrap d-flex gap-12px">
                <button type="button" class="btn px-3 btn-secondary fs-14 text-nowrap place-bid-withdraw-btn"
                        data-bs-toggle="tooltip"
                        data-bs-title="{{ translate('Cancel your most recent bid (available only if allowed by auction rules).') }}"
                        data-url="{{ route('auction.bids.withdraw') }}"
                        data-auction-product-id="{{ $auctionProduct->id }}"
                        data-confirm-title="{{ translate('are_you_sure') }}?"
                        data-confirm-message="{{ translate('Are you sure you want to withdraw your bid?') }}"
                        data-confirm-button="{{ translate('Yes, Withdraw') }}"
                        data-cancel-button="{{ translate('No') }}"
                        data-error-message="{{ translate('Something went wrong. Please try again.') }}">
                    {{ translate('Withdraw Bid') }}
                </button>
                <button type="button" class="btn px-3 btn-primary fs-14 place-bid-open-modal-btn text-nowrap"
                        data-url="{{ route('auction.product.bid-modal-content', $auctionProduct->id) }}"
                        data-error-message="{{ translate('Something went wrong. Please try again.') }}">
                    <span>{{ translate('Raise Bid') }}</span>
                    <span>({{ webCurrencyConverter(amount: $raiseBidAmount) }})</span>
                </button>
            </div>
        @elseif($isParticipant)
            <div class="mt-3">
                @if(($entryFeeAmount ?? 0) > 0 && $auctionProduct?->myParticipation && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid')
                    <button type="button" class="btn w-100 btn-primary fs-14" disabled>
                        {{ translate('Start Bidding') }}
                        ({{ webCurrencyConverter(amount: $nextBidAmount) }})
                    </button>
                @else
                    <button type="button" class="btn w-100 btn-primary fs-14 place-bid-open-modal-btn"
                            data-url="{{ route('auction.product.bid-modal-content', $auctionProduct->id) }}"
                            data-error-message="{{ translate('Something went wrong. Please try again.') }}">
                        {{ translate('Start Bidding') }}
                        ({{ webCurrencyConverter(amount: $nextBidAmount) }})
                    </button>
                @endif
            </div>
        @endif

        @if($isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'paid' && !$auctionProduct?->myBid)
            <div class="mt-3 bg-success bg-opacity-10 rounded p-12px d-flex align-items-center gap-2">
                <i class="fi fi-rr-info text-success fs-14"></i>
                <span class="fs-13 title-semidark">{{ translate('You have Participated this auction.') }} {{ translate('Start Bidding now.') }}</span>
            </div>
        @endif

        @if($isParticipant && $auctionProduct?->myParticipation && ($entryFeeAmount ?? 0) > 0 && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid' && !($auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_payment_status == 'pending') && !$auctionProduct?->myBid)
            <div class="mt-3 bg-success bg-opacity-10 rounded p-12px d-flex align-items-center gap-2">
                <i class="fi fi-rr-info text-success fs-14"></i>
                <span class="fs-13 title-semidark">{{ translate('You have Participated this auction.') }} {{ translate('Pay the entry fee for start bidding now.') }}</span>
            </div>
        @endif

        @if($isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid' && $auctionProduct?->myParticipation?->entry_fee_payment_status == 'pending')
            <div class="mt-3 bg-warning bg-opacity-10 rounded p-12px d-flex align-items-center gap-2">
                <i class="fi fi-rr-info text-warning fs-14"></i>
                <span class="fs-13 title-semidark">{{ translate('You have Participated this auction.') }} {{ translate('Waiting for payment verification.') }}</span>
            </div>
        @endif
    @endif
</div>
