@extends("auction.web-views.user-profile._profile-master")

@section('title', translate('Auction Sales Report'))

@section('profile_content')
    <div class="">
        <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-20">{{ translate('Auction Sales Report') }}</h5>

        <div class="card bs-border">
            <div class="card-body">
                <h3 class="fs-14 fw-semibold title-clr mb-15">{{ translate('Sales Overview') }}</h3>
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon1.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-info text-capitalize mb-1">{{ number_format($total_auctions_created) }}</h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Total Auctions Created') }}
                                            <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Total_number_of_auctions_you_have_created_within_the_selected_date_range') }}">
                                                <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                            </span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon2.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-success text-capitalize mb-1">{{ number_format($total_auctions_sold) }}</h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Total Auctions Sold') }}
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon2.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-success-dark text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_product_sales_value), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Total Product Sales Value') }}
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon4.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-danger-dark text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_vat_tax), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Total VAT/Tax Collected') }}
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon5.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-warning text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_shipping_fee), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Shipping Fee Collected') }}
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon3.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-success text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_commission_given), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Commission Given') }}
                                            <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Total_admin_commission_already_paid_for_your_sold_auctions') }}">
                                                <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                            </span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon5.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-success-dark text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_already_withdraw), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Already Withdraw') }}
                                            <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Total_amount_already_withdrawn_for_your_sold_auctions') }}">
                                                <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                            </span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon5.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-warning text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_withdraw_pending), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Withdraw Pending') }}
                                            <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Total_amount_awaiting_admin_approval_for_withdrawal') }}">
                                                <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                            </span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-12 col-lg-4">
                        <div class="rounded-10 card bs-border shadow-sm p-20 h-100">
                            <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-start">
                                <div class="d-flex align-items-center gap-xxl-20px gap-2">
                                    <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon6.png') }}" alt="">
                                    <div>
                                        <h3 class="fs-24 fw-semibold text-success-dark text-capitalize mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency($total_earning), currencyCode: getCurrencyCode()) }}
                                        </h3>
                                        <h5 class="fs-14 fw-medium pragraph-clr2 d-flex align-items-center gap-1 text-capitalize mb-0">
                                            {{ translate('Total Earning') }}
                                            <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ translate('Net_earning_after_admin_commission_Gross_Sales_minus_total_commission') }}">
                                                <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                            </span>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <form action="{{ route('auction.sales-report') }}" method="GET" id="sales-report-filter" class="mt-4">
                    <div class="d-flex gap-3 align-items-center justify-content-between flex-wrap">
                        <h5 class="mb-0">{{ translate('Sales Trend') }}</h5>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <select class="form-select min-h-45 form-select-sm" name="date_type" id="date_type" style="width:auto">
                                <option value="" {{ !$date_type ? 'selected' : '' }}>{{ translate('All Time') }}</option>
                                <option value="this_year" {{ $date_type === 'this_year' ? 'selected' : '' }}>{{ translate('This Year') }}</option>
                                <option value="this_month" {{ $date_type === 'this_month' ? 'selected' : '' }}>{{ translate('This Month') }}</option>
                                <option value="this_week" {{ $date_type === 'this_week' ? 'selected' : '' }}>{{ translate('This Week') }}</option>
                                <option value="today" {{ $date_type === 'today' ? 'selected' : '' }}>{{ translate('Today') }}</option>
                                <option value="custom_date" {{ $date_type === 'custom_date' ? 'selected' : '' }}>{{ translate('Custom Date') }}</option>
                            </select>
                            <div class="custom-date-field gap-2 align-items-center {{ $date_type !== 'custom_date' ? 'd-none' : 'd-flex' }}">
                                <input type="date" name="from" id="from_date" class="form-control form-control-s " value="{{ $from }}">
                                <input type="date" name="to" id="to_date" class="form-control form-control-sm " value="{{ $to }}">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm text-nowrap">{{ translate('Filter') }}</button>
                        </div>
                    </div>
                </form>

                <div class="chart-area mt-4" id="sales-trend-chart"></div>
            </div>
        </div>
    </div>
@endsection

@push('script')
<script>
    "use strict";
    var dateTypeEl = document.getElementById('date_type');
    var fromDateEl = document.getElementById('from_date');
    var toDateEl   = document.getElementById('to_date');

    function toggleCustomDate(isCustom) {
        document.querySelectorAll('.custom-date-field').forEach(function (el) {
            el.classList.toggle('d-none', !isCustom);
            el.classList.toggle('d-flex', isCustom);
        });
        fromDateEl.required = isCustom;
        toDateEl.required   = isCustom;
    }

    dateTypeEl.addEventListener('change', function () {
        toggleCustomDate(this.value === 'custom_date');
    });

    toggleCustomDate(dateTypeEl.value === 'custom_date');

    fromDateEl.addEventListener('change', function () { toDateEl.min = this.value; });
    toDateEl.addEventListener('change', function () { fromDateEl.max = this.value; });

    if (fromDateEl.value) toDateEl.min = fromDateEl.value;
    if (toDateEl.value)   fromDateEl.max = toDateEl.value;

    var salesTrendEl = document.querySelector("#sales-trend-chart");
    var salesTrendData = @json($sales_trend['data']);
    if (salesTrendEl && salesTrendData.length > 0) {
        var salesTrendChart = new ApexCharts(salesTrendEl, {
            series: [{
                name: '{{ translate('Sales') }}',
                data: salesTrendData
            }],
            chart: {
                height: 350,
                type: 'area',
                toolbar: { show: false }
            },
            stroke: { width: 2, curve: 'smooth' },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 }
            },
            colors: ['#1455AC'],
            xaxis: {
                categories: @json($sales_trend['labels']),
                labels: { rotate: -30 }
            },
            yaxis: {
                labels: {
                    formatter: function (val) {
                        return val >= 1000 ? '$' + (val / 1000).toFixed(0) + 'k' : '$' + val;
                    }
                }
            },
            tooltip: {
                y: { formatter: function (val) { return '$' + Number(val).toLocaleString(); } }
            }
        });
        salesTrendChart.render();
    } else if (salesTrendEl) {
        salesTrendEl.innerHTML = '<p class="text-center pragraph-clr2 py-5">{{ translate('No_sales_data_available') }}</p>';
    }
</script>
@endpush
