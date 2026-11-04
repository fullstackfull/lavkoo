@if(isset($trendingAuctionProducts) && count($trendingAuctionProducts) > 0)
    <div
        class="trending-auction-section p-20 bg-white card border-0 shadow-sm rounded overflow-hidden position-relative mb-20">
        <div class="section-card-margin">
            <div class="d-flex justify-content-between flex-sm-nowrap flex-wrap align-items-center gap-2 mb-30">
                <div class="d-lg-block d-none"></div>
                <div class="text-lg-center">
                    <h2 class="m-0 text-capitalize fw-bold fs-5 d-flex align-items-center justify-content-lg-center gap-1">
                        <img width="34" height="34"
                             src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/trending-fire.png') }}"
                             alt="{{ translate('Trending Auction Products ') }}">
                        <span class="line--limit-1">{{ translate('Trending Auction Products ') }}</span>
                    </h2>
                    <p class="mb-0 fs-16 title-semidark mt-10px">
                        {{ translate('Discover trending auction products with high demand and exciting bids.') }}
                    </p>
                </div>
                <div class="text-end">
                    <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                       href="{{ route('auction.trending-products') }}">
                        {{ translate('view_all')}}
                        <i class="fi fi-rr-angle-right fs-12"></i>
                    </a>
                </div>
            </div>

            <div class="trending-product-wrap">
                @foreach($trendingAuctionProducts as $auctionProduct)
                    @include('auction.web-views.partials._auction-product-card-5', ['auctionProduct' => $auctionProduct])
                @endforeach
            </div>
        </div>
    </div>
@endif
