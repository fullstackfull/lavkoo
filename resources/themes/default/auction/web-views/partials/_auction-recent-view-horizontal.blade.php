<div class="ending-soon-card bg-white border-0 p-10px rounded position-relative">
    @if($auctionProduct['auction_current_status'] == 'upcoming')
        <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Upcoming') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'live')
        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Live') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'ready_to_claim')

    @elseif($auctionProduct['auction_current_status'] == 'purchase_complete')
        @if($auctionProduct->delivery_status == 'ready_to_delivery')
            <span class="badge bg-warning rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-dark z-1">
                {{ translate('Ready to Delivery') }}
            </span>
        @elseif($auctionProduct->delivery_status == 'on_the_way')
            <span class="badge rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1" style="background-color:#546e7a">
                {{ translate('On the Way') }}
            </span>
        @else
            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
                {{ translate('Sold') }}
            </span>
        @endif
    @elseif($auctionProduct['auction_current_status'] == 'unsold')
        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Expired') }}
        </span>
    @endif

    <div class="d-flex gap-10px align-items-center">
        <div class="rounded w-110px max-w-110px h-100px min-w-100px overflow-hidden text-center border position-relative">
            <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}" class="m-thumbnail d-block">
                <img alt="" class="w-100 h-100"
                    src="{{ getStorageImages(path: $auctionProduct->thumbnail_full_url, type: 'product') }}">
            </a>

            @if($auctionProduct['auction_current_status'] != 'unsold')
            <div class="d-flex justify-content-between w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                <div class="flex-aligns gap-1">
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
            </div>
            @endif
        </div>

        <div class="w-100">
            <h6 class="mb-10px">
                <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}"
                   class="text-decoration-none fs-13 text-capitalize fw-semibold title-clr line--limit-1">
                    {{ $auctionProduct['name'] }}
                </a>
            </h6>
            <div class="d-flex justify-content-between mb-12px align-items-center">
                @if($auctionProduct?->current_highest_bid_amount > 0)
                    <span class="fs-12 title-semidark">{{ translate('Highest Bid') }}</span>
                    <strong class="text-primary fs-15px fw-bold">
                        {{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount) }}
                    </strong>
                @else
                    <span class="fs-12 title-semidark">{{ translate('Start Price') }}</span>
                    <strong class="text-primary fs-15px fw-bold">
                        {{ webCurrencyConverter(amount: $auctionProduct->starting_price) }}
                    </strong>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                    <span class="d-flex align-items-center gap-1 title-clr fs-12">
                        <i class="fi fi-rr-eye text-light-gray"></i>
                        {{ formatCompactNumber($auctionProduct->total_views) }}
                    </span>
                    <span class="d-flex align-items-center gap-1 title-clr fs-12">
                        <img alt="" class="svg"
                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}">
                        {{ formatCompactNumber($auctionProduct->total_bids) }}
                    </span>
                </div>
                @php
                    $cardIsOwner = auth('customer')->check() && $auctionProduct->owner_type === 'customer' && auth('customer')->id() == $auctionProduct->owner_id;
                    $cardIsParticipant = auth('customer')->check() && $auctionProduct->myParticipation !== null;
                    $cardHasBid = auth('customer')->check() && $auctionProduct->myBid !== null;
                @endphp
                @if(!$cardIsOwner)
                    @if($auctionProduct['auction_current_status'] == 'upcoming')
                        @if($cardIsParticipant)
                            <a class="btn bg-primary bg-opacity-10 text-primary rounded-pill btn-sm px-2 py-1 fw-bold fs-12 lh-sm"
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
                                <img alt="" class="svg"
                                     src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}">
                                {{ translate('Bid') }}
                            </a>
                        @elseif(!$cardIsParticipant)
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_entry' => 1]) }}">
                                {{ translate('Participate') }}
                            </a>
                        @elseif(!$cardHasBid)
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                <img alt="" class="svg" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}">
                                {{ translate('Bid') }}
                            </a>
                        @else
                            <a class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                               href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                <img alt="" class="svg" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}">
                                {{ translate('Raise Bid') }}
                            </a>
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
