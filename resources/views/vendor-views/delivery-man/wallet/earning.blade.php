@extends('layouts.vendor.app')

@section('title',translate('earning_statement'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('earning_Statement')}}
            </h2>
        </div>
        @include('vendor-views.delivery-man.pages-inline-menu')
        <div class="card mb-3">
            <div class="card-body">

                <div class="row justify-content-between align-items-center g-2 mb-3">
                    <div class="col-sm-6">
                        <h4 class="d-flex align-items-center text-capitalize gap-10 mb-0">
                            {{ translate('earning_statement') }}
                        </h4>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-sm-6 col-lg-4">
                        <div class="business-analytics">
                            <h5 class="business-analytics__subtitle">{{ translate('total_earning') }}</h5>
                            <h2 class="business-analytics__title">
                                {{ $totalEarn ? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalEarn), currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) }}
                            </h2>
                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/aw.png') }}" width="40" class="business-analytics__img" alt="">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="business-analytics">
                            <h5 class="business-analytics__subtitle">{{ translate('withdrawable_balance') }}</h5>
                            <h2 class="business-analytics__title">{{ $withdrawableBalance? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawableBalance)) : setCurrencySymbol(amount: 0) }}</h2>
                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/pw.png') }}" width="40" class="business-analytics__img" alt="">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="business-analytics">
                            <h5 class="business-analytics__subtitle">{{ translate('withdrawn') }}</h5>
                            <h2 class="business-analytics__title">{{ $deliveryMan->wallet? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $deliveryMan->wallet->total_withdraw), currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) }}</h2>
                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/withdraw.png') }}" width="40" class="business-analytics__img" alt="">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <x-k.data-view :title="translate('earning_history')" :count="$orders->total()"
                       searchName="search" :searchValue="$searchValue ?? ''"
                       :searchPlaceholder="translate('search_by_order_no')">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('order_no') }}</th>
                    <th class="k-table__num">{{ translate('earning') }}</th>
                    <th>{{ translate('status') }}</th>
                </tr>
                </thead>
                <tbody id="set-rows">
                @foreach($orders as $key=>$order)
                    <tr>
                        <td><span class="k-num">{{ $orders->firstItem()+$key }}</span></td>
                        <td>
                            <a class="title-color hover-c1" href="{{route('vendor.orders.details',$order['id'])}}">{{$order['id']}}</a>
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order->deliveryman_charge), currencyCode: getCurrencyCode()) }}</span>
                        </td>
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
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($orders)==0)
                <x-k.empty icon="orders" :title="translate('no_order_found')" />
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
@endsection
