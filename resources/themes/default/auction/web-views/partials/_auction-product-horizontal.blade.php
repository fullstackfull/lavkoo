@php
    $bidListContext = $bidListContext ?? false;
    $bidListTab = $bidListTab ?? null;
    $savedListContext = $savedListContext ?? false;
    $myAuctionStatus = $bidListContext ? ($auctionProduct->my_auction_status ?? null) : null;
    $isClaimTimeout = $bidListContext && !empty($auctionProduct->is_claim_expired_loser);
    $isParticipatedTab = $bidListContext && $bidListTab === 'participated';
    $isLeadingBidder = false;
    $isOutbidBidder = false;
    if ($bidListContext && $auctionProduct['auction_current_status'] == 'live' && auth('customer')->check() && $auctionProduct->myBid !== null) {
        $isLeadingBidder = $auctionProduct->highestBid && (int) $auctionProduct->highestBid->user_id === (int) auth('customer')->id();
        $isOutbidBidder = !$isLeadingBidder;
    }
    $cardIsOwner = auth('customer')->check() && $auctionProduct->owner_type === 'customer' && auth('customer')->id() == $auctionProduct->owner_id;
    $cardProfileViewUrl = $cardIsOwner
        ? route('auction.profile-view.author-product-details', ['slug' => $auctionProduct?->slug])
        : route('auction.profile-view.product-details', ['slug' => $auctionProduct?->slug]);
    $cardClickUrl = ($bidListContext && ($myAuctionStatus === 'lost' || $isClaimTimeout))
        ? $cardProfileViewUrl
        : route('auction.product-details', ['slug' => $auctionProduct?->slug]);
@endphp
<div class="ending-soon-card bg-white border__card shadow-sm p-10px rounded position-relative">
    @if($bidListContext && $isLeadingBidder)
        <span
            class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1"
            data-bs-toggle="tooltip" data-bs-placement="top"
            data-bs-title="{{ translate('You have placed the highest bid and are currently leading.') }}">
            {{ translate('Leading') }}
        </span>
    @elseif($bidListContext && $isOutbidBidder)
        <span
            class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1"
            data-bs-toggle="tooltip" data-bs-placement="top"
            data-bs-title="{{ translate('Someone placed a higher bid. Raise your bid to regain the leading position.') }}">
            {{ translate('Outbid') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'upcoming')
        <span
            class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Upcoming') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'live')
        <span
            class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Live') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'unsold')
        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Expired') }}
        </span>
    @endif

    <div class="d-flex gap-10px align-items-center">
        <div class="rounded thumb-xxl-140 overflow-hidden text-center border__card position-relative">
            <a href="{{ $cardClickUrl }}"
               class="m-thumbnail d-block">
                <img alt="" class="w-100 h-100"
                     src="{{ getStorageImages(path: $auctionProduct->thumbnail_full_url, type: 'product') }}">
            </a>

            @unless(in_array($myAuctionStatus, ['won', 'lost', 'claim_expired_lost'], true))
            @if($isClaimTimeout || in_array($auctionProduct['auction_current_status'], ['upcoming', 'live']))
            <div
                class="d-flex justify-content-between w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
                @if($isClaimTimeout)
                    <div class="flex-aligns w-100 justify-content-center gap-1">
                        <span class="fs-12 fw-semibold text-timeout text-capitalize">{{ translate('Time Out') }}</span>
                    </div>
                @else
                    <div class="flex-aligns w-100 justify-content-center gap-1">
                        <i class="fi fi-rr-time-oclock fs-10 text-danger"></i>
                        <div class="d-flex gap-1 countdown auction-countdown"
                             data-end="{{ $auctionProduct['auction_current_status'] == 'upcoming'
                                ? \Carbon\Carbon::parse($auctionProduct->start_time)->format('Y-m-d H:i:s')
                                : (\Carbon\Carbon::parse($auctionProduct->end_time)->isPast() ? \Carbon\Carbon::now()->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($auctionProduct->end_time)->format('Y-m-d H:i:s')) }}">
                            <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                <span class="time hours fw-semibold text-danger fs-10">00</span>
                                <div class="small text-danger fs-10">h</div>
                            </div>
                            <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                <span class="time minutes fw-semibold text-danger fs-10">00</span>
                                <div class="small text-danger fs-10">m</div>
                            </div>
                            <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                <span class="time seconds fw-semibold text-danger fs-10">00</span>
                                <div class="small text-danger fs-10">s</div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            @endif
            @endunless
        </div>
        <div class="flex-grow-1">
            <h6 class="mb-10px pe-3">
                <a href="{{ $cardClickUrl }}"
                   class="text-decoration-none lh-sm fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                    {{ $auctionProduct['name'] }}
                </a>
            </h6>

            <div class="d-flex justify-content-start gap-10px mb-12px align-items-center">
                @if(($myAuctionStatus ?? null) === 'claimed' && $auctionProduct->claimTransaction)
                    <span class="fs-12 title-semidark">{{ translate('Claimed_At') }}</span>
                    <strong class="title-clr fs-15px fw-bold">
                        {{ $auctionProduct->claimTransaction->created_at?->format('d M Y, h:i A') }}
                    </strong>
                @else
                    <span class="fs-12 title-semidark">{{ translate('Start Price') }}</span>
                    <strong class="title-clr fs-15px fw-bold">
                        {{ webCurrencyConverter(amount: $auctionProduct->starting_price) }}
                    </strong>
                @endif
            </div>
            @if($auctionProduct?->current_highest_bid_amount > 0)
                <div class="d-flex justify-content-start gap-10px mb-12px align-items-center">
                    <span class="fs-12 title-semidark">
                        {{ translate('Highest Bid') }}
                    </span>
                    <strong class="text-primary fs-15px fw-bold">
                        {{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount) }}
                    </strong>
                </div>
            @endif
            <div class="d-flex justify-content-between gap-1 flex-wrap">
                <div class="d-flex flex-wrap gap-xxl-20px gap-2 small text-muted mb-2">
                    <span class="d-flex align-items-center gap-1 title-clr fs-12">
                        <i class="fi fi-rr-eye text-light-gray"></i>
                        {{ formatCompactNumber($auctionProduct->total_views) }}
                    </span>
                    <span class="d-flex align-items-center gap-1 title-clr fs-12">
                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}"
                             alt="" class="svg">
                        {{ formatCompactNumber($auctionProduct->total_bids) }}
                    </span>
                </div>
                @php
                    $cardIsParticipant = auth('customer')->check() && $auctionProduct->myParticipation !== null;
                    $cardHasBid = auth('customer')->check() && $auctionProduct->myBid !== null;
                @endphp
                @if($isParticipatedTab && $auctionProduct['auction_current_status'] == 'upcoming')
                    <div class="" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="{{ translate('Kindly wait for the auction to go live to start bidding.') }}">
                        <button type="button" class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm" disabled>
                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                            {{ translate('Bid') }}
                        </button>
                    </div>
                @elseif($isParticipatedTab && $auctionProduct['auction_current_status'] == 'live')
                    <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                       href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                        {{ $cardHasBid ? translate('Raise Bid') : translate('Bid') }}
                    </a>
                @elseif($bidListContext && $myAuctionStatus === 'won' && !$isClaimTimeout)
                    <a href="{{ route('auction.claim.checkout', ['auctionProductId' => $auctionProduct->id]) }}"
                       class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                        {{ translate('Claim Product') }}
                    </a>
                @elseif($bidListContext && $myAuctionStatus === 'claimed')
                    @if(!empty($auctionProduct->tracking_url))
                        <a href="{{ $auctionProduct->tracking_url }}" target="_blank" rel="noopener noreferrer"
                           class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                            {{ translate('Track Order') }}
                        </a>
                    @else
                        <span class="d-inline-flex" tabindex="0" data-bs-toggle="tooltip" data-bs-title="{{ translate('Tracking URL not provided yet') }}">
                            <button type="button" disabled class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm" style="pointer-events:none;">
                                {{ translate('Track Order') }}
                            </button>
                        </span>
                    @endif
                @elseif(!$cardIsOwner)
                    @if($auctionProduct['auction_current_status'] == 'upcoming')
                        @if($cardIsParticipant)
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug]) }}">
                                {{ translate('Participated') }}
                            </a>
                        @elseif(!auth('customer')->check())
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm" href="{{ route('customer.auth.login') }}">
                                {{ translate('Participate') }}
                            </a>
                        @else
                            <button type="button"
                                    class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm auction-participate-btn"
                                    data-url="{{ route('auction.participate.check') }}"
                                    data-auction-product-id="{{ $auctionProduct->id }}"
                                    data-details-url="{{ route('auction.product-details', ['slug' => $auctionProduct->slug]) }}">
                                {{ translate('Participate') }}
                            </button>
                        @endif
                    @elseif($auctionProduct['auction_current_status'] == 'live')
                        @if(!auth('customer')->check())
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm" href="{{ route('customer.auth.login') }}">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt=""
                                     class="svg">
                                {{ translate('Participate') }}
                            </a>
                        @elseif(!$cardIsParticipant)
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_entry' => 1]) }}">
                                {{ translate('Participate') }}
                            </a>
                        @elseif(!$cardHasBid)
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                {{ translate('Bid') }}
                            </a>
                        @else
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                {{ translate('Raise Bid') }}
                            </a>
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if($savedListContext)
        <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
            <div class="btn-group dropstart">
                <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                </button>
                <ul class="dropdown-menu shadow-sm p-0">
                    <li class="p-0">
                        <button type="button"
                                class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1 js-remove-saved-auction"
                                data-auction-product-id="{{ $auctionProduct->id }}"
                                data-toggle-url="{{ route('auction.saved-products.toggle') }}">
                            {{ translate('Remove') }} <i class="fi fi-sr-trash text-danger"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    @elseif(isset($actionBtn) && $actionBtn == true)
        @php
            $viewOnlyStatuses = ['won', 'claimed', 'lost'];
            $showBidActions = !in_array($bidListTab, $viewOnlyStatuses) && $auctionProduct?->myBid && $auctionProduct['auction_current_status'] === 'live';
        @endphp
        <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
            <div class="btn-group dropstart">
                <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                </button>
                <ul class="dropdown-menu shadow-sm p-0">
                    <li class="p-0">
                        <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                           href="{{ auth('customer')->check() && auth('customer')->id() == $auctionProduct?->owner_id && $auctionProduct?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $auctionProduct?->slug]) : route('auction.profile-view.product-details', ['slug' => $auctionProduct?->slug]) }}">
                            {{ translate('View Details') }} <i class="fi fi-sr-eye text-primary"></i>
                        </a>
                    </li>
                    @if($bidListContext && $myAuctionStatus === 'claimed')
                    <li class="p-0">
                        <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                           href="{{ route('auction.generate-invoice', $auctionProduct->id) }}">
                            {{ translate('Invoice') }} <i class="fi fi-sr-file-invoice text-primary"></i>
                        </a>
                    </li>
                    @endif
                    @if($showBidActions && !($bidListContext && $myAuctionStatus === 'claimed') && !($bidListContext && $myAuctionStatus === 'won' && !$isClaimTimeout))
                        <li class="p-0">
                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug]) }}?open_bid=1">
                                {{ translate('Raise Bid') }} <i class="fi fi-sr-auction text-primary"></i>
                            </a>
                        </li>
                        <li class="p-0">
                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1 js-withdraw-bid-btn"
                               href="#"
                               data-auction-product-id="{{ $auctionProduct->id }}"
                               data-withdraw-url="{{ route('auction.bids.withdraw') }}">
                                {{ translate('Withdraw Bid') }}
                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                            </a>
                        </li>
                    @elseif($bidListContext && $auctionProduct['auction_current_status'] == 'live' && !$cardHasBid && !in_array($bidListTab, $viewOnlyStatuses))
                        <li class="p-0">
                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug]) }}?open_bid=1">
                                {{ translate('Bid') }} <i class="fi fi-sr-auction text-primary"></i>
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    @endif
</div>
