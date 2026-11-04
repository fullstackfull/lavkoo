@if(isset($endingSoonProducts) && count($endingSoonProducts) > 0)
    <div class="ending-soon-section p-20 bg-gradient1 rounded overflow-hidden position-relative mb-20">
        <div class="section-card-margin">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-20">
                <h2 class="m-0 text-capitalize fw-bold fs-5 d-flex align-items-center gap-1">
                    <img width="34" height="34"
                         src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/ending-soon.png') }}"
                         alt="{{ translate('Ending Soon') }}">
                    <span class="line--limit-1">{{ translate('Ending Soon') }}</span>
                </h2>
                <div class="text-end">
                    <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                       href="{{ route('auction.ending-soon-products') }}">
                        {{ translate('view_all') }}
                        <i class="fi fi-rr-angle-right fs-12"></i>
                    </a>
                </div>
            </div>

            <div class="ending-soon-product position-relative owl-nav-center">
                <div class="owl-carousel ending_soon_slidewrap owl-theme" data-slide-items="5">
                    @foreach($endingSoonProducts as $auctionProduct)
                        <div class="item">
                            @include('auction.web-views.partials._auction-product-card-5', ['auctionProduct' => $auctionProduct])
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endif
