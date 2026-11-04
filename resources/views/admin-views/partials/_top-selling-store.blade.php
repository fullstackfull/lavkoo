<div class="card-header gap-10">
    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
        <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/shop-info.png')}}" alt="">
        {{translate('top_selling_store')}}
    </h4>
</div>

<div class="card-body">
    <div class="d-flex flex-column gap-10">
        @if($topVendorByEarning)
            @foreach($topVendorByEarning as $key=> $vendor)
                @if(isset($vendor->seller->shop))
                    <div class="border p-20 rounded cursor-pointer get-view-by-onclick" data-link="{{ route('admin.vendors.view', $vendor['seller_id'])}}">
                        <div class="row align-items-center g-2 justify-content-between">
                            <div class="col-8">
                                <div class="d-flex align-items-center gap-10">
                                    <img width="35" class="rounded-circle aspect-1" alt="" src="{{getStorageImages(path: $vendor->seller->shop->image_full_url,type:'backend-basic')}}">
                                    <h5 class="mb-0 line-1 pe-2">{{ $vendor->seller->shop['name'] ?? 'Not exist' }}</h5>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <h5 class="shop-sell mb-0">
                                        {{ setCurrencySymbol(amount: currencyConverter(amount: $vendor['total_earning'])) }}
                                    </h5>
                                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/cart2.png')}}" alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        @else
            <div class="text-center">
                <p class="text-muted">{{translate('no_Top_Selling_Products')}}</p>
                <img class="w-75" src="{{dynamicAsset(path: 'public/assets/back-end/img/no-data.png')}}" alt="">
            </div>
        @endif
    </div>
</div>
