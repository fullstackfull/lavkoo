@extends('layouts.admin.app')

@section('title', translate(request('product-gallery') == 1 ? 'product_Add' : 'product_Edit'))

@push('css_or_js')
    <link href="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.css') }}" rel="stylesheet">
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/inhouse-product-list.png') }}" alt="">
                {{ translate(request('product-gallery')==1 ?'product_Add' : 'product_Edit') }}
            </h2>
        </div>

        <form class="product-form text-start"
              action="{{ request('product-gallery')==1? route('admin.products.add') : route('admin.products.update', $product->id) }}"
              method="post"
              enctype="multipart/form-data" id="product_form">
            @csrf

            <div class="card">
                <div class="card-body">
                    <div class="row gy-4">
                        <div class="col-12">
                            <div class="position-relative nav--tab-wrapper">
                                <ul class="nav nav-pills nav--tab lang_tab text-capitalize" id="pills-tab"
                                    role="tablist">
                                    @foreach ($languages as $language)
                                        <li class="nav-item" role="presentation">
                                            <a class="nav-link {{ $language == $defaultLanguage ? 'active' : '' }}"
                                               id="{{ $language }}-link" data-bs-toggle="pill"
                                               href="#{{ $language }}-form" role="tab"
                                               aria-controls="{{ $language }}-form" aria-selected="true">
                                                {{ getLanguageName($language).'('.strtoupper($language).')' }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="nav--tab__prev">
                                    <button type="button" class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-left"></i>
                                    </button>
                                </div>
                                <div class="nav--tab__next">
                                    <button class="btn btn-circle border-0 bg-white text-primary">
                                        <i class="fi fi-sr-angle-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="tab-content" id="pills-tabContent">
                                @foreach($languages as $language)
                                        <?php
                                            if (count($product['translations'])) {
                                                $translate = [];
                                                foreach ($product['translations'] as $translation) {
                                                    if ($translation->locale == $language && $translation->key == "name") {
                                                        $translate[$language]['name'] = $translation->value;
                                                    }
                                                    if ($translation->locale == $language && $translation->key == "description") {
                                                        $translate[$language]['description'] = $translation->value;
                                                    }
                                                }
                                            }
                                        ?>
                                    <div class="tab-pane fade {{ $language == $defaultLanguage ? 'show active' : '' }}"
                                         id="{{ $language }}-form" role="tabpanel">
                                        <div class="form-group">
                                            <label class="form-label" for="{{ $language}}_name">
                                                {{ translate('product_name') }}
                                                ({{strtoupper($language) }})
                                                @if($language == 'en')
                                                    <span class="input-required-icon">*</span>
                                                @endif
                                            </label>
                                            <input type="text" {{ $language == 'en'? 'required':''}} name="name[]"
                                                   id="{{ $language}}_name"
                                                   value="{{ $translate[$language]['name'] ?? $product['name'] }}"
                                                   class="form-control {{ $language == 'en' ? 'product-title-default-language' : '' }}"
                                                   placeholder="{{ translate('new_Product') }}" required>
                                        </div>
                                        <input type="hidden" name="lang[]" value="{{ $language }}">
                                        <div class="form-group pt-2">
                                            <label class="form-label">{{ translate('description') }}
                                                ({{ strtoupper($language) }})</label>
                                            <div id="description-{{$language}}-editor" class="quill-editor">{!! $translate[$language]['description']??$product['details'] !!}</div>
                                            <textarea name="description[]" id="description-{{$language}}"
                                                      style="display:none;">{!! $translate[$language]['description']??$product['details'] !!}</textarea>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3 rest-part">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">{{ translate('general_setup') }}</h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row gy-4">
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label for="name" class="form-label">
                                    {{ translate('category') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <select class="custom-select action-get-request-onchange" name="category_id"
                                        data-url-prefix="{{ url('/admin/products/get-categories?parent_id=') }}"
                                        data-element-id="sub-category-select"
                                        data-element-type="select"
                                        data-placeholder="{{ translate('select_category') }}"
                                        required>
                                    <option value="0" selected disabled>---{{ translate('select') }}---</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category['id'] }}" {{ $category->id == $product['category_id'] ? 'selected' : ''}}>
                                            {{ $category['defaultName'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label">{{ translate('sub_Category') }}</label>
                                <select
                                    class="custom-select action-get-request-onchange"
                                    name="sub_category_id" id="sub-category-select"
                                    data-id="{{ $product['sub_category_id'] }}"
                                    data-url-prefix="{{ url('/admin/products/get-categories?parent_id=') }}"
                                    data-element-id="sub-sub-category-select"
                                    data-element-type="select">
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label">{{ translate('sub_Sub_Category') }}</label>
                                <select class="custom-select" data-id="{{ $product['sub_sub_category_id'] }}"
                                    name="sub_sub_category_id" id="sub-sub-category-select">
                                </select>
                            </div>
                        </div>
                        @if($brandSetting)
                            <div class="col-md-6 col-lg-4 col-xl-3 show-for-physical-product">
                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('brand') }}
                                        <span class="input-required-icon">*</span>
                                    </label>
                                    <select
                                        class="custom-select"
                                        name="brand_id">
                                        <option value="{{null}}" selected disabled>---{{ translate('select') }}---
                                        </option>
                                        @foreach($brands as $brand)
                                            <option value="{{ $brand['id']}}" {{ $brand['id']==$product->brand_id ? 'selected' : ''}} >
                                                {{ $brand['defaultName'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('product_type') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="product_type" id="product_type" class="form-select" required>
                                        <option value="physical" {{ $product->product_type == 'physical' ? 'selected' : ''}}>
                                            {{ translate('physical') }}
                                        </option>
                                        @if($digitalProductSetting)
                                            <option value="digital" {{ $product->product_type == 'digital' ? 'selected' : ''}}>
                                                {{ translate('digital') }}
                                            </option>
                                        @endif
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-digital-product">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate("Author") }}/{{ translate("Creator") }}/{{ translate("Artist") }}
                                </label>
                                <select class="custom-select tags" name="authors[]" multiple="multiple" id="mySelect">
                                    @foreach($digitalProductAuthors as $authors)
                                        <option value="{{ $authors['name'] }}" {{ in_array($authors['id'], $productAuthorIds) ? 'selected' : '' }}>
                                            {{ $authors['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-digital-product">
                            <div class="form-group">
                                <label class="form-label">{{ translate("Publishing_House") }}</label>
                                <select class="custom-select tags" name="publishing_house[]" multiple="multiple">
                                    @foreach($publishingHouseList as $publishingHouse)
                                        <option value="{{ $publishingHouse['name'] }}"
                                            {{ in_array($publishingHouse['id'], $productPublishingHouseIds) ? 'selected' : '' }}>
                                            {{ $publishingHouse['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-digital-product">
                            <div class="form-group">
                                <label for="digital_product_type"
                                       class="form-label">{{ translate("delivery_type") }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('for_Ready_Product_deliveries,_customers_can_pay_&_instantly_download_pre-uploaded_digital_products._For_Ready_After_Sale_deliveries,_customers_pay_first,_then_admin_uploads_the_digital_products_that_become_available_to_customers_for_download') }}"
                                          data-bs-title="{{ translate('for_Ready_Product_deliveries,_customers_can_pay_&_instantly_download_pre-uploaded_digital_products._For_Ready_After_Sale_deliveries,_customers_pay_first,_then_admin_uploads_the_digital_products_that_become_available_to_customers_for_download') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="digital_product_type" id="digital_product_type" class="form-select"
                                            required>
                                        <option value="{{ old('category_id') }}"
                                                {{ !$product->digital_product_type ? 'selected' : ''}} disabled>
                                            ---{{ translate('select') }}---
                                        </option>
                                        <option value="ready_after_sell" {{ $product->digital_product_type=='ready_after_sell' ? 'selected' : ''}}>{{ translate("ready_After_Sell") }}</option>
                                        <option value="ready_product" {{ $product->digital_product_type=='ready_product' ? 'selected' : ''}}>{{ translate("ready_Product") }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label d-flex justify-content-between gap-2">
                                    <span class="d-flex align-items-center gap-2">
                                        {{ translate('product_SKU') }}
                                        <span class="input-required-icon">*</span>
                                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                              aria-label="{{ translate('create_a_unique_product_code_by_clicking_on_the_Generate_Code_button') }}"
                                              data-bs-title="{{ translate('create_a_unique_product_code_by_clicking_on_the_Generate_Code_button') }}">
                                            <i class="fi fi-sr-info"></i>
                                        </span>
                                    </span>
                                    <span
                                        class="style-one-pro cursor-pointer user-select-none text-primary action-onclick-generate-number"
                                        data-input="#generate_number">
                                        {{ translate('generate_code') }}
                                    </span>
                                </label>

                                <input type="text" id="generate_number" name="code" class="form-control"
                                       value="{{request('product-gallery') ? ' ' : $product->code}}"
                                       placeholder="{{ translate('ex').': YU62TN'}}" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-physical-product">
                            <div class="form-group">
                                <label class="form-label">{{ translate('unit') }}</label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="unit">
                                        @foreach(units() as $unit)
                                            <option value={{ $unit}} {{ $product->unit==$unit ? 'selected' : ''}}>{{ $unit}}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="form-group">
                                <label class="form-label d-flex align-items-center gap-2">
                                    {{ translate('search_tags') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('add_the_product_search_tag_for_this_product_that_customers_can_use_to_search_quickly') }}"
                                          data-bs-title="{{ translate('add_the_product_search_tag_for_this_product_that_customers_can_use_to_search_quickly') }}">
                                          <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="text" class="form-control" name="tags"
                                       value="@foreach($product->tags as $c) {{ $c->tag.','}} @endforeach"
                                       data-role="tagsinput">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3 rest-part">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">{{ translate('Pricing_&_others') }}</h3>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row gy-4 align-items-end">
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <div class="d-flex gap-2">
                                    <label class="form-label">
                                        {{ translate('unit_price') }}
                                        <span class="input-required-icon">*</span>
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    </label>

                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('set_the_selling_price_for_each_unit_of_this_product.') }} {{ translate('this_Unit_Price_section_would_not_be_applied_if_you_set_a_variation_wise_price.') }}"
                                          data-bs-title="{{ translate('set_the_selling_price_for_each_unit_of_this_product.') }} {{ translate('this_Unit_Price_section_would_not_be_applied_if_you_set_a_variation_wise_price.') }}"
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </div>

                                <input type="number" min="0" step="0.01"
                                       placeholder="{{ translate('unit_price') }}"
                                       name="unit_price" class="form-control"
                                       value={{usdToDefaultCurrency($product->unit_price) }} required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3" id="minimum_order_qty">
                            <div class="form-group">
                                <label class="form-label" for="minimum_order_qty">
                                    {{ translate('minimum_order_qty') }}
                                    ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    <span class="input-required-icon">*</span>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('set_the_minimum_order_quantity_that_customers_must_choose._Otherwise,_the_checkout_process_would_not_start') }}."
                                          data-bs-title="{{ translate('set_the_minimum_order_quantity_that_customers_must_choose._Otherwise,_the_checkout_process_would_not_start') }}."
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="number" min="1" value="{{ $product->minimum_order_qty }}" step="1"
                                       placeholder="{{ translate('minimum_order_quantity') }}"
                                       name="minimum_order_qty" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-physical-product" id="quantity">
                            <div class="form-group">
                                <label class="form-label" for="current_stock">
                                    {{ translate('current_stock_qty') }}
                                    <span class="input-required-icon">*</span>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('add_the_Stock_Quantity_of_this_product_that_will_be_visible_to_customers') }}."
                                          data-bs-title="{{ translate('add_the_Stock_Quantity_of_this_product_that_will_be_visible_to_customers') }}."
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="number" min="0" value={{ $product->current_stock }} step="1"
                                       placeholder="{{ translate('quantity') }}"
                                       name="current_stock" id="current_stock" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label" for="discount_Type">
                                    {{ translate('discount_Type') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('if_Flat,_discount_amount_will_be_set_as_fixed_amount._If_Percentage,_discount_amount_will_be_set_as_percentage') }}."
                                          data-bs-title="{{ translate('if_Flat,_discount_amount_will_be_set_as_fixed_amount._If_Percentage,_discount_amount_will_be_set_as_percentage') }}."
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <select class="form-control" name="discount_type" id="discount_type">
                                    <option value="flat" {{ $product['discount_type']=='flat'?'selected':''}}>
                                        {{ translate('flat') }}
                                    </option>
                                    <option value="percent" {{ $product['discount_type']=='percent'?'selected':''}}>
                                        {{ translate('percent') }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label" for="discount">
                                    {{ translate('discount_amount') }}
                                    <span class="discount_amount_symbol">
                                        ({{ $product->discount_type == 'flat' ? getCurrencySymbol(currencyCode: getCurrencyCode()) : '%' }})
                                    </span>
                                    <span class="input-required-icon">*</span>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('add_the_discount_amount_in_percentage_or_a_fixed_value_here') }}."
                                          data-bs-title="{{ translate('add_the_discount_amount_in_percentage_or_a_fixed_value_here') }}."
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <input type="number" min="0"
                                       value="{{ $product->discount_type == 'flat' ? usdToDefaultCurrency($product->discount) : $product->discount }}"
                                       step="0.01"
                                       placeholder="{{ translate('ex: 5') }}" name="discount" id="discount"
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label" for="tax">
                                    {{ translate('tax_amount') }}(%)
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('set_the_Tax_Amount_in_percentage_here') }}."
                                          data-bs-title="{{ translate('set_the_Tax_Amount_in_percentage_here') }}."
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <input type="number" min="0" value={{ $product->tax ?? 0 }} step="0.01"
                                       placeholder="{{ translate('tax') }}" name="tax" id="tax"
                                       class="form-control" required>
                                <input name="tax_type" value="percent" class="d-none">
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="form-group">
                                <label class="form-label" for="tax_model">
                                    {{ translate('tax_calculation') }}
                                    <span class="input-required-icon">*</span>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('set_the_tax_calculation_method_from_here.').' '.translate('select_Include_with_product_to_combine_product_price_and_tax_on_the_checkout.').' '.translate('pick_Exclude_from_product_to_display_product_price_and_tax_amount_separately.') }}"
                                          data-bs-title="{{ translate('set_the_tax_calculation_method_from_here.').' '.translate('select_Include_with_product_to_combine_product_price_and_tax_on_the_checkout.').' '.translate('pick_Exclude_from_product_to_display_product_price_and_tax_amount_separately.') }}"
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="tax_model" id="tax_model" class="form-select" required>
                                        <option
                                            value="include" {{ $product->tax_model == 'include' ? 'selected':'' }}>{{ translate("include_with_product") }}</option>
                                        <option
                                            value="exclude" {{ $product->tax_model == 'exclude' ? 'selected':'' }}>{{ translate("exclude_with_product") }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-physical-product" id="shipping_cost">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('shipping_cost') }}
                                    ({{getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('set_the_shipping_cost_for_this_product_here._Shipping_cost_will_only_be_applicable_if_product-wise_shipping_is_enabled.') }}"
                                          data-bs-title="{{ translate('set_the_shipping_cost_for_this_product_here._Shipping_cost_will_only_be_applicable_if_product-wise_shipping_is_enabled.') }}"
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <input type="number" min="0" value="{{ usdToDefaultCurrency($product->shipping_cost) }}"
                                       step="1"
                                       placeholder="{{ translate('shipping_cost') }}"
                                       name="shipping_cost" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 col-xl-3 show-for-physical-product" id="shipping_cost_multi">
                            <div class="form-group">
                                <div
                                    class="form-control h-auto min-form-control-height d-flex align-items-center flex-wrap justify-content-between gap-2">
                                    <label class="form-label mb-0"
                                           for="shipping_cost">{{ translate('shipping_cost_multiply_with_quantity') }}
                                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                              title="{{ translate('if_enabled,_the_shipping_charge_will_increase_with_the_product_quantity') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                    </label>
                                    <div>
                                        <label class="switcher">
                                            <input class="switcher_input" type="checkbox" name="multiply_qty"
                                                   id="" {{ $product['multiply_qty'] == 1 ? 'checked' : '' }}>
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3 rest-part show-for-physical-product">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">{{ translate('Product_Variation_Setup') }}</h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2" id="digital-product-type-choice-section">
                        <div class="col-sm-6 col-md-4 col-xxl-3">
                            <div class="multi--select">
                                <label class="form-label">{{ translate('File_Type') }}</label>
                                <select class="custom-select" name="file-type" multiple
                                        id="digital-product-type-select">
                                    @foreach($digitalProductFileTypes as $FileType)
                                        @if($product->digital_product_file_types)
                                            <option
                                                value="{{ $FileType }}" {{ in_array($FileType, $product->digital_product_file_types) ? 'selected':'' }}>
                                                {{ translate($FileType) }}
                                            </option>
                                        @else
                                            <option value="{{ $FileType }}">{{ translate($FileType) }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if($product->digital_product_file_types && count($product->digital_product_file_types) > 0)
                            @foreach($product->digital_product_file_types as $digitalProductFileTypes)
                                <div class="col-lg-6 extension-choice-section">
                                    <div class="form-group mb-0">
                                        <input type="hidden" name="extensions_type[]"
                                               value="{{ $digitalProductFileTypes }}">
                                        <label class="form-label">
                                            {{ $digitalProductFileTypes }}
                                        </label>
                                        <input type="text" name="extensions[]" value="{{ $digitalProductFileTypes }}"
                                               hidden>
                                        <div class="">
                                            @if($product->digital_product_extensions && isset($product->digital_product_extensions[$digitalProductFileTypes]))
                                                <input type="text" class="form-control"
                                                       name="extensions_options_{{ $digitalProductFileTypes }}[]"
                                                       placeholder="{{ translate('enter_choice_values') }}"
                                                       data-role="tagsinput"
                                                       value="@foreach($product->digital_product_extensions[$digitalProductFileTypes] as $extensions){{ $extensions.','}}@endforeach"
                                                       onchange="getUpdateDigitalVariationFunctionality()"
                                                >
                                            @else
                                                <input type="text" class="form-control"
                                                       name="extensions_options_{{ $digitalProductFileTypes }}[]"
                                                       placeholder="{{ translate('enter_choice_values') }}"
                                                       data-role="tagsinput"
                                                       onchange="getUpdateDigitalVariationFunctionality()"
                                                >
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            <div class="card mt-3 rest-part" id="digital-product-variation-section"></div>

            <div id="digital-product-single-file-upload-section"></div>

            <div class="card mt-3 rest-part show-for-physical-product">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">{{ translate('Product_Variation_Setup') }}</h3>
                    </div>
                </div>

                <div class="card-body">
                    <div class="row gy-4 align-items-end">
                        <div class="col-md-6">
                            <div class="form-group">
                                <div class="mb-3 d-flex align-items-center gap-2">
                                    <label for="colors" class="text-dark mb-0">
                                        {{ translate('select_colors') }} :
                                    </label>
                                    <label class="switcher">
                                        <input type="checkbox" class="switcher_input" id="product-color-switcher"
                                               name="colors_active" {{count($product['colors'])>0?'checked':''}}>
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>

                                <select class="custom-select color-var-select" name="colors[]" multiple="multiple"
                                        id="colors-selector" {{count($product['colors'])>0?'':'disabled'}}>
                                    @foreach ($colors as $key => $color)
                                        <option value={{ $color->code }} {{in_array($color->code,$product['colors'])?'selected':''}}>
                                            {{ $color['name']}}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="product-choice-attributes" class="form-label">
                                    {{ translate('select_attributes') }} :
                                </label>
                                <select
                                    class="custom-select"
                                    name="choice_attributes[]" id="product-choice-attributes" multiple="multiple"
                                    data-placeholder="{{ translate('choose_attributes') }}">
                                    <option></option>
                                    @foreach ($attributes as $key => $attribute)
                                        @if($product['attributes']!='null')
                                            <option
                                                value="{{ $attribute['id'] }}" {{ in_array($attribute->id,json_decode($product['attributes'], true))? 'selected':'' }}>
                                                {{ $attribute['name']}}
                                            </option>
                                        @else
                                            <option value="{{ $attribute['id']}}">{{ $attribute['name']}}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-12 mt-2 mb-2">
                            <div class="row customer-choice-options-container my-0 gy-2" id="customer-choice-options-container">
                                @include('admin-views.product.partials._choices', [
                                    'choice_no' => json_decode($product['attributes']),
                                    'choice_options' => json_decode($product['choice_options'],true)
                                ])
                            </div>

                            <div class="sku_combination table-responsive form-group mt-2" id="sku_combination">
                                @include('admin-views.product.partials._edit-sku-combinations', [
                                    'combinations' => json_decode($product['variation'], true)
                                ])
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 rest-part">
                <div class="product-image-wrapper row g-4">
                    <div class="col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex flex-column gap-20">
                                    <div>
                                        <label for="" class="form-label fw-semibold mb-1">
                                            {{ translate('product_thumbnail') }}
                                            <span class="text-danger">*</span>
                                        </label>
                                        <div>
                                            <span
                                                class="badge text-bg-info badge-info badge-lg">{{ THEME_RATIO[theme_root_path()]['Product Image'] }}</span>
                                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                  aria-label="{{ translate('add_your_products_thumbnail_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB"
                                                  data-bs-title="{{ translate('add_your_products_thumbnail_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB"
                                            >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="image" class="upload-file__input single_file_input"
                                               accept=".jpg, .webp, .png, .jpeg, .gif"
                                               value="" required>
                                        <label
                                            class="upload-file__wrapper">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg"
                                                     src="{{dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg')}}"
                                                     alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">{{ translate('Click to upload') }}</span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy"
                                                 src="{{ getStorageImages(path: $product->thumbnail_full_url, type:'backend-product') }}"
                                                 data-default-src="{{ getStorageImages(path: $product->thumbnail_full_url, type:'backend-product') }}"
                                                 alt="">
                                        </label>
                                        <div class="overlay">
                                            <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                    <i class="fi fi-sr-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                    <i class="fi fi-rr-camera"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9 color_image_column d-none">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="form-group">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div>
                                            <label for="name" class="form-label fw-bold mb-0">
                                                {{ translate('colour_wise_product_image') }}
                                                <span class="input-required-icon">*</span>
                                            </label>
                                            <span
                                                class="badge badge-info text-bg-info">{{ THEME_RATIO[theme_root_path()]['Product Image'] }}</span>
                                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                  aria-label="{{ translate('add_color-wise_product_images_here') }}."
                                                  data-bs-title="{{ translate('add_color-wise_product_images_here') }}."
                                            >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </div>

                                    </div>
                                    <p class="text-muted">
                                        {{ translate('must_upload_colour_wise_images_first.') }}
                                        {{ translate('Colour_is_shown_in_the_image_section_top_right') }}
                                    </p>

                                    <div id="color-wise-image-area" class="d-flex justify-content-start flex-wrap gap-3 mb-4">
                                        <div class="d-flex justify-content-start flex-wrap gap-3" id="color_wise_existing_image"></div>
                                        <div class="d-flex justify-content-start flex-wrap gap-3" id="color-wise-image-section"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="additional_image_column item-2 h-100">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div>
                                            <label for="name"
                                                   class="form-label fw-bold mb-0">{{ translate('upload_additional_image') }}</label>
                                            <span
                                                class="badge badge-info text-bg-info">{{ THEME_RATIO[theme_root_path()]['Product Image'] }}</span>
                                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                  aria-label="{{ translate('upload_any_additional_images_for_this_product_from_here') }}."
                                                  data-bs-title="{{ translate('upload_any_additional_images_for_this_product_from_here') }}."
                                            >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </div>

                                    </div>
                                    <p class="text-muted">{{ translate('upload_additional_product_images') }}</p>

                                    <div class="d-flex flex-column" id="additional_Image_Section">
                                        <div class="position-relative">
                                            <div class="multi_image_picker d-flex gap-20 pt-20"
                                                 data-ratio="1/1"
                                                 data-field-name="images[]"
                                                 data-max-filesize="{{ getFileUploadMaxSize() }}"
                                                 data-allowed-formats="{{ getFileUploadFormats(skip: '.svg,.gif') }}"
                                                 data-validation-error-msg="{{ translate('File_size_is_too_large_Maximum_').' '.getFileUploadMaxSize().' '.'MB' }}"
                                            >
                                                <div>
                                                    <div class="imageSlide_prev">
                                                        <div
                                                            class="d-flex justify-content-center align-items-center h-100">
                                                            <button type="button"
                                                                class="btn btn-circle border-0 bg-primary text-white">
                                                                <i class="fi fi-sr-angle-left"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="imageSlide_next">
                                                        <div
                                                            class="d-flex justify-content-center align-items-center h-100">
                                                            <button
                                                                type="button"
                                                                class="btn btn-circle border-0 bg-primary text-white">
                                                                <i class="fi fi-sr-angle-right"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                @if(count($product->colors) == 0)
                                                    @foreach ($product->images_full_url as $key => $photo)
                                                        @php($unique_id = rand(1111,9999))

                                                        <div class="upload-file m-0">
                                                            @if(request('product-gallery'))
                                                                <button type="button"
                                                                        class="remove_btn btn btn-danger btn-circle w-20 h-20 fs-8 delete_file_input_css remove-addition-image-for-product-gallery"
                                                                        data-section-remove-id="addition-image-section-{{$key}}">
                                                                    <i class="fi fi-sr-cross"></i>
                                                                </button>
                                                            @else
                                                                <a class="delete_file_input_css remove_btn btn btn-danger btn-circle w-20 h-20 fs-8"
                                                                   href="{{ route('admin.products.delete-image',['id'=>$product['id'],'name'=>$photo['key']]) }}">
                                                                    <i class="fi fi-rr-cross"></i>
                                                                </a>
                                                            @endif

                                                            <label
                                                                class="upload-file__wrapper">
                                                                <img class="upload-file-img" loading="lazy"
                                                                     id="additional_Image_{{ $unique_id }}"
                                                                     src="{{ getStorageImages(path: $photo, type:'backend-product' ) }}"
                                                                     data-default-src="{{ getStorageImages(path: $photo, type:'backend-product' ) }}"
                                                                     alt="">
                                                                @if(request('product-gallery'))
                                                                    <input type="text" name="existing_images[]"
                                                                           value="{{$photo['key']}}" hidden>
                                                                @endif
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                @else
                                                    @if($product->color_image)
                                                        @foreach ($product->color_images_full_url as $photo)
                                                            @if($photo['color'] == null)
                                                                @php($unique_id = rand(1111,9999))
                                                                <div class="spartan_item_wrapper flex-shrink-0"
                                                                     id="addition-image-section-{{$key}}">
                                                                    <div class="">
                                                                        <div class="upload-file file_upload">
                                                                            <img id="additional_Image_{{ $unique_id }}"
                                                                                 alt=""
                                                                                 class="h-100px aspect-1 bg-white onerror-add-class-d-none"
                                                                                 src="{{ getStorageImages(path: $photo, type:'backend-product' ) }}">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    @else
                                                        @foreach ($product->images_full_url as $key => $photo)
                                                            @php($unique_id = rand(1111,9999))

                                                            <div class=""
                                                                 id="addition-image-section-{{$key}}">
                                                                <div
                                                                    class="custom_upload_input custom-upload-input-file-area position-relative border-dashed-2">
                                                                    @if(request('product-gallery'))
                                                                        <button
                                                                            class="delete_file_input_css btn btn-outline-danger icon-btn remove-addition-image-for-product-gallery"
                                                                            data-section-remove-id="addition-image-section-{{$key}}">
                                                                            <i class="fi fi-rr-trash"></i>
                                                                        </button>
                                                                    @else
                                                                        <a class="delete_file_input_css btn btn-outline-danger icon-btn"
                                                                           href="{{ route('admin.products.delete-image',['id'=>$product['id'],'name'=>$photo['key']]) }}">
                                                                            <i class="fi fi-rr-trash"></i>
                                                                        </a>
                                                                    @endif

                                                                    <div
                                                                        class="img_area_with_preview position-absolute z-index-2 border-0">
                                                                        <img id="additional_Image_{{ $unique_id }}"
                                                                             alt=""
                                                                             class="h-auto aspect-1 bg-white onerror-add-class-d-none"
                                                                             src="{{ getStorageImages(path: $photo, type:'backend-product' ) }}">
                                                                        @if(request('product-gallery'))
                                                                            <input type="text" name="existing_images[]"
                                                                                   value="{{$photo['key']}}" hidden>
                                                                        @endif
                                                                    </div>
                                                                    <div
                                                                        class="position-absolute h-100 top-0 w-100 d-flex align-content-center justify-content-center">
                                                                        <div
                                                                            class="d-flex flex-column justify-content-center align-items-center">
                                                                            <img alt="" class="w-75"
                                                                                 src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}">
                                                                            <h3 class="text-muted">{{ translate('Upload_Image') }}</h3>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    @endif
                                                @endif

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3">
                        <div class="item-1 show-for-digital-product h-100">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="form-group">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                            <div>
                                                <label for="name"
                                                       class="form-label text-capitalize fw-bold mb-0">{{ translate('Product_Preview_File') }}</label>
                                                <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                      title="{{ translate('upload_a_suitable_file_for_a_short_product_preview.') }} {{ translate('this_preview_will_be_common_for_all_variations.') }}">
                                                    <i class="fi fi-sr-info"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <p class="text-muted">{{ translate('Upload_a_short_preview') }}.</p>
                                    </div>

                                    <div class="image-uploader">
                                        <input type="file" name="preview_file" class="image-uploader__zip"  data-max-size="{{ getFileUploadMaxSize(type: 'file')  }}" id="input-file">
                                        <div class="image-uploader__zip-preview">
                                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"
                                                class="mx-auto" width="50" alt="">
                                            <div class="image-uploader__title line-2"
                                                 data-default="{{ translate('Upload_File') }}">
                                                @if ($product->preview_file_full_url['path'])
                                                    {{ $product->preview_file }}
                                                @elseif(request('product-gallery') && $product?->preview_file)
                                                    {{ translate('Upload_File') }}
                                                @else
                                                    {{ translate('Upload_File') }}
                                                @endif

                                                @if(request('product-gallery'))
                                                    <input type="hidden" name="existing_preview_file"
                                                           value="{{ $product?->preview_file }}">
                                                    <input type="hidden" name="existing_preview_file_storage_type"
                                                           value="{{ $product?->preview_file_storage_type }}">
                                                @endif

                                            </div>
                                        </div>

                                        @if ($product->preview_file_full_url['path'])
                                            <span class="btn btn-outline-danger icon-btn collapse show zip-remove-btn delete_preview_file_input"
                                                data-route="{{ route('admin.products.delete-preview-file') }}">
                                                <i class="fi fi-rr-trash"></i>
                                            </span>
                                        @else
                                            <span class="btn btn-outline-danger icon-btn collapse zip-remove-btn">
                                                <i class="fi fi-rr-trash"></i>
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-muted mt-2 fs-12">
                                        {{ translate('Format') }} : {{ " pdf, mp4, mp3" }}
                                        <br>
                                        {{ translate('image_size') }} : {{ translate('max') }} {{ "10 MB" }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="color_image" value="{{ json_encode($product->color_images_full_url) }}">
                <input type="hidden" id="images" value="{{ json_encode($product->images_full_url) }}">
                <input type="hidden" id="product_id" name="product_id" value="{{ $product->id }}">
                <input type="hidden" id="remove_url" value="{{ route('admin.products.delete-image') }}">
            </div>

            <div class="card mt-3 rest-part">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">{{ translate('product_video') }}</h3>
                        <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                              aria-label="{{ translate('add_the_YouTube_video_link_here._Only_the_YouTube-embedded_link_is_supported') }}."
                              data-bs-title="{{ translate('add_the_YouTube_video_link_here._Only_the_YouTube-embedded_link_is_supported') }}."
                        >
                            <i class="fi fi-sr-info"></i>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label mb-0">{{ translate('youtube_video_link') }}</label>
                        <span class="text-info"> ( {{ translate('optional_please_provide_embed_link_not_direct_link') }}. )</span>
                    </div>
                    <input type="text" value="{{ $product['video_url'] }}" name="video_url"
                           placeholder="{{ translate('ex').': https://www.youtube.com/embed/5R06LRdUCSE' }}"
                           class="form-control" required>
                </div>
            </div>

            <div class="card mt-3 rest-part">
                <div class="card-header">
                    <div class="d-flex gap-2">
                        <i class="fi fi-sr-user"></i>
                        <h3 class="mb-0">
                            {{ translate('seo_section') }}
                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  title="{{ translate('add_meta_titles_descriptions_and_images_for_products').', '.translate('this_will_help_more_people_to_find_them_on_search_engines_and_see_the_right_details_while_sharing_on_other_social_platforms') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('meta_Title') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          data-bs-placement="top"
                                          title="{{ translate('add_the_products_title_name_taglines_etc_here').' '.translate('this_title_will_be_seen_on_Search_Engine_Results_Pages_and_while_sharing_the_products_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="text" name="meta_title"
                                       value="{{ $product?->seoInfo?->title ?? $product->meta_title}}" placeholder=""
                                       class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('meta_Description') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          data-bs-placement="top"
                                          @if($product['added_by'] == 'admin')
                                              aria-label="{{ translate('write_a_short_description_of_the_InHouse_shops_product.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_products_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]"
                                          data-bs-title="{{ translate('write_a_short_description_of_the_InHouse_shops_product.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_products_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]"
                                          @else
                                              aria-label="{{ translate('write_a_short_description_of_this_shop_product.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_products_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]"
                                                data-bs-title="{{ translate('write_a_short_description_of_this_shop_product.').' '.translate('this_description_will_be_seen_on_search_engine_results_pages_and_while_sharing_the_products_link_on_social_platforms') .' [ '. translate('character_Limit') }} : 100 ]"
                                          @endif
                                    >
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <textarea rows="4" type="text" name="meta_description" id="meta_description"
                                          class="form-control">{{ $product?->seoInfo?->description ??  $product->meta_description}}</textarea>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex justify-content-center">

                                <div class="d-flex flex-column gap-20">
                                    <div>
                                        <label for="meta_Image" class="form-label fw-semibold mb-1">
                                            {{ translate('meta_Image') }}
                                            <span
                                                class="badge badge-info text-bg-info">{{ THEME_RATIO[theme_root_path()]['Meta Thumbnail'] }}</span>
                                            <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                                  aria-label="{{ translate('add_Meta_Image_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                                  data-bs-title="{{ translate('add_Meta_Image_in') }} JPG, PNG or JPEG {{ translate('format_within') }} 2MB, {{ translate('which_will_be_shown_in_search_engine_results') }}."
                                            >
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                    </div>
                                    <div class="upload-file">
                                        <input type="file" name="meta_image"
                                               class="upload-file__input single_file_input"
                                               id="meta_image_input"
                                               accept=".jpg, .webp, .png, .jpeg, .gif"
                                               value=""
                                        >
                                        <label
                                            class="upload-file__wrapper ratio-2-1">
                                            <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" class="svg"
                                                     src="{{dynamicAsset(path: 'public/assets/new/back-end/img/svg/image-upload.svg')}}"
                                                     alt="image upload">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">{{ translate('Click to upload') }}</span>
                                                    <br>
                                                    {{ translate('or drag and drop') }}
                                                </h6>
                                            </div>
                                            <img class="upload-file-img" loading="lazy"
                                                 src="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-banner') }}"
                                                 data-default-src="{{ getStorageImages(path: $product?->seoInfo?->image_full_url['path'] ? $product?->seoInfo?->image_full_url : $product->meta_image_full_url, type: 'backend-banner') }}"
                                                 alt="">
                                        </label>
                                        <div class="overlay">
                                            <div class="d-flex gap-10 justify-content-center align-items-center h-100">
                                                <button type="button" class="btn btn-outline-info icon-btn view_btn">
                                                    <i class="fi fi-sr-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info icon-btn edit_btn">
                                                    <i class="fi fi-rr-camera"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @include('admin-views.product.partials._seo-update-section')

                </div>
            </div>

            <div class="d-flex justify-content-end flex-wrap gap-3 mt-3">
                <button type="button" class="btn btn-primary px-5 product-add-requirements-check">
                    @if($product->request_status == 2)
                        {{ translate('update_&_Publish') }}
                    @else
                        {{ translate(request('product-gallery') ? 'submit' : 'update') }}
                    @endif
                </button>
            </div>
            @if(request('product-gallery'))
                <input hidden name="existing_thumbnail" value="{{ $product->thumbnail_full_url['key'] }}">
                <input hidden name="existing_meta_image" value="{{ $product?->seoInfo?->image_full_url['key'] ?? $product->meta_image_full_url['key'] }}">
            @endif
        </form>
    </div>

    <span id="route-admin-products-sku-combination" data-url="{{ route('admin.products.sku-combination') }}"></span>
    <span id="route-admin-products-digital-variation-combination"
          data-url="{{ route('admin.products.digital-variation-combination') }}"></span>
    <span id="route-admin-products-digital-variation-file-delete"
          data-url="{{ route('admin.products.digital-variation-file-delete') }}"></span>
    <span id="image-path-of-product-upload-icon"
          data-path="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}"></span>
    <span id="image-path-of-product-upload-icon-two"
          data-path="{{ dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}"></span>
    <span id="message-enter-choice-values" data-text="{{ translate('enter_choice_values') }}"></span>
    <span id="message-upload-image" data-text="{{ translate('upload_Image') }}"></span>
    <span id="message-are-you-sure" data-text="{{ translate('are_you_sure') }}"></span>
    <span id="message-yes-word" data-text="{{ translate('yes') }}"></span>
    <span id="message-no-word" data-text="{{ translate('no') }}"></span>
    <span id="message-want-to-add-or-update-this-product"
          data-text="{{ translate('want_to_update_this_product') }}"></span>
    <span id="message-please-only-input-png-or-jpg"
          data-text="{{ translate('please_only_input_png_or_jpg_type_file') }}"></span>
    <span id="message-product-added-successfully" data-text="{{ translate('product_added_successfully') }}"></span>
    <span id="message-discount-will-not-larger-then-variant-price"
          data-text="{{ translate('the_discount_price_will_not_larger_then_Variant_Price') }}"></span>
    <span id="system-currency-code" data-value="{{ getCurrencySymbol(currencyCode: getCurrencyCode()) }}"></span>
    <span id="system-session-direction" data-value="{{ Session::get('direction') }}"></span>
    <span id="message-file-size-too-big" data-text="{{ translate('file_size_too_big') }}"></span>
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/libs/quill-editor/quill-editor-init.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/product-add-colors-img.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin/product-add-update.js') }}"></script>

    <script src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/products/product-add-update.js') }}"></script>

    <script>
        "use strict";

        let colors = {{ count($product->colors) }};
        let imageCount = {{15-count(json_decode($product->images)) }};
        let thumbnail = '{{ productImagePath('thumbnail').'/'.$product->thumbnail ?? dynamicAsset(path: 'public/assets/back-end/img/400x400/img2.jpg') }}';
        $(function () {
            if (imageCount > 0) {
                $("#coba").spartanMultiImagePicker({
                    fieldName: 'images[]',
                    maxCount: colors === 0 ? 15 : imageCount,
                    rowHeight: 'auto',
                    groupClassName: 'col-6 col-md-4 col-xl-3 col-xxl-2',
                    maxFileSize: '',
                    placeholderImage: {
                        image: '{{ dynamicAsset(path: "public/assets/back-end/img/400x400/img2.jpg") }}',
                        width: '100%',
                    },
                    dropFileLabel: "Drop Here",
                    onAddRow: function (index, file) {
                    },
                    onRenderedPreview: function (index) {
                    },
                    onRemoveRow: function (index) {
                    },
                    onExtensionErr: function () {
                        toastr.error(messagePleaseOnlyInputPNGOrJPG, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    },
                    onSizeErr: function () {
                        toastr.error(messageFileSizeTooBig, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                });
            }

        });

        setTimeout(function () {
            $('.call-update-sku').on('change', function () {
                getUpdateSKUFunctionality();
            });
        }, 2000)

        function colorWiseImageFunctionality(t) {
            let colors = t.val();
            let color_image = $('#color_image').val() ? $.parseJSON($('#color_image').val()) : [];

            let images = $.parseJSON($('#images').val());
            let product_id = $('#product_id').val();
            let remove_url = $('#remove_url').val();

            let color_image_value = $.map(color_image, function (item) {
                return item.color;
            });

            $('#color_wise_existing_image').html('')
            $('#color-wise-image-section').html('')

            $.each(colors, function (key, value) {
                let value_id = value.replace('#', '');
                let in_array_image = $.inArray(value_id, color_image_value);
                let input_image_name = "color_image_" + value_id;
                @if(request('product-gallery'))
                $.each(color_image, function (color_key, color_value) {
                    if ((in_array_image !== -1) && (color_value['color'] === value_id)) {
                        let image_name = color_value['image_name'];
                        let exist_image_html = `
                            <div class="color-image-` + color_value['color'] + `">
                                <div class="upload-file position-relative">
                                    <div class="upload--icon-btns d-flex gap-2 position-absolute inset-inline-end-0 z-3 p-2" >
                                        <button type="button" class="btn text-white icon-btn" aria-label="{{ translate('selected_color') }}" style="background: #${color_value['color']}"><i class="fi fi-sr-check"></i></button>
                                        <button class="btn btn-outline-danger icon-btn remove-color-image-for-product-gallery" aria-label="{{ translate(\'remove_image\') }}" data-color="` + color_value['color'] + `"><i class="fi fi-rr-trash"></i></button>
                                    </div>
                                    <img class="w-100" height="auto"
                                        onerror="this.src='{{ dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png') }}'"
                                        src="${image_name['path']}"
                                        alt="Product image">
                                        <input type="text" name="color_image_` + color_value['color'] + `[]" value="` + image_name['key'] + `" hidden>
                                </div>
                            </div>`;
                        $('#color_wise_existing_image').append(exist_image_html)
                    }
                });
                @else
                $.each(color_image, function (color_key, color_value) {
                    if ((in_array_image !== -1) && (color_value['color'] === value_id)) {
                        let image_name = color_value['image_name'];
                        let exist_image_html = `
                            <div class="">
                                <div class="upload-file position-relative">
                                    <div class="upload--icon-btns d-flex gap-2 position-absolute inset-inline-end-0 z-3 p-2" >
                                        <button type="button" class="btn text-white icon-btn shadow" aria-label="{{ translate('selected_color') }}" style="background: #${color_value['color']}"><i class="fi fi-sr-check"></i></button>
                                        <a href="` + remove_url + `?id=` + product_id + `&name=` + image_name['key'] + `&color=` + color_value['color'] + `"
                                    class="btn btn-outline-danger icon-btn"><i class="fi fi-rr-trash"></i></a>
                                    </div>
                                     <div class="upload-file__wrapper">
                                        <img class="upload-file-img d-block" height="auto"
                                        onerror="this.src='{{ dynamicAsset(path: 'public/assets/front-end/img/image-place-holder.png') }}'"
                                        src="${image_name['path']}"
                                        alt="Product image">
                                    </div>

                                </div>
                            </div>`;
                        $('#color_wise_existing_image').append(exist_image_html)
                    }
                });
                @endif
            });

            $.each(colors, function (key, value) {
                let value_id = value.replace('#', '');
                let in_array_image = $.inArray(value_id, color_image_value);
                let input_image_name = "color_image_" + value_id;

                if (in_array_image === -1) {
                    let html = `<div class=''>
                                    <div class="upload-file position-relative">
                                        <label class-"cursor-pointer d-flex flex-column align-items-center justify-content-center m-auto overflow-hidden rounded">
                                        <span class="btn icon-btn inset-inline-end-0 position-absolute shadow top-0 upload--icon z-3 m-2" style="background: ${value}">
                                            <i class="fi fi-rr-pencil"></i>
                                            <input type="file" name="` + input_image_name + `" id="` + value_id + `" class="d-none" accept=".jpg, .webp, .png, .jpeg, .gif" required="">
                                        </span>

                                        <div class="upload-file__wrapper">
                                        <img id="additional_Image_${value_id}" alt="" class="upload-file-img onerror-add-class-d-none" src="img">

                                        <div class="upload-file-textbox text-center">
                                                <img width="34" height="34" src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}" class="svg" alt="">
                                                <h6 class="mt-1 fw-medium lh-base text-center">
                                                    <span class="text-info">Click to upload</span>
                                                    <br>
                                                    Or drag and drop
                                                </h6>
                                        </div>
                                    </div>


                                    </label>
                                    </div>
                                    </div>`;
                    $('#color-wise-image-section').append(html)

                    $("#color-wise-image-section input[type='file']").each(function () {

                        let thisElement = $(this).closest('label');

                        function proPicURL(input) {
                            if (input.files && input.files[0]) {
                                let uploadedFile = new FileReader();
                                uploadedFile.onload = function (e) {
                                    thisElement.find('.upload-file-img').attr('src', e.target.result).show();
                                    thisElement.fadeIn(300);
                                    thisElement.find('h6').hide();
                                };
                                uploadedFile.readAsDataURL(input.files[0]);
                            }
                        }

                        $(this).on("change", function () {
                            proPicURL(this);
                        });
                    });
                }
            });
        }

        $(document).on('click', '.remove-color-image-for-product-gallery', function (event) {
            event.preventDefault();
            let value_id = $(this).data('color');
            let value = '#' + value_id;
            let color = "color_image_" + value_id;
            let html = `<div class="upload-file position-relative">
                            <label class-"cursor-pointer d-flex flex-column align-items-center justify-content-center m-auto overflow-hidden rounded">
                                <span class="btn icon-btn inset-inline-end-0 position-absolute shadow top-0 upload--icon z-3 m-2" style="background: ${value}">
                                <i class="fi fi-rr-pencil"></i>
                                    <input type="file" name="` + color + `" id="` + value_id + `" class="d-none" accept=".jpg, .webp, .png, .jpeg, .gif" required="">
                                </span>

                                 <div class="upload-file-textbox text-center">
                                        <img width="34" height="34" src="{{ dynamicAsset(path: 'public/assets/back-end/img/icons/product-upload-icon.svg') }}" class="svg" alt="">
                                        <h6 class="mt-1 fw-medium lh-base text-center">
                                            <span class="text-info">Click to upload</span>
                                            <br>
                                            Or drag and drop
                                        </h6>
                                </div>
                            </label>
                        </div>`;
            $('.color-image-' + value_id).empty().append(html);
            $("#color-wise-image-area input[type='file']").each(function () {

                let thisElement = $(this).closest('label');

                function proPicURL(input) {
                    if (input.files && input.files[0]) {
                        let uploadedFile = new FileReader();
                        uploadedFile.onload = function (e) {
                            thisElement.find('img').attr('src', e.target.result);
                            thisElement.fadeIn(300);
                            thisElement.find('h3').hide();
                        };
                        uploadedFile.readAsDataURL(input.files[0]);
                    }
                }

                $(this).on("change", function () {
                    proPicURL(this);
                });
            });
        })
        $('.remove-addition-image-for-product-gallery').on('click', function () {
            $('#' + $(this).data('section-remove-id')).remove();
        })

        $(document).ready(function () {
            setTimeout(function () {
                let category = $("#category_id").val();
                let sub_category = $("#sub-category-select").attr("data-id");
                let sub_sub_category = $("#sub-sub-category-select").attr("data-id");
                getRequestFunctionality('{{ route('admin.products.get-categories') }}?parent_id=' + category + '&sub_category=' + sub_category, 'sub-category-select', 'select');
                getRequestFunctionality('{{ route('admin.products.get-categories') }}?parent_id=' + sub_category + '&sub_category=' + sub_sub_category, 'sub-sub-category-select', 'select');
            }, 100)
        });
        updateProductQuantity();

    </script>
@endpush
