@extends('layouts.admin.app')
@section('title', translate('order_Report'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/order_report.png')}}" alt="">
                {{translate('order_Report')}}
            </h2>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h3 class="mb-3">{{translate('filter_Data')}}</h3>
                    <div class="bg-section rounded p-3 p-sm-20 mb-20">
                        <div class="row g-3 align-items-end">
                            <div class="col-sm-6 col-lg-3">
                                <label class="mb-2">{{ translate('select_Seller')}}</label>
                                <select class="custom-select text-ellipsis" name="seller_id">
                                    <option value="all" {{ $seller_id == 'all' ? 'selected' : '' }}>{{translate('all')}}</option>
                                    <option value="inhouse" {{ $seller_id == 'inhouse' ? 'selected' : '' }}>{{translate('in-House')}}</option>
                                    @foreach($sellers as $seller)
                                        <option value="{{ $seller['id'] }}" {{ $seller_id == $seller['id'] ? 'selected' : '' }}>
                                            {{$seller['f_name'] }} {{$seller['l_name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="mb-2">{{ translate('select_Date')}}</label>
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
                            <div class="col-sm-6 col-lg-3" id="from_div">
                                <div>
                                    <label class="mb-2">{{ ucwords(translate('Start_Date'))}}</label>
                                    <input type="date" name="from" value="{{$from}}" id="from_date" class="form-control">
                                </div>
                            </div>
                            <div class="col-sm-6 col-lg-3" id="to_div">
                                <div>
                                    <label class="mb-2">{{ ucwords(translate('End_Date'))}}</label>
                                    <input type="date" value="{{$to}}" name="to" id="to_date" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center justify-content-end flex-wrap gap-3">
                        <button type="reset" class="btn btn-secondary min-w-120">
                            {{translate('reset')}}
                        </button>
                        <button type="submit" class="btn btn-primary min-w-120 filter-btn">
                            {{translate('filter')}}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-3 mb-3">
            <div class="d-flex flex-column gap-3 flex-grow-1">
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center mb-4">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/cart.svg')}}" alt="{{translate('image')}}">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $order_count['total_order'] }}</h4>
                            <h5 class="subtext">{{translate('total_Orders')}}</h5>
                        </div>
                    </div>
                    <div class="coupon__discount d-flex flex-wrap justify-content-around gap-2">
                        <div class="text-center">
                            <strong class="text-danger fs-12 fw-bold">{{ $order_count['canceled_order'] }}</strong>
                            <div class="d-flex gap-2 align-items-center fs-12">
                                <span>{{translate('canceled')}}</span>
                                <span class="lh-1" data-bs-toggle="tooltip" data-bs-title="{{translate('this_count_is_the_summation_of')}} {{translate('failed_to_deliver')}}, {{translate('canceled')}}, {{translate('and')}} {{translate('returned_orders')}}">
                                      <i class="fi fi-rr-info"></i>
                                </span>
                            </div>
                        </div>
                        <div class="text-center">
                            <strong class="text-primary fs-12 fw-bold">{{ $order_count['ongoing_order'] }}</strong>
                            <div class="d-flex gap-2 align-items-center fs-12">
                                <span>{{translate('ongoing')}}</span>
                                <span class="lh-1" data-bs-toggle="tooltip" data-bs-title="{{translate('this_count_is_the_summation_of')}} {{translate('pending')}}, {{translate('confirmed')}}, {{translate('packaging')}}, {{translate('out_for_delivery_orders')}}">
                                      <i class="fi fi-rr-info"></i>
                                </span>
                            </div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success fs-12 fw-bold">{{ $order_count['delivered_order'] }}</strong>
                            <div class="d-flex gap-2 align-items-center fs-12">
                                <span>{{translate('completed')}}</span>
                                <span class="lh-1" data-bs-toggle="tooltip" data-bs-title="{{translate('this_count_is_the_summation_of_delivered_orders')}}">
                                      <i class="fi fi-rr-info"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center mb-4">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/products.svg')}}" alt="{{translate('image')}}">
                        <div class="info">
                            <h4 class="subtitle h1">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $due_amount + $settled_amount - $totalReferralDiscount), currencyCode: getCurrencyCode()) }}
                            </h4>
                            <h5 class="subtext">{{translate('total_Order_Amount')}}</h5>
                        </div>
                    </div>
                    <div class="coupon__discount d-flex gap-2 justify-content-around">
                        <div class="text-center">
                            <strong class="text-danger">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $due_amount), currencyCode: getCurrencyCode()) }}
                            </strong>
                            <div class="d-flex gap-2 align-items-center fs-12">
                                <span>{{translate('due_Amount')}}</span>
                                <span class="trx-y-2 ms-2" data-bs-toggle="tooltip" data-bs-title="{{translate('the_ongoing_order_amount_will_be_shown_here')}}">
                                      <i class="fi fi-rr-info"></i>
                                </span>
                            </div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $settled_amount), currencyCode: getCurrencyCode()) }}
                            </strong>
                            <div class="d-flex gap-2 align-items-center fs-12">
                                <span>{{translate('already_Settled')}}</span>
                                <span class="trx-y-2 ms-2" data-bs-toggle="tooltip" data-bs-title="{{translate('after_the_order_is_delivered_total_order_amount_will_be_shown_here')}}">
                                      <i class="fi fi-rr-info"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @foreach($chart_data['order_amount'] as $amount)
                @php($chartVal[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area flex-grow-1">
                @include('layouts.admin.partials._apexcharts', ['title'=>'order_Statistics','statisticsValue'=>$chartVal,'label'=> $chartDataOrderAmountLabel,'statisticsTitle'=>'total_settled_amount'])
            </div>
            <div class="flex-grow-1">
                <div class="card h-100 bg-white payment-statistics-shadow">
                    <div class="card-header border-0 ">
                        <h3 class="card-title">{{translate('payment_Statistics')}}</h3>
                    </div>
                    <div class="card-body px-0 pt-0">
                        <div class="position-relative pie-chart">
                            <div id="dognut-pie" class="label-hide"></div>
                            <div class="total--orders">
                                <h3 class="mb-1">
                                    {{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['total_payment'])) }}
                                </h3>
                                <span>{{translate('completed')}} <br> {{translate('payments')}}</span>
                            </div>
                        </div>
                        <div class="apex-legends flex-column">
                            <div data-color="#A2CEEE">
                                <span>{{translate('cash_Payments')}} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#004188">
                                <span>{{translate('digital_Payments')}} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#0177CD">
                                <span>{{translate('wallet')}} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#7B94A4">
                                <span>{{translate('offline_payments')}} ({{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#FFA500">
                                <span>{{translate('Returned_Amount')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['return_amount']), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <x-k.data-view :title="translate('total_Orders')" :count="$orders->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_by_order_id')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.report.order-report-excel', ['date_type'=>request('date_type'), 'seller_id'=>request('seller_id'), 'from'=>request('from'), 'to'=>request('to'), 'search'=>request('search')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('excel') }}
                </a>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.report.order-report-pdf', ['date_type'=>request('date_type'), 'seller_id'=>request('seller_id'), 'from'=>request('from'), 'to'=>request('to'), 'search'=>request('search')]) }}">
                    <x-k.icon name="download" :size="15" /> PDF
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('order_ID') }}</th>
                    <th class="k-table__num">{{ translate('total_Amount') }}</th>
                    <th class="k-table__num">{{ translate('Due_Amount') }}</th>
                    <th class="k-table__num">{{ translate('Return_Amount') }}</th>
                    <th class="k-table__num">{{ translate('product_Discount') }}</th>
                    <th class="k-table__num">{{ translate('coupon_Discount') }}</th>
                    <th class="k-table__num">{{ translate('referral_Discount') }}</th>
                    <th class="k-table__num">{{ translate('shipping_Charge') }}</th>
                    <th class="k-table__num">{{ translate('VAT/TAX') }}</th>
                    <th class="k-table__num">{{ translate('commission') }}</th>
                    <th class="k-table__num">{{ translate('deliveryman_incentive') }}</th>
                    <th>{{ translate('status') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($orders as $order)
                    <tr>
                        <td>
                            <a class="k-num" href="{{route('admin.orders.details',['id'=>$order->id])}}">#{{$order->id}}</a>
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($order?->order_amount + $order?->edit_due_amount - $order?->edit_return_amount)), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->edit_due_amount??0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->edit_return_amount??0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->details_sum_discount??0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->discount_amount??0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->refer_and_earn_discount ?? 0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order->shipping_cost - ($order->extra_discount_type == 'free_shipping_over_order_amount' ? $order->extra_discount : 0)), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->total_tax_amount ?? $order?->details_sum_tax), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->admin_commission??0), currencyCode: getCurrencyCode()) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $order?->deliveryman_charge??0), currencyCode: getCurrencyCode()) }}</span></td>
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
                <x-k.empty icon="orders" :title="translate('no_order_found')"
                           :text="$search ? translate('no_order_matches_your_search') : null" />
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

    <span id="currency_symbol" data-text="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>

    <span id="cash_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['cash_payment']) }}"></span>
    <span id="digital_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['digital_payment']) }}"></span>
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
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/order-report.js') }}"></script>
@endpush
