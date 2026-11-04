@extends('layouts.vendor.app')

@section('title', translate('Vendor_Tax_Report'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-20">
            <h2 class="fs-20 mb-0">
                {{ translate('VAT_Report') }}
            </h2>
        </div>
        <div class="card card-body mb-20">
            <form action="{{ route('vendor.report.get-vat-report') }}" method="get">
                <div class="row g-2 align-items-end justify-content-between">
                    <div class="col-lg-4">
                        <div class="">
                            <label class="form-label title-color" for="">{{ translate('Date_Range') }}</label>
                            <div class="position-relative">
                                <span class="fi fi-sr-calendar icon-absolute-on-right fz-14 lh-1"></span>
                                @if(isset($startDate) && isset($endDate))
                                    <input type="text" class="js-daterangepicker_till_current ltr rtl-text-end form-control"
                                           placeholder="{{ translate('select_date_range') }}" name="dates"
                                           value="{{ $startDate?->format('m/d/Y') }} - {{ $endDate?->format('m/d/Y') }}">
                                @else
                                    <input type="text" class="js-daterangepicker_till_current ltr rtl-text-end form-control"
                                           placeholder="{{ translate('select_date_range') }}" name="dates"
                                           value="">
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn--primary min-w-120">
                                {{ translate('Filter') }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="card card-body mb-20">
            <div class="row g-2">
                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="w-100 h-100 bg-warning-dark bg-opacity-10 p-3 rounded-10">
                            <div>
                                <img width="30" class="aspect-1 mb-20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-order.png') }}" alt="">
                                <h2 class="overflow-wrap-anywhere text-warning-dark fz-22 mb-1">
                                    {{ $totalOrders }}
                                </h2>
                                <p class="text-body line-1 mb-0" title="{{ translate('Total_Orders') }}">{{ translate('Total_Orders') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="w-100 h-100 bg-info-dark bg-opacity-10 p-3 rounded-10">
                            <div>
                                <img width="30" class="aspect-1 mb-20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-order-amount.png') }}" alt="">
                                <h2 class="overflow-wrap-anywhere text-info-dark fz-22 mb-1">
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalOrderAmount), currencyCode: getCurrencyCode()) }}
                                </h2>
                                <p class="text-body line-1 mb-0" title="{{ translate('Total_Order_Amount') }}">
                                    {{ translate('Total_Order_Amount') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="d-flex align-items-center h-100">
                        <div class="w-100 h-100 bg-success-dark bg-opacity-10 p-3 rounded-10">
                            <div class="row g-2 align-items-center h-100">
                                <div class="col-lg-6">
                                    <div>
                                        <img width="30" class="aspect-1 mb-20" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-vat-amount.png') }}" alt="">
                                        <h2 class="overflow-wrap-anywhere text-success-dark fz-22 mb-1">
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalTax), currencyCode: getCurrencyCode()) }}
                                        </h2>
                                        <p class="text-body line-1 mb-0" title="{{ translate('Total_VAT_Amount') }}">
                                            {{ translate('Total_VAT_Amount') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="overflow-y-auto max-h-100px scrollbar-width-none">
                                        <div class="d-flex flex-column gap-7px">
                                            @foreach($typeWiseTaxesList as $typeWiseTaxKey => $typeWiseTaxes)
                                                <span class="fs-12 fw-semibold mt-1">{{ $typeWiseTaxKey }}</span>
                                                @foreach($typeWiseTaxes as $typeWiseTax)
                                                    <div class="d-flex gap-2 justify-content-between align-items-center bg-white rounded px-2 py-1 fs-12 text-dark">
                                                        <span class="fw-medium text-body flex-grow-1">
                                                            {{ ucwords($typeWiseTax['name']) }} ({{ $typeWiseTax['tax_rate'] }}%)
                                                        </span>
                                                        <span class="fw-semibold overflow-wrap-anywhere">
                                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $typeWiseTax['total_amount']), currencyCode: getCurrencyCode()) }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between justify-content-sm-end align-items-center gap-3 flex-wrap">
                    <h4 class="flex-grow-1 d-flex gap-1 mb-0">
                        {{ translate('All_VAT') }}
                        <span class="badge badge-soft-dark radius-50 fs-12">
                            {{ $orderTransactions->total() }}
                        </span>
                    </h4>
                    <div class="d-flex flex-wrap gap-3 align-items-stretch justify-content-sm-end flex-grow-1">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="input-group input-group-merge input-group-custom">
                                <div class="input-group-prepend">
                                    <div class="input-group-text">
                                        <i class="tio-search"></i>
                                    </div>
                                </div>
                                <input id="datatableSearch_" type="search" name="search" class="form-control"
                                       placeholder="{{translate('search_here')}}" aria-label="{{translate('search_here')}}"
                                       value="{{ request('search') }}">
                                <button type="submit" class="btn btn--primary">{{ translate('search') }}</button>
                            </div>
                        </form>
                        @if($orderTransactions->total() > 0)
                        <div class="dropdown">
                            <button class="btn btn-outline--primary h-100"
                                    type="button"
                                    id="exportDropdown"
                                    data-toggle="dropdown"
                                    aria-haspopup="true"
                                    aria-expanded="false">
                                <i class="fi fi-sr-inbox-in lh-1"></i>
                                <span class="fs-12">{{ translate('Export') }}</span>
                                <i class="fi fi-rr-angle-small-down lh-1"></i>
                            </button>

                            <div class="dropdown-menu dropdown-menu-right rounded mt-1 py-2 w-100 min-w-200" aria-labelledby="exportDropdown">
                                <a class="dropdown-item d-flex align-items-center gap-2 px-3 py-2"
                                href="{{ route('vendor.report.get-vat-report-export', ['export_type' => 'excel', 'shop_id' => $shop->id, request()->getQueryString()]) }}">
                                    <span class="text-success pt-1"><i class="fi fi-sr-file-excel"></i></span>
                                    {{ translate('Excel') }}
                                </a>

                                <a class="dropdown-item d-flex align-items-center gap-2 px-3 py-2"
                                href="{{ route('vendor.report.get-vat-report-export', ['export_type' => 'csv', 'shop_id' => $shop->id, request()->getQueryString()]) }}">
                                    <span class="text-info pt-1"><i class="fi fi-sr-file-csv"></i></span>
                                    {{ translate('CSV') }}
                                </a>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table text-dark">
                        <thead class="thead-light thead-50 text-capitalize">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('Order_ID') }}</th>
                            <th>{{ translate('Order_Date') }}</th>
                            <th>{{ translate('Order_Amount') }}</th>
                            <th>{{ translate('VAT_Type') }}</th>
                            <th>{{ translate('VAT_Amount') }}</th>
                            <th class="text-center">{{ translate('Action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($orderTransactions as $key => $orderTransaction)
                            <tr>
                                <td>{{ $orderTransactions->firstItem() + $key }}</td>
                                <td>
                                    <a class="text-dark" target="_blank"
                                       href="{{ route('vendor.orders.details', ['id' => $orderTransaction['order_id']]) }}">
                                        {{ '#'.$orderTransaction['order_id'] }}
                                    </a>
                                </td>
                                <td>
                                    {{ $orderTransaction?->order?->created_at?->format('d M, Y') ?? "N/a" }}
                                </td>
                                <td>
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction['order_amount']), currencyCode: getCurrencyCode()) }}
                                </td>
                                <td>
                                    @if($orderTransaction?->order?->tax_model == 'include')
                                        {{ translate('Tax_Included') }}
                                    @else
                                        {{ translate($orderTransaction?->orderTaxes?->first()?->tax_type ?? 'Order_Wise') }}
                                    @endif
                                </td>
                                <td>
                                    <div>
                                        <div class="d-flex gap-2">
                                            <span class="min-w-40"> {{ translate('Total') }}:</span>
                                            @if(count($orderTransaction?->orderTaxes) > 0)
                                                <span>
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->orderTaxes?->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                                </span>
                                            @else
                                                <span>
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->order?->total_tax_amount), currencyCode: getCurrencyCode()) }}
                                                </span>
                                            @endif
                                        </div>
                                        @foreach($orderTransaction?->orderTaxes->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem)
                                            @if($orderTaxItemKey == 'basic')
                                                <span class="fs-12 fw-semibold">{{ ucwords('Order Tax') }}:</span>
                                            @else
                                                <span class="fs-12 fw-semibold">{{ ucwords(str_replace('_', ' ', $orderTaxItemKey)) }}:</span>
                                            @endif
                                            @foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTax)
                                                <div class="d-flex gap-2 fs-12 text-body">
                                                    <span class="min-w-40"> {{ ucwords($taxItemKey) }}:</span>
                                                    <span>
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTax->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-3">
                                        <button type="button" class="btn btn-outline--primary btn-sm square-btn" data-toggle="offcanvas" data-target="#vatDetailsOffcanvas-{{ $orderTransaction['id'] }}">
                                            <i class="fi fi-sr-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="d-flex justify-content-center align-items-center py-5">
                                        <div class="text-center">
                                            <img width="60" class="aspect-1 mb-30"
                                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/tax.png') }}"
                                                 alt="">
                                            <h4 class="mb-2">{{ translate('No_Data_Found') }}</h4>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive mt-4">
                    <div class="d-flex justify-content-lg-end">
                        {!! $orderTransactions->links() !!}
                    </div>
                </div>
            </div>
        </div>
    </div>


    @foreach($orderTransactions as $key => $orderTransaction)
        <div class="offcanvas-sidebar" id="vatDetailsOffcanvas-{{ $orderTransaction['id'] }}">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="text-capitalize m-0">{{ translate('Details') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <div class="bg-light p-3 rounded d-flex flex-column gap-20">
                    <h4 class="mb-0 d-flex gap-2 align-items-center">
                        <span>
                            {{ translate('Order_ID') }} #{{ $orderTransaction['order_id'] }}
                        </span>
                        <span class="badge badge-soft-primary fs-12 px-10px py-1">
                            {{ translate($orderTransaction?->order?->order_status) }}
                        </span>
                    </h4>
                    <div>
                        <div class="mb-1">
                            {{ translate('Date') }}: {{ $orderTransaction->order->created_at->format('d M, Y h:m A') }}
                        </div>
                        <div class="mb-0 d-flex gap-2 align-items-center">
                            <span>{{ translate('Payment_Status') }} :</span>
                            <span class="badge badge-soft-success fs-12 px-10px py-1">
                                {{ translate($orderTransaction?->order?->payment_status) }}
                            </span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 justify-content-between align-items-center bg-white rounded p-3 fs-12">
                        <span class="">{{ translate('Order_Amount') }}</span>
                        <span class="fw-semibold text-dark overflow-wrap-anywhere">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction['order_amount']), currencyCode: getCurrencyCode()) }}
                        </span>
                    </div>
                    <div class="d-flex gap-2 flex-column bg-white rounded p-3">
                        @foreach($orderTransaction?->orderTaxes->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem)
                            @if($orderTaxItemKey == 'basic')
                                <span class="fs-12 fw-semibold">{{ ucwords('Order Tax') }}:</span>
                            @else
                                <span class="fs-12 fw-semibold">{{ ucwords(str_replace('_', ' ', $orderTaxItemKey)) }}:</span>
                            @endif
                            @foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTax)
                                <div class="d-flex gap-2 justify-content-between align-items-center fs-12">
                                    <span class="">{{ ucwords($taxItemKey) }}</span>
                                    <span class="fw-semibold text-dark overflow-wrap-anywhere">
                                         {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTax->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                    </span>
                                </div>
                            @endforeach
                            <div class="d-block mb-1"></div>
                        @endforeach

                        <div class="d-flex gap-2 justify-content-between align-items-center fz-14 border-top pt-2">
                            <span class="">{{ translate('Total_VAT_Amount') }}</span>
                            @if(count($orderTransaction?->orderTaxes) > 0)
                                <span class="fw-semibold text-dark overflow-wrap-anywhere">
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->orderTaxes?->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                </span>
                            @else
                                <span class="fw-semibold text-dark overflow-wrap-anywhere">
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->order?->total_tax_amount), currencyCode: getCurrencyCode()) }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
@endsection

@push('script')
    <script>
        $(document).ready(function() {

            function initDateRangePicker(selctor) {
                let previousValue = $(selctor).val();

                $(selctor).daterangepicker(
                    {
                        autoUpdateInput: false,
                        locale: {
                            format: "DD MMM YYYY"
                        }
                    },
                );
                $(selctor).on("show.daterangepicker", function() {
                    previousValue = $(this).val();
                });
                $(selctor).on("apply.daterangepicker", function(ev, picker) {
                    $(this).val(
                        picker.startDate.format("DD MMM, YYYY") + " - " + picker.endDate.format("DD MMM, YYYY")
                    );
                });
                $(selctor).on("cancel.daterangepicker", function(ev, picker) {
                    $(this).val(previousValue);
                });

            }

            initDateRangePicker(".js-daterangepicker-with-month");

        });
    </script>
@endpush
