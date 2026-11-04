<form action="{{ route('admin.customer.view', $customer['id']) }}" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasCustomerFilter"
         aria-labelledby="offcanvasCustomerFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;"
                    data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <div class="row g-4">
                    <div class="col-12">
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
                <label for="" class="form-label">
                    {{ translate('Order_status') }}
                </label>
                <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('pending', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusPending" value="pending">
                                <label class="form-check-label fs-12" for="orderStatusPending">
                                    {{ translate('Pending') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('confirmed', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusConfirmed" value="confirmed">
                                <label class="form-check-label fs-12" for="orderStatusConfirmed">
                                    {{ translate('Confirmed') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('processing', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusPackaging" value="processing">
                                <label class="form-check-label fs-12" for="orderStatusPackaging">
                                    {{ translate('Packaging') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('out_for_delivery', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusOutForDelivery" value="out_for_delivery">
                                <label class="form-check-label fs-12" for="orderStatusOutForDelivery">
                                    {{ translate('Out_For_Delivery') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('delivered', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusPackaging" value="delivered">
                                <label class="form-check-label fs-12" for="orderStatusDelivered">
                                    {{ translate('Delivered') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('canceled', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusOutForDelivery" value="canceled">
                                <label class="form-check-label fs-12" for="orderStatusCanceled">
                                    {{ translate('Canceled') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('returned', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusReturned" value="returned">
                                <label class="form-check-label fs-12" for="orderStatusReturned">
                                    {{ translate('Returned') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                       name="order_current_status[]"
                                       {{ in_array('failed', $orderStatus) ? 'checked' : '' }}
                                       id="orderStatusFailedToDeliver" value="failed">
                                <label class="form-check-label fs-12" for="orderStatusFailedToDeliver">
                                    {{ translate('Failed_to_Deliver') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                <a class="btn btn-secondary w-100" href="{{ route('admin.customer.view', $customer['id']) }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn-primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
