@extends('layouts.vendor.app')
@section('title', translate('order_Transactions'))
@section('content')
    <div class="content container-fluid ">
        <div class="mb-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/order_report.png') }}" alt="">
                {{ translate('transaction_report') }}
            </h2>
        </div>
        @include('vendor-views.transaction.transaction-report-inline-menu')

        <div class="card mb-2">
            <div class="card-body">
                <h4 class="mb-3">{{ translate('filter_Data') }}</h4>
                <form action="#" id="form-data" method="GET" class="w-100">
                    <div class="row  gx-2 gy-3 align-items-center text-{{Session::get('direction') === "rtl" ? 'right' : 'left'}}">
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <select class="form-control __form-control" name="status">
                                    <option class="text-center" value="0" disabled>
                                        ---{{ translate('select_status') }}---
                                    </option>
                                    <option class="text-capitalize"
                                            value="all" {{ $status == 'all'? 'selected' : '' }} >{{ translate('all') }} </option>
                                    <option class="text-capitalize"
                                            value="disburse" {{ $status == 'disburse'? 'selected' : '' }} >{{ translate('disburse') }} </option>
                                    <option class="text-capitalize"
                                            value="hold" {{ $status == 'hold'? 'selected' : '' }}>{{ translate('hold') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="">
                                <select class="js-select2-custom form-control __form-control" name="customer_id">
                                    <option class="text-center"
                                            value="all" {{ $customer_id == 'all' ? 'selected' : '' }}>
                                        {{ translate('All_Customer') }}
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
                            <select class="form-control __form-control" name="date_type" id="date_type">
                                <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{ translate('this_Year') }}</option>
                                <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{ translate('this_Month') }}</option>
                                <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{ translate('this_Week') }}</option>
                                <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{ translate('today') }}</option>
                                <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{ translate('custom_Date') }}</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div class="form-floating">
                                <input type="date" name="from" value="{{ $from}}" id="from_date"
                                       class="form-control __form-control">
                                <label>{{ translate('Start_Date') }}</label>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="form-floating">
                                <input type="date" value="{{ $to}}" name="to" id="to_date"
                                       class="form-control __form-control">
                                <label>{{ translate('End_Date') }}</label>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn--primary px-4 min-w-120 __h-45px"
                                    id="formUrlChange"
                                    data-action="{{ url()->current() }}">
                                {{ translate('filter') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="store-report-content mb-2">
            <div class="left-content">
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/cart.svg') }}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ $product_data['total_products'] }}</h4>
                        <h6 class="subtext">{{ translate('total_Products') }}</h6>
                    </div>
                </div>
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/products.svg') }}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ $product_data['active_products'] }}</h4>
                        <h6 class="subtext">{{ translate('active_Products') }}</h6>
                    </div>
                </div>
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/inactive-product.svg') }}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ $product_data['inactive_products'] }}</h4>
                        <h6 class="subtext">{{ translate('inactive_Products') }}</h6>
                    </div>
                </div>
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/pending_products.svg') }}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ $product_data['pending_products'] }}</h4>
                        <h6 class="subtext">{{ translate('pending_Products') }}</h6>
                    </div>
                </div>
            </div>
            @foreach($order_transaction_chart['order_amount'] as $amount)
                @php($chartVal[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area">
                @include('layouts.vendor.partials._apexcharts',['title'=>'order_Statistics','statisticsValue'=>$chartVal,'label'=> $chartTransactionOrderLabel, 'statisticsTitle'=>'total_order_amount'])
            </div>
            <div class="right-content">
                <div class="card h-100 bg-white payment-statistics-shadow">
                    <div class="card-header border-0 ">
                        <h5 class="card-title">
                            <span>{{ translate('payment_Statistics') }}</span>
                        </h5>
                    </div>
                    <div class="card-body px-0 pt-0">
                        <div class="position-relative pie-chart">
                            <div id="dognut-pie" class="label-hide"></div>
                            <div class="total--orders">
                                <h3>{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}{{ getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['total_payment'])) }}</h3>
                                <span>{{ translate('completed_payments') }}</span>
                            </div>
                        </div>
                        <div class="apex-legends">
                            <div class="before-bg-A2CEEE">
                                <span>{{ translate('cash_payments') }} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div class="before-bg-004188">
                                <span>{{ translate('digital_payments') }} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment']), currencyCode: getCurrencyCode()) }}) &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
                            </div>
                            <div class="before-bg-0177CD">
                                <span>{{ translate('wallet') }} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div class="before-bg-7B94A4">
                                <span>{{ translate('offline_payments') }} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div class="before-bg-FFA500">
                                <span>{{translate('Returned_Amount')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['return_amount']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <x-k.data-view :title="translate('total_Transactions')" :count="$transactions->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_by_orders_id_or_transaction_ID')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.transaction.order-transaction-summary-pdf', ['date_type'=>request('date_type'), 'customer_id'=>request('customer_id'), 'status'=>request('status'), 'from'=>request('from'), 'to'=>request('to'), 'search'=>request('search')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('download_PDF') }}
                </a>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.transaction.order-transaction-export-excel', ['date_type'=>request('date_type'), 'customer_id'=>request('customer_id')??'all', 'search'=>request('search'), 'status'=>request('status'), 'from'=>request('from'), 'to'=>request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('order_id') }}</th>
                    <th>{{ translate('customer_name') }}</th>
                    <th class="k-table__num">{{ translate('total_product_amount') }}</th>
                    <th class="k-table__num">{{ translate('product_discount') }}</th>
                    <th class="k-table__num">{{ translate('coupon_discount') }}</th>
                    <th class="k-table__num">{{ translate('referral_Discount') }}</th>
                    <th class="k-table__num">{{ translate('discounted_amount') }}</th>
                    <th class="k-table__num">{{ translate('VAT/TAX') }}</th>
                    <th class="k-table__num">{{ translate('shipping_charge') }}</th>
                    <th class="k-table__num">{{ translate('order_amount') }}</th>
                    <th>{{ translate('delivered_by') }}</th>
                    <th class="k-table__num">{{ translate('deliveryman_incentive') }}</th>
                    <th class="k-table__num">{{ translate('admin_discount') }}</th>
                    <th class="k-table__num">{{ translate('vendor_discount') }}</th>
                    <th class="k-table__num">{{ translate('admin_commission') }}</th>
                    <th class="k-table__num">{{ translate('vendor_net_income') }}</th>
                    <th>{{ translate('payment_method') }}</th>
                    <th>{{ translate('payment_Status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactionsTableData as $key => $transaction)
                    <tr>
                        <td><span class="k-num">{{ $key }}</span></td>
                        <td>
                            <a class="title-color" href="{{ route('vendor.orders.details', $transaction['order_id']) }}">
                                {{ $transaction['order_id'] }}
                            </a>
                        </td>
                        <td>
                            @if (!$transaction['is_guest'] && $transaction['customer_id'])
                                {{ $transaction['customer_name'] }}
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
                        <td>{{ $transaction['delivered_by'] }}</td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_discount']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['vendor_net_income']), currencyCode: getCurrencyCode()) }}</span></td>
                        <td>{{ ucwords(str_replace('_',' ', $transaction['payment_method'])) }}</td>
                        <td>
                            <x-k.badge :tone="$transaction['payment_status'] == 'disburse' ? 'success' : 'warning'">
                                {{ translate(str_replace('_',' ', $transaction['payment_status'])) }}
                            </x-k.badge>
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a href="{{ route('vendor.transaction.pdf-order-wise-transaction', ['order_id'=> $transaction['order_id']]) }}"
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

    <span id="digital_payment_text" data-text="{{ translate('digital_payment') }}"></span>
    <span id="cash_payment_text" data-text="{{ translate('cash_payment') }}"></span>
    <span id="wallet_payment_text" data-text="{{ translate('wallet_payment') }}"></span>
    <span id="offline_payment_text" data-text="{{ translate('offline_payments') }}"></span>
    <span id="return_amount_text" data-text="{{ translate('Returned_Amount') }}"></span>

    <span id="digital_payment_format" data-text="{{ getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment'])) }}"></span>
    <span id="cash_payment_format" data-text="{{ getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment'])) }}"></span>
    <span id="wallet_payment_format" data-text="{{ getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment'])) }}"></span>
    <span id="offline_payment_format" data-text="{{ getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment'])) }}"></span>
    <span id="return_amount_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['return_amount'])) }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/apexcharts.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/apexcharts-data-show.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/transaction-report.js') }}"></script>
@endpush
