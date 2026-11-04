
<form action="{{ route('admin.orders.list', ['status' => request('status')]) }}" id="form-data" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasOrderFilter" aria-labelledby="offcanvasOrderFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <div class="row g-4">
                    <div class="col-12">
                        @if (request('delivery_man_id'))
                            <input type="hidden" name="delivery_man_id" value="{{ requestString('delivery_man_id') }}">
                        @endif

                        <label for="" class="form-label">{{ translate('Date_Type') }}</label>
                        <div class="select-wrapper">
                            <select class="form-select" name="date_type" id="date_type">
                                <option value="all" {{ empty($dateType) ? 'selected' : '' }}>
                                    {{ translate('All') }}
                                </option>
                                <option value="today" {{ $dateType == 'today' ? 'selected' : '' }}>
                                    {{ translate('Today') }}
                                </option>
                                <option value="this_week" {{ $dateType == 'this_week' ? 'selected' : '' }}>
                                    {{ translate('this_Week') }}
                                </option>
                                <option value="this_month" {{ $dateType == 'this_month' ? 'selected' : '' }}>
                                    {{ translate('this_Month') }}
                                </option>
                                <option value="this_year" {{ $dateType == 'this_year' ? 'selected' : '' }}>
                                    {{ translate('this_Year') }}
                                </option>
                                <option value="custom_date" {{ $dateType == 'custom_date' ? 'selected' : '' }}>
                                    {{ translate('custom_Date') }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="col-sm-6" id="from_div">
                        <label for="" class="form-label">
                            {{ translate('Start_Date') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                  data-bs-title="{{ translate('Choose the date to start showing orders from') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="date" name="from" value="{{ $from }}" id="from_date"
                               class="form-control">
                    </div>
                    <div class="col-sm-6" id="to_div">
                        <label for="" class="form-label">
                            {{ translate('End_Date') }}
                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                  data-bs-title="{{ translate('Choose the date to show orders up to') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input class="form-control" type="date" value="{{ $to }}" name="to" id="to_date">
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Show_Order_For') }}</label>
                <div class="bg-white rounded p-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="d-flex gap-2 flex-grow-1">
                            <input class="form-check-input radio--input m-0" type="radio" name="filter"
                                   id="inlineRadioAll" value="all" {{ empty($filter) || $filter == 'all' ? 'checked' : '' }}>
                            <label class="form-check-label fs-12 cursor-pointer" for="inlineRadioAll">
                                {{ translate('All') }}
                            </label>
                        </div>
                        <div class="d-flex gap-2 flex-grow-1">
                            <input class="form-check-input radio--input m-0" type="radio" name="filter"
                                   id="inlineRadioInhouse" value="admin" {{ $filter == 'admin' ? 'checked' : '' }}>
                            <label class="form-check-label fs-12 cursor-pointer" for="inlineRadioInhouse">
                                {{ translate('In-house_Order') }}
                            </label>
                        </div>
                        <div class="d-flex gap-2 flex-grow-1">
                            <input class="form-check-input radio--input m-0" type="radio" name="filter"
                                   id="inlineRadioVendor" value="seller" {{ $filter == 'seller' ? 'checked' : '' }}>
                            <label class="form-check-label fs-12 cursor-pointer" for="inlineRadioVendor">
                                {{ translate('Vendor_Order') }}
                            </label>
                        </div>
                    </div>

                    @if (($status == 'all' || $status == 'delivered') && !request()->has('delivery_man_id'))
                        <div class="p-12 p-sm-20 bg-section rounded d-flex justify-content-between align-items-center flex-wrap gap-3 mt-20">
                            <div class="d-flex gap-2 flex-grow-1">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                       id="inlineCheckboxPosOrder" value="pos" name="order_types[]" {{ in_array('pos', $orderTypes) ? 'checked' : '' }}>
                                <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckboxPosOrder">
                                    {{ translate('Only_POS_Orders') }}
                                </label>
                            </div>
                            <div class="d-flex gap-2 flex-grow-1">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                       id="inlineCheckboxWebsiteOrder" value="default_type" name="order_types[]" {{ in_array('default_type', $orderTypes) ? 'checked' : '' }}>
                                <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckboxWebsiteOrder">
                                    {{ translate('Only_Website_Orders') }}
                                </label>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere vendor-list-dropdown-section d--none">
                <label class="form-label" for="vendor_list">{{ translate('Vendor') }}</label>
                <select class="custom-select form-control" name="seller_id" id="vendor_list">
                    <option value="all">
                        {{'---'.translate('Select_Vendor').'---'}}
                    </option>
                    @foreach($sellers as $seller)
                        @if($seller?->shop)
                            <option class="text-start text-capitalize"
                                    value="{{ $seller->id }}" {{ $seller->id == request('seller_id') ? 'selected' : '' }}>
                                {{ $seller?->shop?->name }}
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>

            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Edit_Order_Amount_Settlement') }}</label>
                <div class="bg-white rounded p-3">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer"
                                       type="checkbox"
                                       id="hasReturnAmount"
                                       name="order_amount_settlement[]"
                                       value="return"
                                    {{ in_array('return', request('order_amount_settlement', [])) ? 'checked' : '' }}>

                                <label class="form-check-label fs-12 cursor-pointer" for="hasReturnAmount">
                                    {{ translate('Has_Return_Amount') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer"
                                       type="checkbox"
                                       id="hasDueAmount"
                                       name="order_amount_settlement[]"
                                       value="due"
                                    {{ in_array('due', request('order_amount_settlement', [])) ? 'checked' : '' }}>

                                <label class="form-check-label fs-12 cursor-pointer" for="hasDueAmount">
                                    {{ translate('Has_due_Amount') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($status == 'all')
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                    <label for="" class="form-label">{{ translate('Order_Status') }}</label>
                    <div class="bg-white rounded p-3">
                        <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox1" name="order_current_status[]" value="pending"
                                        {{ in_array('pending', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox1">
                                        {{ translate('Pending') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox2" name="order_current_status[]" value="confirmed"
                                        {{ in_array('confirmed', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox2">
                                        {{ translate('Confirmed') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox3" name="order_current_status[]" value="processing"
                                        {{ in_array('processing', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox3">
                                        {{ translate('Packaging') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox4" name="order_current_status[]" value="out_for_delivery"
                                        {{ in_array('out_for_delivery', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox4">
                                        {{ translate('Out_For_Delivery') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox5" name="order_current_status[]" value="delivered"
                                        {{ in_array('delivered', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox5">
                                        {{ translate('Delivered') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox6" name="order_current_status[]" value="canceled"
                                        {{ in_array('canceled', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox6">
                                        {{ translate('Canceled') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox7" name="order_current_status[]" value="returned"
                                        {{ in_array('returned', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox7">
                                        {{ translate('Returned') }}
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                           id="inlineCheckbox8" name="order_current_status[]" value="failed"
                                        {{ in_array('failed', $orderStatus) ? 'checked' : '' }}>
                                    <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckbox8">
                                        {{ translate('Failed To Deliver') }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Payment_Status') }}</label>
                <div class="bg-white rounded p-3">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                       id="inlineCheckboxPaid" name="payment_status[]" value="paid"
                                {{ in_array('paid', $paymentPaidStatus) ? 'checked' : '' }}>
                                <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckboxPaid">
                                    {{ translate('Paid') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input m-0 cursor-pointer" type="checkbox"
                                       id="inlineCheckboxUnpaid" name="payment_status[]" value="unpaid"
                                    {{ in_array('unpaid', $paymentPaidStatus) ? 'checked' : '' }}>
                                <label class="form-check-label fs-12 cursor-pointer" for="inlineCheckboxUnpaid">
                                    {{ translate('Unpaid') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label class="form-label" for="customer">{{ translate('Customer') }}</label>
                <input type="hidden" id='customer_id' name="customer_id"
                    value="{{ request('customer_id') ? request('customer_id') : 'all' }}">
                <select id="customer_id_value" class="custom-select">
                    @foreach($customers as $customer)
                        <option value="{{ $customer['id'] }}"
                            {{ (request('customer_id', 'all') === (string) $customer['id']) ? 'selected' : '' }}>
                            {{ $customer['text'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-4 py-3">
                <a class="btn btn-secondary w-100"
                   href="{{ route('admin.orders.list', ['status' => request('status'), 'delivery_man_id' => request('delivery_man_id')]) }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn-primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
