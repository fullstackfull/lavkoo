<div class="card-header gap-3  {{ ($topRatedProducts && count($topRatedProducts) > 0) ? '' : 'bg-section' }}">
    <h4 class="fs-14 d-flex align-items-center text-capitalize gap-2 mb-0">
        <i class="fi fi-sr-star text-warning-dark fs-18 d-inline-flex"></i>
        {{translate('Most_Rated_Products')}}
    </h4>
    @if($topRatedProducts && count($topRatedProducts) > 0)
    <a href="{{ route('vendor.products.list', ['type' => 'all', 'filter_sort_by' => 'most-favorite']) }}"
       class="text--primary fw-semibold fs-12">{{ translate('View_All') }}</a>
    @endif
</div>

<div class="card-body min-h-260 max-h-460 overflow-y-auto {{ ($topRatedProducts && count($topRatedProducts) > 0) ? '' : 'bg-section' }}">
    @if($topRatedProducts && count($topRatedProducts) > 0)
        <div class="row g-2">
            @foreach($topRatedProducts as $key=>$product)
                <div class="col-sm-6">
                    <a href="{{ route('vendor.products.view', [$product['id']]) }}"
                       class="shadow-sm border-0 basic-box-shadow d-block text-decoration-none p-10px"
                       aria-label="{{ $product->name }}">
                        <div class="d-flex align-items-center gap-2">
                            <img class="avatar border avatar-60 rounded flex-shrink-0"
                                src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                alt="{{ $product->name }} image">
                            <div>
                                <div class="fs-12 title-color fw-medium line--limit-1 mb-1 hover-c1">
                                    {{ isset($product) ? $product->name : 'not exists' }}
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="bg-light px-2 py-1 rounded text-warning-dark d-flex align-items-center gap-2 fw-medium fs-12">
                                        <i class="fi fi-sr-star"></i>
                                        {{ round($product['ratings_average'], 2) }}
                                    </span>
                                    <span class="d-flex align-items-center gap-1 text-body fw-medium fs-12">
                                        {{ $product['reviews_count'] }} {{ translate('reviews') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @else
        <div class="d-flex justify-content-center align-items-center h-100">
            @include('layouts.vendor.partials._empty-state', [
                'text' => 'No_Product_Available',
                'image' => 'product',
                'width' => 45
            ])
        </div>
    @endif
</div>
