<form action="{{ route('vendor.refund.index',['status' => request('status')])}}" method="GET">
    <div class="offcanvas-sidebar" id="PendingRefundRequestFilter">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>
        <div class="offcanvas-content bg-white shadow d-flex flex-column">

            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">

                <div class="p-12 p-sm-20 bg-section rounded mb-3 overflow-wrap-anywhere">
                    <h4 class="mb-3">{{ translate('Select_Date_Range') }}</h4>

                    <div class="row">
                        <div class="col-sm-6">
                            <label class="form-label fs-12 mb-2">{{ translate('From') }}</label>
                            <input type="date"
                                   name="from_date"
                                   id="start-date-time"
                                   class="form-control"
                                   value="{{ requestString('from_date') }}"
                                   title="{{ translate('from_date') }}">
                        </div>

                        <div class="col-sm-6">
                            <label class="form-label fs-12 mb-2">{{ translate('To') }}</label>
                            <input type="date"
                                   name="to_date"
                                   id="end-date-time"
                                   class="form-control"
                                   value="{{ requestString('to_date') }}"
                                   title="{{ translate('to_date') }}">
                        </div>
                    </div>

                </div>

            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
                <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                    <a href="{{ route('vendor.refund.index',['status' => request('status')])}}" class="btn btn-secondary w-100">
                        {{ translate('Clear_Filter') }}
                    </a>
                    <button type="submit" class="btn btn--primary w-100">
                        {{ translate('Apply') }}
                    </button>
                </div>
            </div>

        </div>
    </div>
</form>
