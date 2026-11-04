@php
use Illuminate\Support\Facades\Session;
@endphp
@extends('layouts.admin.app')

@section('title', $seller?->shop->name ?? translate("shop_name_not_found"))

@section('content')
    @php($direction = Session::get('direction'))
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{ translate('Vendor_Details') }}
            </h2>
        </div>

        <div class="flex-between d-sm-flex row align-items-center justify-content-between mb-2 mx-1">
            <div>
                @if ($seller->status=="pending")
                    <div class="mt-4 pe-2">
                        <div class="">
                            <div class="mx-1">
                                <h4><i class="fi fi-rr-shop"></i></h4>
                            </div>
                            <div>{{ translate('vendor_request_for_open_a_shop.') }}</div>
                        </div>
                        <div class="text-center">
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-primary btn-sm">{{translate('approve')}}</button>
                            </form>
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller->id}}">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger btn-sm">{{translate('reject')}}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="page-header mb-4">
            <h2 class="page-header-title mb-3">{{ $seller?->shop->name ?? translate("Shop_Name").' : '. translate("Update_Please") }}</h2>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab">
                    <li class="nav-item">
                        <a class="nav-link "
                           href="{{ route('admin.vendors.view',$seller->id) }}">{{translate('shop')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'order']) }}">{{translate('order')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'product']) }}">{{translate('product')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="{{ route('admin.vendors.view',['id'=>$seller['id'], 'tab'=>'clearance_sale']) }}">{{translate('Clearance_Sale_Products')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'setting']) }}">{{translate('setting')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'transaction']) }}">{{translate('transaction')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link"
                           href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'review']) }}">{{translate('review')}}</a>
                    </li>
                </ul>
                <div class="nav--tab__prev">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-left"></i>
                    </button>
                </div>
                <div class="nav--tab__next">
                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                        <i class="fi fi-sr-angle-right"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="card card-body mb-20">
            <h3 class="mb-20">{{ translate('Current_Order_Summary') }}</h3>
            <div class="row g-3">
                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/pending.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Pending') }}</h4>
                        </div>
                        <span class="text-primary h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['pending_order'] }}
                        </span>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_confirmed">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/confirmed.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Confirmed') }}</h4>
                        </div>
                        <span class="text-success h3 mb-0 overflow-wrap-anywhere">
                                {{ $allOrdersInfo['confirmed_order'] }}
                            </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_packaging">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/packaging.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Packaging') }}</h4>
                        </div>
                        <span class="text-danger h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['processing_order'] }}
                        </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_out-for-delivery">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/out-of-delivery.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Out_for_Delivery') }}</h4>
                        </div>
                        <span class="text-success h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['out_for_delivery_order'] }}
                        </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_delivered">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/delivered.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Delivered') }}</h4>
                        </div>
                        <span class="text-primary h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['delivered_order'] }}
                        </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_canceled">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/canceled.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Canceled') }}</h4>
                        </div>
                        <span class="text-danger h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['canceled_order'] }}
                        </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_returned">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/returned.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Returned') }}</h4>
                        </div>
                        <span class="text-warning h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['returned_order'] }}
                        </span>
                    </div>
                </div>

                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex gap-3 align-items-center justify-content-between p-20 bg-section rounded order-stats_failed">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="20"
                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/failed-to-deliver.png') }}"
                                 alt="">
                            <h4 class="mb-0">{{ translate('Failed_to_Deliver') }}</h4>
                        </div>
                        <span class="text-danger h3 mb-0 overflow-wrap-anywhere">
                            {{ $allOrdersInfo['failed_order'] }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
                    <div>
                        <h3 class="mb-0 d-flex gap-2 align-items-center">
                            {{ translate('order_List') }}
                            <span class="badge text-dark bg-body-secondary fw-semibold rounded-45">
                                {{ $orders->total() }}
                            </span>
                        </h3>
                    </div>

                    <div class="d-flex gap-3 align-items-center flex-wrap">
                        <form action="{{ url()->current() }}" method="GET" class="min-w-100-mobile min-w-280">
                            <div class="form-group">
                                <div class="input-group">
                                    <input type="hidden" name="order_date" value="{{ requestString('order_date') }}">
                                    <input type="hidden" name="customer_joining_date" value="{{ requestString('customer_joining_date') }}">
                                    <input type="hidden" name="is_active" value="{{ requestString('is_active') }}">
                                    <input type="hidden" name="sort_by" value="{{ requestString('sort_by') }}">
                                    <input type="hidden" name="choose_first" value="{{ requestString('choose_first') }}">
                                    <input id="datatableSearch_" type="search" name="searchValue" class="form-control"
                                           placeholder="{{ translate('search_by_Customer_name')}}"  aria-label="Search orders" value="{{ requestString('searchValue') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                        <a type="button" class="btn btn-outline-primary min-h-40" href="{{route('admin.vendors.order-list-export',[$seller['id'],'searchValue' => request('searchValue')])}}">
                            <i class="fi fi-sr-inbox-in"></i>
                            <span class="fs-12">{{ translate('export') }}</span>
                        </a>
                    </div>
                </div>

                <div class="k-table-wrap mt-4">
                    <table class="k-table">
                        <thead>
                        <tr>
                            <th>{{translate('SL')}}</th>
                            <th>{{translate('Order ID')}}</th>
                            <th>{{translate('Order Date')}}</th>
                            <th>{{translate('Customer info')}}</th>
                            <th class="k-table__num">{{translate('Total_Amount')}}</th>
                            <th>{{ translate('order_Status') }}</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="set-rows">
                        @foreach($orders as $key=>$order)
                            <tr class="status class-all">
                                <td><span class="k-num">{{$orders->firstItem()+$key}}</span></td>
                                <td>
                                    <div class="d-flex gap-1 flex-column">
                                        <div class="d-flex align-items-center gap-1">
                                            <a class="hover-primary text-dark"
                                               href="{{route('admin.vendors.order-details',['order_id'=>$order['id'],'vendor_id'=>$order['seller_id']])}}">
                                                {{ $order['id'] }}
                                                {!! $order->order_type == 'POS' ? '<span class="text--primary">(POS)</span>' : '' !!}
                                            </a>
                                            @if($order->edited_status == 1 && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != "offline_payment" && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_amount > 0)
                                                <span class="flex-shrink-0" data-bs-toggle="tooltip"
                                                      title="{{ translate('customer_will_pay_due_the_amount') }}">
                                                        <img width="14"
                                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/due-amount-icon.png') }}"
                                                             alt="">
                                                    </span>
                                            @elseif($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == 'pending' && $order?->latestEditHistory?->order_return_amount > 0)
                                                <span class="flex-shrink-0" data-bs-toggle="tooltip"
                                                      title="{{ translate('you_need_to_return_the_excess_amount_to_the_customer.') }}">
                                                        <img width="16"
                                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/return-amount-icon.png') }}"
                                                             alt="">
                                                    </span>
                                            @endif
                                        </div>
                                        @if($order->edited_status == 1)
                                            <x-k.badge tone="info">{{ translate('Edited') }}</x-k.badge>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="k-num">{{date('d M Y',strtotime($order['created_at']))}}</div>
                                    <div class="k-text-subtle k-num">{{ date("h:i A",strtotime($order['created_at'])) }}</div>
                                </td>
                                <td>
                                    @if($order->is_guest)
                                        <strong class="title-name">{{translate('guest_customer')}}</strong>
                                    @elseif($order->customer_id == 0)
                                        <strong class="title-name">
                                            {{ translate('Walk-In-Customer') }}
                                        </strong>
                                    @else
                                        @if($order->customer)
                                            <a class="text-dark text-capitalize" href="{{route('admin.customer.view',['user_id'=>$order['customer_id']])}}">
                                                <strong class="title-name d-block max-w-300 text-wrap">
                                                    {{$order->customer['f_name'].' '.$order->customer['l_name']}}
                                                </strong>
                                            </a>
                                            <a class="d-block text-dark" href="tel:{{ $order->customer['phone'] }}">{{ $order->customer['phone'] }}</a>
                                        @else
                                            <x-k.badge tone="danger">{{translate('invalid_customer_data')}}</x-k.badge>
                                        @endif
                                    @endif
                                </td>
                                <td class="k-table__num">
                                    @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                                    <div class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount']), currencyCode: getCurrencyCode()) }}</div>
                                    @if ($order->payment_status == 'paid')
                                        <x-k.badge tone="success">{{ translate('paid') }}</x-k.badge>
                                    @else
                                        <x-k.badge tone="danger">{{ translate('unpaid') }}</x-k.badge>
                                    @endif
                                </td>
                                <td class="text-capitalize">
                                    @switch($order['order_status'])
                                        @case('pending')
                                        @case('confirmed')
                                            <x-k.badge tone="info">{{ translate($order['order_status']) }}</x-k.badge>
                                            @break
                                        @case('processing')
                                        @case('out_for_delivery')
                                            <x-k.badge tone="warning">{{ translate($order['order_status']) }}</x-k.badge>
                                            @break
                                        @case('delivered')
                                            <x-k.badge tone="success">{{ translate($order['order_status']) }}</x-k.badge>
                                            @break
                                        @default
                                            <x-k.badge tone="danger">{{ translate(str_replace('_',' ',$order['order_status'])) }}</x-k.badge>
                                    @endswitch
                                </td>
                                <td>
                                    <div class="k-table__actions">
                                        @if($order->edited_status == 1 && $order?->edit_return_amount > 0)
                                            <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                                    title="{{ translate('return_amount') }}"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#returnDueAmountModal-{{$order['id']}}">
                                                <x-k.icon name="refresh" :size="15" />
                                            </button>
                                        @endif
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           title="{{ translate('view') }}"
                                           href="{{ route('admin.vendors.order-details',['order_id' => $order['id'],'vendor_id' => $order['seller_id']]) }}">
                                            <x-k.icon name="eye" :size="15" />
                                        </a>
                                        <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                           target="_blank" title="{{ translate('invoice') }}"
                                           href="{{ route('admin.orders.generate-invoice', [$order['id']]) }}">
                                            <x-k.icon name="download" :size="15" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($orders)==0)
                    <x-k.empty icon="orders" :title="translate('no_order_found')" />
                @endif
                <div class="k-pager">
                    <span class="k-pager__info">
                        @if ($orders->total() > 0)
                            {{ translate('showing') }}
                            <span class="k-num">{{ $orders->firstItem() }}–{{ $orders->lastItem() }}</span>
                            {{ translate('of') }} <span class="k-num">{{ $orders->total() }}</span>
                        @endif
                    </span>
                    <div>{!! $orders->appends(request()->except('page'))->links() !!}</div>
                </div>
            </div>
        </div>
    </div>

    @if($orders->isNotEmpty())
        @foreach($orders as $order)
            @include('admin-views.order.partials.modal.order-edit-return-amount-modal',['order' => $order])
        @endforeach
    @endif

    @include('admin-views.vendor.partials.offcanvas._filter-offcanvas')
@endsection

@push('script')
    <script>
        $(function () {
            let slider = $("#price_range_slider");
            let minThumb = $("#thumb_min");
            let maxThumb = $("#thumb_max");
            let range = $(".slider-range");
            let minInput = $("#min_price");
            let maxInput = $("#max_price");

            let sliderMin = slider?.data('min-value') ?? 0;
            let sliderMax = slider?.data('max-value') ?? 100000000;

            let minValue = sliderMin;
            let maxValue = sliderMax;

            let isRtl = $('html').attr('dir') === 'rtl';

            function updateSlider() {
                let sliderWidth = slider.width();

                let minLeft = (((minValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth);
                let maxLeft = ((maxValue - sliderMin) / (sliderMax - sliderMin)) * sliderWidth;

                if (isRtl) {
                    minLeft = sliderWidth - minLeft;
                    maxLeft = sliderWidth - maxLeft;
                }

                minThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", minLeft + "px");
                maxThumb.css(isRtl ? "insetInlineEnd" : "insetInlineStart", maxLeft + "px");

                range.css({
                    [isRtl ? 'insetInlineEnd' : 'insetInlineStart']: Math.min(minLeft, maxLeft) + "px",
                    width: Math.abs(maxLeft - minLeft) + "px",
                });

                minInput.val(minValue !== null ? minValue : minInput.attr('placeholder'));
                maxInput.val(maxValue !== null ? maxValue : maxInput.attr('placeholder'));

                let distance = maxValue - minValue;
                $('#slider_distance').text("$" + distance.toLocaleString());
            }

            function clamp(value, min, max) {
                return Math.min(Math.max(value, min), max);
            }

            function handleDrag(thumb, isMinThumb) {
                function startDrag(startX, startValue) {
                    let sliderWidth = slider.width();

                    function moveHandler(e) {
                        let pageX = e.pageX || (e.originalEvent.touches && e.originalEvent.touches[0].pageX);
                        if (!pageX) return;

                        let deltaX = isRtl ? (startX - pageX) : (pageX - startX);
                        let valueChange = (deltaX / sliderWidth) * (sliderMax - sliderMin);
                        let newValue = clamp(startValue + valueChange, sliderMin, sliderMax);

                        newValue = Math.round(newValue);

                        if (isMinThumb) {
                            minValue = Math.min(newValue, maxValue || sliderMax);
                        } else {
                            maxValue = Math.max(newValue, minValue || sliderMin);
                        }

                        updateSlider();
                    }

                    function stopHandler() {
                        $(document).off(".slider");
                    }

                    $(document).on("mousemove.slider touchmove.slider", moveHandler);
                    $(document).on("mouseup.slider touchend.slider touchcancel.slider", stopHandler);
                }

                thumb.on("mousedown touchstart", function (e) {
                    e.preventDefault();
                    let pageX = e.pageX || (e.originalEvent.touches && e.originalEvent.touches[0].pageX);
                    if (!pageX) return;

                    console.log("drag start", this.id, pageX);

                    let startValue = isMinThumb ? minValue : maxValue;
                    startDrag(pageX, startValue);
                });
            }

            minInput.on("input", function () {
                let inputValue = parseInt($(this).val(), 10);
                if (!isNaN(inputValue)) {
                    minValue = clamp(inputValue, sliderMin, maxValue || sliderMax);
                } else {
                    minValue = null;
                }
                updateSlider();
            });

            maxInput.on("input", function () {
                let inputValue = parseInt($(this).val(), 10);
                if (!isNaN(inputValue)) {
                    maxValue = clamp(inputValue, minValue || sliderMin, sliderMax);
                } else {
                    maxValue = null;
                }
                updateSlider();
            });

            handleDrag(minThumb, true);
            handleDrag(maxThumb, false);

            updateSlider();

            $(window).on("resize", function () {
                updateSlider();
            });
        });

        $(document).ready(function () {

            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

        });
    </script>
@endpush
