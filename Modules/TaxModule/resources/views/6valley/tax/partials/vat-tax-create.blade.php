<form action="{{ route('admin.vat-tax.store') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="createVatTaxOffcanvas"
         aria-labelledby="offcanvasSubCatFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Create_Vat/Tax') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-4">
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold mb-2" for="">
                                {{ translate('Availability') }}
                                <span class="tooltip-icon" data-bs-toggle="tooltip"
                                      data-bs-placement="top"
                                      aria-label="{{ translate('Use_the_status_toggle_to_enable_or_disable_this_tax._When_enabled,_the_tax_will_automatically_be_applied_during_checkout._If_it_is_turned_off,_this_tax_will_not_affect_any_order_calculations.') }}"
                                      data-bs-title="{{ translate('Use_the_status_toggle_to_enable_or_disable_this_tax._When_enabled,_the_tax_will_automatically_be_applied_during_checkout._If_it_is_turned_off,_this_tax_will_not_affect_any_order_calculations.') }}">
                                    <i class="fi fi-sr-info"></i>
                                </span>
                            </label>
                            <p class="fs-12 mb-0">
                                {{ translate('if_you_turn_off_this_status_your_tax_calculation_will_effect.') }}
                            </p>
                        </div>
                        <label
                            class="bg-white d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 user-select-none">
                            <span class="fw-medium text-dark line-1">
                                {{ translate('Status') }}
                            </span>
                            <label class="switcher" for="create-vat-tax">
                                <input
                                    class="switcher_input"
                                    type="checkbox" name="status" value="1"
                                    checked
                                    id="create-vat-tax"
                                    >
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div class="form-group mb-20">
                        <label class="form-label" for="">
                            {{ translate('VAT/TAX_Name') }}
                            <span class="input-required-icon">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  aria-label="{{ translate('Enter_the_name_of_the_tax_you_want_to_apply,_such_as_VAT,_GST,_or_Sales_Tax._This_name_will_be_visible_to_customers_on_invoices,_order_summaries,_and_checkout_pages,_so_make_sure_it_is_clear_and_recognizable.') }}"
                                  data-bs-title="{{ translate('Enter_the_name_of_the_tax_you_want_to_apply,_such_as_VAT,_GST,_or_Sales_Tax._This_name_will_be_visible_to_customers_on_invoices,_order_summaries,_and_checkout_pages,_so_make_sure_it_is_clear_and_recognizable.') }}">
                                <i class="fi fi-sr-info"></i>
                            </span>
                        </label>
                        <input type="text" name="name" class="form-control"    data-required-msg="{{ translate('name_field_is_required') }}" required
                               placeholder="{{ translate('Type_tax_name')}}" value="{{ old('name') }}"
                               maxlength="50">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="tax_rate">
                            {{ translate('VAT/TAX_Rate') }}
                            <span class="input-required-icon">*</span>
                            <span class="tooltip-icon" data-bs-toggle="tooltip"
                                  data-bs-placement="top"
                                  aria-label="{{ translate('Specify_the_value_of_the_tax_here._You_can_enter_a_number_(e.g.,_5,_10,_or_15)_and_then_choose_whether_it_should_be_applied_as_a_percentage_of_the_product_price_or_as_a_fixed_amount_from_the_dropdown_menu.') }}"
                                  data-bs-title="{{ translate('Specify_the_value_of_the_tax_here._You_can_enter_a_number_(e.g.,_5,_10,_or_15)_and_then_choose_whether_it_should_be_applied_as_a_percentage_of_the_product_price_or_as_a_fixed_amount_from_the_dropdown_menu.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                        </label>
                        <div class="input-group">
                            <input type="number" value="{{ old('tax_rate') }}"    data-required-msg="{{ translate('tax_rate_is_required') }}" required name="tax_rate"
                                   min="0" step="0.001" id="tax_rate"
                                   class="form-control" placeholder="{{ translate('Ex:_5') }}">
                            <span class="input-group-text" id="basic-addon1">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">
                    {{ translate('Reset') }}
                </button>
                <button type="submit" class="btn btn-primary flex-grow-1">
                    {{ translate('Save') }}
                </button>
            </div>
        </div>
    </div>
</form>
