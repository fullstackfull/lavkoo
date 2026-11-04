@if (count($category['products']) > 0)
@php($sectionBanners = ($categorySectionBanners ?? collect())->get($category['id'], collect()))
<section class="container rtl pb-0 px-max-sm-0">
    <div class="__shadow-2">
        <div class="__p-20px rounded bg-white overflow-hidden">
            {{-- Category section banners: merchandising strip above this category's
                 products. The apps render the same records with their mobile image. --}}
            @if ($sectionBanners->count() > 0)
                <div class="mb-3">
                    @include('web-views.partials._banner-grid', [
                        'banners' => $sectionBanners,
                        'fallbackUrl' => route('category-products', ['slug' => $category['slug']]),
                    ])
                </div>
            @endif

            <div class="d-flex __gap-6px flex-between align-items-baseline px-sm-3">
                <h2 class="category-product-view-title mb-0">
                    <span class="for-feature-title font-bold __text-20px text-uppercase">
                            {{$category['name']}}
                    </span>
                </h2>
                <div class="category-product-view-all">
                    <a class="text-capitalize view-all-btn-text"
                       href="{{ route('category-products', ['slug' => $category['slug']]) }}">
                        <span class="view-btn-text">{{ translate('View_All') }}</span>
                        <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                    </a>
                </div>
            </div>

            <div class="mt-2">
                <div class="carousel-wrap-2 d-none d-sm-block">
                    <div class="owl-carousel owl-theme category-wise-product-slider" data-slide-items="6">
                        @foreach($category['products'] as $key => $product)
                            @if($key < 8)
                                @include('web-views.partials._category-single-product',['product' => $product])
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="d-sm-none">
                    <div class="row g-2">
                        @foreach($category['products'] as $key=>$product)
                            @if($key < 4)
                                <div class="col-6">
                                    @include('web-views.partials._category-single-product', ['product'=>$product])
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
