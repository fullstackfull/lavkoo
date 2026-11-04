@extends('layouts.admin.app')
@section('title', translate('vendor_earning'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/earning_report.png') }}" alt="">
                {{ translate('earning_Reports') }}
            </h2>
        </div>

        @include('admin-views.report.earning-report-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h4 class="mb-3">{{ translate('filter_Data')}}</h4>
                    <div class="row gy-3 gx-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="mb-2">{{ translate('select_Date')}}</label>
                            <select class="form-select" name="date_type" id="date_type">
                                <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>
                                    {{ translate('this_Year') }}
                                </option>
                                <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>
                                    {{ translate('this_Month') }}
                                </option>
                                <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>
                                    {{ translate('this_Week') }}
                                </option>
                                <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>
                                    {{ translate('today') }}
                                </option>
                                <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>
                                    {{ translate('custom_Date') }}
                                </option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div>
                                <label class="mb-2">{{ translate('start Date')}}</label>
                                <input type="date" name="from" value="{{ $from }}" id="from_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="">
                                <label class="mb-2">{{ translate('end Date')}}</label>
                                <input type="date" value="{{ $to }}" name="to" id="to_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                {{ translate('filter') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-3 mb-3">
            <div class="d-flex flex-column gap-3 flex-grow-1">
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="35" src="{{ dynamicAsset(path: 'public/assets/back-end/img/stores.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $data['total_seller'] }}</h4>
                            <h5 class="subtext">{{ translate('total_Vendor')}}</h5>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center mb-4">
                        <img width="35" src="{{ dynamicAsset(path: 'public/assets/back-end/img/cart.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $data['all_product'] }}</h4>
                            <h5 class="subtext">{{ translate('total_Vendor_Products')}}</h5>
                        </div>
                    </div>
                    <div class="coupon__discount d-flex flex-wrap justify-content-around">
                        <div class="text-center">
                            <strong class="text-danger">{{ $data['rejected_product'] }}</strong>
                            <div class="fs-12 text-muted">{{ translate('denied')}}</div>
                        </div>
                        <div class="text-center">
                            <strong class="text-primary">{{ $data['pending_product'] }}</strong>
                            <div class="fs-12 text-muted">{{ translate('pending_Request')}}</div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success">{{ $data['active_product'] }}</strong>
                            <div class="fs-12 text-muted">{{ translate('approved')}}</div>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="35" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-earning.svg')}}"
                             alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $total_earning), currencyCode: getCurrencyCode()) }}</h4>
                            <h5 class="subtext">{{ translate('total_Earning')}}</h5>
                        </div>
                    </div>
                </div>
            </div>
            @foreach($chart_earning_statistics as $amount)
                @php($chartEarningStatistics[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area flex-grow-1">
                @include('layouts.admin.partials._apexcharts',['title'=>'earning_Statistics','statisticsValue'=>$chartEarningStatistics, 'label'=> $chartEarningStatisticsLabel ,'statisticsTitle'=>'total_Earnings','average'=>(array_sum($chartEarningStatistics)/count($chartEarningStatistics))])
            </div>
            <div class="flex-grow-1">
                <div class="card h-100 bg-white payment-statistics-shadow">
                    <div class="card-header border-0 ">
                        <h4 class="card-title">{{ translate('vendor_Wallet_Status')}}</h4>
                    </div>
                    <div class="card-body px-0 pt-0">
                        <div class="position-relative pie-chart">
                            <div id="dognut-pie" class="label-hide d-flex justify-content-center"></div>
                            <div class="total--orders">
                                <h3>{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['wallet_amount'] ?? 0)) }}</h3>
                                <span>{{ translate('wallet_Amount')}}</span>
                            </div>
                        </div>
                        <div class="apex-legends">
                            <div data-color="#004188">
                                <span>{{translate('withdrawble_Balance')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['withdrawable_balance'] ?? 0), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#0177CD">
                                <span>{{translate('pending_Withdraws')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['pending_withdraw'] ?? 0), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                            <div data-color="#A2CEEE">
                                <span>{{translate('already_Withdrawn')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['already_withdrawn'] ?? 0), currencyCode: getCurrencyCode()) }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4">
                    <h3 class="mb-0 me-auto">
                        {{translate('total_Vendor')}}
                        <span class="badge badge-info text-bg-info fs-12">{{ count($seller_earn_table) }}</span>
                    </h3>

                    <a type="button" class="btn btn-outline-primary"
                       href="{{ route('admin.report.vendor-earning-excel-export', ['date_type'=>$date_type, 'from'=>$from, 'to'=>$to]) }}">
                        <i class="fi fi-sr-inbox-in"></i>
                        <span class="fs-12">{{ translate('export') }}</span>
                    </a>
                </div>

                <div class="k-table-wrap">
                    <table id="datatable"
                           class="k-table">
                        <thead>
                        <tr>
                            <th>{{translate('SL')}}</th>
                            <th>{{translate('vendor_Info')}}</th>
                            <th>{{translate('earn_From_Order')}}</th>
                            <th>{{translate('earn_From_Shipping')}}</th>
                            <th>{{translate('deliveryman_incentive')}}</th>
                            <th>{{translate('commission_Given')}}</th>
                            <th>{{translate('discount_Given')}}</th>
                            <th>{{translate('tax_Collected')}}</th>
                            <th>{{translate('refund_Given')}}</th>
                            <th>{{translate('total_Earning')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @php($i=0)
                        @foreach($seller_earn_table as $key => $seller_earn)
                            @if($seller_earn['vendor_id'])
                                <tr>
                                    <td>{{ ++$i }}</td>
                                    <td>
                                        <div>
                                            <h4 class="mb-1">
                                                <a title="{{ $seller_earn['vendor_info'] }}" class="title-color"
                                                   href="{{ route('admin.vendors.view', ['id' => $seller_earn['vendor_id']]) }}">
                                                    {{ Str::limit($seller_earn['vendor_info'], 20, '...') }}
                                                </a>
                                            </h4>
                                        </div>
                                    </td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['earn_from_order']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['earn_from_shipping']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['commission_given']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['discount_given']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['tax_collected']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['refund_given']), currencyCode: getCurrencyCode()) }}</td>
                                    <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $seller_earn['total_earning']), currencyCode: getCurrencyCode()) }}</td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($seller_earn_table) <= 0)
                    @include('layouts.admin.partials._empty-state',['text'=>'no_data_found'],['image'=>'default'])
                @endif
            </div>
        </div>
    </div>

    <span id="currency_symbol" data-text="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>

    <span id="withdrawable_balance"
          data-text="{{ usdToDefaultCurrency(amount: $payment_data['withdrawable_balance'] ?? 0) }}"></span>
    <span id="pending_withdraw"
          data-text="{{ usdToDefaultCurrency(amount: $payment_data['pending_withdraw'] ?? 0) }}"></span>
    <span id="already_withdrawn"
          data-text="{{ usdToDefaultCurrency(amount: $payment_data['already_withdrawn'] ?? 0) }}"></span>

    <span id="withdrawable_balance_text" data-text="{{translate('withdrawble_Balance')}}"></span>
    <span id="pending_withdraw_text" data-text="{{translate('pending_Withdraws')}}"></span>
    <span id="already_withdrawn_text" data-text="{{translate('already_Withdrawn')}}"></span>

    <span id="withdrawable_balance_format"
          data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['withdrawable_balance'] ?? 0)) }}"></span>
    <span id="pending_withdraw_format"
          data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['pending_withdraw'] ?? 0)) }}"></span>
    <span id="already_withdrawn_format"
          data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['already_withdrawn'] ?? 0)) }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/seller-earning-report.js') }}"></script>
@endpush
