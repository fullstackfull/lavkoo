@extends('layouts.admin.app')
@section('title', translate('Admin_Earning'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/earning_report.png')}}" alt="">
                {{translate('earning_Reports')}}
            </h2>
        </div>
        @include('admin-views.report.earning-report-inline-menu')

        <div class="card mb-3">
            <div class="card-body">
                <form action="" id="form-data" method="GET">
                    <h3 class="mb-3">{{ translate('filter_Data')}}</h3>
                    <div class="row gy-3 gx-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="mb-2">{{ translate('select_Date')}}</label>
                            <select class="form-select" name="date_type" id="date_type">
                                <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{translate('this_Year')}}</option>
                                <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{translate('this_Month')}}</option>
                                <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{translate('this_Week')}}</option>
                                <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{translate('today')}}</option>
                                <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{translate('custom_Date')}}</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div class="">
                                <label class="mb-2">{{ ucwords(translate('Start_Date'))}}</label>
                                <input type="date" name="from" value="{{ $from }}" id="from_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="">
                                <label class="mb-2">{{ ucwords(translate('End_Date'))}}</label>
                                <input type="date" value="{{ $to }}" name="to" id="to_date" class="form-control">
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                {{ translate('filter')}}
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
                            <h4 class="subtitle h1">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: array_sum($earning_data['total_earning_statistics'])), currencyCode: getCurrencyCode()) }}</h4>
                            <h5 class="subtext">{{ translate('total_earnings')}}</h5>
                        </div>
                    </div>
                    <div class="coupon__discount d-flex flex-wrap justify-content-around">
                        <div class="text-center">
                            <strong class="text-danger break-all">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning_data['total_commission']), currencyCode: getCurrencyCode()) }}</strong>
                            <div class="fs-12 text-muted">{{ translate('commission')}}</div>
                        </div>
                        <div class="text-center">
                            <strong class="text-primary break-all">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning_data['total_inhouse_earning']), currencyCode: getCurrencyCode()) }}</strong>
                            <div class="fs-12 text-muted">{{ translate('in_House')}}</div>
                        </div>
                        <div class="text-center">
                            <strong class="text-success break-all">{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning_data['total_shipping_earn']), currencyCode: getCurrencyCode()) }}</strong>
                            <div class="fs-12 text-muted">
                                {{ translate('shipping')}}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/products.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $earning_data['total_in_house_products'] }}</h4>
                            <h5 class="subtext">{{ translate('total_In_House_Products')}}</h5>
                        </div>
                    </div>
                </div>
                <div class="card card-body">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="35" src="{{dynamicAsset(path: 'public/assets/back-end/img/stores.svg')}}" alt="">
                        <div class="info">
                            <h4 class="subtitle h1">{{ $earning_data['total_stores'] }}</h4>
                            <h5 class="subtext">{{ translate('total_Shop')}}</h5>
                        </div>
                    </div>
                </div>
            </div>
            @foreach($earning_data['total_earning_statistics'] as $amount)
                @php($earningData[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area flex-grow-1">
                @include('layouts.admin.partials._apexcharts', [
                    'title' => 'earning_Statistics',
                    'statisticsValue' => $earningData,
                    'label' => $totalEarningStatisticsLabel,
                    'statisticsTitle' => 'total_Earnings'
                ])
            </div>
            <div class="flex-grow-1">
                <div class="card h-100 bg-white payment-statistics-shadow">
                    <div class="card-header border-0 ">
                        <h4 class="card-title">{{ translate('payment_Statistics')}} </h4>
                    </div>
                    <div class="card-body px-0 pt-0">
                        <div class="position-relative pie-chart">
                            <div id="dognut-pie" class="label-hide"></div>
                            <div class="total--orders">
                                <h3>{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['total_payment'])) }}</h3>
                                <span>{{ translate('payments_Amount')}}</span>
                            </div>
                        </div>
                        <div class="apex-legends">
                            <div data-color="#A2CEEE">
                                <span>{{translate('cash_payments')}} ({{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment']), currencyCode: getCurrencyCode()) }})</span>
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

        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-4">
                    <h4 class="mb-0">
                        {{translate('total_Earnings')}}
                        <span class="badge badge-info text-bg-info fs-12">{{ count($inhouse_earn) }}</span>
                    </h4>

                    <a type="button" class="btn btn-outline-primary" href="{{ route('admin.report.admin-earning-excel-export', ['date_type'=>$date_type, 'from'=>$from, 'to'=>$to]) }}">
                        <i class="fi fi-sr-inbox-in"></i>
                        <span class="fs-12">{{ translate('export') }}</span>
                    </a>
                </div>

                <div class="k-table-wrap">
                    <table id="datatable" class="k-table">
                        <thead>
                        <tr>
                            <th>{{translate('SL')}}</th>
                            <th>{{translate('duration')}}</th>
                            <th>{{translate('in-House_Earning')}}</th>
                            <th>{{translate('commission_Earning')}}</th>
                            <th>{{translate('earn_From_Shipping')}}</th>
                            <th>{{translate('deliveryman_incentive')}}</th>
                            <th>{{translate('discount_Given')}}</th>
                            <th>{{translate('VAT/TAX')}}</th>
                            <th>{{translate('refund_Given')}}</th>
                            <th>{{translate('total_Earning')}}</th>
                            <th class="text-center">{{translate('action')}}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @php($i=1)
                        @foreach($inhouse_earn as $key => $earning)
                            <tr>
                                <td>{{ $i++ }}</td>
                                <td>{{ $earning['duration'] }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['in_house_earning']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['commission_earning']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['earn_from_shipping']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['deliveryman_incentive']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['discount_given']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['vat_tax']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['refund_given']), currencyCode: getCurrencyCode()) }}</td>
                                <td>{{setCurrencySymbol(amount: usdToDefaultCurrency(amount: $earning['total_earning']), currencyCode: getCurrencyCode()) }}</td>
                                <td>
                                    <div class="d-flex justify-content-center">
                                        <form action="{{ route('admin.report.admin-earning-duration-download-pdf') }}"
                                              method="post">
                                            @csrf
                                            <input type="hidden" name="duration" value="{{ $earning['duration']}}">
                                            <input type="hidden" name="inhouse_earning" value="{{ $earning['in_house_earning'] }}">
                                            <input type="hidden" name="admin_commission"
                                                   value="{{ $earning['commission_earning'] }}">
                                            <input type="hidden" name="shipping_earn" value="{{ $earning['earn_from_shipping'] }}">
                                            <input type="hidden" name="discount_given" value="{{ $earning['discount_given'] }}">
                                            <input type="hidden" name="total_tax" value="{{ $earning['vat_tax'] }}">
                                            <input type="hidden" name="refund_given" value="{{ $earning['refund_given']}}">
                                            <input type="hidden" name="deliveryman_incentive" value="{{ $earning['deliveryman_incentive'] }}">
                                            <input type="hidden" name="total_earning"
                                                   value="{{$earning['total_earning'] }}">
                                            <button type="submit" class="btn btn-outline-success icon-btn">
                                                <i class="fi fi-rr-down-to-line"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($inhouse_earn)==0)
                    @include('layouts.admin.partials._empty-state',['text'=>'no_data_found'],['image'=>'default'])
                @endif
            </div>
        </div>
    </div>

    <span id="currency_symbol" data-text="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>

    <span id="cash_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['cash_payment']) }}"></span>
    <span id="digital_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['digital_payment']) }}"></span>
    <span id="wallet_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['wallet_payment']) }}"></span>
    <span id="offline_payment" data-text="{{ usdToDefaultCurrency(amount: $payment_data['offline_payment']) }}"></span>
    <span id="return_amount" data-text="{{ usdToDefaultCurrency(amount: $payment_data['return_amount']) }}"></span>

    <span id="cash_payment_text" data-text="{{translate('cash_Payments')}}"></span>
    <span id="digital_payment_text" data-text="{{translate('digital_payment')}}"></span>
    <span id="wallet_payment_text" data-text="{{translate('wallet_payment')}}"></span>
    <span id="offline_payment_text" data-text="{{translate('offline_payment')}}"></span>
    <span id="return_amount_text" data-text="{{ translate('Returned_Amount') }}"></span>

    <span id="cash_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['cash_payment'])) }}"></span>
    <span id="digital_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['digital_payment'])) }}"></span>
    <span id="wallet_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['wallet_payment'])) }}"></span>
    <span id="offline_payment_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['offline_payment'])) }}"></span>
    <span id="return_amount_format" data-text="{{getFormatCurrency(amount: usdToDefaultCurrency(amount: $payment_data['return_amount'])) }}"></span>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/new/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/admin/admin-earning-report.js') }}"></script>
@endpush
