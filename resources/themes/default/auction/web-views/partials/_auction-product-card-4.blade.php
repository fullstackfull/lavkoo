<div class="bg-white h-100">

    <div class="ending-soon-card card-icon-support bg-white shadow-sm border-0 p-10px rounded position-relative h-100">
            @if($auctionProduct['auction_current_status'] == 'upcoming')
                <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                    {{ translate('Upcoming') }}
                </span>
            @elseif($auctionProduct['auction_current_status'] == 'live')
                <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                    {{ translate('Live') }}
                </span>
            @endif

            <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}" class="m-thumbnail d-block overflow-hidden">
                    <img src="{{ getStorageImages(path: $auctionProduct->thumbnail_full_url, type: 'product') }}"
                         alt="" class="w-100 object-fit-cover">
                </a>

                <div class="d-flex bg-light py-1 px-2 justify-content-between w-100 align-items-center">
                    <span class="fs-12 text-black status-text"
                          data-timeend-text="{{ translate('Closed') }}">
                        {{ $auctionProduct['auction_current_status'] == 'upcoming' ? translate('Starts At') : translate('Closes In') }}
                    </span>

                    <div class="d-flex align-items-center gap-1">
                        <i class="fi fi-rr-time-oclock d-flex fs-12 text-danger"></i>

                        <div class="d-flex align-items-center gap-1 countdown auction-countdown"
                             data-end="{{ $auctionProduct['auction_current_status'] == 'upcoming'
                                ? \Carbon\Carbon::parse($auctionProduct->start_time)->format('Y-m-d H:i:s')
                                : (\Carbon\Carbon::parse($auctionProduct->end_time)->isPast() ? \Carbon\Carbon::now()->format('Y-m-d H:i:s') : \Carbon\Carbon::parse($auctionProduct->end_time)->format('Y-m-d H:i:s')) }}">

                            <div class="time-box fw-semibold d-flex align-items-center text-center">
                                <span class="time hours text-danger fs-14">00</span>
                                <div class="small text-danger fs-14">h</div>
                            </div>

                            <div class="time-box fw-semibold d-flex align-items-center text-center">
                                <span class="time minutes text-danger fs-14">00</span>
                                <div class="small text-danger fs-14">m</div>
                            </div>

                            <div class="time-box fw-semibold d-flex align-items-center text-center">
                                <span class="time seconds text-danger fs-14">00</span>
                                <div class="small text-danger fs-14">s</div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="content_box">
                <h6 class="mb-10px">
                    <a href="{{ route('auction.product-details', ['slug' => $auctionProduct?->slug]) }}"
                       class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
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
                    <div>
                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                            <span class="d-flex align-items-center gap-1 text-black fs-12">
                                <i class="fi fi-rr-eye text-gray text-black-50 fs-12"></i>
                                {{ formatCompactNumber($auctionProduct->total_views) }}
                            </span>
                            <span class="d-flex align-items-center gap-1 text-black fs-12">
                                <i class="fi fi-rr-auction text-gray text-black-50 fs-13"></i>
                                {{ formatCompactNumber($auctionProduct->total_bids) }}
                            </span>
                        </div>
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
                                <a class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                                   href="{{ route('customer.auth.login') }}">
                                    {{ translate('Participate') }}
                                </a>
                            @else
                                <button type="button"
                                        class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm auction-participate-btn"
                                        data-url="{{ route('auction.participate.check') }}"
                                        data-auction-product-id="{{ $auctionProduct->id }}"
                                        data-details-url="{{ route('auction.product-details', ['slug' => $auctionProduct->slug]) }}">
                                    {{ translate('Participate') }}
                                </button>
                            @endif
                        @elseif($auctionProduct['auction_current_status'] == 'live')
                            @if(!auth('customer')->check())
                                <a class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                                   href="{{ route('customer.auth.login') }}">
                                    <i class="fi fi-sr-auction"></i>
                                    {{ translate('Participate') }}
                                </a>
                            @elseif(!$cardIsParticipant)
                                <a class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                                   href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_entry' => 1]) }}">
                                    {{ translate('Participate') }}
                                </a>
                            @elseif(!$cardHasBid)
                                <a class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                                   href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                    <i class="fi fi-sr-auction"></i>
                                    {{ translate('Bid') }}
                                </a>
                            @else
                                <a class="btn btn--primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm"
                                   href="{{ route('auction.product-details', ['slug' => $auctionProduct->slug, 'open_bid' => 1]) }}">
                                    <i class="fi fi-sr-auction"></i>
                                    {{ translate('Raise Bid') }}
                                </a>
                            @endif
                        @endif
                    @endif
                </div>
            </div>
        </div>
</div>
