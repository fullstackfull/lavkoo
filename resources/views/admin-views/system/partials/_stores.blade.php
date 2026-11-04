<div class="card card-body">
    <h3 class="mb-3 fw-bold fs-16">{{ translate('Stores') }}</h3>
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card border h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <img width="16"
                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/most-popular.png') }}" alt="">
                        {{ translate('Most_Popular_Stores') }}
                    </h4>
                    @if($topVendorListByWishlist && count($topVendorListByWishlist) > 0)
                    <a href="{{ route('admin.vendors.vendor-list', ['sort_by' => 'most-favorite']) }}"
                       class="fw-semibold text-primary">
                        {{ translate('View_All') }}
                    </a>
                    @endif
                </div>
                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    @if($topVendorListByWishlist && count($topVendorListByWishlist) > 0)
                        <div class="row g-3">
                            @foreach($topVendorListByWishlist as $key => $vendorItem)
                                @if(isset($vendorItem?->shop))
                                    <div class="col-sm-6">
                                        <a href="{{ route('admin.vendors.view', $vendorItem['id']) }}">
                                            <div class="d-flex align-items-center gap-2 bg-section p-3 rounded overflow-wrap-anywhere">
                                                <img width="48" src="{{getStorageImages(path: $vendorItem?->shop?->image_full_url, type:'backend-basic') }}" class="rounded-circle aspect-1 border" alt="">
                                                <div class="flex-grow-1">
                                                    <h5 class="mb-1 line-1 fw-medium text-hover-primary">{{ $vendorItem?->shop?->name ?? 'Not exist' }}</h5>
                                                    <div class="d-flex align-items-center gap-2 text-warning-dark">
                                                        <i class="fi fi-sr-heart"></i>
                                                        <h5 class="shop-sell mb-0 text-warning-dark">{{ $vendorItem['wishlist_count'] ?? 0 }}</h5>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_stores_available',
                                'image' => 'store',
                                'width' => 45
                            ])
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card border h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <img width="16" src="{{ dynamicAsset(path: 'public/assets/back-end/img/top-selling.png') }}" alt="">
                        {{ translate('top_selling_stores') }}
                    </h4>
                    @if($topVendorByEarning && count($topVendorByEarning) > 0)
                    <a href="{{ route('admin.vendors.vendor-list', ['sort_by' => 'orders_count']) }}" class="fw-semibold text-primary">
                        {{ translate('View_All') }}
                    </a>
                    @endif
                </div>

                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    <div class="row g-3">
                        @if($topVendorByEarning && count($topVendorByEarning) > 0)
                            @foreach($topVendorByEarning as $key=> $vendor)
                                @if(isset($vendor->seller->shop))
                                <div class="col-sm-6">
                                    <a class="d-flex align-items-center gap-2 bg-section p-3 rounded overflow-wrap-anywhere cursor-pointer get-view-by-onclick" href="{{ route('admin.vendors.view', $vendor['seller_id'])}}">
                                        <img width="48" class="rounded-circle aspect-1 border" alt="" src="{{getStorageImages(path: $vendor->seller->shop->image_full_url,type:'backend-basic') }}">
                                        <div class="flex-grow-1">
                                            <h5 class="mb-1 line-1 fw-medium text-hover-primary">{{ $vendor->seller->shop['name'] ?? 'Not exist' }}</h5>
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="fi fi-sr-shopping-cart text-body-light"></i>
                                                <h4 class="shop-sell mb-0 fw-bold text-primary">
                                                    {{ setCurrencySymbol(amount: currencyConverter(amount: $vendor['total_earning'])) }}
                                                </h4>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                @endif
                            @endforeach
                        @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_stores_available',
                                'image' => 'store',
                                'width' => 45
                            ])
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
