@if(isset($upcomingAuctionProducts) && count($upcomingAuctionProducts) > 0)
    <div class="upcoming-section rounded overflow-hidden position-relative mb-20">
        <div class="section-card-margin">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-20">
                <h2 class="m-0 text-capitalize fw-bold fs-5 d-flex align-items-center gap-1">
                    <span class="line--limit-1">{{ translate('Upcoming Auction') }}</span>
                </h2>
                <div class="text-end">
                    <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                       href="{{ route('auction.upcoming-products') }}">
                        {{ translate('View_All') }}
                        <i class="fi fi-rr-angle-right fs-12"></i>
                    </a>
                </div>
            </div>
            <div class="row g-xxl-3 g-lg-3 g-2">
                <div class="col-xxl-1 col-sm-2 d-sm-block d-none">
                    <div class="upcoming-badge-thumb d-center rounded overflow-hidden">
                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/coming-soon.png') }}"
                             alt="{{ translate('Upcoming Auction') }}" class="w-100 h-100 object-fit-contain">
                    </div>
                </div>
                <div class="col-xxl-11 col-sm-10">
                    <div class="upcoming-soon-product position-relative owl-nav-center">
                        <div class="owl-carousel upcoming_auction_slidewrap owl-theme" data-slide-items="{{ count($upcomingAuctionProducts) }}">
                            @foreach($upcomingAuctionProducts as $auctionProduct)
                                <div class="item">
                                    @include('auction.web-views.partials._auction-product-card-5', ['auctionProduct' => $auctionProduct])
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
