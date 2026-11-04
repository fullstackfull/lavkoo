@extends('layouts.admin.app')

@section('title', translate('Shipping_Method'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('Business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form action="{{ route('admin.business-settings.shipping-method.update-shipping-responsibility') }}"
              method="post">
            @csrf
            @php($shippingMethod = getWebConfig('shipping_method'))
            <div class="card mb-3">
                <div class="card-body">
                    <div class="mb-3 mb-sm-20">
                        <h3 class="text-capitalize">{{ translate('Shipping_Responsibility') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('set_up_the_shipping_responsibility_and_associated_charges_for_order_delivery.') }}
                        </p>
                    </div>
                    <div class="p-12 p-sm-20 bg-section rounded">
                        <div class="form-group mb-20">
                            <div class="bg-white p-3 rounded">
                                <div class="row g-4">
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input type="hidden" name="id" value="{{ isset($shippingMethod->id) ? $shippingMethod->id : null }}">
                                            <input
                                                class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                type="radio" value="inhouse_shipping" name="shipping_method"
                                                id="inhouse-shipping"
                                                {{ $shippingMethod == 'inhouse_shipping' ? 'checked' : '' }}
                                                data-modal-type="input-change"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/inhouse-shipping.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/inhouse-shipping.png') }}"
                                                data-on-title="{{ translate('change_the_shipping_responsibility_to_Inhouse') . '?' }}"
                                                data-off-title="{{ translate('change_the_shipping_responsibility_to_Inhouse') . '?' }}"
                                                data-on-message="<p>{{ translate('admin_will_handle_the_shipping_responsibilities_when_you_choose_inhouse_shipping_method') . '.' }}</p>"
                                                data-off-message="<p>{{ translate('admin_will_handle_the_shipping_responsibilities_when_you_choose_inhouse_shipping_method') . '.' }}</p>">
                                            <div class="flex-grow-1">
                                                <label for="inhouse-shipping"
                                                       class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                    {{ translate('inhouse_shipping') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('admin_will_handle_the_shipping_responsibilities_when_you_choose_inhouse_shipping_method.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-6 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input
                                                class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                type="radio" value="sellerwise_shipping" name="shipping_method"
                                                id="seller-wise-shipping"
                                                {{ $shippingMethod == 'sellerwise_shipping' ? 'checked' : '' }}
                                                data-modal-type="input-change"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-wise-shipping.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/vendor-wise-shipping.png') }}"
                                                data-on-title="{{ translate('change_the_shipping_responsibility_to_Vendor_Wise') . '?' }}"
                                                data-off-title="{{ translate('change_the_shipping_responsibility_to_Vendor_Wise') . '?' }}"
                                                data-on-message="<p>{{ translate('vendors_will_handle_the_shipping_responsibilities_when_you_choose_vendor_wise_shipping_method') . '.' }}</p>"
                                                data-off-message="<p>{{ translate('vendors_will_handle_the_shipping_responsibilities_when_you_choose_vendor_wise_shipping_method') . '.' }}</p>">
                                            <div class="flex-grow-1">
                                                <label for="seller-wise-shipping"
                                                       class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                    {{ translate('vendor_wise_shipping') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('vendors_will_handle_the_shipping_responsibilities_when_you_choose_vendor_wise_shipping_method.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end flex-wrap gap-3">
                            <button type="reset" class="btn btn-secondary px-4 w-120">
                                {{ translate('Reset') }}
                            </button>
                            <button type="submit" class="btn btn-primary px-4 w-120">
                                {{ translate('Submit') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-4">
                    <h3 class="text-capitalize">{{ translate('Shipping_Methods') }}</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('vendors_will_handle_the_shipping_responsibilities_when_you_choose_vendor_wise_shipping_method.') }}
                    </p>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded">
                    <form action="{{ route('admin.business-settings.shipping-type.index') }}" method="post" id="shipping-type-form">
                        @csrf
                        <div class="form-group">
                            <div class="bg-white p-3 rounded">
                                @php($shippingType = isset($adminShipping) ? $adminShipping['shipping_type'] : 'order_wise')
                                <div class="row g-4">
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input
                                                class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                type="radio" value="order_wise" name="shippingType"
                                                id="order_wise" {{ $shippingType == 'order_wise' ? 'checked' : '' }}

                                                data-modal-type="input-change-form"
                                                data-modal-form="#shipping-type-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-on-title="{{ translate('want_to_turn_on_this_order_wise_shipping_method') . '?' }}"
                                                data-off-title="{{ translate('want_to_turn_on_this_order_wise_shipping_method') . '?' }}"
                                                data-on-message="<p>{{ translate('turning_on_the_option_will_make_this_shipping_option_available_for_selection_during_the_shipping_method_selection_process') . '.' }}</p>"
                                                data-off-message="<p>{{ translate('turning_off_the_option_will_disable_this_shipping_option_and_will_hide_it_for_selection_during_the_shipping_method_selection_process') . '.' }}</p>">
                                            <div class="flex-grow-1">
                                                <label for="order_wise" class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                    {{ translate('order_wise') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('need_to_set_the_shipping_cost_based_on_order_amount_and_shipping_duration.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input
                                                class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                type="radio" value="category_wise" name="shippingType"
                                                id="category_wise" {{ $shippingType == 'category_wise' ? 'checked' : '' }}

                                                data-modal-type="input-change-form"
                                                data-modal-form="#shipping-type-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-on-title="{{ translate('Change_Shipping_Method_to_Category_Wise') . '?' }}"
                                                data-off-title="{{ translate('Change_Shipping_Method_to_Category_Wise') . '?' }}"
                                                data-on-message="<p>{{ translate('When_change_shipping_method_to_category_wise_then_you_need_to_set_individual_shipping_cost_in_every_category') . '.' }}</p>"
                                                data-off-message="<p>{{ translate('When_change_shipping_method_to_category_wise_then_you_need_to_set_individual_shipping_cost_in_every_category') . '.' }}</p>">
                                            <div class="flex-grow-1">
                                                <label for="category_wise" class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                    {{ translate('category_wise') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('need_to_set_the_shipping_cost_for_each_category_to_make_sure_the_correct_amount_is_charged.') }}
                                                </p>
                                            </div>
                                        </div>

                                    </div>
                                    <div class="col-xl-4 col-md-6">
                                        <div class="form-check d-flex gap-3">
                                            <input
                                                class="form-check-input radio--input radio--input_lg custom-modal-plugin"
                                                type="radio" value="product_wise" name="shippingType"
                                                id="product_wise" {{ $shippingType == 'product_wise' ? 'checked' : '' }}

                                                data-modal-type="input-change-form"
                                                data-modal-form="#shipping-type-form"
                                                data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-wise-shipping.png') }}"
                                                data-on-title="{{ translate('Change_Shipping_Method_to_Product_Wise') . '?' }}"
                                                data-off-title="{{ translate('Change_Shipping_Method_to_Product_Wise') . '?' }}"
                                                data-on-message="<p>{{ translate('When_change_shipping_method_to_product_wise_then_you_need_to_set_individual_shipping_cost_in_every_product') . '.' }}</p>"
                                                data-off-message="<p>{{ translate('When_change_shipping_method_to_product_wise_then_you_need_to_set_individual_shipping_cost_in_every_product') . '.' }}</p>">
                                            <div class="flex-grow-1">
                                                <label for="product_wise" class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                    {{ translate('product_wise') }}
                                                </label>
                                                <p class="fs-12 mb-3">
                                                    {{ translate('need_to_set__the_shipping_cost_for_each_product_to_enable_individual_shipping_fee_calculation.') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body d-flex flex-column gap-20">
                <div id="order_wise_shipping">
                    <div class="d-flex flex-column gap-20">
                        <div class="card card-sm shadow-2">
                            <div class="card-body">
                                <div class="mb-4">
                                    <h3 class="text-capitalize">
                                        {{ translate('add_order_wise_shipping_method') }}
                                    </h3>
                                    <p class="mb-0 fs-12">
                                        {{ translate('set_up_shipping_fee_calculation_based_on_order_and_shipping_time.') }}
                                    </p>
                                </div>
                                <form action="{{route('admin.business-settings.shipping-method.index')}}" method="post" class="form-advance-validation  form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
                                    @csrf
                                    <div class="px-20 py-4 bg-section rounded mb-20">
                                        <div class="row g-4">
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-group mb-0">
                                                    <label class="form-label text-capitalize" for="">
                                                        {{ translate('Title') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                              data-bs-placement="right" data-bs-title="{{ translate('enter_the_title_that_will_be_shown_for_this_shipping_method') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                    </label>
                                                    <input type="text" name="title" class="form-control"
                                                           placeholder="{{ translate('enter_the_title_that_will_be_shown_for_this_shipping_method') }}"    data-required-msg="{{ translate('title_is_required') }}" required>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-group mb-0">
                                                    <label class="form-label text-capitalize"
                                                           for="">{{ translate('Shipping_Duration') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                              data-bs-placement="right"
                                                              data-bs-title="{{ translate('set_up_the_timeframe_for_the_shipping_method') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                    </label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" name="duration"
                                                               placeholder="{{ translate('set_up_the_timeframe_for_the_shipping_method') }}"     data-required-msg="{{ translate('shipping_duration_is_required') }}" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-md-6">
                                                <div class="form-group mb-0">
                                                    <label class="form-label text-capitalize" for="">
                                                        {{ translate('Shipping_Cost') }}
                                                        <span class="text-danger">*</span>
                                                        <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                              data-bs-placement="right"
                                                              data-bs-title="{{ translate('define_the_cost_based_on_the_time_frame') }}">
                                                            <i class="fi fi-sr-info"></i>
                                                        </span>
                                                    </label>
                                                    <input type="number" min="0" step="0.01" max="1000000"
                                                           name="cost" class="form-control"
                                                           placeholder="{{ translate('define_the_cost_based_on_the_time_frame') }}:"   data-required-msg="{{ translate('shipping_cost_is_required') }}" required="">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end flex-wrap gap-3">
                                        <button type="reset" class="btn btn-secondary px-4 w-120">
                                            {{ translate('Reset') }}
                                        </button>
                                        <button type="submit" class="btn btn-primary px-4 w-120">
                                            {{ translate('Submit') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="card shadow-2">
                            <div class="card-body">
                                <div class="d-flex flex-column gap-20">
                                    <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                                        <h4 class="text-capitalize">{{ translate('list_of_order_wise_shipping_method') }}
                                            <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $shippingMethods->count() }}</span>
                                        </h4>
                                        <form action="{{route('admin.business-settings.shipping-method.index')}}"
                                              method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                            @csrf
                                            <div class="input-group">
                                                <input type="search" name="order_search" class="form-control"
                                                       placeholder="{{ translate('Search_by_title') }}"
                                                       value="{{ requestString('order_search') }}">
                                                <div class="input-group-append search-submit">
                                                    <button type="submit">
                                                        <i class="fi fi-rr-search"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="k-table-wrap">
                                        <table class="k-table">
                                            <thead>
                                            <tr>
                                                <th>{{ translate('SL') }}</th>
                                                <th>{{ translate('Title') }}</th>
                                                <th>{{ translate('Shipping_Duration') }}</th>
                                                <th>
                                                    {{ translate('Cost') }} ({{ getCurrencySymbol(type: 'default') }})
                                                </th>
                                                <th class="text-center">{{ translate('Status') }}</th>
                                                <th class="text-center">{{ translate('action') }}</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($shippingMethods as $key => $method)
                                                <tr>
                                                    <th>{{ $key + 1 }}</th>
                                                    <td>{{ $method['title'] }}</td>
                                                    <td>
                                                        {{ $method['duration'] }}
                                                    </td>
                                                    <td>
                                                        {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: $method['cost']), currencyCode: getCurrencyCode(type: 'default')) }}
                                                    </td>
                                                    <td>
                                                        <form
                                                            action="{{ route('admin.business-settings.shipping-method.update-status') }}"
                                                            method="post" id="shipping-methods{{ $method['id'] }}-form" class="no-reload-form">
                                                            @csrf
                                                            <input type="hidden" name="id"
                                                                   value="{{ $method['id'] }}">
                                                            <label class="switcher mx-auto" for="shipping-methods{{ $method['id'] }}">
                                                                <input
                                                                    class="switcher_input custom-modal-plugin"
                                                                    type="checkbox" value="1" name="status"
                                                                    id="shipping-methods{{ $method['id'] }}"
                                                                    {{ $method->status == 1 ? 'checked' : '' }}
                                                                    data-modal-type="input-change-form"
                                                                    data-modal-form="#shipping-methods{{ $method['id'] }}-form"
                                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-on.png') }}"
                                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/category-status-off.png') }}"
                                                                    data-on-title="{{ translate('want_to_Turn_ON_This_Shipping_Method') . '?' }}"
                                                                    data-off-title="{{ translate('want_to_Turn_OFF_This_Shipping_Method') . '?' }}"
                                                                    data-on-message="<p>{{ translate('if_you_enable_this_shipping_method_will_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>"
                                                                    data-off-message="<p>{{ translate('if_you_disable_this_shipping_method_will_not_be_shown_in_the_user_app_and_website_for_customer_checkout') }}</p>"
                                                                    data-on-button-text="{{ translate('turn_on') }}"
                                                                    data-off-button-text="{{ translate('turn_off') }}">
                                                                <span class="switcher_control"></span>
                                                            </label>
                                                        </form>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex justify-content-center gap-3">
                                                            <a class="btn btn-outline-info icon-btn edit"
                                                               title="{{ translate('edit') }}"
                                                               href="{{ route('admin.business-settings.shipping-method.update', [$method['id']]) }}">
                                                                <i class="fi fi-sr-pencil"></i>
                                                            </a>
                                                            <a title="{{ translate('delete') }}"
                                                               class="btn btn-outline-danger icon-btn delete-data"
                                                               data-action="{{ route('admin.business-settings.shipping-method.delete') }}"
                                                               data-id="{{ $method['id'] }}">
                                                                <i class="fi fi-rr-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="k-table-wrap">
                                        <div class="d-flex justify-content-lg-end">
                                            {{ $shippingMethods->links() }}
                                        </div>
                                    </div>
                                    @if (count($shippingMethods) == 0)
                                        @include(
                                            'layouts.admin.partials._empty-state',
                                            ['text' => 'shipping_method_found'],
                                            ['image' => 'default']
                                        )
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="update_category_shipping_cost">
                    <div class="card shadow-2">
                        <div class="card-body">
                            <div class="d-flex flex-column gap-20">
                                <div class="d-flex justify-content-between align-items-center gap-20 flex-wrap">
                                    <h4>{{ translate('Category_wise_shipping_cost') }}
                                        <span class="badge text-dark bg-body-secondary fw-semibold rounded-50">{{ $allCategoryShippingCost->count() }}</span>
                                    </h4>
                                    <form action="{{route('admin.business-settings.shipping-method.index')}}"
                                          method="get" class="flex-grow-1 max-w-300 min-w-100-mobile">
                                        @csrf
                                        <div class="input-group">
                                            <input type="search" name="category_search" class="form-control"
                                                   placeholder="{{ translate('Search_by_category_name') }}"
                                                   value="{{ requestString('category_search') }}">
                                            <div class="input-group-append search-submit">
                                                <button type="submit">
                                                    <i class="fi fi-rr-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="k-table-wrap">
                                    <form action="{{route('admin.business-settings.category-shipping-cost.store')}}"
                                          method="POST">
                                        @csrf
                                        <table class="k-table">
                                            <thead>
                                            <tr>
                                                <th>{{ translate('SL') }}</th>
                                                <th>{{ translate('Image	') }}</th>
                                                <th>{{ translate('Category_name	') }}</th>
                                                <th>
                                                    {{ translate('Shipping_Cost') }} ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                                </th>
                                                <th class="text-center">
                                                    {{ translate('Shipping_cost_multiply_with_quantity') }}
                                                </th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @php($sl = 0)
                                            @foreach ($allCategoryShippingCost as $key => $item)
                                                @if ($item->category)
                                                    <tr>
                                                        <td>
                                                            {{ ++$sl }}
                                                        </td>
                                                        <td>
                                                            <img class="rounded-circle aspect-1" width="60"
                                                                 src="{{ getStorageImages(path: $item->category->icon_full_url, type: 'backend-category') }}"
                                                                 alt="">
                                                        </td>
                                                        <td>
                                                            {{ $item->category->name }}
                                                        </td>
                                                        <td>
                                                            <input type="hidden" class="form-control w-auto"
                                                                   name="ids[]" value="{{ $item->id }}">
                                                            <input type="hidden" class="form-control w-auto"
                                                                   name="category_ids[]"
                                                                   value="{{ $item->category->id }}">
                                                            <input type="number" class="form-control w-auto"
                                                                   min="0" step="0.01" name="cost[]"
                                                                   placeholder="{{ translate('ex:_50') }}"
                                                                   value="{{ usdToDefaultCurrency(amount: $item->cost) }}">
                                                        </td>
                                                        <td>
                                                            <div
                                                                class="d-flex justify-content-center align-items-center">
                                                                <input class="form-check-input checkbox--input"
                                                                       type="checkbox" name="multiplyQTY[]" id=""
                                                                       value="{{ $item->id }}"
                                                                    {{ $item->multiply_qty == 1 ? 'checked' : '' }}>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                            <tr>
                                                <td colspan="5">
                                                    <div class="d-flex justify-content-end flex-wrap gap-3">
                                                        <button type="reset"
                                                                class="btn btn-secondary px-4 w-120">{{ translate('Reset') }}</button>
                                                        <button type="submit"
                                                                class="btn btn-primary px-4 w-120">{{ translate('Save') }}</button>
                                                    </div>
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </form>
                                </div>
                                <div class="k-table-wrap">
                                    <div class="d-flex justify-content-lg-end">
                                        {{ $shippingMethods->links() }}
                                    </div>
                                </div>
                                @if (count($shippingMethods) == 0)
                                    @include(
                                        'layouts.admin.partials._empty-state',
                                        ['text' => 'shipping_method_found'],
                                        ['image' => 'default']
                                    )
                                @endif
                            </div>
                        </div>
                    </div>
                    </div>
                </div>

                <div class="bg-section rounded-10 p-20 text-center" id="product_wise_note">
                    <img width="60" class="rounded-circle aspect-1 mb-3"
                         src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/product-wise-shipping.png') }}"
                         alt="">
                    <h3 class="mb-2">{{ translate('you_are_currently_use_product_wise_shipping_method') }}</h3>
                    <p>{{ translate('please_ensure_that_the_delivery_cost_for_each_product_is_updated_from_the_product_add/update_page.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    @include("layouts.admin.partials.offcanvas._shipping-method")

    <span id="order-wise-shipping-method-delete-content" data-sure="{{ translate('are_you_sure_to_delete_this_order_wise_shipping_method') }}?" data-text="{{ translate('once_deleted') }}, {{ translate('_the_option_will_remove_this_shipping_option_for_selection_during_the_shipping_method_selection_process') }}" data-confirm="{{ translate('Yes, Delete it') }}" data-cancel="Cancel" aria-hidden="true"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/shipping-method.js') }}"></script>
@endpush
