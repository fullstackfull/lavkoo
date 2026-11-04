<div class="general_wrapper mt-3">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-between align-items-center border-0 pb-0 pc-header-ai-btn">
                <div>
                    <h2 class="mb-1">{{ translate('General_Setup') }}</h2>
                    <p class="fs-12 mb-0">
                        {{ translate('Here_you_can_setup_the_foundational_details_required_for_product_creation.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 general_setup_auto_fill"
                    id="general_setup_auto_fill" data-route="{{ route('admin.product.general-setup-auto-fill') }}" data-item=""  data-lang="en">
                    <div class="btn-svg-wrapper">
                        <img width="18" height="18" class=""
                            src="{{ dynamicAsset(path: 'public/assets/back-end/img/ai/blink-right-small.svg') }}" alt="">
                    </div>
                    <span class="ai-text-animation d-none" role="status">
                        {{ translate('Just_a_second') }}
                    </span>
                    <span class="btn-text">{{ translate('Generate') }}</span>
                </button>
                @endif
            </div>
            <div class="card-body">
                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row gy-4">
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('product_type') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="product_type" id="product_type" class="form-select" data-required-msg="{{ translate('product_type_is_required') }}" required>
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

                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <label for="name" class="form-label">
                                    {{ translate('category') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <select class="custom-select action-get-request-onchange"
                                        name="category_id"
                                        id="category_id"
                                        data-url-prefix="{{ url('/admin/products/get-categories?parent_id=') }}"
                                        data-element-id="sub-category-select"
                                        data-element-type="select"
                                        data-placeholder="{{ translate('select_category') }}"
                                        data-required-msg="{{ translate('Category_field_is_required') }}" required
                                        required>
                                    <option value="{{ old('category_id') }}" selected disabled>
                                        {{ translate('select_category') }}
                                    </option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category['id'] }}"
                                            {{ $category->id == $product['category_id'] ? 'selected' : '' }}>
                                            {{ $category['defaultName'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <label for="name" class="form-label">{{ translate('sub_Category') }}</label>
                                <select
                                    class="custom-select action-get-request-onchange"
                                    name="sub_category_id" id="sub-category-select"
                                    data-id="{{ $product['sub_category_id'] }}"
                                    data-url-prefix="{{ url('/admin/products/get-categories?parent_id=') }}"
                                    data-element-id="sub-sub-category-select"
                                    data-element-type="select"
                                    data-placeholder="{{ translate('select_Sub_Category') }}">
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <label for="name" class="form-label">{{ translate('sub_Sub_Category') }}</label>
                                <select class="custom-select"
                                        name="sub_sub_category_id"
                                        id="sub-sub-category-select"
                                        data-id="{{ $product['sub_sub_category_id'] }}"
                                        data-placeholder="{{ translate('select_Sub_Sub_Category') }}"
                                >
                                </select>
                            </div>
                        </div>
                        @if($brandSetting)
                            <div class="col-md-6 col-lg-4 show-for-physical-product">
                                <div class="form-group">
                                    <label class="form-label">
                                        {{ translate('brand') }}
                                    </label>
                                    <select class="custom-select" name="brand_id" id="brand_id">
                                        <option value="{{ null }}" selected disabled>
                                            {{ translate('select_Brand') }}
                                        </option>
                                        <option value="{{ null }}">
                                            {{ translate('No_Brand') }}
                                        </option>
                                        @foreach ($brands as $brand)
                                            <option value="{{ $brand['id'] }}"
                                                {{ $brand['id'] == $product->brand_id ? 'selected' : ''}}>
                                                {{ $brand['defaultName'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div class="col-md-6 col-lg-4 show-for-digital-product">
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

                        <div class="col-md-6 col-lg-4 show-for-digital-product">
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

                        <div class="col-md-6 col-lg-4 show-for-digital-product">
                            <div class="form-group">
                                <label for="digital-product-type-input" class="form-label">
                                    {{ translate("delivery_type") }}
                                    <span class="input-required-icon">*</span>
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{
                                                    translate('for_Ready_Product_deliveries,_customers_can_pay_&_instantly_download_pre-uploaded_digital_products').' '.
                                                    translate('For_Ready_After_Sale_deliveries,_customers_pay_first_then_admin_uploads_the_digital_products_that_become_available_to_customers_for_download') }}"
                                          data-bs-title="{{
                                                    translate('for_Ready_Product_deliveries,_customers_can_pay_&_instantly_download_pre-uploaded_digital_products').' '.
                                                    translate('For_Ready_After_Sale_deliveries,_customers_pay_first_then_admin_uploads_the_digital_products_that_become_available_to_customers_for_download') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <div class="select-wrapper">
                                    <select name="digital_product_type" id="digital-product-type-input" class="form-select"
                                            >
                                        <option value="ready_after_sell" {{ $product->digital_product_type == 'ready_after_sell' ? 'selected' : ''}}>
                                            {{ translate("ready_After_Sell") }}
                                        </option>
                                        <option value="ready_product" {{ $product->digital_product_type == 'ready_product' ? 'selected' : ''}}>
                                            {{ translate("ready_Product") }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
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
                                        data-input="#generate-sku-code">
                                        {{ translate('generate_code') }}
                                    </span>
                                </label>
                                <input type="text" minlength="6" id="generate-sku-code" name="code"
                                       class="form-control" value="{{ request('product-gallery') ? ' ' : $product->code }}"
                                       data-required-msg="{{ translate('sku_code_is_required') }}"
                                       placeholder="{{ translate('ex'). ': YU62TN' }}" required>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <label class="form-label d-flex align-items-center gap-2">
                                    {{ translate('product_barcode') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('the_barcode_printed_on_the_product_box_EAN_or_UPC_leave_it_empty_to_print_the_SKU') }}"
                                          data-bs-title="{{ translate('the_barcode_printed_on_the_product_box_EAN_or_UPC_leave_it_empty_to_print_the_SKU') }}">
                                        <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>
                                <input type="text" name="barcode" class="form-control direction-ltr"
                                       value="{{ request('product-gallery') ? '' : old('barcode', $product->barcode) }}" maxlength="64"
                                       placeholder="{{ translate('ex').': 6291234567890'}}">
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 show-for-physical-product">
                            <div class="form-group">
                                <label class="form-label">
                                    {{ translate('unit') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <div class="select-wrapper">
                                    <select class="form-select" name="unit">
                                        @foreach (units() as $unit)
                                            <option value={{ $unit }} {{ $product->unit == $unit ? 'selected' : '' }}>
                                                {{ $unit }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group">
                                <label class="form-label d-flex align-items-center gap-2">
                                    {{ translate('Search_Tags') }}
                                    <span class="tooltip-icon cursor-pointer" data-bs-toggle="tooltip"
                                          aria-label="{{ translate('add_the_product_search_tag_for_this_product_that_customers_can_use_to_search_quickly') }}"
                                          data-bs-title="{{ translate('add_the_product_search_tag_for_this_product_that_customers_can_use_to_search_quickly') }}">
                                          <i class="fi fi-sr-info"></i>
                                    </span>
                                </label>

                                <input type="text" class="form-control" name="tags" id="tags" placeholder="{{ translate('enter_tag') }}"
                                       value="@foreach($product->tags as $c) {{ $c->tag.','}} @endforeach"
                                       data-role="tagsinput">
                            </div>
                        </div>

                        <div class="col-md-4">
                            @include('admin-views.partials._bought-together-picker', ['boughtTogetherIds' => $product->bought_together_ids ?? ''])
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

