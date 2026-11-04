<form action="{{ url()->current() }}" method="GET">
    <div class="offcanvas-sidebar" id="offcanvasRestockFilter">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">

                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                    <div class="row g-2 date-filter-wrapper">
                        <div class="col-12">
                            <label for="" class="form-label mb-2">
                                {{ translate('Show_Data') }}
                            </label>
                            <div class="select-wrapper">
                                <select name="" id="" class="form-control date-type-select">
                                    <option value="">{{ translate('All_Time') }}</option>
                                    <option value="custom" selected>{{ translate('Custom_date') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6 custom-date-div d--none">
                            <div>
                                <label for="start-date-time" class="form-label fs-12 mb-2">{{ translate('Start_Date') }}</label>
                                <input type="date" name="from" id="start-date-time" value="" class="form-control"
                                    title="{{translate('from_date')}}">
                            </div>
                        </div>
                        <div class="col-sm-6 custom-date-div d--none">
                            <div>
                                <label for="end-date-time" class="form-label fs-12 mb-2">{{ translate('End_Date') }}</label>
                                <input type="date" name="to" id="end-date-time" value="" class="form-control"
                                    title="{{translate('to_date')}}">
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup d-flex gap-3 bg-white px-3 px-sm-4 py-3">
                <a class="btn btn-secondary w-100" href="#">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn--primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
