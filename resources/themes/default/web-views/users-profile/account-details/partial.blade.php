<?php
    $isOrderOnlyDigital = true;
    if($order->details) {
        foreach ($order->details as $detail) {
            $product = json_decode($detail->product_details);
            if (isset($product->product_type) && $product->product_type == 'physical') {
                $isOrderOnlyDigital = false;
            }
        }
    }
    use Carbon\Carbon;
    $isEligibleForRefundButtonShow = 0;
    $refund_day_limit = getWebConfig(name: 'refund_day_limit');
    $current = Carbon::now();
    foreach ($order->details as $key => $detail) {
        $product = $detail?->productAllStatus ?? json_decode($detail->product_details, true);
        if ($product) {
            $length = $detail?->refund_started_at?->diffInDays($current);
            if ($order->order_type == 'default_type' && $order->order_status == 'delivered') {
                if ($detail->refund_request != 0) {
                    $isEligibleForRefundButtonShow++;
                }
                if ($refund_day_limit > 0 && !is_null($length) && $length <= $refund_day_limit && $detail->refund_request == 0) {
                    $isEligibleForRefundButtonShow++;
                }
            }
        }
    }
?>


<div class="d-flex align-items-start justify-content-between flex-sm-nowrap flex-wrap gap-3 mb-2">
    <div>
        <div class="d-flex align-items-center gap-2 text-capitalize">
            <h4 class="text-capitalize mb-0 mobile-fs-14 fs-18 font-bold">{{translate('order')}} #{{$order->id}} </h4>
            @if($order['edited_status'] == 1)
                <span class="edit-text title-semidark fs-14">(Edited)</span>
            @endif
            @if($order['order_status'] == 'confirmed' || $order['order_status'] == 'delivered' || $order['order_status'] == 'processing' ? 'badge-soft-success':'')
                <span
                    class="fs-12 font-semibold rounded badge __badge {{$order['order_status'] == 'confirmed' || $order['order_status'] == 'delivered' || $order['order_status'] == 'processing' ? 'badge-soft-success border-soft-success':''}}">
                    {{ $order['order_status'] == 'processing' ? translate('packaging') : $order['order_status'] }}
                </span>
            @elseif($order['order_status'] == 'failed' || $order['order_status'] == 'canceled' || $order['order_status'] == 'returned' ? 'badge-soft-danger':'')
                <span
                    class="fs-12 font-semibold rounded badge __badge {{$order['order_status'] == 'failed' || $order['order_status'] == 'canceled' || $order['order_status'] == 'returned' ? 'badge-soft-danger':''}}">
                    {{ $order['order_status'] == 'failed' ? translate('Failed_To_Delivery') : $order['order_status'] }}
                </span>
            @elseif($order['order_status'] == 'pending' || $order['order_status'] == 'out_for_delivery' ? 'badge-soft-primary':'')
                <span
                    class="fs-12 font-semibold rounded badge __badge {{$order['order_status'] == 'pending' || $order['order_status'] == 'out_for_delivery' ? 'badge-soft-primary border-soft-primary':''}}">
                    {{ $order['order_status'] == 'out_for_delivery' ? translate('Out_For_Delivery') : $order['order_status'] }}
                </span>
            @else
                <span class="fs-12 font-semibold badge __badge-soft-primary rounded">
                    {{ $order['order_status']}}
                </span>
            @endif
        </div>
        @if(isset($order['seller_id']) != 0)
            @php($shopName=\App\Models\Shop::where('seller_id', $order['seller_id'])->first())
        @endif
        <div class="date fs-12 font-semibold text-secondary-50 text-body mt-2">
            {{ date('d M, Y h:i A', strtotime($order['created_at'])) }}
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        @if($isEligibleForRefundButtonShow > 0)
        <button class="btn rounded btn-outline-primary py-1 px-3" data-toggle="modal" data-target="#refund_request">{{translate('refund')}}</button>
        @endif
        <button type="button" class="btn py-1 px-2 rounded btn--primary get-view-by-onclick"
                data-link="{{route('generate-invoice',[$order->id])}}">
            <i class="fi fi-rr-file-download"></i>
        </button>
        <button class="profile-aside-btn btn btn--primary px-2 rounded px-2 py-1 d-lg-none">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15" fill="none">
                <path fill-rule="evenodd" clip-rule="evenodd"
                      d="M7 9.81219C7 9.41419 6.842 9.03269 6.5605 8.75169C6.2795 8.47019 5.898 8.31219 5.5 8.31219C4.507 8.31219 2.993 8.31219 2 8.31219C1.602 8.31219 1.2205 8.47019 0.939499 8.75169C0.657999 9.03269 0.5 9.41419 0.5 9.81219V13.3122C0.5 13.7102 0.657999 14.0917 0.939499 14.3727C1.2205 14.6542 1.602 14.8122 2 14.8122H5.5C5.898 14.8122 6.2795 14.6542 6.5605 14.3727C6.842 14.0917 7 13.7102 7 13.3122V9.81219ZM14.5 9.81219C14.5 9.41419 14.342 9.03269 14.0605 8.75169C13.7795 8.47019 13.398 8.31219 13 8.31219C12.007 8.31219 10.493 8.31219 9.5 8.31219C9.102 8.31219 8.7205 8.47019 8.4395 8.75169C8.158 9.03269 8 9.41419 8 9.81219V13.3122C8 13.7102 8.158 14.0917 8.4395 14.3727C8.7205 14.6542 9.102 14.8122 9.5 14.8122H13C13.398 14.8122 13.7795 14.6542 14.0605 14.3727C14.342 14.0917 14.5 13.7102 14.5 13.3122V9.81219ZM12.3105 7.20869L14.3965 5.12269C14.982 4.53719 14.982 3.58719 14.3965 3.00169L12.3105 0.915687C11.725 0.330188 10.775 0.330188 10.1895 0.915687L8.1035 3.00169C7.518 3.58719 7.518 4.53719 8.1035 5.12269L10.1895 7.20869C10.775 7.79419 11.725 7.79419 12.3105 7.20869ZM7 2.31219C7 1.91419 6.842 1.53269 6.5605 1.25169C6.2795 0.970186 5.898 0.812187 5.5 0.812187C4.507 0.812187 2.993 0.812187 2 0.812187C1.602 0.812187 1.2205 0.970186 0.939499 1.25169C0.657999 1.53269 0.5 1.91419 0.5 2.31219V5.81219C0.5 6.21019 0.657999 6.59169 0.939499 6.87269C1.2205 7.15419 1.602 7.31219 2 7.31219H5.5C5.898 7.31219 6.2795 7.15419 6.5605 6.87269C6.842 6.59169 7 6.21019 7 5.81219V2.31219Z"
                      fill="white"/>
            </svg>
        </button>
    </div>
</div>

<div class="order-details-nav overflow-auto nav-menu gap-3 gap-xl-30 mb-4 text-capitalize d-flex">
    <button data-link="{{ route('account-order-details', ['id'=>$order->id]) }}"
            class="get-view-by-onclick {{Request::is('account-order-details')  ? 'active' :''}}">{{translate('order_summary')}}</button>
    <button data-link="{{ route('account-order-details-vendor-info', ['id'=>$order->id]) }}"
            class="get-view-by-onclick {{Request::is('account-order-details-vendor-info')  ? 'active' :''}}">{{translate('vendor_info')}}</button>
    @if($order->order_type != 'POS')
        @if(!$isOrderOnlyDigital)
            <button data-link="{{ route('account-order-details-delivery-man-info', ['id'=>$order->id]) }}"
                    class="get-view-by-onclick {{Request::is('account-order-details-delivery-man-info') ? 'active':''}}">
                {{ translate('delivery_man_info') }}
            </button>
        @endif
        <button data-link="{{ route('account-order-details-reviews', ['id'=>$order->id]) }}"
                class="get-view-by-onclick {{Request::is('account-order-details-reviews')  ? 'active' :''}}">{{translate('reviews')}}</button>
    <button data-link="{{ route('track-order.order-wise-result-view',['order_id'=>$order['id']])}}"
            class="get-view-by-onclick {{Request::is('track-order/order-wise-result-view*')  ? 'active' :''}}">{{translate('track_order')}}</button>
    @endif
</div>


<div class="modal fade" id="refund_details_modal" tabindex="-1" aria-labelledby="refundRequestModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="text-center text-capitalize m-0 flex-grow-1">{{translate('refund_details')}}</h6>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body d-flex flex-column gap-3" id="refund_details_field">
            </div>
        </div>
    </div>
</div>

@include("web-views.partials._refund-request-list-modal", ['order' => $order])

@foreach ($order->details as $key=>$detail)
    @php($product = $detail?->productAllStatus ?? json_decode($detail->product_details, true))
    @if($product)
        @include('layouts.front-end.partials.modal._review',['id'=>$detail->id,'order_details'=>$detail])
        @include('layouts.front-end.partials.modal._refund',['id'=>$detail->id, 'order_details'=>$detail,'order'=>$order,'product'=>$product])
    @endif
@endforeach
