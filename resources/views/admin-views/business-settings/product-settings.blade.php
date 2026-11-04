@extends('layouts.admin.app')

@section('title', translate('product_settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form data-k-save-bar action="{{ route('admin.business-settings.product-settings.index') }}" method="post" enctype="multipart/form-data">
            @csrf

            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3>{{ translate('General_Setup') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('Manage_digital_products_and_brands_visibility_preferences_for_the_shops') }}
                        </p>
                    </div>
                    <div class="bg-section-sm">
                        <div class="row g-4">
                            <div class="col-xl-6 col-md-6">
                                <div
                                    class="border rounded p-3 user-select-none h-100 bg-white d-flex flex-column justify-content-between gap-2">
                                   <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                                {{ translate('Sell_Digital_Product') }}
                                            </div>
                                            <p class="mb-0 fs-12">
                                                {{ translate('if_this_option_is_enabled_vendors_can_sell_digital_products_(such_as_software,_ebooks,_activation_keys,_jpg,_png_etc.)_in_their_shops.') }}
                                            </p>
                                        </div>
                                        <label class="switcher" for="digital-product">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="digital_product"
                                                id="digital-product"
                                                {{ $digitalProduct && $digitalProduct['value'] ? 'checked' : '' }}
                                                data-modal-type="input-change"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/digital-product-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/digital-product-off.png') }}"
                                                data-on-title = "{{ translate('want_to_Turn_ON_Digital_Product') . '?' }}"
                                                data-off-title = "{{ translate('want_to_Turn_OFF_Digital_Product') . '?' }}"
                                                data-on-message = "<p>{{ translate('if_enabled_vendors_can_sell_digital_products_in_their_shops') }}</p>"
                                                data-off-message = "<p>{{ translate('if_disabled_vendors_can_not_sell_digital_products_in_their_shops') }}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                   </div>
                                    <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-bulb text-info fs-16"></i>
                                       <span>
                                            {{ translate('to_add_new_digital_product_for_your_shop_visit') }}
                                            <a href="{{ route('admin.products.add') }}" target="_blank" class="text-decoration-underline fw-semibold">{{ translate('Add_New_Product') }}</a>
                                            {{ translate('page') }}.
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <label
                                    class="border rounded p-3 user-select-none h-100 bg-white d-flex flex-column justify-content-between gap-2">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <span>
                                            <div class="fw-medium text-dark fs-14 mb-1  text-capitalize">
                                                {{ translate('show_brand') }}
                                            </div>
                                            <p class="mb-0 fs-12">
                                                {{ translate('if_enabled_customers_can_see_brands_and_they_can_browse_and_search_for_products_from_each_brand_inside_any_shop') }}.
                                            </p>
                                        </span>
                                        <label class="switcher" for="product-brand">
                                            <input
                                                class="switcher_input custom-modal-plugin"
                                                type="checkbox" value="1" name="product_brand"
                                                id="product-brand"
                                                {{ $brand && $brand['value'] ? 'checked' : '' }}
                                                data-modal-type="input-change"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-brand-on.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-brand-off.png') }}"
                                                data-on-title = "{{ translate('Do_You_Want_to_Show_Brands') . '?' }}"
                                                data-off-title = "{{ translate('Do_You_Want_to_Hide_Brands') . '?' }}"
                                                data-on-message = "<p>{{ translate('If_enabled_customers_can_see_brands_and_they_can_browse_and_search_for_products_from_each_brand_inside_any_shop.') }}</p>"
                                                data-off-message = "<p>{{ translate('if_disabled_brand_section_will_be_hidden_from_the_customer_app_and_website') }}</p>"
                                                data-on-button-text="{{ translate('turn_on') }}"
                                                data-off-button-text="{{ translate('turn_off') }}">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                            <i class="fi fi-sr-bulb text-info fs-16"></i>
                                            <span>
                                                {{ translate('you_can_manage_all_brands_from_this_page') }}
                                                <a href="{{ route('admin.brand.list') }}" target="_blank" class="text-decoration-underline fw-semibold">{{ translate('Brand_List') }}</a>.
                                            </span>
                                        </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="text-capitalize">{{ translate('need_Product_Approval') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('choose_in_which_cases_need_approval_for_the_vendor_products') }}
                        </p>
                    </div>
                    @php($newProductApproval = getWebConfig('new_product_approval'))
                    @php($productWiseShippingCostApproval = getWebConfig('product_wise_shipping_cost_approval'))
                    <div class="bg-section-sm">
                        <div class="bg-white p-3 rounded border">
                            <div class="row g-4">
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-check d-flex gap-10">
                                        <input class="form-check-input checkbox--input checkbox--input-lg" type="checkbox" name="new_product_approval"
                                            id="new-product-approval" value="1" {{$newProductApproval == 1 ? 'checked': ''}}>
                                        <div class="flex-grow-1">
                                            <label for="new-product-approval"
                                                class="form-label text-dark fw-semibold mb-1 user-select-none">
                                                {{ translate('new_Product') }}
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('if_you_checked') }}
                                                <span class="fw-semibold">{{ translate('New_Product') }}</span>,
                                                {{ translate('vendors_need_admin_approval_to_add_new_product_to_their_shop') }}.
                                            </p>
                                            <div
                                                class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mt-2">
                                                <i class="fi fi-sr-bulb text-info fs-16"></i>
                                                <span>
                                                    {{ translate('you_can_see_all_the_request_from') }}
                                                    <a href="{{route('admin.products.list',['vendor', 'request_status'=>'0'])}}" target="_blank" class="text-decoration-underline fw-semibold">{{ translate('new_products_request') }}</a>
                                                    {{ translate('page') }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-xl-6 col-md-6">
                                    <div class="form-check d-flex gap-10">
                                        <input class="form-check-input checkbox--input checkbox--input-lg" name="product_wise_shipping_cost_approval" type="checkbox" id="product-wise-shipping" value="1" {{$productWiseShippingCostApproval == 1 ? 'checked' : '' }}>
                                        <div class="flex-grow-1">
                                            <label for="product-wise-shipping" class="form-label text-dark fw-semibold mb-1 user-select-none d-flex gap-1 align-items-center">
                                                {{ translate('Update_Product_Wise_Shipping_Cost') }}

                                                <span class="tooltip-icon d-flex" data-bs-toggle="tooltip"
                                                      data-bs-placement="right" data-bs-title="{{ translate('vendors_will_need_approval_to_update_shipping_costs_for_each_product.') }}">
                                                            <i class="fi fi-sr-triangle-warning text-warning"></i>
                                                </span>
                                            </label>
                                            <p class="fs-12 mb-0">
                                                {{ translate('vendors_need_approval_from_the_admin_before_their_updated_shipping_cost_is_applied.') }}
                                            </p>
                                            <div
                                                class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center mt-2">
                                                <i class="fi fi-sr-bulb text-info fs-16"></i>
                                                <span>
                                                    {{ translate('shipping_cost_updates_require_admin_approval_before_going_live') }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="text-capitalize">{{ translate('Re-order_Level') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('choose_in_which_cases_need_approval_for_the_vendor_products') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="row g-4 align-items-center">
                            <div class="col">
                                <h4 class=" text-capitalize">
                                    {{ translate('Re-order_Level_Amount') }}
                                </h4>
                                <p>{{ translate('Set_the_stock_limit_for_the_reorder_level_vendors_can_see_all_products_that_need_to_be_restocked_in_a_section_when_they_reach_this_re-order_level') }}</p>
                            </div>
                            <div class="col">
                                <div class="form-group">
                                    <input type="number" class="form-control" name="stock_limit"
                                           id="stock_limit"
                                           placeholder="{{ translate('ex') . ': ' . '100' }}"
                                           value="{{ $stockLimit->value }}" min="0">
                                    <p class="mt-1 mb-0">
                                        {{ translate('Set_the_stock_limit_for_the_reorder_level.') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Product page signals: what the storefront shows above the fold besides the price. --}}
            <div class="card mt-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="text-capitalize">{{ translate('product_page_signals') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('trust_and_urgency_elements_shown_on_the_product_page_above_the_add_to_cart_button') }}
                        </p>
                    </div>
                    @php($liveViewersStatus = getWebConfig(name: 'product_live_viewers_status'))
                    @php($liveViewersMin = getWebConfig(name: 'product_live_viewers_min'))
                    @php($liveViewersMax = getWebConfig(name: 'product_live_viewers_max'))
                    @php($authenticityStatus = getWebConfig(name: 'product_authenticity_badge_status'))
                    @php($authenticityText = getWebConfig(name: 'product_authenticity_badge_text'))
                    <div class="bg-section-sm">
                        <div class="row g-4">
                            <div class="col-xl-6 col-md-6">
                                <div class="border rounded p-3 h-100 bg-white d-flex flex-column gap-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                                {{ translate('people_viewing_this_product_now') }}
                                            </div>
                                            <p class="mb-0 fs-12">
                                                {{ translate('shows_a_live_viewers_line_on_the_product_page_the_number_is_generated_within_the_range_below_and_is_not_real_traffic') }}
                                            </p>
                                        </div>
                                        <label class="switcher" for="product-live-viewers">
                                            <input class="switcher_input" type="checkbox" value="1"
                                                   name="product_live_viewers_status" id="product-live-viewers"
                                                   {{ $liveViewersStatus ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label class="form-label fs-12" for="product-live-viewers-min">{{ translate('minimum') }}</label>
                                            <input type="number" min="2" class="form-control" id="product-live-viewers-min"
                                                   name="product_live_viewers_min" value="{{ $liveViewersMin ?: 8 }}">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label fs-12" for="product-live-viewers-max">{{ translate('maximum') }}</label>
                                            <input type="number" min="3" class="form-control" id="product-live-viewers-max"
                                                   name="product_live_viewers_max" value="{{ $liveViewersMax ?: 60 }}">
                                        </div>
                                    </div>
                                    <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-bulb text-info fs-16"></i>
                                        <span>{{ translate('the_number_stays_steady_for_a_few_minutes_per_product_instead_of_changing_on_every_refresh') }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <div class="border rounded p-3 h-100 bg-white d-flex flex-column gap-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                                {{ translate('frequently_bought_together') }}
                                            </div>
                                            <p class="mb-0 fs-12">
                                                {{ translate('a_panel_of_companion_products_under_the_buy_buttons_pick_them_on_each_product_or_let_the_store_learn_them_from_orders') }}
                                            </p>
                                        </div>
                                        <label class="switcher" for="bought-together-status">
                                            <input class="switcher_input" type="checkbox" value="1"
                                                   name="bought_together_status" id="bought-together-status"
                                                   {{ getWebConfig(name: 'bought_together_status') ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div class="row g-3 align-items-end">
                                        <div class="col-6">
                                            <label class="form-label fs-12" for="bought-together-limit">{{ translate('max_items') }}</label>
                                            <input type="number" min="2" max="12" class="form-control" id="bought-together-limit"
                                                   name="bought_together_limit" value="{{ getWebConfig(name: 'bought_together_limit') ?: 6 }}">
                                        </div>
                                        <div class="col-6">
                                            <div class="form-check d-flex gap-2">
                                                <input class="form-check-input checkbox--input" type="checkbox" value="1"
                                                       name="bought_together_auto_fill" id="bought-together-auto"
                                                       {{ getWebConfig(name: 'bought_together_auto_fill') ? 'checked' : '' }}>
                                                <label class="form-label mb-0 fs-12" for="bought-together-auto">
                                                    {{ translate('fill_the_rest_from_what_customers_buy_together') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                        <i class="fi fi-sr-bulb text-info fs-16"></i>
                                        <span>
                                            {{ translate('pick_the_companions_on_a_product_from') }}
                                            <a href="{{ route('admin.products.list', ['in-house']) }}" target="_blank" class="text-decoration-underline fw-semibold">{{ translate('product_List') }}</a>
                                            — {{ translate('frequently_bought_together') }}.
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <div class="border rounded p-3 h-100 bg-white d-flex flex-column gap-3">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <div class="fw-medium text-dark fs-14 mb-1 text-capitalize">
                                                {{ translate('authenticity_badge') }}
                                            </div>
                                            <p class="mb-0 fs-12">
                                                {{ translate('a_short_trust_badge_beside_the_product_title_such_as_100_percent_authentic') }}
                                            </p>
                                        </div>
                                        <label class="switcher" for="product-authenticity-badge">
                                            <input class="switcher_input" type="checkbox" value="1"
                                                   name="product_authenticity_badge_status" id="product-authenticity-badge"
                                                   {{ $authenticityStatus ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="form-label fs-12" for="product-authenticity-text">{{ translate('badge_text') }}</label>
                                        <input type="text" class="form-control" id="product-authenticity-text"
                                               name="product_authenticity_badge_text" maxlength="40"
                                               value="{{ $authenticityText }}"
                                               placeholder="{{ translate('100_percent_authentic') }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                        {{ translate('Reset') }}
                    </button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4 text-capitalize">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('Save_Information') }}
                    </button>
                </div>
            </div>
            <x-k.save-bar />
        </form>
    </div>
    @include("layouts.admin.partials.offcanvas._product-settings")
@endsection
