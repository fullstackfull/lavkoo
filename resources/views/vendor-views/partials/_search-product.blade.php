@if (count($products) > 0)
    <div
        class="select-product-item media gap-3 border-bottom py-3 cursor-pointer action-select-product align-items-center">
        <div class="media-body d-flex flex-column gap-1 text-start">
            <h5 class="text-capitalize mb-1 product-name">{{ translate('All Products') }}</h5>
        </div>
    </div>
    @foreach($products as $product)
        <div class="select-product-item media gap-3 border-bottom py-2 cursor-pointer action-select-product"
             data-id="{{ $product['id'] }}">
            <img class="avatar avatar-xl border" width="75"
                 src="{{ getStorageImages(path:$product->thumbnail_full_url,type:'backend-product') }}"
                 data-default-src="{{ getStorageImages(path:$product->thumbnail_full_url,type:'backend-product') }}"
                 alt="">
            <div class="media-body d-flex flex-column gap-1">
                <h6 class="product-id" hidden>{{$product['id']}}</h6>
                <h6 class="fz-13 mb-1 text-truncate custom-width product-name ">{{$product['name']}}</h6>
                @if(!empty($product?->category?->name))
                    <div class="fs-10">{{ translate('category') }}: {{ $product?->category?->name ?? 'N/a' }}</div>
                @endif
                @if(!empty($product?->brand?->name))
                    <div class="fs-10">{{ translate('brand_Name') }}: {{ $product?->brand?->name }}</div>
                @endif
                @if ($product->added_by == 'admin' && !empty(getInHouseShopConfig(key:'name')))
                    <div class="fs-10">{{ translate('vendor') }}: {{ getInHouseShopConfig(key:'name') }}</div>
                @elseif(!empty($product?->seller?->shop?->name))
                    <div class="fs-10">
                        {{ translate('vendor') }}
                        : {{ $product?->seller?->shop?->name}}
                    </div>
                @endif
            </div>
        </div>
    @endforeach
@else
    <div>
        <h5 class="m-0 text-muted">{{ translate('No_Product_Found') }}</h5>
    </div>
@endif
