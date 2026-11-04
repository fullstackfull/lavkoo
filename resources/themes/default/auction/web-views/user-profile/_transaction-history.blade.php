@extends("auction.web-views.user-profile._profile-master")

@section('title', translate('Transaction History'))

@php
    $selectedTransactionTypes = (array) request('transaction_type', []);
    $selectedDuration = request('filter_duration_type', 'all');
    $activeFilterCount = 0;
    if (!empty(request('transaction_type'))) {
        $activeFilterCount += count((array) request('transaction_type'));
    }
    if (request('filter_duration_type') && request('filter_duration_type') !== 'all') {
        $activeFilterCount += 1;
    }
@endphp
@section('profile_content')
    <div class="d-flex flex-column h-100">
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-3 mt-2">
            <h4 class="fs-18 text-capitalize flex-grow-1 mb-0 d-flex align-items-center gap-2">
                {{ translate('Transaction_with_Admin') }}
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill fs-12 fw-medium">{{ $transactions->total() }}</span>
            </h4>
            <div class="flex-grow-1 max-w-280px max-w-100-mobile">
                <form action="{{ route('auction.transaction-history') }}" method="GET">
                    @foreach(['transaction_type', 'filter_duration_type', 'transaction_range'] as $preserveKey)
                        @if(request()->filled($preserveKey))
                            @if(is_array(request($preserveKey)))
                                @foreach(request($preserveKey) as $val)
                                    <input type="hidden" name="{{ $preserveKey }}[]" value="{{ $val }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $preserveKey }}" value="{{ request($preserveKey) }}">
                            @endif
                        @endif
                    @endforeach
                    <div class="input-group rounded overflow-hidden border">
                        <input type="search" name="search_auction_id" class="form-control border-0 shadow-none text-muted"
                               placeholder="{{ translate('Search by auction id') }}"
                               value="{{ $search_auction_id }}">
                        <button type="submit" class="btn border-0 px-3 fs-20 text-muted">
                            <i class="fi fi-rr-search"></i>
                        </button>
                    </div>
                </form>
            </div>
            <div class="dropdown filter">
                <button type="button"
                    class="btn border bg-white px-3 py-1 text-dark fs-14 d-flex align-items-center gap-3 h-45px"
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fi fi-rr-bars-filter text-muted"></i>
                    {{ translate('filter') }}
                </button>
                @if($activeFilterCount > 0)
                <span class="count bg-danger fs-8" style="--size: 20px;">
                    {{ $activeFilterCount }}
                </span>
                @endif

                <div class="dropdown-menu dropdown-menu-end shadow bg-white transaction-filter_dropdown min-w-450px min-w-280px-mobile pb-0">
                    <form action="{{ route('auction.transaction-history') }}" method="get">
                        @if(request()->filled('search_auction_id'))
                            <input type="hidden" name="search_auction_id" value="{{ request('search_auction_id') }}">
                        @endif
                        <div class="d-flex justify-content-between align-items-center p-3 p-sm-4 border-bottom">
                            <h5 class="fs-18"> {{ translate('filter_data') }}</h5>
                            <button id="filterCloseBtn" type="button" class="btn border-0 p-1">
                                <i class="fi fi-rr-cross d-flex"></i>
                            </button>
                        </div>
                        <div class="p-3 p-sm-4 overflow-auto max-h-290px">
                            <div class="bg-light p-3 rounded mb-4">
                                <label for="" class="form-label d-flex gap-1 align-items-center mb-2">
                                    {{ translate('Transaction_Type') }}
                                </label>
                                <div class="bg-white p-3 rounded d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="flex-grow-1 d-flex align-items-center gap-2">
                                        <input type="checkbox" class="" name="transaction_type[]" id="filterTxCredit" value="credit"
                                               {{ in_array('credit', $selectedTransactionTypes) ? 'checked' : '' }}>
                                        <label for="filterTxCredit" class="m-0">{{ translate('Credit') }}</label>
                                    </div>
                                    <div class="flex-grow-1 d-flex align-items-center gap-2">
                                        <input type="checkbox" class="" name="transaction_type[]" id="filterTxDebit" value="debit"
                                               {{ in_array('debit', $selectedTransactionTypes) ? 'checked' : '' }}>
                                        <label for="filterTxDebit" class="m-0">{{ translate('Debit') }}</label>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-light p-3 rounded mb-0">
                                <div class="date-filter-wrapper">
                                    <div>
                                        <div class="text-start">
                                            <label for="" class="form-label text-start mb-2">{{ translate('Date_Type') }}</label>
                                        </div>
                                        <div class="select-wrapper">
                                            <select name="filter_duration_type" id="filter_duration_type"
                                                    class="form-select date-type-select">
                                                <option value="all" {{ $selectedDuration === 'all' ? 'selected' : '' }}>{{ translate('All_Time') }}</option>
                                                <option value="today" {{ $selectedDuration === 'today' ? 'selected' : '' }}>{{ translate('Today') }}</option>
                                                <option value="month" {{ $selectedDuration === 'month' ? 'selected' : '' }}>{{ translate('This_Month') }}</option>
                                                <option value="year" {{ $selectedDuration === 'year' ? 'selected' : '' }}>{{ translate('This_Year') }}</option>
                                                <option value="custom" {{ $selectedDuration === 'custom' ? 'selected' : '' }}>{{ translate('Custom_Date') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-4 custom-date-div d--none">
                                        <div class="text-start">
                                            <label class="form-label mb-2"> {{ translate('date_range') }}</label>
                                        </div>
                                        <div class="position-relative">
                                            <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                                            <input type="text" id="dateRangeInput" name="transaction_range"
                                                    class="form-control" placeholder="{{ translate('Select_Date') }}" value="{{ request('transaction_range') }}"
                                                    inputmode="numeric" maxlength="25" autocomplete="off"
                                                    pattern="\d{2}/\d{2}/\d{4}\s*-\s*\d{2}/\d{2}/\d{4}"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card border-0 shadow-lg p-3 p-sm-4 d-flex flex-row gap-3">
                            <a href="{{ route('auction.transaction-history', array_filter(['search_auction_id' => request('search_auction_id')])) }}"
                               class="btn btn-light w-100 text-nowrap">
                                {{ translate('clear_filter') }}
                            </a>
                            <button type="submit" class="btn btn-primary w-100">
                                {{ translate('filter') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="card card-body border-0 h-100 mt-4">
            @if($transactions->count() > 0)
                <div class="row g-3">
                    @foreach($transactions as $item)
                        @php
                            $transactionTypeLabel = match($item->transaction_type ?? null) {
                                'withdraw_payout' => translate('Withdraw_Payout'),
                                'commission_payment' => translate('Commission_Payment'),
                                default => null,
                            };
                        @endphp
                        <div class="col-sm-6">
                            <div class="bg-light p-2 p-sm-4 rounded d-flex justify-content-between gap-3">
                                <div>
                                    <h4 class="mb-2 d-flex align-items-center gap-2">
                                        @if($item->type === 'debit')
                                            <img src="{{ dynamicAsset(path: 'public/assets//front-end/img/icons/coin-danger.png') }}" width="25" alt="">
                                        @else
                                            <img src="{{ dynamicAsset(path: 'public/assets//front-end/img/icons/coin-success.png') }}" width="25" alt="">
                                        @endif
                                        <span class="font-bold fs-18">
                                            {{ $item->type === 'debit' ? ' - ' : ' + ' }}{{ webCurrencyConverter(amount: $item->amount) }}
                                        </span>
                                    </h4>

                                    <div class="d-flex gap-1 flex-wrap">
                                        <div class="fs-12 text-muted"># {{ $item->auction_product_id }}</div>
                                        @if($transactionTypeLabel)
                                            <div class="fs-12 text-muted">( {{ $transactionTypeLabel }} )</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="text-muted mb-1 fs-12">
                                        {{ $item->date?->format('d M, Y H:i A') ?? '—' }}
                                    </div>
                                    @if($item->type === 'debit')
                                        <p class="text-warning fs-14 m-0">{{ translate('Debit') }}</p>
                                    @else
                                        <p class="text-success fs-14 m-0">{{ translate('Credit') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div
                    class="d-flex flex-column justify-content-center align-items-center gap-2 py-5 w-100 bg-light rounded">
                    <img width="80" class="mb-3"
                         src="{{ dynamicAsset(path: 'public/assets//front-end/img/empty-icons/empty-orders.svg') }}" alt="">
                    <h5 class="text-center text-muted fs-14 mb-0">
                        {{ translate('No_Transactions_Found') }}!
                    </h5>
                </div>
            @endif

            @if($transactions->count() > 0)
                <div class="d-flex justify-content-end">
                    {!! $transactions->appends(request()->query())->links() !!}
                </div>
            @endif
        </div>
    </div>
@endsection

@push('script')
    <script>
        $(document).ready(function () {
            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

            $('input[name="transaction_range"]').daterangepicker({
                autoUpdateInput: false,
                maxDate: moment(),
                linkedCalendars: true,
                locale: {
                    format: "DD/MM/YYYY",
                },
            });

            $('input[name="transaction_range"]').on(
                "apply.daterangepicker",
                function (ev, picker) {
                    $(this).val(
                        picker.startDate.format("DD/MM/YYYY") +
                            " - " +
                            picker.endDate.format("DD/MM/YYYY")
                    );
                }
            );

            $('input[name="transaction_range"]').on(
                "cancel.daterangepicker",
                function (ev, picker) {
                    $(this).val("");
                }
            );

            // Allow only digits, slash, dash, and space — strip anything else as the user types/pastes.
            $(document).on('input', 'input[name="transaction_range"]', function () {
                const cleaned = this.value.replace(/[^0-9\/\- ]/g, '');
                if (this.value !== cleaned) {
                    this.value = cleaned;
                }
            });

            $('.transaction-filter_dropdown').closest('.dropdown').on('hide.bs.dropdown', function (e) {
                if (e.clickEvent) {
                    let isInsideDropdown = $(e.clickEvent.target).closest('.transaction-filter_dropdown').length > 0;
                    let isCloseBtn = $(e.clickEvent.target).closest('#filterCloseBtn').length > 0;
                    let isDaterangepicker = $(e.clickEvent.target).closest('.daterangepicker').length > 0;

                    if ((isInsideDropdown && !isCloseBtn) || isDaterangepicker) {
                        e.preventDefault();
                    }
                }
            });

            $(document).on('click', '#filterCloseBtn', function () {
                let dropdownToggle = $(this).closest('.dropdown').find('[data-bs-toggle="dropdown"]');
                if (dropdownToggle.length && window.bootstrap) {
                    let bsDropdown = bootstrap.Dropdown.getOrCreateInstance(dropdownToggle[0]);
                    if (bsDropdown) {
                        bsDropdown.hide();
                    }
                } else if (dropdownToggle.length) {
                    dropdownToggle.trigger('click');
                }
            });
        })
    </script>
@endpush
