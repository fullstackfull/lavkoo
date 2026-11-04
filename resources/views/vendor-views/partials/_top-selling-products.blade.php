<div class="card-header gap-3 {{ ($topSell && count($topSell) > 0) ? '' : 'bg-section' }}">
    <h4 class="fs-14 d-flex align-items-center text-capitalize gap-2 mb-0">
         <img width="18" src="{{dynamicAsset(path: 'public/assets/back-end/img/top-selling.png')}}" alt="">
        {{ translate('Top_Selling_Products') }}
    </h4>
    @if($topSell && count($topSell) > 0)
    <a href="{{ route('vendor.products.list', ['type' => 'all', 'filter_sort_by' => 'best-selling']) }}"
       class="text--primary fw-semibold fs-12">{{ translate('View_All') }}</a>
    @endif
</div>

<div class="card-body min-h-260 max-h-460 overflow-y-auto {{ ($topSell && count($topSell) > 0) ? '' : 'bg-section' }}">
    @if($topSell && count($topSell) > 0)
        <div class="row g-2">
            @foreach($topSell as $key=> $product)
                <div class="col-xl-4 col-sm-6">
                    <div class="p-2 bg-light rounded cursor-pointer basic-box-shadow"  onclick="location.href='{{route('vendor.products.view',[$product['id']])}}'">
                        <div class="d-flex flex-column align-items-center justify-items-center gap-1">
                            <img class="avatar border avatar-60 rounded flex-shrink-0"
                                 src="{{ getStorageImages(path:$product->thumbnail_full_url, type: 'backend-product') }}"
                                 alt="{{$product->name}} image">
                            <div class="fs-12 title-color fw-medium line--limit-1 mt-2 hover-c1">{{ $product['name'] }}</div>
                            <div class="fs-12 text-center">
                                <div class="mb-1">{{ translate('Total_Sold_Price') }}</div>
                                <div class="text-dark fw-medium overflow-wrap-anywhere">{{ webCurrencyConverter($product->totalSoldAmount ?? 0)}}</div>
                            </div>
                            <div class="btn btn--primary py-1 px-2">
                                <div class="d-flex gap-3 align-items-center">
                                    <div class="fs-12 fw-medium d-flex gap-1 align-items-center">
                                        <div class="flex-shrink-0">{{translate('sold')}} :</div>
                                        <div class="sold-count overflow-wrap-anywhere">{{ $product->total_qty_sold ?? 0 }}</div>
                                    </div>
                                    <i class="fi fi-sr-shopping-cart fs-14 flex-shrink-0"></i>
                                </div>
                            </div>
                        </div>
                    </div>
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
