@extends('layouts.admin.app')

@section('title', translate('Admin_Tax_Report'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-20">
            <div class="flex-grow-1">
                <h2 class="fs-20 mb-1">
                    {{ translate('Tax_Report') }} - {{ ucwords(translate($taxSource)) }}
                </h2>
                <p class="mb-0">
                    {{ translate('Date:') }} {{ $startDate?->format('d M, Y') }} - {{ $endDate?->format('d M, Y') }}
                </p>
            </div>
        </div>
        <div class="card card-body mb-20">
            <div class="row g-4">
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                       href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-success mb-1">
                                {{ $totalOrderCount }}
                            </h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order') }}">{{ translate('Total_Order') }}</h4>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                       href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-info mb-1">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalOrderAmount), currencyCode: getCurrencyCode()) }}
                            </h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order_Amount') }}">
                                {{ translate('Total_Order_Amount') }}
                            </h4>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                       href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-warning mb-1">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalAmount), currencyCode: getCurrencyCode()) }}
                            </h2>
                            @if($taxSource == 'admin_commission')
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Commission') }}">
                                    {{ translate('Total_Commission') }}
                                </h4>
                            @else
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('delivery_charge') }}">
                                    {{ translate('delivery_charge') }}
                                </h4>
                            @endif
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <a class="bg-body p-3 rounded d-flex align-items-center h-100"
                       href="#">
                        <div>
                            <h2 class="overflow-wrap-anywhere fw-bold text-warning-dark mb-1">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalTaxAmount), currencyCode: getCurrencyCode()) }}
                            </h2>
                            <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Tax_Amount') }}">
                                {{ translate('Total_Tax_Amount') }}
                            </h4>
                        </div>
                    </a>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('Order_List') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
                            {{ $transactions->total() }}
                        </span>
                    </h3>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                        <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <form action="{{ route('admin.report.getTaxDetails') }}" method="GET">
                                @foreach(request()->query() as $key => $value)
                                    @if(is_array($value))
                                        @foreach($value as $subValue)
                                            <input type="hidden" name="{{ $key }}[]" value="{{ $subValue }}">
                                        @endforeach
                                    @else
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach

                                <div class="input-group">
                                    <input id="" type="search" name="search" class="form-control"
                                           placeholder="{{ translate('Search_by_Order_Id,_Transaction_Id') }}"
                                           value="{{ request('search') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="dropdown">
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown">
                                <i class="fi fi-sr-inbox-in"></i>
                                <span class="fs-12">{{ translate('Export') }}</span>
                                <i class="fi fi-rr-angle-small-down"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-right">
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                       href="{{ route('admin.report.getTaxDetailsExport', ['source'=> $taxSource ,'export_type' => 'excel', request()->getQueryString()]) }}">
                                        <span class="text-success pt-1"><i class="fi fi-sr-file-excel"></i></span>
                                        {{ translate('Excel') }}
                                    </a>
                                </li>

                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2"
                                       href="{{ route('admin.report.getTaxDetailsExport', ['source'=> $taxSource ,'export_type' => 'csv', request()->getQueryString()]) }}">
                                        <span class="text-info pt-1"><i class="fi fi-sr-file-csv"></i></span>
                                        {{ translate('CSV') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle text-dark">
                        <thead class="text-capitalize">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('Order') }}</th>
                            <th>{{ translate('Transaction') }}</th>
                            @if($taxSource == 'admin_commission')
                                <th>{{ translate('Commission') }}</th>
                            @else
                                <th>{{ translate('Delivery_Charge') }}</th>
                            @endif
                            <th>{{ translate('Tax') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions as $key => $transaction)
                            <tr>
                                <td>
                                    {{ $transactions->firstItem() + $key }}
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['order_amount']), currencyCode: getCurrencyCode()) }}
                                    </div>
                                    <a class="fs-12 text-primary fw-semibold" href="{{ route('admin.orders.details', ['id' => $transaction['order_id']]) }}" target="_blank">
                                        {{ '#'.$transaction['order_id'] }}
                                    </a>
                                </td>
                                <td>
                                    <div>{{ Str::upper($transaction['transaction_id']) }}</div>
                                    <div class="fs-12 text-body">
                                        {{ $transaction['created_at']?->format('d M, Y') }}
                                    </div>
                                </td>
                                <td>
                                    @if($taxSource == 'admin_commission')
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_commission']), currencyCode: getCurrencyCode()) }}
                                    @else
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['delivery_charge']), currencyCode: getCurrencyCode()) }}
                                    @endif
                                </td>
                                <td>
                                    <table>
                                        <tbody>
                                        <tr>
                                            <td>
                                                {{ translate('Total_Tax') }} ({{ $taxRates->sum('tax_rate') }}%)
                                            </td>
                                            <td>
                                                <span class="px-2">:</span>
                                                @if($taxSource == 'admin_commission')
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['admin_commission'] * $taxRates->sum('tax_rate')) / 100), currencyCode: getCurrencyCode()) }}
                                                @else
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['delivery_charge'] * $taxRates->sum('tax_rate')) / 100), currencyCode: getCurrencyCode()) }}
                                                @endif
                                            </td>
                                        </tr>
                                        @foreach($taxRates as $taxRate)
                                            <tr class="fs-12 text-body">
                                                <td class="max-w-150px pt-2 text-wrap">
                                                    {{ $taxRate['name'] }} ({{ $taxRate['tax_rate'] }}%)
                                                </td>
                                                <td class="pt-2">
                                                    <span class="px-2">:</span>
                                                    @if($taxSource == 'admin_commission')
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['admin_commission'] * $taxRate['tax_rate']) / 100), currencyCode: getCurrencyCode()) }}
                                                    @else
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: ($transaction['delivery_charge'] * $taxRate['tax_rate']) / 100), currencyCode: getCurrencyCode()) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
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
                        {!! $transactions->links() !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')

@endpush
