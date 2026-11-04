@extends('layouts.front-end.app')

@section('title', translate('my_Order_List'))
@push('css_or_js')
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/payment.css') }}">
@endpush
@section('content')

    <div class="container py-2 py-md-4 p-0 p-md-2 user-profile-container px-5px">
        <div class="row">
            @include('web-views.partials._profile-aside')

            <section class="col-lg-9 __customer-profile customer-profile-wishlist px-0">
                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-10px px-2 px-xl-0">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <h5 class="font-semi-bold m-0 fs-16">
                            {{ translate('My_Order') }}
                            @if($orders->count() > 0)
                                <span>({{ $orders->count() }})</span>
                            @endif
                        </h5>
                        
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2 border rounded py-1 px-3">
                            <i class="fi fi-rr-bars-filter"></i>
                            @php
                                $currentOrder = request('order_by');
                            @endphp
                            <select name="filter" id="orderFilter"
                                    class="bg-transparent outline-0 fs-14 w-auto border-0 p-0">
                                <option
                                    value="{{ route('account-oder', ['order_by' => 'desc']) }}" {{ $currentOrder === 'desc' ? 'selected' : '' }}>
                                    {{ translate('sort_by_latest') }}
                                </option>
                                <option
                                    value="{{ route('account-oder', ['order_by' => 'asc']) }}" {{ $currentOrder === 'asc' ? 'selected' : '' }}>
                                    {{ translate('sort_by_oldest') }}
                                </option>
                            </select>
                        </div>
                        <button class="profile-aside-btn btn btn--primary px-2 rounded px-2 py-1 d-lg-none">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 15 15" fill="none">
                                <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M7 9.81219C7 9.41419 6.842 9.03269 6.5605 8.75169C6.2795 8.47019 5.898 8.31219 5.5 8.31219C4.507 8.31219 2.993 8.31219 2 8.31219C1.602 8.31219 1.2205 8.47019 0.939499 8.75169C0.657999 9.03269 0.5 9.41419 0.5 9.81219V13.3122C0.5 13.7102 0.657999 14.0917 0.939499 14.3727C1.2205 14.6542 1.602 14.8122 2 14.8122H5.5C5.898 14.8122 6.2795 14.6542 6.5605 14.3727C6.842 14.0917 7 13.7102 7 13.3122V9.81219ZM14.5 9.81219C14.5 9.41419 14.342 9.03269 14.0605 8.75169C13.7795 8.47019 13.398 8.31219 13 8.31219C12.007 8.31219 10.493 8.31219 9.5 8.31219C9.102 8.31219 8.7205 8.47019 8.4395 8.75169C8.158 9.03269 8 9.41419 8 9.81219V13.3122C8 13.7102 8.158 14.0917 8.4395 14.3727C8.7205 14.6542 9.102 14.8122 9.5 14.8122H13C13.398 14.8122 13.7795 14.6542 14.0605 14.3727C14.342 14.0917 14.5 13.7102 14.5 13.3122V9.81219ZM12.3105 7.20869L14.3965 5.12269C14.982 4.53719 14.982 3.58719 14.3965 3.00169L12.3105 0.915687C11.725 0.330188 10.775 0.330188 10.1895 0.915687L8.1035 3.00169C7.518 3.58719 7.518 4.53719 8.1035 5.12269L10.1895 7.20869C10.775 7.79419 11.725 7.79419 12.3105 7.20869ZM7 2.31219C7 1.91419 6.842 1.53269 6.5605 1.25169C6.2795 0.970186 5.898 0.812187 5.5 0.812187C4.507 0.812187 2.993 0.812187 2 0.812187C1.602 0.812187 1.2205 0.970186 0.939499 1.25169C0.657999 1.53269 0.5 1.91419 0.5 2.31219V5.81219C0.5 6.21019 0.657999 6.59169 0.939499 6.87269C1.2205 7.15419 1.602 7.31219 2 7.31219H5.5C5.898 7.31219 6.2795 7.15419 6.5605 6.87269C6.842 6.59169 7 6.21019 7 5.81219V2.31219Z"
                                        fill="white"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="card __card d-flex web-direction customer-profile-orders h-100-44">
                    <div class="card-body">
                        @if($orders->count()>0)
                            <div class="row g-3">
                                @foreach($orders as $order)
                                    <div class="col-md-6">
                                        <div class="cus-shadow rounded-8 p-xl-3 p-2">
                                            <div class="media-order">
                                                <a href="{{ route('account-order-details', ['id'=>$order->id]) }}"
                                                   class="d-block position-relative w-70px border rounded h-70px min-w-60px">
                                                    @if($order->seller_is == 'seller')
                                                        <img alt="{{ translate('shop') }}"
                                                             src="{{ getStorageImages(path: $order?->seller?->shop->image_full_url, type: 'shop') }}"
                                                             class="w-100 h-100">
                                                    @elseif($order->seller_is == 'admin')
                                                        <img alt="{{ translate('shop') }}"
                                                             src="{{ getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop') }}"
                                                             class="w-100 h-100">
                                                    @endif
                                                </a>
                                                <div class="cont w-auto text-start flex-grow-1">
                                                    <div
                                                        class="d-flex align-items-center justify-content-between gap-2">
                                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                                            <h6 class="mb-0">
                                                                <a href="{{ route('account-order-details', ['id'=>$order->id]) }}"
                                                                   class="fs-14 font-semibold min-w-110 line--limit-1">
                                                                    {{ translate('order') }} #{{$order['id']}}
                                                                </a>
                                                            </h6>
                                                            <div>
                                                                @if($order->edited_status == 1)
                                                                    <span class="edit-text title-semidark fs-14">
                                                                    {{ (translate('Edited')) }}
                                                                        @if($order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != "offline_payment" && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_amount > 0)
                                                                            <span data-toggle="tooltip"
                                                                                  data-title="{{ translate('You will pay due the amount ')}}">
                                                                         <i class="fi fi-sr-usd-circle text-danger"></i>
                                                                        </span>
                                                                        @endif

                                                                        @if($order?->latestEditHistory?->order_return_payment_status == 'pending' && $order?->latestEditHistory?->order_return_amount > 0)
                                                                            <span data-toggle="tooltip"
                                                                                  data-title="{{ translate('Admin return the excess amount to you')}}">
                                                                            <i class="fi fi-sr-usd-circle text-danger"></i>
                                                                        </span>
                                                                        @endif
                                                                </span>
                                                                @endif

                                                                @if($order['order_status']=='failed' || $order['order_status']=='canceled')
                                                                    <span
                                                                        class="status-badge rounded-pill __badge badge-soft-danger fs-12 font-semibold text-capitalize">
                                                                    {{ translate($order['order_status'] =='failed' ? 'failed_to_deliver' : $order['order_status']) }}
                                                                </span>
                                                                @elseif($order['order_status']=='confirmed' || $order['order_status']=='processing' || $order['order_status']=='delivered')
                                                                    <span
                                                                        class="status-badge rounded-pill __badge badge-soft-success fs-12 font-semibold text-capitalize">
                                                                    {{ translate($order['order_status']=='processing' ? 'packaging' : $order['order_status']) }}
                                                                </span>
                                                                @else
                                                                    <span
                                                                        class="status-badge rounded-pill __badge badge-soft-primary fs-12 font-semibold text-capitalize">
                                                                    {{ translate($order['order_status']) }}
                                                                </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <div class="btn-group myorder-dropdown">
                                                            <button class="btn p-0 bg-transparent m-0 outline-0"
                                                                    type="button" data-toggle="dropdown"
                                                                    aria-expanded="false">
                                                                <i class="fi fi-rr-menu-dots-vertical fs-14"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-right p-2">
                                                                <li>
                                                                    <div class="dropdown-item w-100">
                                                                        <a class="d-flex align-items-center justify-content-between w-100 fs-14 gap-2"
                                                                           href="{{route('generate-invoice',[$order->id]) }}"
                                                                           title="{{ translate('download_invoice') }}">
                                                                            {{ translate('Download Invoice') }} <i
                                                                                class="fi fi-rr-download web-text-primary"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                                <li>
                                                                    <div class="dropdown-item w-100">
                                                                        <a class="d-flex align-items-center justify-content-between w-100 fs-14 gap-2"
                                                                           href="{{ route('account-order-details', ['id'=>$order->id]) }}"
                                                                           title="{{ translate('view_order_details') }}">
                                                                            {{ translate('View Order Details') }} <i
                                                                                class="fa fa-eye web-text-primary"></i>
                                                                        </a>
                                                                    </div>
                                                                </li>
                                                                @if($order->edited_status == 1 && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != "offline_payment" && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_amount > 0)
                                                                    <li>
                                                                        <button
                                                                            class="dropdown-item d-flex align-items-center justify-content-between fs-14 gap-2"
                                                                            type="button"
                                                                            title="{{ translate('view_order_details') }}"
                                                                            data-toggle="modal"
                                                                            data-target="#choosePaymentMethodModal-{{ $order->id }}">
                                                                            {{ translate('Pay Due') }}
                                                                            <i class="bi bi-eye-fill"></i>
                                                                        </button>
                                                                    </li>
                                                                @endif
                                                            </ul>
                                                        </div>
                                                    </div>
                                                    <span class="fs-12 font-weight-medium">
                                                        <span class="text-dark fw-semibold">
                                                            {{ $order->order_details_sum_qty }}
                                                        </span>
                                                        {{ translate('Products') }}
                                                    </span>
                                                    <div
                                                        class="d-flex align-items-center justify-content-between gap-1 flex-wrap mt-1">
                                                        <div class="text-secondary-50 fs-12 font-weight-normal">
                                                            {{date('d M, Y h:i A',strtotime($order['created_at'])) }}
                                                        </div>
                                                        <div class="web-text-primary fs-16 font-bold">
                                                            @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                                                            {{ webCurrencyConverter(amount:  $orderTotalPriceSummary['totalAmount']) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="d-flex justify-content-center align-items-center h-100">
                                <div class="d-flex flex-column justify-content-center align-items-center gap-3">
                                    <img
                                        src="{{ theme_asset(path: 'public/assets/front-end/img/empty-icons/empty-orders.svg') }}"
                                        alt="" width="100">
                                    <h5 class="text-muted fs-14 font-semi-bold text-center">{{ translate('You_have_not_any_order_yet') }}
                                        !</h5>
                                </div>
                            </div>
                        @endif
                        <div class="card-footer border-0">
                            {{$orders->links() }}
                        </div>
                    </div>
                </div>

            </section>
        </div>

    </div>
    <?php
    $orderSuccessIds = session('order_success_ids') ?? [];
    if (!is_array($orderSuccessIds)) {
        $orderSuccessIds = [];
    }
    $isPlural = count($orderSuccessIds) > 1;
    session()->forget('order_success_ids');
    ?>
    @if($orderSuccessIds && auth('customer')->check())
        <div class="modal fade" id="order_successfully" aria-labelledby="order_successfully" tabindex="-1"
             aria-hidden="true">
            <div class="modal-dialog modal--md modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body rtl">
                        <div class="d-flex justify-content-end pb-2">
                            <button class="close close-quick-view-modal ps-2 pe-1 z-index-99" type="button"
                                    data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="text-center px-sm-3 pb-2 pt-4 mt-xl-1">
                            <div class="mb-20">
                                <img width="56" height="56" class=""
                                     src="{{theme_asset(path: "public/assets/front-end/img/icons/checked-circle.png")}}"
                                     alt="">
                            </div>
                            <h6 class="mb-3 fs-18 fw-semibold">{{translate('Thank You For Your Purchase!')}}</h6>
                            <p class="fs-14 title-semidark mb-30">
                                {{ translate('We have received your order and will ship it shortly.') }}
                                {{ translate('Your Order ID' . ($isPlural ? 's' : '')) }}
                                {{ implode(', ', $orderSuccessIds) }}
                                {{ translate('keep it handy for tracking.') }}
                            </p>
                            <a href="{{ route('home') }}"
                               class="btn btn--primary font-bold px-4 font-weight-normal rounded-10">
                                {{ translate('Explore More Items') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($orders->count()> 0)
        @foreach($orders as $order)
            @include('web-views.partials._choose-payment-method-order-details',[
                'order' => $order,
                'orderDueAmount' => $order?->latestEditHistory?->order_due_amount ?? 0,
                'paymentGatewayList' => $paymentGatewayList,
             ])
        @endforeach
    @endif

@endsection

@push('script')
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/payment.js') }}"></script>
    @if($orderSuccessIds)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const modalEl = document.getElementById('order_successfully');
                if (!modalEl || typeof bootstrap === 'undefined') return;
                const orderModal = new bootstrap.Modal(modalEl, {
                    backdrop: 'static',
                    keyboard: false
                });
                orderModal.show();
                const closeBtn = document.getElementById('modal-close-btn');
                if (closeBtn) closeBtn.addEventListener('click', function () {
                    setTimeout(() => {
                        orderModal.hide();
                    }, 600);
                });
            });
        </script>
    @endif
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // The filter select is not rendered on every state of this page;
            // the unguarded listener used to throw and kill later inits.
            const filterSelect = document.getElementById('orderFilter');
            if (!filterSelect) return;
            filterSelect.addEventListener('change', function () {
                const url = this.value;
                if (url) {
                    window.location.href = url;
                }
            });
        });
    </script>
@endpush
