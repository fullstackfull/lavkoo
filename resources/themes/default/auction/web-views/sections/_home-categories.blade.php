@if(isset($auctionCategoryItem['auctionProduct']) && count($auctionCategoryItem['auctionProduct']) > 0)
<div class="phone-telecom-section p-20 card border-0 shadow-sm rounded overflow-hidden position-relative mb-20">
    <div class="section-card-margin">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3 pe-xl-3">
            <h2 class="m-0 text-capitalize flex-wrap fw-bold fs-5 d-flex align-items-center gap-2">
                <span class="line--limit-1">
                    {{ $auctionCategoryItem['name'] }}
                </span>
                <span class="badge bg-light title-clr fs-12 py-6px px-12px fw-medium">
                    {{ count($auctionCategoryItem['auctionProduct']) > 1 ? translate('Products') : translate('Product') }}
                    {{ count($auctionCategoryItem['auctionProduct']) }}
                </span>
            </h2>
            <div class="text-end">
                <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                   href="{{ route('auction.category-products', ['slug' => $auctionCategoryItem?->slug]) }}">
                    {{ translate('view_all')}}
                    <i class="fi fi-rr-angle-right fs-12"></i>
                </a>
            </div>
        </div>
        <div class="all-viewed-product position-relative owl-nav-center">
            <div class="owl-carousel including_product_slidewrap owl-theme" data-slide-items="{{ count($auctionCategoryItem['auctionProduct']) }}">
                @foreach($auctionCategoryItem['auctionProduct'] as $auctionProduct)
                    <div class="item py-2 px-2">
                        @include('auction.web-views.partials._auction-product-horizontal', ['auctionProduct' => $auctionProduct])
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif
