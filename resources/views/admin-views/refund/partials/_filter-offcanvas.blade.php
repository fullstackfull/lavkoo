<form action="{{ route('admin.refund-section.refund.list',['status' => request('status')])}}" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="PendingRefundRequestFilter"
        aria-labelledby="PendingRefundRequestFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <div class="select-wrapper">
                    <select name="type" id="" class="form-select">
                        <option
                            value="" {{ request('type') == 'all' ?'selected':''}}>{{ translate('all') }}</option>
                        <option
                            value="admin" {{ request('type')== 'admin' ? 'selected':''}}>{{ translate('inhouse_Requests') }}</option>
                        <option
                            value="seller" {{ request('type') == 'seller' ? 'selected':''}}>{{ translate('vendor_Requests') }}</option>
                    </select>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <h4 class="mb-3">{{ translate('Select_Date_Range') }}</h4>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div>
                            <label for="" class="form-label fs-12 mb-2">{{ translate('From') }}</label>
                            <input type="date" name="from_date" id="start-date-time" value="{{ requestString('from_date') }}" class="form-control" title="{{translate('from_date')}}">
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div>
                            <label for="" class="form-label fs-12 mb-2">{{ translate('To') }}</label>
                            <input type="date" name="to_date" id="end-date-time" value="{{ requestString('to_date') }}" class="form-control" title="{{translate('to_date')}}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                <a href="{{ route('admin.refund-section.refund.list',['status' => request('status')])}}" class="btn btn-secondary w-100">{{ translate('Clear_Filter') }}</a>
                <button type="submit" class="btn btn-primary w-100">{{ translate('Apply') }}</button>
            </div>
        </div>
    </div>
</form>
