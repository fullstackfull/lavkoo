<div class="details product-cart-option-container">
    <h2 class="mb-24 fs-24 line--limit-2 title-clr fw-semibold">
        {{ $auctionProduct['name'] }}
    </h2>
    <div class="d-flex flex-column gap-3 auction-details-right-content">

        @if(!empty($auctionProduct['item_condition']))
            <div class="d-flex align-items-center gap-sm-1 gap-3">
                <div
                    class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                    {{ translate('Item condition') }}
                    <span data-bs-toggle="tooltip" data-bs-title="{{ translate('Indicates_the_physical_condition_of_this_item,_e.g._new,_used,_or_refurbished.') }}">
                        <img alt="icon" class="svg"
                             src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/info.svg') }}">
                    </span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-18 m-0 title-clr fw-semibold">
                        {{ $auctionProduct['item_condition'] }}
                    </h4>
                </div>
            </div>
        @endif

        <div class="d-flex align-items-center gap-sm-1 gap-3">
            <div
                class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                {{ translate('Starting Price') }}
                <span data-bs-toggle="tooltip"
                      data-bs-title="{{ translate('The_minimum_opening_price_for_this_auction._All_bids_must_be_equal_to_or_higher_than_this_amount.') }}">
                    <img
                        src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/info.svg') }}"
                        alt="icon" class="svg">
                </span>
            </div>
            <div class="info-option2">
                <h4 class="fs-20 m-0 title-clr fw-semibold">
                    {{ webCurrencyConverter(amount: $auctionProduct->starting_price) }}
                </h4>
            </div>
        </div>

        @if($auctionProduct?->current_highest_bid_amount > 0)
            <div class="d-flex align-items-start gap-sm-1 gap-3">
                <div
                    class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                    {{ translate('Highest Bid') }}
                    <span data-bs-toggle="tooltip"
                          data-bs-title="{{ translate('This is the current top bid placed in this auction.') }}">
                        <img
                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/info.svg') }}"
                            alt="icon" class="svg">
                    </span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-2 m-0 text-primary text-break fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount) }}
                    </h4>
                </div>
            </div>
        @endif

        @if(auth('customer')->check() && $auctionProduct?->myBid)
            <div class="d-flex align-items-center gap-sm-1 gap-3">
                <div
                    class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                    {{ translate('My Bid') }}
                    <span data-bs-toggle="tooltip"
                          data-bs-title="{{ translate('This is the most recent bid you placed in this auction.') }}">
                        <img
                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/info.svg') }}"
                            alt="icon" class="svg">
                    </span>
                </div>
                <div class="info-option2">
                    <h4 class="fs-20 m-0 title-clr text-break fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct?->myBid?->bid_amount) }}
                    </h4>
                </div>
            </div>
        @endif

        @if($auctionProduct?->shipping_fee)
            <div class="d-flex align-items-center gap-sm-1 gap-3">
                <div
                    class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                    {{ translate('Shipping Fee') }}
                </div>
                <div class="info-option2">
                    <h4 class="fs-20 m-0 title-clr text-break fw-semibold">
                        {{ webCurrencyConverter(amount: $auctionProduct?->shipping_fee) }}
                    </h4>
                </div>
            </div>
        @endif

        @if(trim(strip_tags((string) ($auctionProduct?->return_policy ?? ''))) !== '')
            <div class="d-flex align-items-start gap-sm-1 gap-3">
                <div
                    class="min-w-sm-170px fs-16 lh-sm title-semidark d-flex align-items-center gap-6px info-option">
                    {{ translate('Return Policy') }}
                </div>
                <div class="info-option2">
                    <h4 class="fs-14 m-0 title-clr fw-semibold line--limit-2 text-break" data-bs-toggle="tooltip" data-bs-title="{{ $auctionProduct?->return_policy }}">
                        {{ $auctionProduct?->return_policy }}
                    </h4>
                </div>
            </div>
        @endif

        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
            <span class="d-flex align-items-center gap-2 title-clr fs-20"
                  data-bs-toggle="tooltip"
                  data-bs-title="{{ translate('Number of people who have viewed this auction.') }}">
                <i class="fi fi-rr-eye text-light-gray fs-18"></i>
                {{ formatCompactNumber($auctionProduct->total_views) }}
            </span>
            <span class="d-flex align-items-center gap-2 title-clr fs-20"
                  data-bs-toggle="tooltip"
                  data-bs-title="{{ translate('Total number of bids placed on this auction.') }}">
                <img
                    src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}"
                    alt="" class="svg">
                {{ formatCompactNumber($auctionProduct->total_bids) }}
            </span>
        </div>

        <div>
            <div class="product-add-and-buy-section d-flex flex-wrap gap-xxl-2 gap-2 mt-lg-1">
                @if(!$isOwner && $auctionProduct?->auction_current_status === 'ready_to_claim' && !$auctionProduct->isFinalizing())
                    @if(auth('customer')->check() && isset($eligibleBid) && $eligibleBid)
                        <a href="{{ route('auction.claim.checkout', $auctionProduct->id) }}"
                           class="btn btn-sm btn-primary px-4">
                            {{ translate('Claim Auction') }}
                        </a>
                    @elseif(!auth('customer')->check())
                        <a href="{{ route('customer.auth.login') }}" class="btn btn-sm btn-primary px-3">
                            {{ translate('Claim Auction') }}
                        </a>
                    @elseif($auctionProduct?->myBid && $auctionProduct?->bids?->where('user_id', '!=', auth('customer')->id())->isNotEmpty())
                        <button type="button" class="btn btn-sm btn-secondary px-4" disabled>
                            {{ translate('Not Eligible to Claim') }}
                        </button>
                    @endif
                @elseif(!$isOwner)
                    @if($auctionProduct?->auction_current_status === 'upcoming')
                        @if($isParticipant)
                            <button type="button"
                                    class="btn btn-sm bg-primary bg-opacity-10 text-primary px-3">
                                {{ translate('Participated') }}
                            </button>
                        @else
                            @if(!auth('customer')->check())
                                <a type="button" class="btn btn-sm btn-primary px-3"
                                   href="{{ route('customer.auth.login') }}">
                                    {{ translate('Participate') }}
                                </a>
                            @else
                                <button type="button"
                                        class="btn btn-sm btn-primary px-3 auction-participate-btn"
                                        data-url="{{ route('auction.participate.check') }}"
                                        data-auction-product-id="{{ $auctionProduct->id }}">
                                    {{ translate('Participate') }}
                                </button>
                            @endif
                        @endif
                    @elseif($auctionProduct?->auction_current_status === 'live')
                        @if(!auth('customer')->check())
                            <a type="button" class="btn btn-sm btn-primary px-3"
                               href="{{ route('customer.auth.login') }}">
                                {{ translate('Participate') }}
                            </a>
                        @elseif(!$isParticipant)
                            <button type="button"
                                    class="btn btn-sm btn-primary px-3 auction-participate-btn"
                                    data-url="{{ route('auction.participate.check') }}"
                                    data-auction-product-id="{{ $auctionProduct->id }}">
                                {{ translate('Participate') }}
                            </button>
                        @elseif(!$hasBid)
                            @if($entryFeeAmount > 0 && $auctionProduct?->myParticipation && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid')
                                <button type="button" class="btn btn-sm btn-primary fs-16 px-3" disabled>
                                    {{ translate('Start Bidding') }}
                                    ({{ webCurrencyConverter(amount: $nextBidAmount) }})
                                </button>
                            @else
                                <button type="button" class="btn btn-sm btn-primary fs-16 px-3 place-bid-open-modal-btn"
                                        data-url="{{ route('auction.product.bid-modal-content', $auctionProduct->id) }}"
                                        data-error-message="{{ translate('Something went wrong. Please try again.') }}">
                                    {{ translate('Start Bidding') }}
                                    ({{ webCurrencyConverter(amount: $nextBidAmount) }})
                                </button>
                            @endif
                        @else
                            <button type="button" class="btn btn-sm btn-secondary text-nowrap px-3 place-bid-withdraw-btn"
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
                            <button type="button" class="btn btn-sm btn-primary text-nowrap px-3 place-bid-open-modal-btn"
                                    data-url="{{ route('auction.product.bid-modal-content', $auctionProduct->id) }}"
                                    data-error-message="{{ translate('Something went wrong. Please try again.') }}">
                                {{ translate('Raise Bid') }}
                                ({{ webCurrencyConverter(amount: $raiseBidAmount) }})
                            </button>
                        @endif
                    @endif
                @else
                    @php
                        $_ownerStatus      = $auctionProduct->auction_current_status;
                        $_participants     = $auctionProduct->participants ?? collect();
                        $_hasPaidEntryFee  = $_participants->contains(function ($p) {
                            return in_array($p->entry_fee_paid_status, \Modules\Auction\app\Enums\PaymentStatus::VALID, true)
                                || $p->entry_fee_payment_status === \Modules\Auction\app\Enums\PaymentStatus::VERIFIED;
                        });
                        $_participantCount = $_participants->count();
                        $_blockedByStatus  = in_array($_ownerStatus, [
                                \Modules\Auction\app\Enums\AuctionStatus::READY_TO_CLAIM,
                                \Modules\Auction\app\Enums\AuctionStatus::PURCHASE_COMPLETE,
                            ], true)
                            || in_array($auctionProduct->delivery_status, [
                                \Modules\Auction\app\Enums\DeliveryStatus::READY_TO_DELIVERY,
                                \Modules\Auction\app\Enums\DeliveryStatus::ON_THE_WAY,
                                \Modules\Auction\app\Enums\DeliveryStatus::DELIVERED,
                            ], true);
                        $_blockedByPaidParticipants = $_participantCount > 0 && $_hasPaidEntryFee;
                        $canCancelAuction = (!$auctionProduct->is_canceled)
                            && !$_blockedByStatus
                            && !$_blockedByPaidParticipants
                            && in_array($_ownerStatus, [
                                \Modules\Auction\app\Enums\AuctionStatus::UPCOMING,
                                \Modules\Auction\app\Enums\AuctionStatus::LIVE,
                                \Modules\Auction\app\Enums\AuctionStatus::UNSOLD,
                            ], true);
                    @endphp
                    @if($auctionProduct?->auction_current_status === 'unsold')
                        <button type="button"
                                class="btn btn-sm bg-danger bg-opacity-10 fs-14 fw-semibold text-danger px-3 flex-shrink-0 js-delete-auction-btn"
                                data-auction-id="{{ $auctionProduct->id }}">
                            {{ translate('Delete Auction') }}
                        </button>
                        <a href="{{ route('auction.auction-recreate-product', $auctionProduct->id) }}"
                           class="btn btn-sm btn-primary fs-14 px-3 flex-shrink-0">
                            {{ translate('Re Create Auction') }}
                        </a>
                    @else
                        @if($canCancelAuction)
                            <button type="button"
                                    class="btn btn-sm bg-danger bg-opacity-10 fs-14 fw-semibold text-danger px-3 flex-shrink-0 js-cancel-auction-btn"
                                    data-auction-id="{{ $auctionProduct->id }}">
                                {{ translate('Cancel Auction') }}
                            </button>
                        @endif
                        @if($auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING)
                            <a href="{{ route('auction.auction-update-product', $auctionProduct->id) }}"
                               class="btn btn-sm btn-primary fs-14 px-3 flex-shrink-0">
                                {{ translate('Edit Auction') }}
                            </a>
                        @endif
                    @endif
                @endif

                @if(!$isOwner)
                    @if(auth('customer')->check())
                        <button type="button"
                                class="btn btn-sm border d-flex align-items-center gap-10px fs-14 px-3 auction-saved-update {{ $auctionProduct?->mySavedProduct ? 'is-saved' : '' }}"
                                data-bs-toggle="tooltip"
                                data-bs-title="{{ $auctionProduct?->mySavedProduct ? translate('This auction item to your saved auctions.') : translate('Add this auction item to your saved auctions.') }}"
                                data-saved-title="{{ translate('This auction item to your saved auctions.') }}"
                                data-unsaved-title="{{ translate('Add this auction item to your saved auctions.') }}"
                                data-url="{{ route('auction.saved-products.toggle') }}"
                                data-auction-product-id="{{ $auctionProduct->id }}"
                        >
                            <i class="fi fi-rr-bookmark text-primary icon-unsaved"></i>
                            <i class="fi fi-sr-bookmark text-primary icon-saved"></i>
                            <span class="auction-saved-count">
                                {{ $auctionProduct->saved_products_count ?? 0 }}
                            </span>
                        </button>
                    @else
                        <a href="{{ route('customer.auth.login') }}"
                           class="btn btn-sm border d-flex align-items-center gap-10px fs-14 px-3"
                           title="{{ translate('Add this auction item to your saved auctions.') }}">
                            <i class="fi fi-rr-bookmark text-primary"></i>
                            <span class="auction-saved-count">
                                {{ $auctionProduct->saved_products_count ?? 0 }}
                            </span>
                        </a>
                    @endif
                @endif
            </div>

            @if(!$isOwner && $isParticipant && $auctionProduct?->auction_current_status == 'live' && in_array(auth('customer')->id(), $auctionProduct?->bids?->pluck('user_id')->toArray()) && $auctionProduct?->myBid?->bid_amount < $auctionProduct->current_highest_bid_amount)
                <div
                    class="mt-20 py-2 px-2 badge bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1 flex-nowrap text-wrap text-start">
                    <i class="fi fi-sr-info text-warning"></i>
                    {{ translate('Someone placed a higher bid.') }} {{ translate('Raise your bid to stay in the lead !') }}
                </div>
            @endif

            @if(!$isOwner && !$isParticipant && $entryFeeAmount > 0 && $auctionProduct?->auction_current_status == 'live')
                <div
                    class="mt-20 py-2 px-2 badge bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1 flex-nowrap text-wrap text-start">
                    <i class="fi fi-sr-info text-warning"></i>
                    <div class="">
                        {{ translate('To participate in this bid') }},
                        <strong>{{ webCurrencyConverter(amount: $entryFeeAmount) }}</strong> {{ translate('entry fee will be charged.') }}
                    </div>
                </div>
            @endif

            @if(!$isOwner && $isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->auction_current_status == 'live' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'paid' && !in_array(auth('customer')->id(), $auctionProduct?->bids?->pluck('user_id')->toArray()))
                <div
                    class="mt-20 py-2 px-2 badge text-wrap text-start bg-success title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                    <i class="fi fi-sr-info text-success"></i>
                    {{ translate('You have Participated this auction.') }} {{ translate('Start Bidding now.') }}
                </div>
            @endif

            @if(!$isOwner && $isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->auction_current_status == 'live' && $entryFeeAmount > 0 && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid' && !($auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_payment_status == 'pending') && !in_array(auth('customer')->id(), $auctionProduct?->bids?->pluck('user_id')->toArray()))
                <div
                    class="mt-20 py-2 px-2 badge text-wrap text-start bg-success title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                    <i class="fi fi-sr-info text-success"></i>
                    {{ translate('You have Participated this auction.') }} {{ translate('Pay the entry fee for start bidding now.') }}
                </div>
            @endif

            @if(!$isOwner && $isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->auction_current_status == 'live' && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid' && $auctionProduct?->myParticipation?->entry_fee_payment_status == 'pending')
                <div
                    class="mt-20 py-2 px-2 badge bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1 flex-nowrap text-wrap text-start">
                    <i class="fi fi-sr-info text-warning"></i>
                    {{ translate('You have Participated this auction.') }} {{ translate('Waiting for payment verification.') }}
                </div>
            @endif

            @if($isParticipant && $auctionProduct?->auction_current_status === 'upcoming')
                <div
                    class="mt-20 py-2 px-2 badge text-wrap text-start bg-success title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                    <i class="fi fi-sr-info text-success"></i>
                    {{ translate("You've Participated this auction. Bidding will start soon.") }}
                </div>
            @endif
        </div>

    </div>
</div>
