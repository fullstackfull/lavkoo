<div class="ending-soon-card bg-white border-0 p-10px shadow-sm rounded position-relative">

    @if($auctionProduct['auction_current_status'] == 'upcoming')
        <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Upcoming') }}
        </span>
    @elseif($auctionProduct['auction_current_status'] == 'live')
        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-2 px-10px py-5px fs-13 text-white z-1">
            {{ translate('Live') }}
        </span>
    @endif
    <div class="rounded ratio-1-1 overflow-hidden text-center border mb-10px position-relative">
        <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}" class="m-thumbnail d-block">
            <img src="{{ getStorageImages(path: $auctionProduct->thumbnail_full_url, type: 'product') }}"
                 alt="{{ $auctionProduct['name'] }}" class="w-100 object-fit-cover">
        </a>
        <div class="d-flex justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 start-0 flex-sm-nowrap flex-wrap gap-2px">
            <span class="fs-12 text-black status-text"
                  data-timeend-text="{{ translate('Closed') }}">
                {{ $auctionProduct['auction_current_status'] == 'upcoming' ? translate('Starts At') : translate('Closes In') }}
            </span>

            <div class="flex-aligns gap-lg-2 gap-1">
                <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                <div class="d-flex gap-5px countdown auction-countdown"
                 data-end="{{ $auctionProduct['auction_current_status'] == 'upcoming'
                    ? \Carbon\Carbon::parse($auctionProduct->start_time)->format('Y-m-d H:i:s')
                    : (\Carbon\Carbon::parse($auctionProduct->end_time)->isPast() ? \Carbon\Carbon::now()->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($auctionProduct->end_time)->format('Y-m-d H:i:s')) }}">
                    <div class="time-box flex-aligns gap-1px text-center fw-bold">
                        <span class="time hours fw-bold text-danger fs-14">00</span>
                        <div class="small text-danger fs-14">h</div>
                    </div>
                    <div class="time-box flex-aligns gap-1px text-center fw-bold">
                        <span class="time minutes fw-bold text-danger fs-14">00</span>
                        <div class="small text-danger fs-14">m</div>
                    </div>
                    <div class="time-box flex-aligns gap-1px text-center fw-bold">
                        <span class="time seconds fw-bold text-danger fs-14">00</span>
                        <div class="small text-danger fs-14">s</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="">
        <h6 class="mb-10px">
            <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}" class="text-decoration-none lh-sm fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                {{ $auctionProduct['name'] }}
            </a>
        </h6>
        <div class="d-flex flex-wrap justify-content-between mb-12px align-items-center">
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
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center min-h-24px">
            <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                <span class="d-flex align-items-center gap-1 title-clr fs-12">
                    <i class="fi fi-rr-eye text-light-gray"></i>
                    {{ formatCompactNumber($auctionProduct->total_views) }}
                </span>
                <span class="d-flex align-items-center gap-1 title-clr fs-12">
                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                    {{ formatCompactNumber($auctionProduct->total_bids) }}
                </span>
            </div>
            @php
                $cardIsOwner = auth('customer')->check() && $auctionProduct->owner_type === 'customer' && auth('customer')->id() == $auctionProduct->owner_id;
                $cardIsParticipant = auth('customer')->check() && $auctionProduct?->myParticipation !== null;
                $cardHasBid = auth('customer')->check() && $auctionProduct?->myBid !== null;
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
                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
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
