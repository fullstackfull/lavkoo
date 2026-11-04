<div class="card card-body">
    <h3 class="mb-3 fw-bold fs-16">{{ translate('Users') }}</h3>
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card bg-body h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <img width="16" src="{{ dynamicAsset(path: 'public/assets/back-end/img/top-customers-2.png') }}"
                             alt="">
                        {{ translate('top_customers') }}
                    </h4>
                    @if($top_customer && count($top_customer) > 0)
                        <a href="{{ route('admin.customer.list', ['sort_by' => 'order_amount']) }}"
                           class="fw-semibold text-primary">{{ translate('View_All') }}</a>
                    @endif
                </div>
                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    @if($top_customer && count($top_customer) > 0)
                        <div class="d-grid gap-3">
                            @foreach($top_customer as $key => $customer)
                                <a href="{{ route('admin.customer.view', [$customer['id']]) }}">
                                    <div class="card card-body">
                                        <div
                                            class="d-flex justify-content-between align-items-center gap-3 flex-wrap overflow-wrap-anywhere">
                                            <div class="d-flex align-items-center gap-2">
                                                <img width="50" class="aspect-1 rounded-circle"
                                                     src="{{ getStorageImages(path: $customer->image_full_url, type:'backend-profile') }}"
                                                     alt="">
                                                <div class="">
                                                    @if($customer)
                                                        <h4 class="fw-medium mb-1 text-hover-primary">{{ $customer['f_name'] .' '.$customer['l_name'] }}</h4>
                                                    @else
                                                        <h4 class="fw-medium mb-1">{{ translate('not_exist') }}</h4>
                                                    @endif
                                                    <p class="text-body fs-12 mb-0">{{ $customer['email'] ?? translate('not_exist') }}</p>
                                                </div>
                                            </div>

                                            <span
                                                class="badge text-bg-info badge-info badge-lg d-inline-flex justify-content-center fs-12 gap-1">
                                                    <div>{{ translate('orders') }} : </div>
                                                    <div
                                                        class="fw-bold text-wrap">{{ count($customer['orders']) }}</div>
                                                </span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_user_available',
                                'image' => 'user',
                                'width' => 45
                            ])
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card bg-body h-100">
                <div class="card-header border-0 shadow-sm d-flex align-items-center justify-content-between gap-3">
                    <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                        <img width="16" src="{{ dynamicAsset(path: 'public/assets/back-end/img/top-deliveryman.png') }}"
                             alt="">
                        {{ translate('Top_Delivery_Man') }}
                    </h4>
                    @if($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0)
                        <a href="{{ route('admin.delivery-man.list', ['sort_by' => 'rating']) }}"
                           class="fw-semibold text-primary">{{ translate('View_All') }}</a>
                    @endif
                </div>
                <div class="card-body min-h-260 max-h-460 overflow-y-auto">
                    @if($topRatedDeliveryMan && count($topRatedDeliveryMan) > 0)
                        <div class="grid-card-wrap gap-3" style="--item-value: 200px;">
                            @foreach($topRatedDeliveryMan as $key=> $deliveryMan)
                                @if(isset($deliveryMan['id']))
                                    <div
                                        class="cursor-pointer bg-white shadow-sm rounded d-flex flex-column overflow-wrap-anywhere get-view-by-onclick"
                                        data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                                        <div class="text-center p-3">
                                            <img width="70" class="rounded-circle border get-view-by-onclick aspect-1"
                                                 alt=""
                                                 src="{{ getStorageImages(path: $deliveryMan->image_full_url,type:'backend-profile') }}"
                                                 data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                                        </div>
                                        <div class="bg-section text-center p-3 flex-grow-1">
                                            <h5 class="get-view-by-onclick line-1 mb-1 text-hover-primary lh-1"
                                                data-link="{{ route('admin.delivery-man.earning-statement-overview',[$deliveryMan['id']]) }}">
                                                {{Str::limit($deliveryMan['f_name'].' '.$deliveryMan['l_name'], 25)}}
                                            </h5>
                                            <div>
                                                <div
                                                    class="orders-count d-inline-flex justify-content-center flex-wrap fs-12 gap-1         ">
                                                    <div class="text-capitalize">{{ translate('Rating') }} :</div>
                                                    <div class="fw-semibold text-primary">
                                                        <span class="d-inline-flex align-items-center gap-1">{{ number_format($deliveryMan['review_avg_rating'] ?? 0.00,2 ,'.', ' ')}}  <i class="fi fi-sr-star text-secondary""></i></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div
                                                class="orders-count d-inline-flex justify-content-center flex-wrap fs-12 gap-1         ">
                                                <div class="text-capitalize">{{ translate('order_delivered') }} :</div>
                                                <div
                                                    class="fw-semibold text-primary">{{$deliveryMan['delivered_orders_count']}}</div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="d-flex justify-content-center align-items-center h-100">
                            @include('layouts.admin.partials._empty-state', [
                                'text' => 'No_delivery_man_available',
                                'image' => 'deliveryman',
                                'width' => 45
                            ])
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
