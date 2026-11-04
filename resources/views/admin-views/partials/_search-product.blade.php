@if (count($products) > 0)
    <div class="select-product-item media gap-3 border-bottom py-3 cursor-pointer action-select-product align-items-center">
        <div class="media-body d-flex flex-column gap-1 text-start">
            <h5 class="text-capitalize mb-1 product-name">{{ translate('All Products') }}</h5>
        </div>
    </div>
    @foreach ($products as $key => $product)
        <div class="select-product-item media gap-3 border-bottom py-3 cursor-pointer action-select-product align-items-center" data-id="{{ $product['id'] }}">
            <img class="border" width="75" src="{{ getStorageImages(path: $product->thumbnail_full_url , type: 'backend-basic') }}" alt="">
            <div class="media-body d-flex flex-column gap-1 text-start">
                <h5 class="product-id" hidden>{{$product['id']}}</h5>
                <h5 class="text-capitalize mb-1 product-name">{{$product['name']}}</h5>
                <div  class="fs-10">
                    @if($product['unit_price'] > 0)
                    <span class="me-2">{{translate('price').' '.':'.' '.setCurrencySymbol(usdToDefaultCurrency(amount: $product['unit_price']))}}</span>
                    @endif
                    @if(!empty($product?->category?->name))
                    <span class="me-2">{{translate('category').' '.':'.' '}}{{  $product->category->name }}</span>
                    @endif
                    @if(!empty($product?->brand?->name))
                    <span class="me-2">{{translate('brand').' '.':'.' '}}{{ $product?->brand?->name }}</span>
                    @endif
                    @if ($product->added_by == "seller" && !empty($product?->seller?->shop?->name))
                        <span>{{translate('shop').' '.':'.' '}} <span class="text-primary">{{ $product?->seller?->shop?->name }}</span></span>
                    @elseif(!empty(getInHouseShopConfig(key:'name')))
                        <span>{{translate('shop').' '.':'.' '}} <span class="text-primary">{{getInHouseShopConfig(key:'name')}}</span></span>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
@else
    {{-- Centered icon + text empty state, matching the clearance-sale
         _search-product partial. Renders when the AJAX search returns
         no products inside the dropdown. --}}
    <div class="text-center p-4">
        <img class="mb-3 w-60" src="{{ dynamicAsset(path: 'public/assets/back-end/img/empty-state-icon/default.png') }}"
             alt="{{ translate('image_description') }}">
        <p class="mb-0 text-muted">{{ translate('No_Product_Found') }}</p>
    </div>
@endif
