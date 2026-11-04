<div class="card-header gap-10">
    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
        <img src="{{dynamicAsset(path: 'public/assets/back-end/img/most-popular-store-icon.png')}}" alt="">
        {{translate('most_Popular_Stores')}}
    </h4>
</div>
<div class="card-body">
    @if($top_store_by_order_received)
        <div class="d-flex flex-column gap-10">
            @foreach($top_store_by_order_received as $key => $vendorItem)
                @if(isset($vendorItem?->shop))
                    <a href="{{route('admin.vendors.view', $vendorItem['id'])}}" class="border p-20 rounded">
                        <div class="row align-items-center g-2 justify-content-between">
                            <div class="col-9">
                                <div class="d-flex align-items-center gap-10">
                                    <img width="35" src="{{getStorageImages(path: $vendorItem?->shop?->image_full_url, type:'backend-basic')}}" class="rounded-circle aspect-1" alt="">
                                    <h5 class="mb-0 line-1 pe-2">{{ $vendorItem?->shop?->name ?? 'Not exist' }}</h5>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <h5 class="shop-sell mb-0">{{ $vendorItem['wishlist_count'] ?? 0 }}</h5>
                                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/love.png')}}" alt="">
                                </div>
                            </div>
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    @else
        <div class="text-center">
            <p class="text-muted">{{translate('no_Top_Selling_Products')}}</p>
            <img class="w-75" src="{{dynamicAsset(path: 'public/assets/back-end/img/no-data.png')}}" alt="">
        </div>
    @endif
</div>
