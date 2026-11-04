<div class="card-header gap-3 {{ ($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0) ? '' : 'bg-section' }}">
    <h4 class="fs-14 d-flex align-items-center text-capitalize gap-2 mb-0">
         <img width="18" src="{{dynamicAsset(path: 'public/assets/back-end/img/top-deliveryman.png')}}" alt="">
        {{translate('Top_Delivery_Man')}}
    </h4>
    @if($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0)
    <a href="{{ route('vendor.delivery-man.list', ['sort_by' => 'rating']) }}"
       class="text--primary fw-semibold fs-12">{{ translate('View_All') }}</a>
    @endif
</div>

<div class="card-body min-h-260 max-h-460 overflow-y-auto {{ ($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0) ? '' : 'bg-section' }}">
    @if($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0)
        <div class="grid-card-wrap" style="--item-value: 180px;">
            @foreach($topRatedDeliveryMan as $key=> $deliveryMan)
                @if(isset($deliveryMan['id']))
                    <div class="cursor-pointer get-view-by-onclick" data-link="{{ route('vendor.delivery-man.wallet.index', ['id' => $deliveryMan['id']]) }}">
                        <div class="rounded basic-box-shadow shadow-sm">
                            <div class="text-center px-3 py-4">
                                <img class="avatar border avatar-70 rounded-circle get-view-by-onclick" alt=""
                                     src="{{ getStorageImages(path:$deliveryMan->image_full_url,type:'backend-profile') }}">
                            </div>
                            <div class="bg-light px-2 py-3 text-center">
                                <div class="fs-12 title-color fw-medium line--limit-1 mb-1 hover-c1 get-view-by-onclick lh-1">
                                    {{Str::limit($deliveryMan['f_name'].' '.$deliveryMan['l_name'], 25)}}
                                </div>
                                <div>
                                    <div class="mb-1 d-inline-flex justify-content-center flex-wrap fs-12 gap-1" >
                                        <div class="text-capitalize">{{ translate('Rating') }} :</div>
                                        <div class="fw-semibold text--primary">
                                            <span class="d-inline-flex align-items-center gap-1">{{ number_format($deliveryMan['review_avg_rating'] ?? 0.00,2 ,'.', ' ')}}  <i class="fi fi-sr-star text-warning-dark"></i></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-1 align-items-center justify-content-center fs-12">
                                    <div class="flex-shrink-0">{{translate('Order_Delivered')}} :</div>
                                    <div class="text--primary fw-bold overflow-wrap-anywhere">{{$deliveryMan['delivered_orders_count']}}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <div class="d-flex justify-content-center align-items-center h-100">
            @include('layouts.vendor.partials._empty-state', [
                'text' => 'No_delivery_man_available',
                'image' => 'deliveryman',
                'width' => 45
            ])
        </div>
    @endif
</div>
