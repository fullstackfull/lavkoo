@extends('layouts.vendor.app')
@section('title', translate('order_List'))

@push('css_or_js')
    <link href="{{dynamicAsset(path: 'public/assets/back-end/vendor/datatables/dataTables.bootstrap4.min.css')}}"
          rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/all-orders.png') }}" class="mb-1 me-1"
                     alt="">
                <span class="page-header-title">
                    @if($status =='processing')
                        {{translate('packaging')}}
                    @elseif($status =='failed')
                        {{translate('failed_to_Deliver')}}
                    @elseif($status == 'all')
                        {{translate('all')}}
                    @else
                        {{translate(str_replace('_',' ',$status))}}
                    @endif
                </span>
                {{translate('orders')}}
            </h2>
            <span class="badge badge-soft-dark radius-50 fz-14">{{$orders->total()}}</span>
        </div>

        @if($status == 'all')
            @php($orderSummaryTiles = [
                ['status' => 'pending', 'label' => 'Pending', 'icon' => 'clock', 'count' => $allOrdersInfo['pending_order']],
                ['status' => 'confirmed', 'label' => 'Confirmed', 'icon' => 'check', 'count' => $allOrdersInfo['confirmed_order']],
                ['status' => 'processing', 'label' => 'Packaging', 'icon' => 'orders', 'count' => $allOrdersInfo['processing_order']],
                ['status' => 'out_for_delivery', 'label' => 'Out_for_Delivery', 'icon' => 'arrow-end', 'count' => $allOrdersInfo['out_for_delivery_order']],
                ['status' => 'delivered', 'label' => 'Delivered', 'icon' => 'store', 'count' => $allOrdersInfo['delivered_order']],
                ['status' => 'canceled', 'label' => 'Canceled', 'icon' => 'close', 'count' => $allOrdersInfo['canceled_order']],
                ['status' => 'returned', 'label' => 'Returned', 'icon' => 'refresh', 'count' => $allOrdersInfo['returned_order']],
                ['status' => 'failed', 'label' => 'Failed_to_Deliver', 'icon' => 'alert', 'count' => $allOrdersInfo['failed_order']],
            ])
            <h3 class="mb-3">{{ translate('Current_Order_Summary') }}</h3>
            <div class="k-stats" style="margin-block-end:16px">
                @foreach($orderSummaryTiles as $tile)
                    <x-k.stat :label="translate($tile['label'])" :value="$tile['count']" :icon="$tile['icon']"
                              :href="route('vendor.orders.list', [$tile['status']])" />
                @endforeach
            </div>
        @endif

        <x-k.data-view :title="translate('order_list')" :count="$orders->total()"
                       searchName="searchValue" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_Order_ID')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.orders.export-excel', [
                                'delivery_man_id' => request('delivery_man_id'),
                                'status' => $status, 'from' => $from, 'to' => $to,
                                'filter' => $filter, 'searchValue' => $searchValue,
                                'seller_id' => $vendorId, 'customer_id' => $customerId,
                                'date_type' => $dateType,
                                'payment_status' => request('payment_status'),
                                'order_current_status' => request('order_current_status'),
                                'order_amount_settlement' => request('order_amount_settlement')
                            ]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
                @php($vendorOrderFilterActive = request('delivery_man_id') || $from || $to || $filter || $searchValue || $vendorId || $customerId || $dateType)
                <button type="button" class="k-btn {{ $vendorOrderFilterActive ? 'k-btn--primary' : 'k-btn--secondary' }}"
                        data-toggle="offcanvas" data-target="#offcanvasOrderFilter">
                    <x-k.icon name="filter" :size="15" /> {{ translate('Filter') }}
                </button>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('order_ID')}}</th>
                    <th>{{translate('order_Date')}}</th>
                    <th>{{translate('customer_info')}}</th>
                    <th class="k-table__num">{{translate('total_amount')}}</th>
                    @if($status == 'all')
                        <th>{{translate('order_Status')}}</th>
                    @else
                        <th>{{translate('payment_method')}}</th>
                    @endif
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($orders as $key=>$order)
                    <tr>
                        <td><span class="k-num">{{ $orders->firstItem() + $key }}</span></td>
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <a class="title-color hover-c1"
                                   href="{{route('vendor.orders.details',$order['id'])}}">{{ $order['id'] }}@if($order->order_type == 'POS') <span class="text--primary">(POS)</span>@endif</a>
                                @if( $order->edited_status == 1 && $order?->latestEditHistory?->order_due_payment_status == 'unpaid' && $order?->latestEditHistory?->order_due_payment_method != "offline_payment" && $order?->latestEditHistory?->order_due_payment_method != "cash_on_delivery" && $order?->latestEditHistory?->order_due_amount > 0)
                                    <span class="flex-shrink-0" data-toggle="tooltip"
                                          title="{{ translate('customer_will_pay_due_the_amount') }}">
                                        <img width="14"
                                             src="{{ dynamicAsset(path: 'public/assets/back-end/img/due-amount-icon.png') }}"
                                             alt="">
                                    </span>
                                @elseif($order->edited_status == 1 && $order?->latestEditHistory?->order_return_payment_status == 'pending' && $order?->latestEditHistory?->order_return_amount > 0)
                                    <span class="flex-shrink-0" data-toggle="tooltip"
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
                        </td>
                        <td>
                            <div class="k-num">{{date('d M Y',strtotime($order['created_at']))}}</div>
                            <div class="k-text-subtle k-num">{{date('H:i A',strtotime($order['created_at']))}}</div>
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
                                    <span class="text-body text-capitalize">
                                        <strong class="title-name">
                                            {{ $order->customer['f_name'].' '.$order->customer['l_name'] }}
                                        </strong>
                                    </span>
                                    @if($order->customer['phone'])
                                        <a class="d-block title-color"
                                           href="tel:{{ $order->customer['phone'] }}">{{ $order->customer['phone'] }}</a>
                                    @else
                                        <a class="d-block title-color k-truncate" style="max-inline-size:180px"
                                           href="mailto:{{ $order->customer['email'] }}">{{ $order->customer['email'] }}</a>
                                    @endif
                                @else
                                    <x-k.badge tone="danger">{{translate('invalid_customer_data')}}</x-k.badge>
                                @endif
                            @endif
                        </td>
                        <td class="k-table__num">
                            @php($orderTotalPriceSummary = \App\Utils\OrderManager::getOrderTotalPriceSummary(order: $order))
                            <div class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTotalPriceSummary['totalAmount']), currencyCode: getCurrencyCode()) }}</div>
                            @if($order->payment_status=='paid')
                                <x-k.badge tone="success">{{translate('paid')}}</x-k.badge>
                            @else
                                <x-k.badge tone="danger">{{translate('unpaid')}}</x-k.badge>
                            @endif
                        </td>
                        @if($status == 'all')
                            <td>
                                @switch($order['order_status'])
                                    @case('pending')
                                        <x-k.badge tone="info">{{ translate($order['order_status']) }}</x-k.badge>
                                        @break
                                    @case('processing')
                                        <x-k.badge tone="warning">{{ translate('packaging') }}</x-k.badge>
                                        @break
                                    @case('out_for_delivery')
                                        <x-k.badge tone="warning">{{ translate($order['order_status']) }}</x-k.badge>
                                        @break
                                    @case('confirmed')
                                    @case('delivered')
                                        <x-k.badge tone="success">{{ translate($order['order_status']) }}</x-k.badge>
                                        @break
                                    @case('failed')
                                        <x-k.badge tone="danger">{{ translate('failed_to_deliver') }}</x-k.badge>
                                        @break
                                    @default
                                        <x-k.badge tone="danger">{{ translate($order['order_status']) }}</x-k.badge>
                                @endswitch
                            </td>
                        @else
                            <td class="text-capitalize">
                                {{str_replace('_',' ',$order['payment_method'])}}
                            </td>
                        @endif
                        <td>
                            <div class="k-table__actions">
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon"
                                   title="{{translate('view')}}"
                                   href="{{route('vendor.orders.details',[$order['id']])}}">
                                    <x-k.icon name="eye" :size="15" />
                                </a>
                                <a class="k-btn k-btn--ghost k-btn--sm k-btn--icon" target="_blank"
                                   title="{{translate('invoice')}}"
                                   href="{{route('vendor.orders.generate-invoice',[$order['id']])}}">
                                    <x-k.icon name="download" :size="15" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($orders)==0)
                <x-k.empty icon="orders" :title="translate('no_order_found')"
                           :text="$searchValue ? translate('no_order_matches_your_search') : null" />
            @endif

            @if ($orders->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $orders->firstItem() }}–{{ $orders->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $orders->total() }}</span>
                    </span>
                    <div>{!! $orders->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>

    <span id="message-date-range-text" data-text="{{ translate("invalid_date_range") }}"></span>
    <span id="js-data-example-ajax-url" data-url="{{ route('vendor.orders.customers') }}"></span>

    @include('vendor-views.order.partials._filter-offcanvas')

    @if($orders->isNotEmpty())
        @foreach($orders as $order)
            @include('vendor-views.order.partials.modal.order-edit-return-amount-modal', ['order' => $order])
        @endforeach
    @endif
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/vendor/datatables/jquery.dataTables.min.js')}}"></script>
    <script
        src="{{dynamicAsset(path: 'public/assets/back-end/vendor/datatables/dataTables.bootstrap4.min.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/order.js')}}"></script>
@endpush
