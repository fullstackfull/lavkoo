<form action="" method="get">
    <div class="offcanvas-sidebar" id="couponFilterOffcanvas">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">

            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;"
                    data-dismiss="offcanvas" aria-label="Close">
                    <i class="fi fi-rr-cross"></i>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                    <div class="row g-2 date-filter-wrapper">
                        <div class="col-12">
                            <label for="" class="form-label text-dark mb-2">
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
                                <label for="start-date-time" class="form-label text-dark fs-12 mb-2">{{ translate('Start_Date') }}</label>
                                <input type="date" name="from" id="start-date-time" value="" class="form-control"
                                    title="{{translate('from_date')}}">
                            </div>
                        </div>
                        <div class="col-sm-6 custom-date-div d--none">
                            <div>
                                <label for="end-date-time" class="form-label text-dark fs-12 mb-2">{{ translate('End_Date') }}</label>
                                <input type="date" name="to" id="end-date-time" value="" class="form-control"
                                    title="{{translate('to_date')}}">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="free-delivery-collapse">
                    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
                        <label for="" class="form-label text-dark">{{ translate('Coupons_Type') }}</label>
                        <div class="bg-white rounded p-3">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <div class="d-flex gap-2">
                                        <input class="flex-shrink-0" type="checkbox" name="coupon_type[]"
                                            id="couponTypeDiscountOnPurchase" value="">
                                        <label class="form-check-label fs-12" for="couponTypeDiscountOnPurchase">
                                            {{ translate('Discount_on_Purchase') }}
                                        </label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="d-flex gap-2">
                                        <input class="flex-shrink-0 free-del-input" type="checkbox" name="coupon_type[]"
                                            id="couponTypeFreeDelivery" value="">
                                        <label class="form-check-label fs-12" for="couponTypeFreeDelivery">
                                            {{ translate('Free_Delivery') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere discount-type-div">
                        <label for="" class="form-label text-dark">{{ translate('Discount_Type') }}</label>
                        <div class="bg-white rounded p-3">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <div class="d-flex gap-2">
                                        <input class="flex-shrink-0" type="checkbox" name="discount_type[]"
                                            id="discountTypePercentage" value="">
                                        <label class="form-check-label fs-12" for="discountTypePercentage">
                                            {{ translate('Percentage') }}
                                        </label>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="d-flex gap-2">
                                        <input class="flex-shrink-0" type="checkbox" name="discount_type[]"
                                            id="discountTypeFreeFlat" value="">
                                        <label class="form-check-label fs-12" for="discountTypeFreeFlat">
                                            {{ translate('Flat_discount') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup">
                <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                    <a href="{{ route('vendor.coupon.index') }}" class="btn btn-secondary w-100">
                        {{ translate('Reset_Filter') }}
                    </a>

                    <button type="submit" class="btn btn--primary w-100">
                        {{ translate('Apply') }}
                    </button>
                </div>
            </div>

        </div>
    </div>
</form>
