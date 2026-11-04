@if(count($allVendorList) > 0)
    @foreach($allVendorList as $vendor)
        <div class="col-lg-12 select-clearance-vendor-item">
                <div class="mt-20">
                    <div
                        class="media gap-3 p-3 radius-5 cursor-pointer justify-content-between align-items-center flex-wrap flex-xl-nowrap"
                        data-id="1">
                        <div class="d-flex align-items-center gap-3">
                            <img class="avatar avatar-xl border" width="75"
                                 src="{{ getStorageImages(path:$vendor?->shop?->image_full_url , type: 'backend-basic') }}"
                                 class="rounded border" alt="">
                            <div class="media-body d-flex flex-column gap-1">
                                <input type="hidden" id="shop-id{{ $vendor['id'] }}" value="{{ $vendor?->shop?->id }}">
                                <h4 class="mb-1 product-name line-1">
                                    {{$vendor?->shop?->name}}
                                </h4>
                                <div class="fs-12 text-dark">
                                    <div class="border-between wrap">
                                         <span class="parent">
                                             <span class="opacity-75">({{$vendor->review_count}} {{ translate('review') }})</span>
                                         </span>
                                        <span class="parent">
                                             <span class="opacity-75"><i class="fi fi-sr-star text-warning"></i>{{round($vendor->average_rating,1)}}</span>
                                        </span>
                                    </div>
                                    <div class="mt-2">
                                        <span class="parent">
                                            <span class="opacity-75">{{ translate('total_products_in_clearance_offer') }}:</span>
                                            <strong>{{$vendor->products_count}}</strong>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
        </div>
    @endforeach
@else
    <div class="text-center p-4">
        <img class="mb-3 w-60" src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/empty-vendor.png')}}"
             alt="{{ translate('image_description')}}">
        <p class="mb-0">{{ translate('no_vendor_found')}}</p>
    </div>
@endif
