<div class="variation_wrapper mt-3 physical_product_show show-for-physical-product">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-end align-items-center gap-3 border-0 pc-header-ai-btn shadow-none">
                <div class="flex-grow-1">
                    <h3 class="mb-1">{{ translate('Product_Variation_Setup') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('Enable_and_manage_different_variations_of_a_product.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 variation_setup_auto_fill"
                    id="variation_setup_auto_fill" data-route="{{ route('vendor.product.variation-setup-auto-fill') }}" data-lang="en">
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

                <label class="switcher">
                    <input type="checkbox" class="switcher_input product_variation_toggle"
                        {{ count(json_decode($product['variation'], true)) > 0 ? 'checked' : '' }}>
                    <span class="switcher_control"></span>
                </label>
            </div>
            <div class="card-body pt-0 product_variation_content {{ count(json_decode($product['variation'], true)) > 0 ? '' : 'd--none' }}">

                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row gy-2 align-items-end">
                        <div class="col-md-6">
                            <div class="mb-3 d-flex align-items-center gap-2 justify-content-between">
                                <label for="colors" class="text-dark mb-0 d-flex gap-1">
                                    {{ translate('colors') }}
                                </label>
                                <label class="switcher">
                                    <input type="checkbox" class="switcher_input" id="product-color-switcher"
                                            name="colors_active" {{count($product['colors'])>0?'checked':''}}>
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                            <select
                                class="js-example-basic-multiple select_with-icon js-states js-example-responsive form-control color-var-select"
                                name="colors[]" multiple="multiple"
                                id="colors-selector" {{ count($product['colors'])>0?'':'disabled' }}>
                                @foreach ($colors as $key => $color)
                                    <option
                                        value={{ $color->code }} {{in_array($color->code,$product['colors'])?'selected':''}}>
                                        {{ $color['name'] }}
                                    </option>
                                @endforeach
                            </select>

                        </div>
                        <div class="col-md-6">
                            <label for="product-choice-attributes" class="form-label d-flex gap-1">
                                {{ translate('attributes') }}
                            </label>
                            <select
                                class="js-example-basic-multiple select_with-icon js-states js-example-responsive form-control"
                                name="choice_attributes[]" id="product-choice-attributes" multiple="multiple">
                                @foreach ($attributes as $key => $attribute)
                                    @if($product['attributes']!='null')
                                        <option value="{{ $attribute['id'] }}" {{ in_array($attribute->id,json_decode($product['attributes'], true))? 'selected':'' }}>
                                            {{ $attribute['name']}}
                                        </option>
                                    @else
                                        <option value="{{ $attribute['id']}}">{{ $attribute['name']}}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-12">
                            <div class="row customer-choice-options-container my-0 gy-2" id="customer-choice-options-container">
                                @include('vendor-views.product.partials._choices',['choice_no'=>json_decode($product['attributes']),'choice_options'=>json_decode($product['choice_options'],true)])
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sku_combination table-responsive form-group mt-2 max-h-300" id="sku_combination">
                    @include('vendor-views.product.partials._edit-sku-combinations', ['combinations'=>json_decode($product['variation'],true)])
                </div>
                @include('vendor-views.product.update._color-wise-images')
            </div>
        </div>
    </div>
</div>

<div class="variation_wrapper mt-3 show-for-digital-product digitalProductVariationSetupSection">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-end align-items-center gap-3 border-0 pc-header-ai-btn shadow-none">
                <div class="flex-grow-1">
                    <h3 class="mb-1">{{ translate('Product_Variation_Setup') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('Enable_and_manage_different_variations_of_a_product.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 variation_setup_auto_fill"
                    id="variation_setup_auto_fill" data-route="{{ route('vendor.product.variation-setup-auto-fill') }}" data-lang="en">
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

                <label class="switcher">
                    <input type="checkbox" class="switcher_input product_variation_toggle"
                       @if ($product->product_type == 'digital' && ($product->digital_product_file_types && count($product->digital_product_file_types) > 0))
                           checked
                       @endif
                    >
                    <span class="switcher_control"></span>
                </label>
            </div>
            <div class="card-body pt-0 product_variation_content d--none">
                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row gy-3" id="digital-product-type-choice-section">
                        <div class="col-12">
                            <div class="multi--select">
                                <label class="form-label">{{ translate('File_Type') }}</label>
                                <select class="custom-select" name="file-type" multiple
                                        id="digital-product-type-select">
                                    @foreach($digitalProductFileTypes as $FileType)
                                        @if($product->digital_product_file_types)
                                            <option value="{{ $FileType }}"
                                                {{ in_array($FileType, $product->digital_product_file_types) ? 'selected' : '' }}>
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

                <div id="digital-product-variation-section"></div>
            </div>
        </div>
    </div>
</div>

<div id="digital-product-single-file-upload-section"></div>
