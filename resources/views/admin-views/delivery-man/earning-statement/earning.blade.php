@extends('layouts.admin.app')

@section('title', translate('Earning'))
@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="mb-4 pb-2">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/add-new-seller.png') }}" alt="">
                {{ translate('earning_statement') }}
            </h2>
        </div>
        @include('admin-views.delivery-man.pages-inline-menu')
        <div class="card mb-3">
            <div class="card-body">
                <div class="row justify-content-between align-items-center g-2 mb-3">
                    <div class="col-sm-6">
                        <h3 class="d-flex align-items-center text-capitalize gap-2 mb-0">
                            {{ translate('earning') }}
                        </h3>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 d-flex justify-content-center align-items-center py-xl-4">
                            <div class="card-body d-flex flex-column gap-2 align-items-center justify-content-center">
                                <img src="{{ dynamicAsset('public/assets/back-end/img/aw.png') }}" width="48"
                                    class="mb-2" alt="">
                                <h4 class="text-capitalize mb-2">{{ translate('total_earning') }}</h4>
                                <h2 class="business-analytics__title h1 fw-bold">
                                    {{ $totalEarn ? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $totalEarn), currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) }}
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 d-flex justify-content-center align-items-center py-xl-4">
                            <span class="tooltip-icon position-absolute top-0 inset-inline-end-0 p-3"
                                data-bs-toggle="tooltip"
                                data-bs-title="{{ translate('the_delivery_man_can_request_to_withdraw_this_amount') . '.' }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                            <div class="card-body d-flex flex-column gap-2 align-items-center justify-content-center">
                                <img src="{{ dynamicAsset('public/assets/back-end/img/pw.png') }}" width="40"
                                    class="mb-2" alt="">
                                <h4 class="text-capitalize mb-2">{{ translate('withdrawable_balance') }}</h4>
                                <h2 class="business-analytics__title h1 fw-bold">
                                    {{ $withdrawalableBalance <= 0 ? setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: usdToDefaultCurrency(amount: $withdrawalableBalance), currencyCode: getCurrencyCode()) }}
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 d-flex justify-content-center align-items-center py-xl-4">
                            <span class="tooltip-icon position-absolute top-0 inset-inline-end-0 p-3"
                                data-bs-toggle="tooltip"
                                data-bs-title="{{ translate('the_delivery_man_has_already_withdrawn_this_amount') . '.' }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                            <div class="card-body d-flex flex-column gap-2 align-items-center justify-content-center">
                                <img src="{{ dynamicAsset('public/assets/back-end/img/withdraw.png') }}" width="40"
                                    class="mb-2" alt="">
                                <h4 class="text-capitalize mb-2">{{ translate('already_withdrawn') }}</h4>
                                <h2 class="business-analytics__title h1 fw-bold">
                                    {{ $deliveryMan->wallet ? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $deliveryMan->wallet->total_withdraw), currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) }}
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 d-flex justify-content-center align-items-center py-xl-4">
                            <span class="tooltip-icon position-absolute top-0 inset-inline-end-0 p-3"
                                data-bs-toggle="tooltip"
                                data-bs-title="{{ translate('the_delivery_man_has_this_amount_in_hand') . '.' }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                            <div class="card-body d-flex flex-column gap-2 align-items-center justify-content-center">
                                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/cash-in-hand.png') }}" width="40"
                                    class="mb-2" alt="">
                                <h4 class="text-capitalize mb-2">{{ translate('cash_in_hand') }}</h4>
                                <h2 class="business-analytics__title h1 fw-bold">
                                    {{ $deliveryMan->wallet ? setCurrencySymbol(amount: usdToDefaultCurrency(amount: $deliveryMan->wallet->cash_in_hand), currencyCode: getCurrencyCode()) : setCurrencySymbol(amount: 0, currencyCode: getCurrencyCode()) }}
                                </h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="px-3 py-4">
                    <div class="row gy-3 align-items-center">
                        <div class="col-md-4">
                            <h3 class="d-flex align-items-center text-capitalize gap-2 mb-0">
                                {{ translate('earning_history') }}
                                <span class="badge text-dark bg-body-secondary fw-semibold rounded-50"
                                    id="row-count">{{ $orders->total() }}</span>
                            </h3>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex align-items-center justify-content-md-end flex-wrap flex-sm-nowrap gap-2">
                                <div class="flex-grow-1 max-w-300 min-w-100-mobile">
                                    <form action="" method="GET">
                                        <div class="input-group input-group-merge input-group-custom">
                                            <input id="datatableSearch_" type="search" name="searchValue"
                                                class="form-control" placeholder="{{ translate('search_by_order_no') }}"
                                                aria-label="Search orders" value="{{ requestString('searchValue') }}">
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <div class="dropdown">
                                    <a class="btn btn border text-dark dropdown-toggle min-h-40 min-w-120"
                                        href="javascript:" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        {{ translate('Filter') }}
                                    </a>

                                    <div id="menu" class="dropdown-menu earning_filter_menu px-3 pt-4 pb-2">
                                        <div class="position-relative nav--tab-wrapper">
                                            <ul class="nav nav-pills nav--tab lang_tab gap-3 border-0 mb-4" id="pills-tab"
                                                role="tablist">
                                                <li class="nav-item px-0" role="presentation">
                                                    <a href="#status" data-bs-toggle="pill" role="tab"
                                                        class="nav-link py-2 px-0 active">
                                                        {{ translate('status') }}
                                                    </a>
                                                </li>
                                                <li class="nav-item px-0" role="presentation">
                                                    <a href="#payment" data-bs-toggle="pill" role="tab"
                                                        class="nav-link py-2 px-0 text-capitalize">
                                                        {{ translate('payment_method') }}
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-content earning-order-history" id="pills-tabContent">
                                            <div class="tab-pane active" id="status" role="tabpanel">
                                                <ul class="check-list-group navbar-nav gap-2">
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="pending">
                                                            <span class="label">{{ translate('pending') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="confirmed">
                                                            <span class="label">{{ translate('confirmed') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="processing">
                                                            <span class="label">{{ translate('packaging') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input"
                                                                value="out_for_delivery">
                                                            <span
                                                                class="label text-capitalize">{{ translate('out_for_delivery') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="delivered">
                                                            <span
                                                                class="label text-capitalize">{{ translate('delivered') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="cancel">
                                                            <span class="label">{{ translate('canceled') }}</span>
                                                        </label>
                                                    </li>
                                                </ul>
                                            </div>
                                            <div class="tab-pane fade show" id="payment" role="tabpanel">
                                                <ul class="check-list-group navbar-nav gap-2">
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="paid">
                                                            <span class="label">{{ translate('received') }}</span>
                                                        </label>
                                                    </li>
                                                    <li>
                                                        <label class="custom-control-group">
                                                            <input type="checkbox" class="input" value="unpaid">
                                                            <span class="label">{{ translate('not_received') }}</span>
                                                        </label>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <a type="button"
                                        class="btn btn-outline-primary earning-file-export-btn"
                                        data-base="{{ route('admin.delivery-man.order-history-log-export', ['id' => $deliveryMan->id, 'type' => 'earn']) }}"
                                        href="{{ route('admin.delivery-man.order-history-log-export', ['id' => $deliveryMan->id, 'type' => 'earn']) }}">
                                        <i class="fi fi-sr-inbox-in"></i>
                                        <span class="fs-12">{{ translate('export') }}</span>
                                    </a>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-2" id="status-wise-view">
                    @include('admin-views.delivery-man.earning-statement._table')
                </div>
            </div>
        </div>
    </div>
    <span id="get-filter-route"
        data-action="{{ route('admin.delivery-man.order-wise-earning-list-by-filter', ['id' => $deliveryMan['id']]) }}"></span>
@endsection
@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/deliveryman.js') }}"></script>
    <script>
        document.querySelectorAll('.earning_filter_menu').forEach(function(dropdown) {
            dropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
@endpush
