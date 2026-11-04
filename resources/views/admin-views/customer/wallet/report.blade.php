@extends('layouts.admin.app')

@section('title', translate('Customer_Wallet'))

@section('content')
    <div class="content container-fluid">
        <h2 class="h1 mb-20 text-capitalize d-flex align-items-center gap-2">
            {{ translate('Customer_Wallet') }}
        </h2>
        <div class="card card-body mb-3">
            <div class="d-flex gap-3 flex-wrap justify-content-between align-items-center">
                <div>
                    <h3 class="mb-1">{{ translate('Add_Fund_to_Customer_Wallet') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('to_send_or_add_fund_to_a_specific_customer_wallet_click_the') }}
                        <strong> {{ translate('Add_Fund') }} </strong>
                        {{ translate('button') }}
                    </p>
                </div>
                @if ($customerStatus == 1)
                    <button type="button" class="btn btn-primary text-capitalize min-w-120" data-bs-toggle="offcanvas"
                        data-bs-target="#add-fund-modal">
                        {{ translate('Add_Fund') }}
                    </button>
                @endif
            </div>
        </div>
        <div class="card card-body mb-3">
            <form action="{{ route('admin.customer.wallet.report') }}" method="get" id="filter-form">
                <div class="bg-section rounded-10 p-12 p-sm-20 mb-20">
                    <div class="row g-3">
                        <div class="col-lg-4">
                            <div class="form-group mb-0">
                                <label class="form-label" for="">
                                    {{ translate('Date_Range') }}
                                    <span class="text-danger">*</span>
                                    <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-custom-class="custom-tooltip"
                                        aria-label=" {{ translate('Select a date range to view wallet transactions') }}"
                                        data-bs-title=" {{ translate('Select a date range to view wallet transactions') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="position-relative">
                                    <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                    <input
                                        type="text"
                                        class="js-daterangepicker form-control previous-date-true placeholder-mode-true"
                                        name="date"
                                        value="{{ $dateRange }}"
                                        placeholder="{{ translate('Start_date') . ' - ' . translate('End_date') }}"
                                    >
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="form-group mb-0">
                                <label class="form-label" for="transaction_type">
                                    {{ translate('Transaction_type') }}
                                </label>
                                @php
                                    $transaction_status = request()->get('transaction_type');
                                @endphp
                                <div class="select-wrapper">
                                    <select name="transaction_type" id="transaction_type" class="form-select"
                                        title="{{ translate('select_transaction_type') }}">
                                        <option value="">{{ translate('all') }}</option>
                                        <option value="add_fund_by_admin"
                                            {{ isset($transaction_status) && $transaction_status == 'add_fund_by_admin' ? 'selected' : '' }}>
                                            {{ translate('add_fund_by_admin') }}</option>
                                        <option value="add_fund"
                                            {{ isset($transaction_status) && $transaction_status == 'add_fund' ? 'selected' : '' }}>
                                            {{ translate('add_fund') }}</option>
                                        <option value="order_refund"
                                            {{ isset($transaction_status) && $transaction_status == 'order_refund' ? 'selected' : '' }}>
                                            {{ translate('refund_order') }}</option>
                                        <option value="loyalty_point"
                                            {{ isset($transaction_status) && $transaction_status == 'loyalty_point' ? 'selected' : '' }}>
                                            {{ translate('customer_loyalty_point') }}</option>
                                        <option value="order_place"
                                            {{ isset($transaction_status) && $transaction_status == 'order_place' ? 'selected' : '' }}>
                                            {{ translate('order_place') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="form-group mb-0">
                                <label class="form-label" for="customer-id">
                                    {{ translate('Customer') }}
                                </label>
                                @php
                                    $transaction_status = request()->get('transaction_type');
                                @endphp
                                <input type="hidden" id='customer-id' name="customer_id"
                                    value="{{ requestString('customer_id') ?: 'all' }}">
                                <select name="customer_id" class="custom-select">
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer['id'] }}"
                                            {{ (request('customer_id', 'all') === (string) $customer['id']) ? 'selected' : '' }}>
                                            {{ $customer['text'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end align-items-center gap-3">
                    <a href="{{ url()->current() }}" class="btn btn-secondary min-w-120">
                        {{ translate('reset') }}
                    </a>

                    <button type="submit" class="btn btn-primary min-w-120">{{ translate('filter') }}</button>
                </div>
            </form>
        </div>

        <div class="card card-body mb-3">
            <div class="d-flex flex-wrap gap-3">
                @php
                    $credit = $data[0]->total_credit;
                    $debit = $data[0]->total_debit;
                    $balance = $credit - $debit;
                @endphp
                <div class="flex-grow-1 d-flex gap-3 align-items-center bg-primary bg-opacity-10 rounded-10 p-3 px-sm-20 overflow-wrap-anywhere h-100">
                    <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/balance.png') }}" alt="" class="aspect-1">
                    </div>
                    <div>
                        <h2 class="fs-26 fw-bold mb-2">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $balance ?? 0)) }}
                        </h2>
                        <div class="text-dark">{{ translate('Balance') }}</div>
                    </div>
                </div>
                <div class="flex-grow-1 d-flex gap-3 align-items-center bg-success bg-opacity-10 rounded-10 p-3 px-sm-20 overflow-wrap-anywhere h-100">
                    <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/credit.png') }}" alt="" class="aspect-1">
                    </div>
                    <div>
                        <h2 class="fs-26 fw-bold mb-2">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $credit ?? 0)) }}
                        </h2>
                        <div class="text-dark">{{ translate('Credit') }}</div>
                    </div>
                </div>
                <div class="flex-grow-1 d-flex gap-3 align-items-center bg-danger bg-opacity-10 rounded-10 p-3 px-sm-20 overflow-wrap-anywhere h-100">
                    <div class="flex-shrink-0 aspect-1 border rounded-circle w-60px d-grid place-items-center bg-white">
                        <img width="30" src="{{ dynamicAsset(path: 'public/assets/back-end/img/debit.png') }}" alt="" class="aspect-1">
                    </div>
                    <div>
                        <h2 class="fs-26 fw-bold mb-2">
                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $debit ?? 0)) }}
                        </h2>
                        <div class="text-dark">{{ translate('Debit ') }}</div>
                    </div>
                </div>
            </div>
        </div>
        <x-k.data-view :title="translate('transactions')" :count="$transactions->total()"
                       searchName="searchValue" :searchValue="requestString('searchValue')"
                       :searchPlaceholder="translate('search_by_Name_or_Email_or_Phone')">

            <x-slot:actions>
                <a class="k-btn k-btn--secondary"
                   href="{{ route('admin.customer.wallet.export', ['transaction_type' => $transaction_status, 'customer_id' => request('customer_id'), 'to' => request('to'), 'from' => request('from')]) }}">
                    <x-k.icon name="download" :size="15" /> {{ translate('export') }}
                </a>
            </x-slot:actions>

            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('SL') }}</th>
                    <th>{{ translate('transaction_ID') }}</th>
                    <th>{{ translate('created_at') }}</th>
                    <th>{{ translate('Customer_Name') }}</th>
                    <th class="k-table__num">{{ translate('credit') }} ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</th>
                    <th class="k-table__num">{{ translate('debit') }} ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</th>
                    <th class="k-table__num">{{ translate('balance') }} ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</th>
                    <th>{{ translate('transaction_type') }}</th>
                    <th>{{ translate('reference') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($transactions as $key => $transaction)
                    <tr>
                        <td><span class="k-num">{{ $transactions->firstItem() + $key }}</span></td>
                        <td><span class="k-num">{{ $transaction['transaction_id'] }}</span></td>
                        <td>
                            <span class="k-num">{{ date('Y/m/d ' . config('timeformat'), strtotime($transaction['created_at'])) }}</span>
                        </td>
                        <td>
                            <a href="{{ route('admin.customer.view', ['user_id' => $transaction['user_id']]) }}"
                                class="text-dark text-hover-primary">{{ Str::limit($transaction['user'] ? $transaction?->user->f_name . ' ' . $transaction?->user->l_name : translate('not_found'), 20) }}</a>
                        </td>
                        <td class="k-table__num">
                            <span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['credit'])) }}</span>
                            @if ($transaction['transaction_type'] == 'add_fund' && $transaction['admin_bonus'] > 0)
                                <div class="fw-semibold lh-sm text-success">
                                    <div class="k-num">
                                        + {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['admin_bonus'])) }}
                                    </div>
                                    <div>{{ translate('Admin_Bonus') }}</div>
                                </div>
                            @endif
                        </td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['debit'])) }}</span></td>
                        <td class="k-table__num"><span class="k-num">{{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $transaction['balance'])) }}</span></td>
                        <td class="text-capitalize">
                            @php($walletTxnTones = ['order_refund' => 'danger', 'loyalty_point' => 'warning', 'order_place' => 'info'])
                            <x-k.badge :tone="$walletTxnTones[$transaction['transaction_type']] ?? 'success'">
                                {{ translate($transaction['transaction_type']) }}
                            </x-k.badge>
                        </td>
                        <td>{{ $transaction['reference'] ? translate(str_replace('_', ' ', $transaction['reference'])) : "N/A" }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (count($transactions) == 0)
                <x-k.empty icon="reports" :title="translate('no_data_found')" />
            @endif

            @if ($transactions->total() > 0)
                <x-slot:pager>
                    <span class="k-pager__info">
                        {{ translate('showing') }}
                        <span class="k-num">{{ $transactions->firstItem() }}–{{ $transactions->lastItem() }}</span>
                        {{ translate('of') }} <span class="k-num">{{ $transactions->total() }}</span>
                    </span>
                    <div>{!! $transactions->appends(request()->query())->links() !!}</div>
                </x-slot:pager>
            @endif
        </x-k.data-view>

        @include('admin-views.customer.partials.offcanvas._add-fund-offcanvas')
    </div>
@endsection

@push('script')
    <script>
        'use strict';

        let errorTimeout;

        $("#amount").on('input', function() {
            const value = parseFloat($(this).val());
            if (isNaN(value) || value <= 0) {
                $(this).val('');
                $("#amount_error").fadeIn(200);
                clearTimeout(errorTimeout);
                errorTimeout = setTimeout(function() {
                    $("#amount_error").fadeOut(500);
                }, 1000);
            } else {
                $("#amount_error").fadeOut(300);
            }
        });

        $('#add-fund').on('submit', function(e) {
            e.preventDefault();
            let formData = new FormData(this);
            Swal.fire({
                title: "{{ translate('are_you_sure') . '?' }} ",
                text: '{{ translate('you_want_to_add_fund') }} ' + $('#amount').val() +
                    ' {{ getCurrencyCode(type: 'default') . ' ' . translate('to') }} ' + $(
                        '#form-customer option:selected').text() + '{{ translate('to_wallet') }}',
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: '#377dff',
                cancelButtonColor: '#dd3333',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('add') }}',
                reverseButtons: true
            }).then(result => {
                if (result.value) {
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        }
                    });
                    $.post({
                        url: '{{ route('admin.customer.wallet.add-fund') }}',
                        data: formData,
                        cache: false,
                        contentType: false,
                        processData: false,
                        success: function(data) {
                            if (data.errors) {
                                $.each(data.errors, function(i, err) {
                                    setTimeout(function() {
                                        toastMagic.error(err.message);
                                    }, 500 * (i + 1));
                                });
                            } else {
                                toastMagic.success(
                                    '{{ translate('fund_added_successfully') }}');
                                setTimeout(() => {
                                    location.reload()
                                }, 500);
                            }
                        },
                        complete: function () {
                        }
                    });
                }
            })
        })
    </script>
@endpush
