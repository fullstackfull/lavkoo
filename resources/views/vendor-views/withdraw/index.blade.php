@extends('layouts.vendor.app')

@section('title', translate('withdraw_Request'))

@section('content')
    <div class="content container-fluid">
        <h1 class="mb-3 text-capitalize">{{ translate('withdraw') }}</h1>

        <div class="d-flex gap-2 alert alert-soft-warning mb-3" role="alert">
            <i class="fi fi-sr-info"></i>
            <p class="fs-12 mb-0 text-dark">
                {{ translate('if_you_want_to_add_withdraw_details_you_can_go_to') }}
                <a href="{{ route('vendor.shop.payment-information.index') }}" target="_blank"
                    class="text-underline font-weight-bold">
                    {{ translate('Payment_Options_page') }}
                </a>
                {{ translate('this_setup_will_help_to_easy_withdraw_request_send.') }}
            </p>
        </div>

        <div class="d-flex gap-2 alert alert-soft-info mb-3" role="alert">
            <i class="fi fi-sr-lightbulb-on"></i>
            <p class="fs-12 mb-0 text-dark">
                {{ translate('here_you_can_show_your_withdraw_details_with_withdraw_request_lists.') }}
            </p>
        </div>

        <div class="row gy-2 mb-3">
            <div class="col-lg-6">
                <div class="card card-body h-100 justify-content-center">
                    <div class="d-flex gap-2 justify-content-between align-items-center">
                        <div class="d-flex gap-3 align-items-center">
                            <img width="40" src="{{ dynamicAsset(path: 'public/assets/back-end/img/cb.png') }}"
                                alt="">
                            <div class="d-flex flex-column align-items-start">
                                <h3 class="mb-1 text-primary">
                                    {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $vendorWallet?->total_earning ?? 0), currencyCode: getCurrencyCode(type: 'default')) }}
                                </h3>
                                <div class="text-capitalize mb-0">{{ translate('Current_Balance') }}</div>
                            </div>
                        </div>
                        @if (($vendorWallet?->total_earning ?? 0) <= 0)
                            <div>
                                <button type="button" class="btn btn--primary" data-toggle="tooltip"
                                    title="{{ translate('due_to_no_balance_this_button_is_disable') }}" disabled>
                                    {{ translate('Withdraw') }}
                                </button>
                            </div>
                        @else
                            <div class="d-flex gap-2">
                                @if (Route::has('vendor.business-settings.withdraw.export-withdraw-list'))
                                    <a href="{{ route('vendor.business-settings.withdraw.export-withdraw-list') }}" class="btn btn-outline--primary">
                                        <i class="tio-download-to"></i> {{ translate('export') }}
                                    </a>
                                @endif
                                <div data-toggle="offcanvas" data-target=".withdraw-request-offcanvas">
                                    <button type="button" class="btn btn--primary">
                                        {{ translate('Withdraw') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="card card-body h-100 justify-content-center">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="40" src="{{ dynamicAsset(path: 'public/assets/back-end/img/rb.png') }}"
                            alt="">
                        <div class="d-flex flex-column align-items-start">
                            <h3 class="mb-1 text-warning">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $vendorWallet?->pending_withdraw ?? 0), currencyCode: getCurrencyCode(type: 'default')) }}
                            </h3>
                            <div class="text-capitalize mb-0">{{ translate('Requested_Balance') }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="card card-body h-100 justify-content-center">
                    <div class="d-flex gap-3 align-items-center">
                        <img width="40" src="{{ dynamicAsset(path: 'public/assets/back-end/img/wb.png') }}"
                            alt="">
                        <div class="d-flex flex-column align-items-start">
                            <h3 class="mb-1 text-success">
                                {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $vendorWallet?->withdrawn ?? 0), currencyCode: getCurrencyCode(type: 'default')) }}
                            </h3>
                            <div class="text-capitalize mb-0">{{ translate('Withdrawn_Balance') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="inline-page-menu mb-4">
                    @php $activeStatus = request('status', 'all');
                         $count = $withdrawRequests->count();
                    @endphp

                    <div class="inline-page-menu mb-4">
                        <ul class="list-unstyled flex-wrap d-flex gap-2">
                            <li class="{{ $activeStatus === 'all' ? 'active' : '' }}">
                                <a href="{{ route('vendor.business-settings.withdraw.index') }}">
                                    {{ translate('All_Request') }}
                                    @if ($activeStatus === 'all' && $count > 0)
                                        <span>({{ $count }})</span>
                                    @endif
                                </a>
                            </li>
                            <li class="{{ $activeStatus === 'pending' ? 'active' : '' }}">
                                <a href="{{ route('vendor.business-settings.withdraw.index', ['status' => 'pending']) }}">
                                    {{ translate('pending') }}
                                    @if ($activeStatus === 'pending' && $count > 0)
                                        <span>({{ $count }})</span>
                                    @endif
                                </a>
                            </li>
                            <li class="{{ $activeStatus === 'approved' ? 'active' : '' }}">
                                <a href="{{ route('vendor.business-settings.withdraw.index', ['status' => 'approved']) }}">
                                    {{ translate('approved') }}
                                    @if ($activeStatus === 'approved' && $count > 0)
                                        <span>({{ $count }})</span>
                                    @endif
                                </a>
                            </li>
                            <li class="{{ $activeStatus === 'denied' ? 'active' : '' }}">
                                <a href="{{ route('vendor.business-settings.withdraw.index', ['status' => 'denied']) }}">
                                    {{ translate('denied') }}
                                    @if ($activeStatus === 'denied' && $count > 0)
                                        <span>({{ $count }})</span>
                                    @endif
                                </a>
                            </li>
                        </ul>
                    </div>

                </div>

                <div class="card">
                    <div class="p-3">
                        <div class="d-flex gy-1 align-items-center flex-sm-nowrap flex-wrap justify-content-between">
                            <h4 class="text-capitalize">
                                {{ translate('All_Request_List') }}
                            </h4>
                            <form action="{{ route('vendor.business-settings.withdraw.index') }}" method="GET">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control"
                                        placeholder="{{ translate('Search_By_Amount') }}"
                                           value="{{ requestString('search') }}">
                                    <div class="input-group-append">
                                        <button type="submit" class="input-group-text bg-light">
                                            <i class="fi fi-rr-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="status-wise-view">
                        @include('vendor-views.withdraw._table')
                    </div>
                </div>
            </div>
        </div>

        <div class="withdraw-request-offcanvas offcanvas-sidebar">
            <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

            <div class="offcanvas-content bg-white shadow d-flex flex-column">
                <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                    <h3 class="text-capitalize m-0">{{ translate('Withdraw_Request') }}</h3>
                    <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form action="{{ route('vendor.dashboard.withdraw-request') }}" method="post"
                    class="d-flex flex-column flex-grow-1">
                    @csrf

                    @include('vendor-views.withdraw._withdraw-request-form')

                    <div class="offcanvas-footer offcanvas-footer-sticky p-3 border-top bg-white d-flex gap-3">
                        <button type="reset" class="btn btn-secondary w-100" data-dismiss="offcanvas">
                            {{ translate('Cancel') }}
                        </button>
                        <button type="submit" class="btn btn--primary w-100">
                            {{ translate('Send_Request') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if ($withdrawRequests->count() > 0)
        @foreach ($withdrawRequests as $key => $withdrawRequest)
            @include('vendor-views.withdraw._withdraw-request-preview', ['withdrawRequest' => $withdrawRequest])
            @include('vendor-views.withdraw._withdraw-request-edit', ['withdrawRequest' => $withdrawRequest])
        @endforeach
    @endif

    <span id="get-status-filter-route" data-action="{{ route('vendor.business-settings.withdraw.index') }}"></span>
    @include('layouts.vendor.partials.offcanvas._withdraw')
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/withdraw.js') }}"></script>
@endpush
