@extends("auction.layouts.auction-app")

@section('title', 'Auction Page')

@section('content')
    <div class="container">

        @if(isset($auctionRecentView) && count($auctionRecentView) > 0)
        <div class="auction__badge" data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="{{ translate('Recent View') }}">
            <div class="btn_auction p-0 d-flex align-items-center justify-content-center" data-bs-toggle="offcanvas" data-bs-target="#recently_view-offcanvas">
                <img width="31" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/recent-view.png') }}" alt="img">
            </div>
            <button type="button" class="auction_view d-center bt outline-0 border-0">
                {{ formatCompactNumber(count($auctionRecentView)) }}
            </button>
        </div>
        @endif

        @include("auction.web-views.sections._banner")
        @include("auction.web-views.sections._ending-soon", ['endingSoonProducts' => $endingSoonProducts])
        @include("auction.web-views.sections._trending-product", ['trendingAuctionProducts' => $trendingAuctionProducts])
        @include("auction.web-views.sections._categories", ['auctionCategories' => $auctionCategories])
        @include("auction.web-views.sections._recently-viewed", ['auctionRecentView' => $auctionRecentView])
        @include("auction.web-views.sections._upcoming-auction", ['upcomingAuctionProducts' => $upcomingAuctionProducts])

        @foreach($auctionCategoryWise as $auctionCategoryItem)
            @include("auction.web-views.sections._home-categories", ['auctionCategoryItem' => $auctionCategoryItem])
        @endforeach

    </div>
@endsection
