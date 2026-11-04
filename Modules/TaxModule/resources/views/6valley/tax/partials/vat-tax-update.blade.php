<form action="{{ route('admin.vat-tax.update') }}" method="post" class="form-advance-validation form-advance-inputs-validation form-advance-file-validation non-ajax-form-validate" novalidate="novalidate">
    @csrf
    <div class="offcanvas offcanvas-end" tabindex="-1" id="editVatTaxOffcanvas-{{ $vatTax->id }}"
         aria-labelledby="offcanvasSubCatFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Edit_Vat/Tax') }}</h3>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <div class="mb-4">
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold mb-2" for="">{{ translate('Availability') }}</label>
                            <p class="fs-12 mb-0">{{ translate('if_you_turn_off_this_status_your_VAT/TAX_calculation_will_effect.') }}</p>
                        </div>
                        <label
                            class="bg-white d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 user-select-none">
                            <span class="fw-medium text-dark line-1">{{ translate('Status') }}</span>
                            <label class="switcher" for="update-vat-tax-{{ $vatTax->id }}">
                                <input
                                    class="switcher_input"
                                    type="checkbox" name="status" value="1"
                                    {{ $vatTax->is_active == 1 ? 'checked' : '' }}
                                    id="update-vat-tax-{{ $vatTax->id }}"
                                >
                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                </div>
                <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                    <input type="hidden" name="id" value="{{ $vatTax->id }}">
                    <div class="form-group mb-20">
                        <label class="form-label" for="">
                            {{ translate('VAT/TAX_Name') }}
                            <span class="input-required-icon">*</span>
                        </label>
                        <input type="text" name="name" class="form-control"    data-required-msg="{{ translate('vat/tax_name_is_required') }}" required readonly
                               placeholder="{{ translate('Type_tax_name')}}" value="{{ $vatTax->name }}"
                               maxlength="50">
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label" for="">
                            {{ translate('VAT/TAX_Rate') }}
                            <span class="input-required-icon">*</span>
                        </label>
                        <div class="input-group">
                            <div class="input-group">
                                <input type="number" value="{{ $vatTax->tax_rate }}"    data-required-msg="{{ translate('tax_rate_is_required') }}" required name="tax_rate"
                                       min="0.001" step="0.001"
                                       class="form-control" placeholder="{{ translate('Ex:_5') }}">
                                <span class="input-group-text" id="basic-addon1">%</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="bg-warning bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                    <i class="fi fi-sr-info text-warning"></i>
                    <span>
                        {{ translate('recheck_your_changes_&_make_sure_before_update.') }}
                        {{ translate('_when_you_change_it_will_effect_on_all_related') }}
                        <span class="fw-semibold">{{ translate('VAT/TAX_calculation.') }}</span>
                    </span>
                </div>
            </div>
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center flex-wrap gap-3 bg-white px-3 py-2">
                <button type="reset" class="btn btn-secondary flex-grow-1">{{ translate('Reset') }}</button>
                <button type="submit" class="btn btn-primary flex-grow-1">{{ translate('Save') }}</button>
            </div>
        </div>
    </div>
</form>
