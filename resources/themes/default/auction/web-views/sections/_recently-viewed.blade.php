@if(isset($auctionRecentView) && count($auctionRecentView) > 0)
    <div class="recently-viewed-section p-20 bg-section3 rounded overflow-hidden position-relative mb-20">
        <div class="section-card-margin">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3 pe-xl-3">
                <h2 class="m-0 text-capitalize fw-bold fs-5 d-flex align-items-center gap-1">
                    <span class="line--limit-1">{{ translate('Recently Viewed') }}</span>
                </h2>
                @if(auth('customer')->check())
                <div class="text-end">
                    <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                       href="{{ route('auction.products.recent') }}">
                        {{ translate('view_all')}}
                        <i class="fi fi-rr-angle-right fs-12"></i>
                    </a>
                </div>
                @endif
            </div>

            <div class="recently-viewed-product position-relative owl-nav-center">
                <div class="owl-carousel recently_views_slidewrap owl-theme" data-slide-items="3">
                    @foreach($auctionRecentView as $auctionProduct)
                        <div class="item">
                            @include('auction.web-views.partials._auction-recent-view-horizontal', ['auctionProduct' => $auctionProduct])
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
