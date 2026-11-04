
@if (count($clearanceSaleProducts) > 0)
<section class="container rtl pb-0 px-max-sm-0">
    <div class="__shadow-2">
        <div class="__p-20px rounded bg-white overflow-hidden">
            <div class="d-flex __gap-6px flex-between align-items-center">
                <div>
                    <div class="clearance-sale-title-bg" data-bg-img="{{ theme_asset(path: 'public/assets/front-end/img/media/clearance-sale-title-bg.svg') }}">
                        <h3 class="title mb-0 letter-spacing-0 text-uppercase">
                            <span>{{ translate('Clearance_Sale') }}</span>
                        </h3>
                    </div>
                </div>
                <div>
                    <a class="text-capitalize view-all-btn-text"
                       href="{{ route('clearance-sale-products') }}">
                        <span class="view-btn-text">{{ translate('View_All') }}</span>
                        <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                    </a>
                </div>
            </div>

            <div class="mt-2">
                <div class="carousel-wrap-2 d-none d-sm-block">
                    <div class="owl-carousel owl-theme category-wise-product-slider clearance-sale-slider" data-slide-items="{{ count($clearanceSaleProducts) }}">
                        @foreach($clearanceSaleProducts as $key => $product)
                            @include('web-views.partials._filter-single-product', ['product'=> $product])
                        @endforeach
                    </div>
                </div>

                <div class="d-sm-none">
                    <div class="row g-2 h-100">
                        @foreach($clearanceSaleProducts as $key => $product)
                            @if(count($clearanceSaleProducts) >= 4 ? ($key < 4) : ($key < 2))
                                <div class="col-6">
                                    @include('web-views.partials._filter-single-product', ['product' => $product])
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif
