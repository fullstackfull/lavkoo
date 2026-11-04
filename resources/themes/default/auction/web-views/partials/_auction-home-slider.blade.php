@if(isset($auctionProducts) && count($auctionProducts) > 0)
<div class="container pt-2">
    <div class="auction-product-dealswrap p-20 overflow-hidden position-relative">
        <div class="auction-product-svg-bg" style="background-image: url({{ theme_asset(path: "public/assets/front-end/img/auction-bg.svg") }})"></div>
        <div class="d-flex justify-content-between align-items-baseline mb-3">
            <h2 class="feature-product-title d-flex align-items-center gap-2 font-bold m-0 text-capitalize h5 letter-spacing-0">
                <img class="" width="20" src="{{ theme_asset(path: "public/assets/front-end/img/bid-icon.png") }}" alt="{{ translate('Auction Products') }}">
                {{ translate('Auction Products') }}
            </h2>
            <div class="text-end d-none d-md-block">
                <a class="text-capitalize view-all-text bg-white rounded py-2 px-3 web-text-primary"
                   href="{{ route('auction.index') }}">
                    {{ translate('Explore Auction')}}
                    <i class="czi-arrow-{{Session::get('direction') === 'rtl' ? 'left mr-1 ml-n1 mt-1' : 'right ml-1'}}"></i>
                </a>
            </div>
        </div>
        <div>
            <div class="owl-carousel auction_products_listSlide owl-theme" data-slide-items="{{ count($auctionProducts) }}">
                @foreach($auctionProducts as $auctionProduct)
                    <div class="item my-1">
                        @include('auction.web-views.partials._auction-product-card-4', ['auctionProduct' => $auctionProduct])
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif
