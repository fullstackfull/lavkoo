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
        <x-k.data-view :title="translate('order_list')" :count="$orders->total()"
                       searchName="search" :searchValue="$searchValue ?? ''"
                       :searchPlaceholder="translate('search_by_order_no')">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('order_no') }}</th>
                    <th>{{ translate('current_status') }}</th>
                    <th>{{ translate('history') }}</th>
                </tr>
                </thead>
                <tbody id="set-rows">
                @foreach($orders as $key=>$order)
                    <tr>
                        <td><span class="k-num">{{ $orders->firstItem()+$key }}</span></td>
                        <td>
                            <a class="title-color hover-c1" href="{{route('vendor.orders.details',$order['id'])}}">{{$order['id']}}</a>
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
                                    <x-k.badge tone="danger">{{ translate('Failed_To_Deliver') }}</x-k.badge>
                                    @break
                                @default
                                    <x-k.badge tone="danger">{{ translate($order['order_status']) }}</x-k.badge>
                            @endswitch
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon order-status-history"
                                        aria-label="{{ translate('order_status_history') }}" title="{{ translate('order_status_history') }}"
                                        data-id="{{ $order->id }}" data-toggle="modal" data-target="#exampleModalLong">
                                    <x-k.icon name="clock" :size="15" />
                                </button>
                            </div>
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
        <div class="modal fade" id="exampleModalLong" tabindex="-1" role="dialog" aria-labelledby="exampleModalLongTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content load-with-ajax">

                </div>
            </div>
        </div>
        <span id="order-status-url" data-url="{{ route('vendor.delivery-man.wallet.order-status-history', ['order' => ':id'] ) }}"></span>
@endsection

@push('script_2')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor/wallet.js')}}"></script>
@endpush
