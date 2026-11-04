@extends('layouts.admin.app')

@section('title', translate('order_Transactions'))

@section('content')
    <div class="content container-fluid ">
        <div class="mb-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{dynamicAsset(path: 'public/assets/back-end/img/order_report.png')}}" alt="">
                {{translate('transaction_report')}}
            </h2>
        </div>

        @include('admin-views.report.transaction-report-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <h3 class="mb-3">{{translate('filter_Data')}}</h3>
                <form action="#" id="form-data" method="GET" class="w-100">
                    <div class="row  gx-2 gy-3 align-items-center">
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <label class="mb-2">{{translate('select_status')}}</label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="status">
                                        <option class="text-center" value="0" disabled>
                                            {{'---'.translate('select_status').'---'}}
                                        </option>
                                        <option class="text-capitalize" value="all" {{ $status == 'all'? 'selected' : '' }} >{{translate('all_status')}} </option>
                                        <option class="text-capitalize" value="disburse" {{ $status == 'disburse'? 'selected' : '' }} >{{translate('disburse')}} </option>
                                        <option class="text-capitalize" value="hold" {{ $status == 'hold'? 'selected' : '' }}>{{translate('hold')}}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <label class="mb-2">{{translate('select_seller')}}</label>
                                <select class="custom-select" name="seller_id">
                                    <option class="text-center" value="all" {{ $seller_id == 'all' ? 'selected' : '' }}>
                                        {{translate('all')}}
                                    </option>
                                    <option class="text-center"
                                            value="inhouse" {{ $seller_id == 'inhouse' ? 'selected' : '' }}>
                                        {{translate('inhouse')}}
                                    </option>
                                    @foreach($sellers as $seller)
                                        <option class="text-start text-capitalize"
                                                value="{{ $seller->id }}" {{ $seller->id == $seller_id ? 'selected' : '' }}>
                                            {{ $seller->f_name.' '.$seller->l_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <label class="mb-2">{{translate('select_customer')}}</label>
                                <select class="custom-select" name="customer_id">
                                    <option class="text-center"
                                            value="all" {{ $customer_id == 'all' ? 'selected' : '' }}>
                                        {{translate('All_Customer')}}
                                    </option>
                                    @foreach($customers as $customer)
                                        <option class="text-start text-capitalize"
                                                value="{{ $customer->id }}" {{ $customer->id == $customer_id ? 'selected' : '' }}>
                                            {{ $customer->f_name.' '.$customer->l_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="mb-2">{{translate('select_date')}}</label>
                            <div class="select-wrapper">
                                <select class="form-select" name="date_type" id="date_type">
                                    <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{translate('this_Year')}}</option>
                                    <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{translate('this_Month')}}</option>
                                    <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{translate('this_Week')}}</option>
                                    <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{translate('today')}}</option>
                                    <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{translate('custom_Date')}}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div>
                                <label class="mb-2">{{translate('Start_Date')}}</label>
                                <input type="date" name="from" value="{{$from}}" id="from_date"
                                       class="form-control __form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="">
                                <label class="mb-2">{{translate('End_Date')}}</label>
                                <input type="date" value="{{$to}}" name="to" id="to_date"
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

        <div class="d-flex flex-wrap gap-3 mb-3">
            <div class="d-flex flex-column gap-3 flex-grow-1">
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center mb-4">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/cart.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $order_data['total_orders'] }}</h4>
                            <h5 class="subtext">{{translate('total_Orders')}}</h5>
                        </div>
                    </div>
                    <div class="coupon__discount d-flex gap-3 justify-content-around">
                        <div class="text-center">
                            <strong class="text-primary">{{ $order_data['in_house_orders'] }}</strong>
                            <div class="d-flex">
                                <span>{{translate('in_House_Orders')}}</span>
                            </div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success">{{ $order_data['seller_orders'] }}</strong>
                            <div class="d-flex">
                                <span>{{translate('vendor_Orders')}}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center mb-4">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/products.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $order_data['total_in_house_products'] + $order_data['total_seller_products'] }}</h4>
                            <h5 class="subtext">{{translate('total_Products')}}</h5>
                        </div>
                    </div>

                    <div class="coupon__discount d-flex justify-content-around mt-4">
                        <div class="text-center">
                            <strong class="text-primary">{{ $order_data['total_in_house_products'] }}</strong>
                            <div class="d-flex">
                                <span>{{translate('in_House_Products')}}</span>
                            </div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success">{{ $order_data['total_seller_products'] }}</strong>
                            <div class="d-flex">
                                <span>{{translate('vendor_Products')}}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/stores.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $order_data['total_stores'] }}</h4>
                            <h5 class="subtext">{{translate('total_Stores')}}</h5>
                        </div>
                    </div>
                </div>
            </div>
            @foreach($order_transaction_chart['order_amount'] as $amount)
                @php($amountArray[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area flex-grow-1">
                @include('layouts.admin.partials._apexcharts',['title'=>'order_Statistics','statisticsValue'=>$amountArray,'label'=> $chartDataOrderAmountLabel,'statisticsTitle'=>'total_order_amount'])
            </div>
            <div class="flex-grow-1">
                <div class="card h-100 bg-white payment-statistics-shadow">
                    <div class="card-header border-0 ">
                        <h5 class="card-title">
                            <span>{{translate('payment_Statistics')}}</span>
                        </h5>
                    </div>
                    <div class="card-body px-0 pt-0">
                        <div class="position-relative pie-chart">
                            <div id="dognut-pie" class="label-hide"></div>
                            <div class="total--orders">
                                <h3>{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['total_payment'])) }}</h3>
                                <span>{{translate('completed_payments')}}</span>
                            </div>
                        </div>
                        <div class="apex-legends">
                            <div data-color="#A2CEEE">
                                <span>{{translate('cash_payments')}} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#004188">
                                <span>{{translate('digital_payments')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment']), currencyCode: getCurrencyCode()) }}) &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
                            </div>
                            <div data-color="#0177CD">
                                <span>{{translate('wallet')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#7B94A4">
                                <span>{{translate('offline_payments')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#FFA500">
                                <span>{{translate('Returned_Amount')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['return_amount']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <x-k.data-view :title="translate('total_Transactions')" :count="$transactions->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_by_orders_id')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.transaction.order-transaction-summary-pdf', ['search'=>$search, 'date_type'=>request('date_type'), 'seller_id'=>request('seller_id'), 'customer_id'=>request('customer_id'), 'status'=>request('status'), 'from'=>request('from'), 'to'=>request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('download_PDF') }}
                </a>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.transaction.order-transaction-export-excel', ['search'=>$search,'date_type'=>request('date_type'), 'seller_id'=>request('seller_id'), 'customer_id'=>request('customer_id'), 'status'=>request('status'), 'from'=>request('from'), 'to'=>request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('order_id')}}</th>
                    <th>{{translate('shop_name')}}</th>
                    <th>{{translate('customer_name')}}</th>
                    <th class="k-table__num">{{translate('total_product_amount')}}</th>
                    <th class="k-table__num">{{translate('product_discount')}}</th>
                    <th class="k-table__num">{{translate('coupon_discount')}}</th>
                    <th class="k-table__num">{{translate('referral_Discount')}}</th>
                    <th class="k-table__num">{{translate('discounted_amount')}}</th>
                    <th class="k-table__num">{{translate('VAT/TAX')}}</th>
                    <th class="k-table__num">{{translate('shipping_charge')}}</th>
                    <th class="k-table__num">{{translate('order_amount')}}</th>
                    <th class="k-table__num">{{translate('Due_Amount')}}</th>
                    <th class="k-table__num">{{translate('Return_Amount')}}</th>
                    <th>{{translate('delivered_by')}}</th>
                    <th class="k-table__num">{{translate('deliveryman_incentive')}}</th>
                    <th class="k-table__num">{{translate('admin_discount')}}</th>
                    <th class="k-table__num">{{translate('vendor_discount') }}</th>
                    <th class="k-table__num">{{translate('admin_commission') }}</th>
                    <th class="k-table__num">{{translate('admin_net_income')}}</th>
                    <th class="k-table__num">{{translate('vendor_net_income')}}</th>
                    <th>{{translate('payment_method')}}</th>
                    <th>{{translate('payment_Status')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactionsTableData as $key => $transaction)
                    <tr>
                        <td><span class="k-num">{{ $key }}</span></td>
                        <td>
                            <a class="title-color" href="{{ route('admin.orders.details', ['id' => $transaction['order_id']]) }}">
                                {{ $transaction['order_id'] }}
                            </a>
                        </td>
                        <td>
                            <div class="k-truncate" style="max-inline-size:200px" title="{{ $transaction['shop_name'] }}">
                                {{ $transaction['shop_name'] }}
                            </div>
                        </td>
                        <td>
                            @if (!$transaction['is_guest'] && $transaction['customer_id'])
                                <a href="{{ route('admin.customer.view',[$transaction['customer_id']]) }}"
                                   class="title-color hover-c1">
                                    {{ $transaction['customer_name'] }}
                                </a>
                            @elseif($transaction['is_guest'])
                                {{ translate('guest_customer') }}
                            @else
                                <span class="k-text-subtle">{{translate('not_found')}}</span>
                            @endif
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['total_product_amount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['product_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['coupon_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['referral_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['discounted_amount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['tax']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['shipping_charge']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['order_amount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['edit_due_amount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['edit_return_amount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td>{{ $transaction['delivered_by'] }}</td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_net_income']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_net_income']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td>{{ ucwords(str_replace('_',' ', $transaction['payment_method'])) }}</td>
                        <td>
                            <x-k.badge :tone="$transaction['payment_status'] == 'disburse' ? 'success' : 'warning'">
                                {{ translate(str_replace('_',' ', $transaction['payment_status'])) }}
                            </x-k.badge>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a href="{{ route('admin.transaction.pdf-order-wise-transaction', ['order_id'=> $transaction['order_id']]) }}"
                                   class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('download_PDF') }}">
                                    <x-k.icon name="download" :size="15" />
                                </a>
                            </div>
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

    <span id="currency_symbol" data-text="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>

    <span id="digital_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['digital_payment']) }}"></span>
    <span id="cash_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['cash_payment']) }}"></span>
    <span id="wallet_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['wallet_payment']) }}"></span>
    <span id="offline_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['offline_payment']) }}"></span>
    <span id="return_amount" data-text="{{ usdToDefaultCurrency(amount: $payment_data['return_amount']) }}"></span>

    <span id="digital_payment_text" data-text="{{translate('digital_payment')}}"></span>
    <span id="cash_payment_text" data-text="{{translate('cash_payment')}}"></span>
    <span id="wallet_payment_text" data-text="{{translate('wallet_payment')}}"></span>
    <span id="offline_payment_text" data-text="{{translate('offline_payments')}}"></span>
    <span id="return_amount_text" data-text="{{ translate('Returned_Amount') }}"></span>

    <span id="digital_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment'])) }}"></span>
    <span id="cash_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment'])) }}"></span>
    <span id="wallet_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment'])) }}"></span>
    <span id="offline_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment'])) }}"></span>
    <span id="return_amount_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['return_amount'])) }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/transaction-report.js') }}"></script>
@endpush
