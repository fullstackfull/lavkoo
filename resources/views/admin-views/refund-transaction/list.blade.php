@php use Illuminate\Support\Str; @endphp
@extends('layouts.admin.app')
@section('title',translate('refund_transactions'))

@section('content')
    <div class="content container-fluid ">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/order_report.png')}}" alt="">
                {{ translate('transaction_report')}}
            </h2>
        </div>

        @include('admin-views.report.transaction-report-inline-menu')

        <x-k.data-view :title="translate('total_transaction')" :count="$refundTransactions->total()"
                       searchName="searchValue" :searchValue="$searchValue"
                       :searchPlaceholder="translate('search_by_orders_id_or_refund_id')">

            <x-slot:actions>
                <form action="#" id="form-data" method="GET" class="k-row">
                    <select class="k-input" style="inline-size:auto" name="payment_method" id="payment_method"
                            aria-label="{{ translate('payment_method') }}">
                        <option value="all" {{ $paymentMethod=='all' ? 'selected': '' }}>{{translate('all')}}</option>
                        <option value="cash" {{ $paymentMethod=='cash' ? 'selected': '' }}>{{translate('cash')}}</option>
                        <option value="digitally_paid" {{ $paymentMethod=='digitally_paid' ? 'selected': '' }}>{{translate('digitally_paid')}}</option>
                        <option value="customer_wallet" {{ $paymentMethod=='customer_wallet' ? 'selected': '' }}>{{translate('customer_wallet')}}</option>
                    </select>
                    <button type="submit" class="k-btn k-btn--secondary" id="formUrlChange" data-action="{{ url()->current() }}">
                        {{translate('filter')}}
                    </button>
                </form>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.report.transaction.refund-transaction-export', ['payment_method'=>$paymentMethod, 'searchValue'=>$searchValue]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('product')}}</th>
                    <th>{{translate('refund_id')}}</th>
                    <th>{{translate('order_id')}}</th>
                    <th>{{translate('shop_name')}}</th>
                    <th>{{translate('payment_method') }}</th>
                    <th>{{translate('payment_status')}}</th>
                    <th>{{translate('paid_by')}}</th>
                    <th class="k-table__num">{{translate('amount')}}</th>
                    <th>{{translate('transaction_type')}}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($refundTransactions as $key => $refund_transaction)
                    <tr class="text-capitalize">
                        <td><span class="k-num">{{$refundTransactions->firstItem()+$key}}</span></td>
                        <td>
                            @if($refund_transaction?->orderDetails?->product)
                                <a href="{{route('admin.products.view', ['addedBy'=>($refund_transaction?->orderDetails?->product->added_by =='seller'?'vendor' : 'in-house'),'id'=>$refund_transaction?->orderDetails?->product->id])}}"
                                   class="k-row">
                                    <img src="{{ getStorageImages(path: $refund_transaction?->orderDetails?->product->thumbnail_full_url,type: 'backend-product')}}"
                                         alt="" width="40" height="40"
                                         style="border-radius:8px;object-fit:cover;flex:0 0 auto;border:1px solid var(--k-border)">
                                    <span class="k-truncate text-dark text-hover-primary" style="max-inline-size:180px">
                                        {{ isset($refund_transaction?->orderDetails?->product->name) ? Str::limit($refund_transaction?->orderDetails?->product->name, 20) : '' }}
                                    </span>
                                </a>
                            @else
                                <span class="k-text-subtle">{{translate('not_found')}}</span>
                            @endif
                        </td>
                        <td>
                            @if ($refund_transaction->refund_id)
                                <a href="{{route('admin.refund-section.refund.details',['id'=>$refund_transaction['refund_id']])}}"
                                   class="text-dark text-hover-primary">
                                    {{$refund_transaction->refund_id}}
                                </a>
                            @else
                                <span class="k-text-subtle">{{translate('not_found')}}</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{route('admin.orders.details',['id'=>$refund_transaction->order_id])}}"
                               class="text-dark text-hover-primary">
                                {{$refund_transaction->order_id}}
                            </a>
                        </td>
                        <td>
                            @if($refund_transaction?->order?->seller_is == 'seller' && $refund_transaction?->order?->seller)
                                <a href="{{ route('admin.vendors.view', ['id' => $refund_transaction->order->seller->id]) }}" target="_blank" class="text-dark text-decoration-none">{{ $refund_transaction->order->seller->shop->name }}</a>
                            @else
                                {{translate('inhouse')}}
                            @endif
                        </td>
                        <td>
                            {{translate(str_replace('_',' ',$refund_transaction->payment_method))}}
                        </td>
                        <td>
                            {{translate(str_replace('_',' ',$refund_transaction->payment_status))}}
                        </td>
                        <td>
                            {{translate($refund_transaction->paid_by)}}
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $refund_transaction->amount), currencyCode: getCurrencyCode())}}</span>
                        </td>
                        <td>
                            {{ $refund_transaction->transaction_type == 'Refund' ? translate('refunded') : str_replace('_',' ',$refund_transaction->transaction_type)}}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($refundTransactions)==0)
                <x-k.empty icon="refresh" :title="translate('no_data_found')" />
            @endif

            @if ($refundTransactions->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $refundTransactions->firstItem() }}–{{ $refundTransactions->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $refundTransactions->total() }}</span>
                    </span>
                    <div>{!! $refundTransactions->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
@endsection
