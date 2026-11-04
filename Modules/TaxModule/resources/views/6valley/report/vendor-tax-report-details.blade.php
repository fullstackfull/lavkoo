@extends('layouts.admin.app')

@section('title', translate('Vendor_Vat_Report'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-20">
            <div class="flex-grow-1">
                <h2 class="fs-20 mb-1">
                    {{ translate('VAT_Details') }} - {{ $shop['name'] }}
                </h2>
                <p class="mb-0">
                    {{ translate('Date:') }} {{ $startDate?->format('d M, Y') }} - {{ $endDate?->format('d M, Y') }}
                </p>
            </div>
            <a class="btn btn-primary min-w-120" href="{{ route('admin.report.vendor-wise-taxes') }}">
                <i class="fi fi-rr-arrow-small-left"></i>
                {{ translate('Back_to_List') }}
            </a>
        </div>
        <div class="card card-body mb-20">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <a class="w-100 h-100 bg-info-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10"
                           href="#">
                            <div class="d-flex gap-2 align-items-center">
                                <img width="30" class="aspect-1" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-order-amount.png') }}" alt="">
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_Order_Amount') }}">{{ translate('Total_Order_Amount') }}</h4>
                            </div>
                            <span class="overflow-wrap-anywhere fw-bold text-info h2 mb-0">
                                 {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalOrderAmount), currencyCode: getCurrencyCode()) }}
                            </span>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <a class="w-100 h-100 bg-warning-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10"
                           href="#">
                            <div class="d-flex gap-2 align-items-center">
                                <img width="30" class="aspect-1" src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-vat-amount.png') }}" alt="">
                                <h4 class="fw-medium line-1 mb-0" title="{{ translate('Total_VAT_Amount') }}">{{ translate('Total_VAT_Amount') }}</h4>
                            </div>
                            <span class="overflow-wrap-anywhere fw-bold text-warning h2 mb-0">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalTax), currencyCode: getCurrencyCode()) }}
                            </span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('all_Vat_List') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">
                            {{ $orderTransactions->total() }}
                        </span>
                    </h3>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                        <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                            <form action="{{ url()->full() }}" method="GET">
                                <input type="hidden" name="shop_id" value="{{ $shop['id'] }}">
                                <div class="input-group">
                                    <input id="" type="search" name="search" class="form-control"
                                           placeholder="{{ translate('search_here') }}"
                                           value="{{ request('search') }}">
                                    <div class="input-group-append search-submit">
                                        <button type="submit">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        @if($orderTransactions->total() > 0)
                            <div class="dropdown">
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown">
                                    <i class="fi fi-sr-inbox-in"></i>
                                    <span class="fs-12">{{ translate('Export') }}</span>
                                    <i class="fi fi-rr-angle-small-down"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.report.vendorTaxExport', ['export_type' => 'excel', 'shop_id' => $shop->id, request()->getQueryString()]) }}">
                                            <span class="text-success pt-1"><i class="fi fi-sr-file-excel"></i></span>
                                            {{ translate('Excel') }}
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.report.vendorTaxExport', ['export_type' => 'excel', 'shop_id' => $shop->id, request()->getQueryString()]) }}">
                                            <span class="text-info pt-1"><i class="fi fi-sr-file-csv"></i></span>
                                            {{ translate('CSV') }}
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle text-dark">
                        <thead class="text-capitalize">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('Order_ID') }}</th>
                            <th>{{ translate('Order_Date') }}</th>
                            <th>{{ translate('Order_Amount') }}</th>
                            <th>{{ translate('VAT_Type') }}</th>
                            <th>{{ translate('VAT_Amount') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($orderTransactions as $key => $orderTransaction)
                            <tr>
                                <td>{{ $orderTransactions->firstItem() + $key }}</td>
                                <td>
                                    <a class="text-dark" target="_blank"
                                       href="{{ route('admin.orders.details', ['id' => $orderTransaction['order_id']]) }}">
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
                                    <table>
                                        <tbody>
                                        <tr>
                                            <td>
                                                <span class="fs-12 fw-semibold">{{ translate('Total') }}</span>
                                            </td>
                                            <td>
                                                <span class="px-2">:</span>
                                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTransaction?->orderTaxes?->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                            </td>
                                        </tr>

                                        @foreach($orderTransaction?->orderTaxes->groupBy('tax_on')->sortKeys() as $orderTaxItemKey => $orderTaxItem)
                                            <tr>
                                                <td colspan="2">
                                                    @if($orderTaxItemKey == 'basic')
                                                        <span class="fs-12 fw-semibold">{{ ucwords('Order Tax') }}</span>
                                                    @else
                                                        <span class="fs-12 fw-semibold">{{ ucwords(str_replace('_', ' ', $orderTaxItemKey)) }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                            @foreach($orderTaxItem->groupBy('tax_name') as $taxItemKey => $orderTaxItem)
                                                <tr class="fs-12 text-body">
                                                    <td class="max-w-150px pt-2 text-wrap">
                                                        {{ ucwords($taxItemKey) }}
                                                    </td>
                                                    <td class="pt-2">
                                                        <span class="px-2">:</span>
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $orderTaxItem->sum('tax_amount')), currencyCode: getCurrencyCode()) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
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
@endsection

@push('script')

@endpush
