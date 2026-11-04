<div class="variation_wrapper mt-3 physical_product_show show-for-physical-product {{ $product->product_type == 'digital' ? 'd--none' : '' }}">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-end align-items-center gap-3 border-0 pc-header-ai-btn shadow-none" >
                <div class="flex-grow-1">
                    <h2 class="mb-1">{{ translate('Product_Variation_Setup') }}</h2>
                    <p class="fs-12 mb-0">
                        {{ translate('Enable_and_manage_different_variations_of_a_product.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 variation_setup_auto_fill"
                    id="variation_setup_auto_fill" data-route="{{ route('admin.product.variation-setup-auto-fill') }}" data-lang="en">
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
            <div class="card-body pt-0 product_variation_content d--none">
                <div class="bg-section rounded-10 p-12 p-sm-20">
                    <div class="row gy-4 align-items-end">
                        <div class="col-md-6">
                            <div class="mb-3 d-flex align-items-center justify-content-between gap-2">
                                <label for="colors" class="text-dark mb-0">
                                    {{ translate('Select_Colors') }} :
                                </label>
                                <label class="switcher">
                                    <input type="checkbox" class="switcher_input" id="product-color-switcher"
                                           value="1" {{ count($product['colors']) > 0 ? 'checked' : '' }}
                                           name="colors_active">
                                    <span class="switcher_control"></span>
                                </label>
                            </div>
                            <select class="custom-select color-var-select" name="colors[]" multiple="multiple"
                                    id="colors-selector-input" {{ count($product['colors']) > 0 ? '' : 'disabled' }}>
                                @foreach ($colors as $color)
                                    <option value="{{ $color->code }}" data-color="{{ $color->code }}"
                                        {{ in_array($color->code,$product['colors']) ? 'selected' : '' }}>
                                        {{ $color['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="product-choice-attributes" class="form-label">
                                {{ translate('Select_Attributes') }} :
                            </label>
                            <select class="custom-select"
                                    name="choice_attributes[]" id="product-choice-attributes" multiple="multiple"
                                    data-placeholder="{{ translate('choose_attributes') }}">
                                <option></option>
                                @foreach ($attributes as $key => $attribute)
                                    @if($product['attributes']!='null')
                                        <option value="{{ $attribute['id'] }}"
                                            {{ in_array($attribute->id, json_decode($product['attributes'], true)) ? 'selected' : '' }}>
                                            {{ $attribute['name']}}
                                        </option>
                                    @else
                                        <option value="{{ $attribute['id'] }}">{{ $attribute['name'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-12 mt-2 mb-2">
                            <div class="row customer-choice-options-container my-0 gy-4" id="customer-choice-options-container">
                                @include('admin-views.product.partials._choices', [
                                    'choice_no' => json_decode($product['attributes']),
                                    'choice_options' => json_decode($product['choice_options'],true)
                                ])
                            </div>

                        </div>
                    </div>
                </div>
                <div class="sku_combination table-responsive form-group mt-3 mb-0" id="sku_combination">
                    @include('admin-views.product.partials._edit-sku-combinations', [
                        'combinations' => json_decode($product['variation'], true)
                    ])
                </div>
                @include('admin-views.product.update._color-wise-images')
            </div>
        </div>
    </div>
</div>

<div class="variation_wrapper mt-3 show-for-digital-product {{ $product->product_type == 'physical' ? 'd--none' : '' }}">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-end align-items-center gap-3 border-0 pc-header-ai-btn shadow-none">
                <div class="flex-grow-1">
                    <h2 class="mb-1">{{ translate('Product_Variation_Setup') }}</h2>
                    <p class="fs-12 mb-0">
                        {{ translate('Enable_and_manage_different_variations_of_a_product.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 variation_setup_auto_fill"
                    id="variation_setup_auto_fill" data-route="{{ route('admin.product.variation-setup-auto-fill') }}" data-lang="en">
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
                    <div class="row gy-4" id="digital-product-type-choice-section">
                        <div class="col-12">
                            <div class="multi--select">
                                <label class="form-label">{{ translate('File_Type') }}</label>
                                <select class="custom-select form-control" name="file-type" multiple id="digital-product-type-select">
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
