@extends('layouts.admin.app')

@section('title', translate('Admin_Tax_Report'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-20">
            <h2 class="fs-20 mb-0">
                {{ translate('Admin_Tax_Report') }}
            </h2>
        </div>
        <form action="{{ route('admin.report.get-tax-report') }}" method="get" class="generate-tax-report-form">
            @csrf
            <div class="card card-body d-flex flex-column gap-20 mb-20">
                <div>
                    <h3 class="fs-18 mb-1">{{ translate('Generate_Tax_Report') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('to_generate_you_tax_report_please_select_&_input_following_field_and_submit_for_the_result.') }}
                    </p>
                </div>
                <div class="bg-section rounded p-12 p-sm-20">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div class="">
                                <label class="form-label" for="date_range_type">
                                    {{ translate('Date_Range_Type') }}
                                </label>
                                <select class="custom-select form-control" name="date_range_type" id="date_range_type">
                                    <option disabled  value="">{{ translate('Select_Date_Range') }}</option>
                                    <option value="this_fiscal_year"
                                        {{ $date_range_type == 'this_fiscal_year' ? 'selected' : '' }}>
                                        {{ translate('This_Fiscal_Year') }}
                                    </option>
                                    <option value="custom" {{ $date_range_type == 'custom' ? 'selected' : '' }}>
                                        {{ translate('Custom') }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 {{ $date_range_type == 'custom' ? '' : 'd-none' }}" id="date_range">
                            <div class="">
                                <label class="form-label" for="date_range_inputs">
                                    {{ translate('Date_Range') }}
                                </label>
                                <div class="position-relative">
                                    <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                    @if(!empty(request('dates')))
                                        <input type="text" class="js-daterangepicker_till_current form-control line-1" name="dates"
                                               value="{{ $startDate?->format('m/d/Y') }} - {{ $endDate?->format('m/d/Y') }}">
                                    @else
                                        <input type="text" class="js-daterangepicker_till_current form-control line-1" name="dates">
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="">
                                <label class="form-label" for="calculate_tax_on">
                                    {{ translate('select_how_to_calculate_tax') }}
                                </label>
                                <select class="custom-select form-control" id="calculate_tax_on"
                                        name="calculate_tax_on">
                                    <option disabled  value="">{{ translate('Select_Calculate_Tax') }}</option>
                                    <option {{ $calculate_tax_on == 'all_source' ? 'selected' : '' }}
                                            value="all_source" data-target=".same_tax_for_all_content">
                                        {{ translate('Same_Tax_for_All_Income_Source') }}
                                    </option>
                                    <option {{ $calculate_tax_on == 'individual_source' ? 'selected' : '' }}
                                            value="individual_source" data-target=".diff_tax_for_diff_content">
                                        {{ translate('Different_Tax_for_Different_Income_Source') }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="col-lg-6 same_tax_for_all_content {{ $calculate_tax_on == 'individual_source' ? 'd-none' : '' }}">
                            <div class="">
                                <label class="form-label" for="">{{ translate('Select_Tax_Rate') }}</label>
                                <select class="custom-select multiple-select2 form-control" id="tax_rate"
                                        name="tax_rate[]" multiple="multiple"
                                        data-placeholder="{{ translate('type_&_Select_Vat/Tax_Rate') }}">
                                    <option value="all">{{ translate('All') }}</option>
                                    @foreach($selectedTax['tax_rate'] as $taxVat)
                                        <option value="{{ $taxVat->id }}" {{ in_array($taxVat->id, request('tax_rate', [])) ? 'selected' : '' }}>
                                            {{ $taxVat->name }}
                                            ({{ $taxVat->tax_rate }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div
                            class="col-lg-6 diff_tax_for_diff_content {{ $calculate_tax_on == 'individual_source' ? '' : 'd-none' }}"
                            id="calculate_commission_tax">
                            <div class="">
                                <label class="form-label" for="tax_on_order_commission">
                                    {{ translate('Tax_On_Order_Commission') }}
                                </label>
                                <select class="custom-select multiple-select2 form-control" id="tax_on_order_commission"
                                        name="tax_on_order_commission[]" multiple="multiple"
                                        data-placeholder="{{ translate('type_&_Select_Vat/Tax_Rate') }}">
                                    @foreach($selectedTax['tax_rate'] as $taxVat)
                                        <option value="{{ $taxVat->id }}" {{ in_array($taxVat->id, request('tax_on_order_commission', [])) ? 'selected' : '' }}>
                                            {{ $taxVat->name }}
                                            ({{ $taxVat->tax_rate }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6 diff_tax_for_diff_content">
                            <div class="">
                                <label class="form-label" for="">{{ translate('tax_On_Delivery_Charge') }}</label>
                                <select class="custom-select multiple-select2 form-control" id="tax_on_delivery_charge_commission"
                                        name="tax_on_delivery_charge_commission[]" multiple="multiple"
                                        data-placeholder="{{ translate('type_&_Select_Vat/Tax_Rate') }}">
                                    @foreach($selectedTax['tax_rate'] as $taxVat)
                                        <option value="{{ $taxVat->id }}" {{ in_array($taxVat->id, request('tax_on_delivery_charge_commission', [])) ? 'selected' : '' }}>
                                            {{ $taxVat->name }}
                                            ({{ $taxVat->tax_rate }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-end gap-3">
                    <button type="reset" class="btn btn-secondary min-w-120">{{ translate('Reset') }}</button>
                    <button type="submit" class="btn btn-primary min-w-120 tax_report_action">
                        {{ translate('Generate') }}
                    </button>
                </div>
            </div>
        </form>
        <div class="card card-body mb-20">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <div class="w-100 h-100 bg-info-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10 flex-sm-nowrap flex-wrap">
                            <div class="d-flex gap-3 align-items-center">
                                <img width="30" class="aspect-1"
                                     src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-order-amount.png') }}"
                                     alt="">
                                <div class="flex-grow-1 d-flex gap-2 align-items-center flex-wrap">
                                    <span class="line-1 text-body mb-0"
                                          title="{{ translate('Total_Income') }}">{{ translate('Total_Income') }}</span>
                                    <span class="overflow-wrap-anywhere fw-bold text-info h2 mb-0">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalBase), currencyCode: getCurrencyCode()) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex align-items-center h-100">
                        <div class="w-100 h-100 bg-warning-dark bg-opacity-10 d-flex align-items-center gap-2 justify-content-between p-3 rounded-10 flex-sm-nowrap flex-wrap">
                            <div class="d-flex gap-3 align-items-center">
                                <img width="30" class="aspect-1"
                                     src="{{ dynamicAsset(path: 'public/assets/back-end/img/total-vat-amount.png') }}"
                                     alt="">
                                <div class="flex-grow-1 d-flex gap-2 align-items-center flex-wrap">
                                    <span class="line-1 text-body mb-0"
                                          title="{{ translate('Total_Tax') }}">{{ translate('Total_Tax') }}</span>
                                    <span class="overflow-wrap-anywhere fw-bold text-warning-dark h2 mb-0">
                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalTax), currencyCode: getCurrencyCode()) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                    <h3 class="mb-0">
                        {{ translate('Tax_Report_List') }}
                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50 ms-1">
                            {{ count($reportList) }}
                        </span>
                    </h3>
                    @if(count($reportList) > 0)
                        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-sm-end flex-grow-1">
                            <div class="dropdown">
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown">
                                    <i class="fi fi-sr-inbox-in"></i>
                                    <span class="fs-12">{{ translate('Export') }}</span>
                                    <i class="fi fi-rr-angle-small-down"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-right">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.report.adminTaxReportExport',['export_type' => 'excel', request()->getQueryString()]) }}">
                                            <span class="text-success pt-1"><i class="fi fi-sr-file-excel"></i></span>
                                            {{ translate('Excel') }}
                                        </a>
                                    </li>

                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2"
                                           href="{{ route('admin.report.adminTaxReportExport',['export_type' => 'csv', request()->getQueryString()]) }}">
                                            <span class="text-info pt-1"><i class="fi fi-sr-file-csv"></i></span>
                                            {{ translate('CSV') }}
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle text-dark">
                        <thead class="text-capitalize">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('Income_Source') }}</th>
                            <th>{{ translate('Total_Income') }}</th>
                            <th>{{ translate('Tax_Amount') }}</th>
                            <th class="text-center">{{ translate('Action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @php
                            $count = 1;
                        @endphp
                        @forelse ($reportList as $key => $reportItem)
                            <tr>
                                <td>{{ $count++ }}</td>
                                <td>
                                    <div class="line-1 max-w-200">
                                        {{ ucwords(translate($reportItem['type'])) }}
                                    </div>
                                </td>
                                <td>
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $reportItem['amount']), currencyCode: getCurrencyCode()) }}
                                </td>
                                <td>
                                    <table>
                                        <tbody>
                                            <tr>
                                                <td>{{ translate('Total') }} ({{ $reportItem['total_tax_percentage'] }}%)</td>
                                                <td>
                                                    <span class="px-2">:</span>
                                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $reportItem['total_tax_amount']), currencyCode: getCurrencyCode()) }}</td>
                                            </tr>
                                             @foreach ($reportItem['taxes'] as $taxName => $taxItems)
                                                <tr class="fs-12 text-body">
                                                    <td class="pt-2"> {{ ucwords($taxItems['name']) }} ({{ $taxItems['tax_rate'] }}%)</td>
                                                    <td class="pt-2">
                                                        <span class="px-2">:</span>
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $taxItems['applicable_amount']), currencyCode: getCurrencyCode()) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-3">
                                        <a class="btn btn-outline-info btn-outline-info-dark icon-btn" target="_blank"
                                           href="{{ route('admin.report.getTaxDetails', ['source' => $reportItem['type'], 'totalTaxAmount'=> $reportItem['amount'], request()->getQueryString()]) }}">
                                            <i class="fi fi-sr-eye"></i>
                                        </a>
                                    </div>
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
                                            <h4 class="mb-2">{{ translate('no_tax_report_generated') }}</h4>
                                            <p class="fs-12 text-body fw-medium max-w-500 mb-0">
                                                {{ translate('to_generate_your_tax_report_please_select_&_input_above_field_and_submit_for_the_result.') }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script>
        'use strict';

        $(document).ready(function () {
            function selectCalcTax() {
                $('.same_tax_for_all_content, .diff_tax_for_diff_content').addClass('d-none');

                let targetSelector = $('#calculate_tax_on option:selected').data('target');
                if (targetSelector) {
                    $(targetSelector).removeClass('d-none');
                }

                if ($('#date_range_type').val()?.toString() === 'custom') {
                    $('#date_range').removeClass('d-none');
                } else {
                    $('#date_range').addClass('d-none');
                }

                actionBtnDisable();
            }

            function actionBtnDisable() {
                const $submitBtn = $('.tax_report_action');
                $submitBtn.prop('disabled', true);

                let selectedVal = null;
                if (!$('.same_tax_for_all_content').hasClass('d-none')) {
                    selectedVal = $('#tax_rate').val();
                } else if (!$('.diff_tax_for_diff_content').hasClass('d-none')) {
                    selectedVal = $('#tax_on_order_commission').val();
                }

                if (selectedVal && selectedVal.length > 0) {
                    $submitBtn.prop('disabled', false);
                }
            }

            selectCalcTax();
            actionBtnDisable();

            $('#date_range_type').on('change', selectCalcTax);
            $('#calculate_tax_on').on('change', selectCalcTax);
            $('#tax_rate, #tax_on_order_commission').on('change', actionBtnDisable);
            $('.generate-tax-report-form button[type="reset"]').on('click', () => {
                setTimeout(selectCalcTax, 100);
                setTimeout(actionBtnDisable, 100);
            });
        });

    </script>
@endpush
