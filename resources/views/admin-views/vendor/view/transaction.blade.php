@extends('layouts.admin.app')

@section('title',$seller?->shop->name ?? translate("shop_name_not_found"))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex gap-2 align-items-center">
                <img src="{{dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png')}}" alt="">
                {{translate('vendor_details')}}
            </h2>
        </div>
        <div class="flex-between d-sm-flex row align-items-center justify-content-between mb-2 mx-1">
            <div>
                @if ($seller['status']=="pending")
                    <div class="mt-4 pe-2">
                        <div class="flex-start">
                            <div class="mx-1"><h4><i class="fi fi-rr-shop"></i></h4></div>
                            <div>{{ translate('vendor_request_for_open_a_shop') }}</div>
                        </div>
                        <div class="text-center">
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller['id']}}">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-primary btn-sm">{{translate('approve')}}</button>
                            </form>
                            <form class="d-inline-block" action="{{route('admin.vendors.updateStatus')}}" method="POST">
                                @csrf
                                <input type="hidden" name="id" value="{{$seller['id']}}">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-danger btn-sm">{{translate('reject')}}</button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <div class="page-header mb-4">
            <h2 class="page-header-title mb-3">{{  $seller?->shop->name ?? translate("shop_Name")." : ".translate("update_Please") }}</h2>

            <div class="position-relative nav--tab-wrapper">
                <ul class="nav nav-pills nav--tab">
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',$seller->id) }}">{{translate('shop')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'order']) }}">{{translate('order')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'product']) }}">{{translate('product')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller['id'], 'tab'=>'clearance_sale']) }}">{{translate('clearance_sale_products')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'setting']) }}">{{translate('setting')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'transaction']) }}">{{translate('transaction')}}</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="{{ route('admin.vendors.view',['id'=>$seller->id, 'tab'=>'review']) }}">{{translate('review')}}</a>
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
        <div class="card mb-3">
            <div class="card-body">
                <h3 class="mb-3">{{translate('filter_Data')}}</h3>
                <form action="{{ url()->current() }}" method="GET">
                    <div class="row  gx-2 gy-3 align-items-center">
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <label class="mb-2">{{translate('select_status')}}</label>
                                <div class="select-wrapper flex-grow-1">
                                    <select class="form-select" name="status">
                                        <option value="0" selected disabled>{{'---'.translate('select_status').'---'}}</option>
                                        <option class="text-capitalize"
                                                value="all" {{ request('status') == 'all'? 'selected' : '' }} >{{translate('all')}} </option>
                                        <option class="text-capitalize"
                                                value="disburse" {{ request('status') == 'disburse'? 'selected' : '' }} >{{translate('disburse')}} </option>
                                        <option class="text-capitalize"
                                                value="hold" {{ request('status') == 'hold'? 'selected' : '' }}>{{translate('hold')}}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <label class="mb-2">{{translate('select_customer')}}</label>
                                <select class="custom-select" name="customer_id">
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer['id'] }}"
                                            {{ (request('customer_id', 'all') === (string) $customer['id']) ? 'selected' : '' }}>
                                            {{ $customer['text'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="mb-2">{{translate('select_date')}}</label>
                            <div class="select-wrapper">
                                <select class="form-select" name="date_type" id="date_type">
                                    <option value="this_year">{{translate('this_Year')}}</option>
                                    <option value="this_month">{{translate('this_Month')}}</option>
                                    <option value="this_week">{{translate('this_Week')}}</option>
                                    <option value="today">{{translate('today')}}</option>
                                    <option value="custom_date">{{translate('custom_Date')}}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div>
                                <label class="mb-2">{{translate('Start_Date')}}</label>
                                <input type="date" name="from" value="" id="from_date"
                                       class="form-control __form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="">
                                <label class="mb-2">{{translate('End_Date')}}</label>
                                <input type="date" value="" name="to" id="to_date"
                                       class="form-control __form-control">
                            </div>
                        </div>
                        <div class="col-md-12 d-flex justify-content-end gap-2 pt-0">
                            <button type="submit" class="btn btn-primary px-4 min-w-120 __h-45px"
                                    id="formUrlChange"
                                    data-action="{{ url()->current() }}">
                                {{translate('filter')}}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <x-k.data-view :title="translate('transaction_table')" :count="$transactions->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_orders_id_or_transaction_id')">

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('vendor_name')}}</th>
                    <th>{{translate('customer_name')}}</th>
                    <th>{{translate('order_id')}}</th>
                    <th>{{translate('transaction_id')}}</th>
                    <th class="k-table__num">{{translate('order_amount')}}</th>
                    <th class="k-table__num">{{translate('vendor_amount') }}</th>
                    <th class="k-table__num">{{translate('admin_commission')}}</th>
                    <th>{{translate('received_by')}}</th>
                    <th>{{translate('delivered_by')}}</th>
                    <th class="k-table__num">{{translate('delivery_charge')}}</th>
                    <th>{{translate('payment_method')}}</th>
                    <th class="k-table__num">{{translate('tax')}}</th>
                    <th>{{translate('status')}}</th>
                </tr>
                </thead>
                <tbody>
                @php($companyName = getInHouseShopConfig(key:'name'))
                @foreach($transactions as $key=>$transaction)
                    <tr>
                        <td><span class="k-num">{{$transactions->firstItem()+$key}}</span></td>
                        <td>
                            @if($transaction['seller_is'] == 'admin')
                                {{ $companyName }}
                            @else
                                {{ $transaction?->seller->f_name .' '.$transaction?->seller->l_name }}
                            @endif
                        </td>
                        <td>
                            {{ $transaction->order->is_guest ? translate('guest_customer'):($transaction->order->customer ? $transaction->order->customer->f_name.' '.$transaction->order->customer->l_name : translate('customer_not_found')) }}
                        </td>
                        <td><span class="k-num">{{$transaction['order_id']}}</span></td>
                        <td><span class="k-num">{{$transaction['transaction_id']}}</span></td>
                        <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['order_amount']))}}</span></td>
                        <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['seller_amount']))}}</span></td>
                        <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']))}}</span></td>
                        <td>{{$transaction['received_by']}}</td>
                        <td>{{$transaction['delivered_by']}}</td>
                        <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['delivery_charge']))}}</span></td>
                        <td>{{str_replace('_',' ',$transaction['payment_method'])}}</td>
                        <td class="k-table__num"><span class="k-num">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['tax']))}}</span></td>
                        <td>
                            <x-k.badge :tone="$transaction['status'] == 'disburse' ? 'success' : 'warning'">
                                {{translate($transaction['status'])}}
                            </x-k.badge>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($transactions)==0)
                <x-k.empty icon="reports" :title="translate('no_data_found')" />
            @endif

            @if ($transactions->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $transactions->firstItem() }}–{{ $transactions->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $transactions->total() }}</span>
                    </span>
                    <div>{!! $transactions->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>
    </div>
</div>
@endsection

@push('script')
    <script>
        "use strict";

        $('#from_date,#to_date').change(function () {
            let from_date = $('#from_date').val();
            let to_date = $('#to_date').val();
            if (from_date != '') {
                $('#to_date').attr('required', 'required');
            }
            if (to_date != '') {
                $('#from_date').attr('required', 'required');
            }
            if (from_date != '' && to_date != '') {
                if (from_date > to_date) {
                    $('#from_date').val('');
                    $('#to_date').val('');
                    toastMagic.error('Invalid date range!');
                }
            }

        })

        $("#date_type").change(function () {
            let val = $(this).val();
            $('#from_div').toggle(val === 'custom_date');
            $('#to_div').toggle(val === 'custom_date');

            if (val === 'custom_date') {
                $('#from_date').attr('required', 'required');
                $('#to_date').attr('required', 'required');
            } else {
                $('#from_date').val(null).removeAttr('required')
                $('#to_date').val(null).removeAttr('required')
            }
        }).change();
    </script>
@endpush
