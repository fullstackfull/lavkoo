<form action="" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasVendorOrderFilter"
        aria-labelledby="offcanvasVendorOrderFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <div class="row g-3 date-filter-wrapper">
                    <div class="col-12">
                        <label for="" class="form-label mb-2">
                            {{ translate('Show_Data') }}
                        </label>
                        <div class="select-wrapper">
                            <select name="" id="" class="form-select date-type-select">
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
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label mb-3">
                    {{ translate('Price_Range') }}
                </label>
                <div class="d-flex align-items-center gap-2">
                    <div class="form-group mb-0">
                        <label for="min_price" class="mb-1 fs-12">{{ translate('Min') }}</label>
                        <input type="number" id="min_price" class="form-control text-center" name="min_price"
                            placeholder="{{ session('currency_symbol') }}{{ '0' }}" value="0">
                    </div>
                    <div class="mt-4">-</div>
                    <div class="form-group mb-0">
                        <label for="max_price" class="mb-1 fs-12">{{ translate('Max') }}</label>
                        <input type="number" id="max_price" class="form-control text-center"
                            name="max_price" value="1000"
                            placeholder="{{ session('currency_symbol') }}1000">
                    </div>
                </div>

                <div id="price_range_slider" class="thumb-white my-4 rounded-10"
                    data-max-value="1000"
                    data-min-value="0">
                    <div class="slider-range"></div>
                    <div class="slider-thumb" id="thumb_min"></div>
                    <div class="slider-thumb" id="thumb_max"></div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Product_Type') }}</label>
                <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_product_type[]"
                                    id="productTypeBoth" value="">
                                <label class="form-check-label fs-12" for="productTypeBoth">
                                    {{ translate('Both') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_product_type[]"
                                    id="productTypePhysical" value="" checked>
                                <label class="form-check-label fs-12" for="productTypePhysical">
                                    {{ translate('Physical') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_product_type[]"
                                    id="productTypeDigital" value="">
                                <label class="form-check-label fs-12" for="productTypeDigital">
                                    {{ translate('Digital') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Product_status') }}</label>
                <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_status[]"
                                    id="productStatusAll" value="">
                                <label class="form-check-label fs-12" for="productStatusAll">
                                    {{ translate('All') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_status[]"
                                    id="productStatusPending" value="">
                                <label class="form-check-label fs-12" for="productStatusPending">
                                    {{ translate('Pending') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_status[]"
                                    id="productStatusApproved" value="" checked>
                                <label class="form-check-label fs-12" for="productStatusApproved">
                                    {{ translate('Approved') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_status[]"
                                    id="productStatusRejected" value="">
                                <label class="form-check-label fs-12" for="productStatusRejected">
                                    {{ translate('Rejected') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Active_Status') }}</label>
                <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_active_status[]"
                                    id="activeStatusBoth" value="">
                                <label class="form-check-label fs-12" for="activeStatusBoth">
                                    {{ translate('Both') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_active_status[]"
                                    id="activeStatusActive" value="" checked>
                                <label class="form-check-label fs-12" for="activeStatusActive">
                                    {{ translate('Active') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_active_status[]"
                                    id="activeStatusDisabled" value="">
                                <label class="form-check-label fs-12" for="activeStatusDisabled">
                                    {{ translate('Disabled') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                <label for="" class="form-label">{{ translate('Feature_Status') }}</label>
                <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
                    <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_feature_status[]"
                                    id="featureStatusBoth" value="">
                                <label class="form-check-label fs-12" for="featureStatusBoth">
                                    {{ translate('Both') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_feature_status[]"
                                    id="featureStatusFeatured" value="" checked>
                                <label class="form-check-label fs-12" for="featureStatusFeatured">
                                    {{ translate('Featured') }}
                                </label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-2">
                                <input class="form-check-input radio--input mt-0" type="radio" name="filter_feature_status[]"
                                    id="featureStatusUnfeatured" value="">
                                <label class="form-check-label fs-12" for="featureStatusUnfeatured">
                                    {{ translate('Unfeatured') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                <a class="btn btn-secondary w-100" href="{{ url()->current() }}">
                    {{ translate('Reset_Filter') }}
                </a>
                <button type="submit" class="btn btn-primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
