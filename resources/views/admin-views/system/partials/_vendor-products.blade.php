<div class="card card-body">
    <h3 class="mb-3 fw-bold fs-16">{{ translate('Vendor_Products') }}</h3>
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card bg-body h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <i class="fi fi-sr-star text-secondary"></i>
                        {{ translate('Most_Rated_Products') }}
                    </h4>
                    @if($vendorMostRatedProducts && count($vendorMostRatedProducts) > 0)
                    <a href="{{ route('admin.products.list', ['type' => 'vendor', 'filter_sort_by' => 'most-favorite', 'request_status' => 1]) }}"
                       class="fw-semibold text-primary">{{ translate('View_All') }}</a>
                    @endif
                </div>
                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    @if($vendorMostRatedProducts && count($vendorMostRatedProducts) > 0)
                        <div class="row">
                            <div class="col-12">
                                <div class="row g-3">
                                    @foreach($vendorMostRatedProducts as $key => $product)
                                        @if(isset($product['id']))
                                            <div class="col-sm-6">
                                                <a href="{{ route('admin.products.view', ['addedBy' => ($product['added_by'] == 'seller' ? 'vendor' : 'in-house'), 'id' => $product['id']]) }}"
                                                   class="card card-body text-decoration-none text-reset">

                                                    <div class="d-flex align-items-center gap-2">
                                                        <img width="45"
                                                             class="border rounded aspect-1"
                                                             src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                                             alt="{{ $product->name }}{{ translate('image') }}">
                                                        <div>
                                                            <h5 class="fs-12 fw-medium line-1 mb-2 text-hover-primary">
                                                                {{ isset($product['name']) ? $product->name : 'not exists' }}
                                                            </h5>
                                                            <div class="d-flex align-items-center gap-1 flex-wrap fs-12">
                                                            <span class="text-secondary d-flex align-items-center fw-bold gap-1">
                                                                <i class="fi fi-sr-star text-secondary"></i>
                                                                {{ round($product['ratings_average'], 2) }}
                                                            </span>
                                                                <span class="d-flex align-items-center gap-10">
                                                                ({{ $product['reviews_count'] }} {{ translate('reviews') }})
                                                            </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </a>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_Product_Available',
                                'image' => 'product',
                                'width' => 45
                            ])
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card bg-body h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <img width="16" src="{{ dynamicAsset(path: 'public/assets/back-end/img/top-selling.png') }}" alt="">
                        {{ translate('Top_Selling_Products') }}
                    </h4>
                    @if(isset($vendorTopSellProduct) && count($vendorTopSellProduct) > 0)
                    <a href="{{ route('admin.products.list', ['type' => 'vendor', 'filter_sort_by' => 'best-selling', 'request_status' => 1]) }}"
                       class="fw-semibold text-primary">{{ translate('View_All') }}</a>
                    @endif
                </div>
                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    @if(isset($vendorTopSellProduct) && count($vendorTopSellProduct) > 0)
                        <div class="grid-card-wrap gap-3" style="--item-value: 200px;">
                            @foreach($vendorTopSellProduct as $key => $product)
                                @if(isset($product['id']))
                                    <a href="{{ route('admin.products.view', ['addedBy' => ($product['added_by'] == 'seller' ? 'vendor' : 'in-house'), 'id' => $product['id']]) }}" class="text-decoration-none text-reset">
                                        <div class="card card-body d-flex flex-column align-items-center overflow-wrap-anywhere">
                                            <img width="64" src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'backend-product') }}"
                                                 class="rounded aspect-1"
                                                 alt="{{ $product['name'].'_'.translate('image') }}">
                                            <h5 class="fs-12 fw-medium line-1 mb-1 mt-3 text-hover-primary">{{ $product['name'] }}</h5>
                                            <div class="fs-12 mb-1">{{ translate('Total_Sold_Price') }}</div>
                                            <h5 class="fs-12 fw-medium mb-2">{{ webCurrencyConverter($product->totalSoldAmount ?? 0)}}</h5>
                                            <span class="badge text-bg-info badge-info badge-lg d-inline-flex justify-content-center fs-12 gap-1">
                                            <div>{{ translate('sold') }} :</div>
                                            <div class="fw-bold text-wrap">{{ $product->total_qty_sold ?? 0 }}</div>
                                        </span>
                                        </div>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_Product_Available',
                                'image' => 'product',
                                'width' => 45
                            ])
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
