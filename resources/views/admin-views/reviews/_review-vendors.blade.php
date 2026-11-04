@if(count($shopList) > 0)
    @foreach($shopList as $shop)
        <div class="select-vendor-item media gap-3 border-bottom py-3 cursor-pointer align-items-center" data-id="{{ $shop['seller_id'] }}">
            <img class="border aspect-1 rounded" width="75" src="{{ getStorageImages(path: $shop?->image_full_url , type: 'backend-basic') }}" alt="">
            <div class="media-body d-flex flex-column gap-1 text-start">
                <h5 class="vendor-id" hidden>{{ $shop['seller_id'] }}</h5>
                <h5 class="text-capitalize mb-1 vendor-name">{{ $shop?->name }}</h5>
                <div  class="d-flex flex-column gap-2 fs-10">
                    <div class="me-2 d-flex gap-2">
                        <span class="w-80">
                            {{ translate('Total_Review') }}
                        </span>
                        <span>:</span>
                        <span>{{ $shop->review_count ?? 0 }}</span>
                    </div>
                    <div class="me-2 d-flex gap-2 align-items-center">
                    <span class="w-80">
                        {{ translate('Ratting') }}
                    </span>
                    <span>:</span>
                    <span class="d-flex gap-1 align-items-center">
                        {{ round($shop->average_rating,1) }}
                        <i class="fi fi-sr-star text-warning" style="line-height: 1; vertical-align: middle;"></i>
                    </span>
                    </div>

                </div>
            </div>
        </div>
    @endforeach
@else
    <div>
        <h5 class="m-0 text-muted">{{ translate('no_vendor_found') }}</h5>
    </div>
@endif

