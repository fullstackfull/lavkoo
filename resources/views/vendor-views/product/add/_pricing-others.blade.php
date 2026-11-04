<div class="price_wrapper mt-3">
    <div class="outline-wrapper">
        <div class="card rest-part bg-animate">
            <div class="card-header d-flex justify-content-between align-items-center border-0 shadow-none pb-0 pc-header-ai-btn">
                <div>
                    <h3 class="mb-1">{{ translate('Pricing_&_Others') }}</h3>
                    <p class="fs-12 mb-0">
                        {{ translate('Here_you_can_setup_the_price_and_other_information_for_the_product.') }}
                    </p>
                </div>

                @if(getActiveAIProviderConfigCache())
                <button type="button"
                    class="btn bg-white text-primary bg-transparent shadow-none border-0 opacity-1 generate_btn_wrapper p-0 price_others_auto_fill"
                    id="price_others_auto_fill"
                        data-route="{{ route('vendor.product.price-others-auto-fill') }}"  data-lang="en">
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
                    <div class="row align-items-end">
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <div class="d-flex gap-2 mb-2">
                                    <label class="title-color mb-0">
                                        {{ translate('unit_price') }}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})
                                        <span class="input-required-icon">*</span>
                                    </label>

                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('set_the_selling_price_for_each_unit_of_this_product._This_Unit_Price_section_would_not_be_applied_if_you_set_a_variation_wise_price') }}.">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                </div>
                                <input type="number" min="0" step="0.01"
                                        placeholder="{{ translate('unit_price') }}" name="unit_price" id="unit_price"
                                        value="{{ old('unit_price') }}" class="form-control"
                                        data-required-msg="{{ translate('unit_price_is_required') }}"
                                        required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4" id="">
                            <div class="form-group">
                                <div class="d-flex gap-2 mb-2">
                                    <label class="title-color mb-0" for="minimum_order_qty">
                                        {{ translate('minimum_order_qty') }}
                                        <span class="input-required-icon">*</span>
                                    </label>

                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('set_the_minimum_order_quantity_that_customers_must_choose._Otherwise,_the_checkout_process_would_not_start') }}.">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                </div>

                                <input type="number" min="1" value="1" step="1"
                                        placeholder="{{ translate('minimum_order_quantity') }}" name="minimum_order_qty"
                                        id="minimum_order_qty" class="form-control only-number-input"   data-required-msg="{{ translate('minimum_order_qty_is_required') }}" required>
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4 physical_product_show" id="quantity">
                            <div class="form-group">
                                <div class="d-flex gap-2 mb-2">
                                    <label class="title-color mb-0" for="current_stock">
                                        {{ translate('current_stock_qty') }}
                                        <span class="input-required-icon">*</span>
                                    </label>

                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('add_the_Stock_Quantity_of_this_product_that_will_be_visible_to_customers') }}.">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                </div>

                                <input type="number" min="0" value="0" step="1"
                                        placeholder="{{ translate('quantity') }}" name="current_stock" id="current_stock"
                                        class="form-control only-number-input"
                                        >
                            </div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <div class="form-group">
                                <div class="d-flex gap-2">
                                    <label class="title-color" for="discount">{{ translate('discount_amount') }} <span
                                            class="discount_amount_symbol">
                                            <span class="input-required-icon">*</span>({{ getCurrencySymbol(currencyCode: getCurrencyCode()) }})</span>
                                    </label>
                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('add_the_discount_amount_in_percentage_or_a_fixed_value_here') }}.">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                </div>
                                <div class="input-group input-group-merge input-group--merge">
                                    <input type="number" min="0" value="0" step="0.01"
                                            placeholder="{{ translate('ex: 5') }}"
                                            name="discount" id="discount" class="form-control" required>
                                    <select class="form-control input-group--merge-action" name="discount_type" id="discount_type">
                                        <option value="flat">{{ translate('flat') }}</option>
                                        <option value="percent">{{ translate('percent') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        @if ($productWiseTax)
                            <div class="col-md-6 col-lg-4">
                                <div class="form-group">
                                    <label class="title-color" for="">
                                        {{ translate('Select_Vat/Tax_Rate') }}
                                        <span class="input-required-icon">*</span>
                                    </label>

                                    <select class="custom-select multiple-select2 multiple-select-tax-input" name="tax_ids[]" multiple="multiple"
                                            data-placeholder="{{ translate('type_&_Select_Vat/Tax_Rate') }}">
                                        <option value="all">{{ translate('All') }}</option>
                                        @foreach ($taxVats as $taxVat)
                                            <option value="{{ $taxVat->id }}"> {{ $taxVat->name }}
                                                ({{ $taxVat->tax_rate }}%)
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div class="col-md-6 col-lg-4 physical_product_show" id="">
                            <div class="form-group">
                                <div class="d-flex gap-2">
                                    <label class="title-color">
                                        {{ translate('shipping_cost') }}
                                        ({{ getCurrencySymbol(currencyCode: getCurrencyCode())  }})
                                        <span class="input-required-icon">*</span>
                                    </label>

                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('set_the_shipping_cost_for_this_product_here._Shipping_cost_will_only_be_applicable_if_product-wise_shipping_is_enabled.') }}">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}" alt="">
                                </span>
                                </div>

                                <input type="number" min="0" value="0" step="1"
                                        placeholder="{{ translate('shipping_cost') }}" name="shipping_cost" id="shipping_cost"
                                        class="form-control" required>
                            </div>
                        </div>

                        <div class="col-md-4 col-lg-4 physical_product_show" id="shipping_cost_multi">
                            <div class="form-group">
                                <div>
                                    <label class="title-color text-capitalize" for="shipping_cost">
                                        {{ translate('shipping_cost_multiply_with_quantity') }}
                                    </label>

                                    <span class="input-label-secondary cursor-pointer" data-toggle="tooltip"
                                            title="{{ translate('if_enabled,_the_shipping_charge_will_increase_with_the_product_quantity') }}">
                                    <img src="{{ dynamicAsset(path: 'public/assets/back-end/img/info-circle.svg') }}"
                                            alt="">
                                </span>
                                </div>
                                <div class="form-control h-auto min-form-control-height d-flex align-items-center flex-wrap justify-content-between gap-2">
                                    <div class="d-flex gap-2">
                                        <label class="title-color m-0 text-capitalize" for="shipping_cost">
                                            {{ translate('Status') }}
                                        </label>
                                    </div>

                                    <div>
                                        <label class="switcher">
                                            <input type="checkbox" class="switcher_input" id="is_shipping_cost_multil" name="multiply_qty">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


