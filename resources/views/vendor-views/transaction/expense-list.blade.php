@extends('layouts.vendor.app')
@section('title', translate('expense_Transactions'))
@section('content')
    <div class="content container-fluid ">
        <div class="mb-4">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img width="20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/order_report.png') }}" alt="">
                {{translate('transaction_report')}}
            </h2>
        </div>

        @include('vendor-views.transaction.transaction-report-inline-menu')

        <div class="card mb-2">
            <div class="card-body">
                <form action="#" id="form-data" method="GET">
                    <h4 class="mb-3">{{translate('filter_Data')}}</h4>
                    <div class="row  gy-2 align-items-center text-{{Session::get('direction') === "rtl" ? 'right' : 'left'}}">
                        <div class="col-sm-6 col-md-3">
                            <select class="form-control __form-control" name="date_type" id="date_type">
                                <option value="this_year" {{ $date_type == 'this_year'? 'selected' : '' }}>{{translate('this_year')}}</option>
                                <option value="this_month" {{ $date_type == 'this_month'? 'selected' : '' }}>{{translate('this_month')}}</option>
                                <option value="this_week" {{ $date_type == 'this_week'? 'selected' : '' }}>{{translate('this_week')}}</option>
                                <option value="today" {{ $date_type == 'today'? 'selected' : '' }}>{{translate('today')}}</option>
                                <option value="custom_date" {{ $date_type == 'custom_date'? 'selected' : '' }}>{{translate('custom_date')}}</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-3" id="from_div">
                            <div class="form-floating">
                                <input type="date" name="from" value="{{$from}}" id="from_date"
                                       class="form-control __form-control">
                                <label>{{translate('Start_Date')}}</label>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3" id="to_div">
                            <div class="form-floating">
                                <input type="date" value="{{$to}}" name="to" id="to_date"
                                       class="form-control __form-control">
                                <label>{{translate('End_Date')}}</label>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <button type="submit" class="btn btn--primary px-4 w-100" id="formUrlChange"
                                    data-action="{{ url()->current() }}">
                                {{translate('filter')}}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="store-report-content mb-2">
            <div class="left-content expense--content">
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/expense.svg')}}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $total_expense), currencyCode: getCurrencyCode()) }}</h4>
                        <h6 class="subtext">
                            <span>{{translate('total_Expense')}}</span>
                            <span class="ms-2" data-toggle="tooltip" data-placement="top"
                                  title="{{translate('free_delivery')}}, {{translate('coupon_discount_will_be_shown_here')}}">
                                <img class="info-img" src="{{dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg')}}"
                                     alt="img">
                            </span>
                        </h6>
                    </div>
                </div>
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/free-delivery.svg')}}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $free_delivery), currencyCode: getCurrencyCode()) }}</h4>
                        <h6 class="subtext">{{translate('free_Delivery')}}</h6>
                    </div>
                </div>
                <div class="left-content-card">
                    <img src="{{dynamicAsset(path: 'public/assets/back-end/img/coupon-discount.svg')}}" alt="">
                    <div class="info">
                        <h4 class="subtitle">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $coupon_discount), currencyCode: getCurrencyCode()) }}</h4>
                        <h6 class="subtext">
                            <span>{{translate('coupon_Discount')}}</span>
                            <span class="ms-2" data-toggle="tooltip" data-placement="top"
                                  title="{{translate('discount_on_purchase_and_first_order_coupon_amount_will_be_shown_here')}}">
                                <img class="info-img" src="{{dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg')}}"
                                     alt="img">
                            </span>
                        </h6>
                    </div>
                </div>
            </div>
            @foreach($expense_transaction_chart['discount_amount'] as $amount)
                @php($chartVal[] = usdToDefaultCurrency(amount: $amount))
            @endforeach
            <div class="center-chart-area">
                @include('layouts.vendor.partials._apexcharts',['title'=>'expense_Statistics','statisticsValue'=>$chartVal,'label'=> $chartDataExpenseTransactionLabel,'statisticsTitle'=>'total_expense_amount'])
            </div>
        </div>

        <x-k.data-view :title="translate('total_Transactions')" :count="$expense_transactions_table->total()"
                       searchName="search" :searchValue="$search"
                       :searchPlaceholder="translate('search_by_Order_ID_or_Transaction_ID')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.transaction.expense-transaction-summary-pdf', ['search'=>request('search'),'date_type'=>request('date_type'), 'from'=>request('from'), 'to'=>request('to')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('download_PDF') }}
                </a>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('vendor.transaction.expense-transaction-export-excel', ['date_type'=>request('date_type'), 'from'=>request('from'), 'to'=>request('to'), 'search'=>request('search')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{translate('SL')}}</th>
                    <th>{{translate('XID')}}</th>
                    <th>{{translate('transaction_Date')}}</th>
                    <th>{{translate('order_ID')}}</th>
                    <th class="k-table__num">{{translate('expense_Amount')}}</th>
                    <th>{{translate('expense_Type')}}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($expense_transactions_table as $key=>$transaction)
                    <tr>
                        <td><span class="k-num">{{ $expense_transactions_table->firstItem()+$key }}</span></td>
                        <td><span class="k-num">{{ $transaction->orderTransaction->transaction_id }}</span></td>
                        <td><span class="k-num">{{ date_format($transaction->updated_at, 'd F Y, h:i:s a') }}</span></td>
                        <td>
                            <a class="title-color"
                               href="{{route('vendor.orders.details',['id'=>$transaction->id])}}">
                                {{$transaction->id}}
                            </a>
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction->coupon_discount_bearer == 'seller'?$transaction->discount_amount:0) + ($transaction->free_delivery_bearer=='seller'?$transaction->extra_discount:0)), currencyCode: getCurrencyCode()) }}</span></td>
                        <td>
                            {{ $transaction->coupon_discount_bearer == 'seller'?(isset($transaction->coupon->coupon_type) ? ($transaction->coupon->coupon_type == 'free_delivery' ? 'Free Delivery Promotion':ucwords(str_replace('_', ' ', $transaction->coupon->coupon_type))) : ''):'' }}
                            <br>
                            {{ $transaction->free_delivery_bearer == 'seller' ? ucwords(str_replace('_', ' ', $transaction->extra_discount_type)):'' }}
                        </td>
                        <td>
                            <div class="k-table__actions">
                                <a href="{{ route('vendor.transaction.pdf-order-wise-expense-transaction', ['id'=>$transaction->id]) }}"
                                   class="k-btn k-btn--ghost k-btn--sm k-btn--icon" title="{{ translate('download_PDF') }}">
                                    <x-k.icon name="download" :size="15" />
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(count($expense_transactions_table)==0)
                <x-k.empty icon="reports" :title="translate('no_data_found')" />
            @endif

            @if ($expense_transactions_table->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $expense_transactions_table->firstItem() }}–{{ $expense_transactions_table->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $expense_transactions_table->total() }}</span>
                    </span>
                    <div>{!! $expense_transactions_table->appends(request()->except('page'))->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>

    </div>
@endsection

@push('script')
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/apexcharts.js')}}"></script>
    <script src="{{dynamicAsset(path: 'public/assets/back-end/js/apexcharts-data-show.js')}}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/expense-report.js') }}"></script>
@endpush
