<div class="card-header">
    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
        <img src="{{dynamicAsset(path: 'public/assets/back-end/img/top-customers.png')}}" alt="">
        {{translate('top_Delivery_Man')}}
    </h4>
</div>

<div class="card-body">
    @if($topRatedDeliveryMan)
        <div class="grid-card-wrap">
            @foreach($topRatedDeliveryMan as $key=> $deliveryMan)
                @if(isset($deliveryMan['id']))
                    <div class="cursor-pointer get-view-by-onclick" data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                        <div class="border p-20 rounded">
                            <div class="text-center mb-2">
                                <img width="60" class="rounded-circle get-view-by-onclick aspect-1" alt=""
                                     src="{{ getStorageImages(path: $deliveryMan->image_full_url,type:'backend-profile') }}"
                                     data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                            </div>
                            <h5 class="mb-0 mt-2 get-view-by-onclick line-1 text-center" data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                                {{Str::limit($deliveryMan['f_name'].' '.$deliveryMan['l_name'], 25)}}
                            </h5>
                            <div class="d-flex justify-content-center">
                                <div class="border orders-count d-inline-flex justify-content-center flex-wrap fs-12 gap-1 mt-2 px-2 py-1 rounded text-nowrap">
                                    <div class="text-capitalize">{{translate('order_delivered')}} :</div>
                                    <div class="fw-semibold text-primary">{{$deliveryMan['delivered_orders_count']}}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <div class="text-center">
            <p class="text-muted">{{translate('no_data_found').'!'}}</p>
            <img class="w-75" src="{{dynamicAsset(path: 'public/assets/back-end/img/no-data.png')}}" alt="">
        </div>
    @endif
</div>
