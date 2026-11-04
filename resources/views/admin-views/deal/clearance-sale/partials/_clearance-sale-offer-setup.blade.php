<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-center">
            <div class="col-md-8 col-xl-9">
                <h2>{{ translate('active_clearance_sale_offer') }} ?</h2>
                <p class="m-0">
                    {{ translate('show_your_offer_in_the_store_details_page_in_customer_website_and_apps') }}
                </p>
            </div>
            <div class="col-md-4 col-xl-3">
                <div class="d-flex justify-content-between align-items-center border rounded px-3 py-2">
                    <h4 class="mb-0 fw-normal">{{ translate('Active_Offer') }}</h4>

                    <form action="{{ route('admin.deal.clearance-sale.status-update') }}" data-from="clearance-sale"
                          method="post" id="clearance-sale-status-form" class="no-reload-form">
                        @csrf
                        <label class="switcher" for="clearance-sale-status">
                            <input
                                class="switcher_input custom-modal-plugin"
                                type="checkbox" value="1" name="status"
                                id="clearance-sale-status"
                                {{  $clearanceConfig?->is_active == 1 ? 'checked':'' }}
                                data-modal-type="input-change-form"
                                data-modal-form="#clearance-sale-status-form"
                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-on.png') }}"
                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/clearance-sale-off.png') }}"
                                data-on-title="{{ translate('Are_you_sure_to_turn_on_the_Clearance_Sale').'?'}}"
                                data-off-title="{{ translate('Are_you_sure_to_turn_off_the_Clearance_Sale').'?'}}"
                                data-on-message="<p>{{ translate('when_you_turn_on_the_clearance_sale_customers_can_get_the_clearance_offer_of_your_products_when_they_want_to_purchase_from_your_store')}}</p>"
                                data-off-message="<p>{{ translate('if_you_turn_off_the_clearance_sale_customers_will_not_get_the_clearance_offer_of_your_products_when_they_want_to_purchase_from_your_store.')}}</p>"
                                data-on-button-text="{{ translate('turn_on') }}"
                                data-off-button-text="{{ translate('turn_off') }}">
                            <span class="switcher_control"></span>
                        </label>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@include("admin-views.deal.clearance-sale.partials._clearance-sale-meta-setup")

<div class="card mb-3">
    <div class="card-body">
        <form action="{{ route('admin.deal.clearance-sale.update-config') }}" method="POST">
            @csrf
            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                <h3 class="">{{ translate('Setup_Offer_Logics') }}</h3>

                @if($clearanceConfig)
                    <div class="text-primary fw-bold d-flex align-items-center gap-2 cursor-pointer" title="{{ translate('Add') }}"
                         data-bs-toggle="offcanvas" href="#clearanceSaleMetaSetupOffcanvas">
                        {{ translate('Meta Data Setup') }}
                        <i class="fi fi-rr-arrow-right"></i>
                    </div>
                @endif
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <input type="hidden" name="setup_by" value="admin">
                    <label class="form-label fw-medium">{{ translate('Duration') }}<span class="text-danger">*</span> </label>
                    <div class="position-relative">
                        <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                        @if($clearanceConfig?->duration_start_date && $clearanceConfig?->duration_end_date)
                            <input type="text" class="js-daterangepicker-times-sec form-control line-1" name="clearance_sale_duration"
                                   value="{{ $clearanceConfig?->duration_start_date?->format('m/d/Y h:i:s A') }} - {{ $clearanceConfig?->duration_end_date?->format('m/d/Y h:i:s A') }}">
                        @else
                            <input type="text" class="js-daterangepicker-times-sec form-control line-1" name="clearance_sale_duration">
                        @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">{{ translate('Discount_Type') }}<span class="text-danger">*</span></label>
                    <div class="form-control d-flex gap-2 h-auto">
                        <div class="form-check d-flex gap-2 flex-grow-1">
                            <input type="radio" class="form-check-input radio--input clearance-sale-discount" value="flat" name="discount_type"
                                   id="flat" {{ is_null($clearanceConfig) || $clearanceConfig?->discount_type == 'flat' ? 'checked' : '' }}>
                            <label class="form-check-label" for="flat">
                                {{ translate('Flat_Discount') }}
                            </label>
                        </div>
                        <div class="form-check d-flex gap-2 flex-grow-1">
                            <input type="radio" class="form-check-input radio--input clearance-sale-discount" value="product_wise" name="discount_type"
                                   id="product" {{ $clearanceConfig?->discount_type == 'product_wise' ? 'checked' : '' }}>
                            <label class="form-check-label" for="product">
                                {{ translate('Product_wise_discount') }}
                            </label>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 clearance-sale-discount-flat {{ $clearanceConfig?->discount_type == 'product_wise' ? 'd--none' : '' }}">
                    <label class="form-label fw-medium">
                        {{ translate('Discount_Amount') }} (%)<span class="text-danger">*</span>
                    </label>

                    <div class="input-group">
                        <input type="text" class="form-control" max="100" pattern="[0-9]*"
                               name="discount_amount" placeholder="{{ translate('ex') }} : {{ '10' }}"
                               value="{{ $clearanceConfig?->discount_amount ?? 0 }}">
                        <div class="input-group-append h-100">
                            <span class="input-group-text fw-bolder min-h-40" id="basic-addon2">{{ '%' }}</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label
                        class="form-label fw-medium">
                        {{ translate('Offer_Active_Time') }}<span class="text-danger">*</span>
                    </label>
                    <div class="form-control d-flex gap-2 h-auto">
                        <div class="form-check d-flex gap-2 flex-grow-1">
                            <input type="radio" class="form-check-input radio--input offer-active-time" value="always" name="offer_active_time"
                                   id="always" {{ is_null($clearanceConfig) || $clearanceConfig?->offer_active_time == 'always' ? 'checked' : '' }}>
                            <label class="form-check-label" for="always">{{ translate('Always') }}</label>
                        </div>
                        <div class="form-check d-flex gap-2 flex-grow-1">
                            <input type="radio" class="form-check-input radio--input offer-active-time" value="specific_time" name="offer_active_time"
                                   id="specificTime" {{ $clearanceConfig?->offer_active_time == 'specific_time' ? 'checked' : '' }}>
                            <label class="form-check-label" for="specificTime">
                                {{ translate('specific_time_in_a_day') }}
                            </label>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 offer-active-time-section {{ $clearanceConfig?->offer_active_time != 'specific_time' ? 'd--none' : '' }}">
                    <label class="form-label fw-medium">
                        {{ translate('Start_&_End_Time') }}
                    </label>
                    <div class="position-relative">
                        <span class="fi fi-sr-calendar icon-absolute-on-right"></span>
                        @if($clearanceConfig?->offer_active_range_start && $clearanceConfig?->offer_active_range_end)
                            <input type="text" class="js-daterangepicker-time-only form-control" name="offer_active_range"
                                   value="{{ $clearanceConfig?->offer_active_range_start?->format('h:i:s A') }} - {{ $clearanceConfig?->offer_active_range_end?->format('h:i:s A') }}">
                        @else
                            <input type="text" class="js-daterangepicker-time-only form-control" name="offer_active_range">
                        @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-check d-flex gap-2 border rounded px-3 py-2 min-h-40">
                            <span class="user-select-none form-check-label flex-grow-1">
                                {{ translate('also_show_in_home_page') }}
                                <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top"
                                      aria-label="{{ translate('enable_this_option_to_display_clearance_products_with_other_stores_product') }}"
                                      data-bs-title="{{ translate('enable_this_option_to_display_clearance_products_with_other_stores_product') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </span>
                        <div>
                            <input type="checkbox" name="show_in_homepage" class="form-check-input checkbox--input" value="1"
                            {{ $clearanceConfig?->show_in_homepage == 1 ? 'checked' : '' }}>
                        </div>
                    </label>
                </div>
                <div class="col-md-6 ms-auto">
                    <label class="form-label d-none d-md-block">&nbsp;</label>
                    <div class="d-flex fllex-wrap justify-content-end gap-3">
                        <a class="btn btn-secondary" href="{{ route('admin.deal.clearance-sale.index') }}">
                            {{ translate('Reset') }}
                        </a>
                        @if($clearanceConfig)
                            <button class="btn btn-primary" type="submit">{{ translate('Update') }}</button>
                        @else
                            <button class="btn btn-primary" type="submit">{{ translate('Save') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
