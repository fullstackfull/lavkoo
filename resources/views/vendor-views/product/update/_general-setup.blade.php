<div class="general_wrapper mt-3">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-between align-items-center border-0 shadow-none pb-0 pc-header-ai-btn">
                <div>
                    <h3 class="mb-1">{{ translate('General_Setup') }}</h2>
                    <p class="fs-12 mb-0">
                        {{ translate('Here_you_can_setup_the_foundational_details_required_for_product_creation.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 general_setup_auto_fill"
                    id="general_setup_auto_fill" data-route="{{ route('vendor.product.general-setup-auto-fill') }}"  data-lang="en">
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
                    <div class="row g-2">
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="title-color">
                                    {{ translate('product_type') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <select name="product_type" id="product_type" class="form-control" required>
                                    <option
                                        value="physical" {{ $product['product_type'] == 'physical' ? 'selected' : ''}}>{{ translate('physical') }}</option>
                                    @if($digitalProductSetting)
                                        <option
                                            value="digital" {{ $product['product_type'] == 'digital' ? 'selected' : ''}}>{{ translate('digital') }}</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label for="name" class="title-color">
                                    {{ translate('category') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <select class="js-example-basic-multiple js-states js-example-responsive form-control action-get-request-onchange"
                                        name="category_id"
                                        id="category_id"
                                        data-url-prefix="{{ url('/vendor/products/get-categories?parent_id=') }}"
                                        data-element-id="sub-category-select"
                                        data-element-type="select">
                                    <option value="0" selected disabled>---{{ translate('select') }}---</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category['id']}}" {{ $category->id==$product['category_id'] ? 'selected' : ''}}>{{ $category['defaultName']}}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="title-color">{{ translate('sub_Category') }}</label>
                                <select
                                    class="js-example-basic-multiple js-states js-example-responsive form-control action-get-request-onchange"
                                    name="sub_category_id" id="sub-category-select"
                                    data-id="{{ $product['sub_category_id'] }}"
                                    data-url-prefix="{{ url('/vendor/products/get-categories?parent_id=') }}"
                                    data-element-id="sub-sub-category-select"
                                    data-element-type="select">
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="title-color">{{ translate('sub_Sub_Category') }}</label>
                                <select
                                    class="js-example-basic-multiple js-states js-example-responsive form-control"
                                    data-id="{{ $product['sub_sub_category_id'] }}"
                                    name="sub_sub_category_id" id="sub-sub-category-select">
                                </select>
                            </div>
                        </div>
                        @if($brandSetting)
                            <div class="col-md-6 col-lg-4 physical_product_show">
                                <div class="form-group mb-0">
                                    <label class="title-color">
                                        {{ translate('brand') }}
                                    </label>
                                    <select
                                        class="js-example-basic-multiple js-states js-example-responsive form-control"
                                        name="brand_id">
                                        <option value="{{null}}" selected disabled>---{{ translate('select') }}---
                                        </option>
                                        <option value="{{ null }}">
                                            {{ translate('No_Brand') }}
                                        </option>
                                        @foreach($brands as $brand)
                                            <option value="{{ $brand['id']}}" {{ $brand['id']==$product['brand_id'] ? 'selected' : ''}} >
                                                {{ $brand['defaultName']}}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div class="col-md-6 col-lg-4 digital-product-sections-show">
                            <div class="form-group mb-0">
                                <label class="title-color">
                                    {{ translate("Author") }}/{{ translate("Creator") }}/{{ translate("Artist") }}
                                </label>
                                <select class="multiple-select2 form-control" name="authors[]" multiple="multiple" id="mySelect">
                                    @foreach($digitalProductAuthors as $authors)
                                        <option value="{{ $authors['name'] }}" {{ in_array($authors['id'], $productAuthorIds) ? 'selected' : '' }}>{{ $authors['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4 digital-product-sections-show">
                            <div class="form-group mb-0">
                                <label class="title-color">{{ translate("Publishing_House") }}</label>
                                <select class="multiple-select2 form-control" name="publishing_house[]" multiple="multiple">
                                    @foreach($publishingHouseList as $publishingHouse)
                                        <option value="{{ $publishingHouse['name'] }}"
                                            {{ in_array($publishingHouse['id'], $productPublishingHouseIds) ? 'selected' : '' }}>{{ $publishingHouse['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4" id="digital_product_type_show">
                            <div class="form-group mb-0">
                                <label for="digital_product_type"
                                        class="title-color">{{ translate("delivery_type") }}</label>
                                <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                        title="{{
                                        translate('for_Ready_Product_deliveries,_customers_can_pay_&_instantly_download_pre-uploaded_digital_products').' '.
                                        translate('For_Ready_After_Sale_deliveries,_customers_pay_first_then_vendor_uploads_the_digital_products_that_become_available_to_customers_for_download') }}">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                <select name="digital_product_type" id="digital_product_type" class="form-control"
                                        required>
                                    <option value="{{ old('category_id') }}"
                                            {{ !$product['digital_product_type'] ? 'selected' : ''}} disabled>
                                        ---{{ translate('select') }}---
                                    </option>
                                    <option
                                        value="ready_after_sell" {{ $product['digital_product_type'] == 'ready_after_sell' ? 'selected' : ''}}>{{ translate("ready_After_Sell") }}</option>
                                    <option
                                        value="ready_product" {{ $product['digital_product_type'] == 'ready_product' ? 'selected' : ''}}>{{ translate("ready_Product") }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="title-color d-flex justify-content-between gap-2">
                                    <span class="d-flex align-items-center gap-2">
                                        {{ translate('product_SKU') }}
                                        <span class="input-required-icon">*</span>
                                        <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                                title="{{ translate('create_a_unique_product_code_by_clicking_on_the_Generate_Code_button') }}">
                                            <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                        </span>
                                    </span>
                                    <span class="style-one-pro cursor-pointer user-select-none text--primary action-onclick-generate-number" data-input="#generate_number">
                                        {{ translate('generate_code') }}
                                    </span>
                                </label>

                                <input type="text" id="generate_number" name="code" class="form-control"
                                        value="{{request('product-gallery') ? ' ':$product->code}}" placeholder="{{translate('4FOITO')}}" required>
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
                        <div class="col-md-6 col-lg-4 physical_product_show">
                            <div class="form-group mb-0">
                                <label class="title-color">{{ translate('unit') }}</label>
                                <select
                                    class="js-example-basic-multiple js-states js-example-responsive form-control"
                                    name="unit">
                                    @foreach(units() as $unit)
                                        <option
                                            value={{ $unit}} {{ $product['unit'] == $unit ? 'selected' : ''}}>{{ $unit }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-8">
                            <div class="form-group mb-0">
                                <label class="title-color d-flex align-items-center gap-2">
                                    {{ translate('search_tags') }}
                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('add_the_product_search_tag_for_this_product_that_customers_can_use_to_search_quickly') }}">
                                        <img width="16" src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}"
                                                alt="">
                                    </span>
                                </label>
                                <input type="text" class="form-control" name="tags" id="tags"
                                        value="@foreach($product['tags'] as $tag) {{$tag->tag.','}} @endforeach"
                                        data-role="tagsinput">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

