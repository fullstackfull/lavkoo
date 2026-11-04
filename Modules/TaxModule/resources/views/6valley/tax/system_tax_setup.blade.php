@extends('layouts.admin.app')

@section('title', translate('Setup_Vat/Tax_Calculation'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="fs-20 mb-0">
                {{ translate('Setup_Vat/Tax_Calculation') }}
            </h2>
        </div>
        <div class="card card-body mb-3 mb-sm-4">
            <div class="row align-items-center">
                <div class="col-md-8 col-xl-9">
                    <h3 class="fs-18 mb-1 text-capitalize">
                        {{ translate('Allow_Vat/Tax_calculation_for_vendor').' ?' }}
                    </h3>
                    <p class="mb-0 fs-12 text-capitalize">
                        {{ translate('To_active_Vat/Tax_calculation_turn_on_the_status.') }}
                    </p>
                </div>

                <div class="col-md-4 col-xl-3">
                    <div class="mt-3 mt-md-0">
                        <label
                            class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                            <span class="fw-medium text-dark">{{ translate('Status') }}</span>

                            <form action="{{ route('admin.vat-tax.systemTaxVatVendorStatus', $systemTaxVat?->id) }}"
                                  method="post"
                                  id="vat-tax-{{$systemTaxVat?->id }}-status-form">
                                @csrf
                                <input type="hidden" name="id" value="{{ $systemTaxVat?->id }}">
                                <input type="hidden" name="tax_payer" value="vendor">
                                <label class="switcher">
                                    <input
                                        class="switcher_input custom-modal-plugin"
                                        type="checkbox" value="1" name="is_active"
                                        id="vat-tax-{{ $systemTaxVat?->id }}-status"
                                        {{ $systemTaxVat?->is_active == 1 ? 'checked' : '' }}
                                        data-modal-type="input-change-form"
                                        data-modal-form="#vat-tax-{{ $systemTaxVat?->id }}-status-form"
                                        data-on-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-on.svg') }}"
                                        data-off-image="{{ dynamicAsset(path: 'public/assets/backend/media/toggle-modal-icons/turn-off.svg') }}"
                                        data-on-title="{{ translate('want_to_Turn_ON_the_status') }} ?"
                                        data-off-title="{{ translate('want_to_Turn_OFF_the_status') }} ?"
                                        data-on-message="<p>{{ translate('do_you_want_to_turn_on_the_vat_status_from_your_system').' '.translate('it_will_effect_on_tax_calculation_&_report') }}</p>"
                                        data-off-message="<p>{{ translate('do_you_want_to_turn_off_the_vat_status_from_your_system').' '.translate('it_will_effect_on_tax_calculation_&_report') }}</p>">
                                    <span class="switcher_control"></span>
                                </label>
                            </form>

                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card card-body d-flex flex-column gap-4 mt-4 {{ $systemTaxVat?->is_active == 1 ? '' : 'disabled' }}">
            <form action="{{ route('admin.vat-tax.systemTaxVatStore') }}" method="post" class="setup-vat-tax-config-form">
                @csrf
                <input type="hidden" name="country_code" value="{{ $country_code ?? ($systemTaxVat?->country_code ?? null) }}">
                <input type="hidden" name="id" value="{{ $systemTaxVat?->id }}">

                <div class="bg-section rounded p-12 p-sm-20">
                    <h3 class="mb-20">{{ translate('vat_calculation_based_on_product_price') }}</h3>
                    <div class="bg-white p-3 rounded border">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="form-check d-flex gap-3">
                                    <input class="form-check-input radio--input radio--input_lg" type="radio"
                                           name="tax_status" id="include_product_price" value="include"
                                        {{ !$systemTaxVat || $systemTaxVat?->is_included == 1 ? 'checked' : '' }}>

                                    <label for="include_product_price">
                                        <div class="flex-grow-1">
                                            <div class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                {{ translate('calculate_VAT_include_product_price') }}
                                            </div>
                                            <p class="fs-12 mb-2">
                                                {{ translate('By_selecting_this_option_VAT_is_included_in_the_product_price.') }}
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check d-flex gap-3">
                                    <input class="form-check-input radio--input radio--input_lg" type="radio"
                                           value="exclude" name="tax_status" id="exclude_product_price"
                                        {{ $systemTaxVat && $systemTaxVat?->is_included == 0 ? 'checked' : '' }}
                                    >
                                    <label for="exclude_product_price">
                                        <div class="flex-grow-1">
                                            <div class="form-label text-dark fw-semibold mb-1 text-capitalize">
                                                {{ translate('calculate_VAT_exclude_product_price') }}
                                            </div>
                                            <p class="fs-12 mb-2">
                                                {{ translate('VAT_is_added_on_top_of_the_item_price._Use_this_option_to_apply_the_VAT_type.') }}
                                            </p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-section rounded p-12 p-sm-20 exclude_product_price_details {{ !$systemTaxVat || $systemTaxVat?->is_included == 1 ? 'disabled' : '' }}">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div>
                                <h3 class="mb-1">{{ translate('Basic_Setup') }}</h3>
                                <p class="fs-12 mb-0">{{ translate('here_you_can_setup_your_vat_type_&_vat_rate_for_the_vat_type.') }}</p>
                            </div>
                            <div class="product_wise_content mt-2 {{ $systemTaxVat?->tax_type == 'product_wise' ? 'd-block' : 'd-none' }}">
                                <div class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-triangle-warning text-danger"></i>
                                    <span>
                                       {{ translate('when_you_change') }}
                                        <span class="fw-semibold">{{ translate('Vat_Type') }}</span>
                                        {{ translate('to_product_wise.') }}
                                        {{ translate('vendors_will_have_control_to_setup_the_taxes_of_their_products.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="">
                                <label class="form-label" for="select_vat_type">
                                    {{ translate('Select_Vat_Type') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <div class="select-wrapper">
                                    <select class="form-select" id="select_vat_type" name="tax_type">
                                        @php($tax_calculate_on = $tax_payer == 'vendor' ? 'tax_calculate_on' : 'tax_calculate_on_' . $tax_payer)
                                        @php($taxTypes = isset($systemData[$tax_calculate_on]) ? $systemData[$tax_calculate_on] : ['order_wise', 'product_wise', 'category_wise'])
                                        @foreach ($taxTypes as $item)
                                            <option {{ $systemTaxVat?->tax_type == $item ? 'selected' : '' }} value="{{ $item }}" data-target=".{{ $item }}_content">
                                                {{ ucwords(translate($item)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="order_wise_content mt-4 {{ !$systemTaxVat || in_array($systemTaxVat?->tax_type, ['order_wise']) ? '' : 'd-none' }}">
                                <label class="form-label" for="">
                                    {{ translate('Select_Vat_Rate') }}
                                    <span class="input-required-icon">*</span>
                                </label>
                                <select class="custom-select multiple-select2" name="tax_ids[]" multiple="multiple"
                                        data-placeholder="{{ translate('type_&_select_vat_rate') }}"
                                    {{ in_array($systemTaxVat?->tax_type, ['order_wise']) ? 'selected' : '' }}
                                >
                                    <option></option>
                                    @foreach ($taxVats as $taxVat)
                                        <option
                                            {{ in_array($taxVat->id, $systemTaxVat?->tax_ids ?? []) ? 'selected' : '' }}
                                            value="{{ $taxVat->id }}"> {{ $taxVat->name }}
                                            ({{ $taxVat->tax_rate }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="category_wise_content mt-2 {{ $systemTaxVat?->tax_type == 'category_wise' ? '' : 'd-none' }}">
                                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                                    <span>
                                        {{ translate('please_specify_the_vat_rate_while_creating_a_category_from') }}
                                        <a href="{{ route('admin.category.view') }}" target="_blank"
                                           class="text-decoration-underline fw-semibold">
                                            {{ translate('category_list.') }}
                                        </a>
                                        {{ translate('if_you_already_created_category_without_vat_then_go_to_category_edit_&_update_vat.') }}
                                    </span>
                                </div>
                            </div>
                            <div class="product_wise_content mt-2 {{ $systemTaxVat?->tax_type == 'product_wise' ? '' : 'd-none' }}">
                                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                                    <span>
                                        {{ translate('please_specify_the_vat/tax_rate_while_creating_a_item_from') }}
                                        <a href="{{ route('admin.products.list', ['in-house']) }}"
                                           class="text-decoration-underline fw-semibold" target="_blank">
                                            {{ translate('In_House_Product_list.') }}
                                        </a>
                                        {{ translate('if_you_already_created_product_without_tax_then_go_to_edit_product_and_update_tax.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @php($additional_tax = $tax_payer == 'vendor' ? 'additional_tax' : 'additional_tax_' . $tax_payer)
                @if (isset($systemData[$additional_tax]))
                    <div class="bg-section rounded p-12 p-sm-20 exclude_product_price_details">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div>
                                    <h3 class="mb-1">{{ translate('Additional_Setup') }}</h3>
                                    <p class="fs-12 mb-0">{{ translate('here_you_can_setup_vat_for_additional_charges.') }}</p>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                @foreach ($systemData[$additional_tax] as $item)
                                    @php($additionalData = $systemTaxVat?->additionalData?->where('name', $item)->first())
                                    <div class="">
                                        <div class="form-label d-flex gap-2 justify-content-between align-items-center">
                                            <span class="text-capitalize">{{ translate($item) }}</span>
                                            <label class="switcher" for="services__charge_{{ $item }}">
                                                <input type="checkbox" class="switcher_input" value="1"
                                                       name="additional_status[{{ $item }}]"
                                                       id="services__charge_{{ $item }}"
                                                    {{ $additionalData?->is_active ? 'checked' : '' }}>
                                                <span class="switcher_control"></span>
                                            </label>
                                        </div>
                                        <select class="custom-select multiple-select2" multiple="multiple"
                                                data-placeholder="{{ translate('type_&_select_vat_rate') }}"
                                                id="additional_charge_{{ $item }}"
                                                name="additional[{{ $item }}][]">
                                            <option></option>
                                            @foreach ($taxVats as $taxVat)
                                                <option {{ in_array($taxVat->id, $additionalData?->tax_ids ?? []) ? 'selected' : '' }}
                                                    value="{{ $taxVat->id }}">
                                                    {{ $taxVat->name }} ({{ $taxVat->tax_rate }}%)
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                <div class="d-flex flex-wrap justify-content-end gap-3 mt-3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">
                        {{ translate('Reset') }}
                    </button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('Save information') }}
                    </button>
                </div>
            </form>
        </div>

    </div>

    @include("taxmodule::6valley.offcanvas._setup-vat-tax-calculation")
@endsection

@push('script')
    <script>
        'use strict';
        $(document).ready(function () {
            function toggleVatCalcOption() {
                if ($('#exclude_product_price').prop('checked')) {
                    $('.exclude_product_price_details').removeClass('disabled');
                } else {
                    $('.exclude_product_price_details').addClass('disabled');
                }
            }

            function selectVatType() {
                $('.order_wise_content, .category_wise_content, .product_wise_content').addClass('d-none');

                let $selectedOption = $('#select_vat_type option:selected');
                let targetSelector = $selectedOption.data('target');

                if (targetSelector) {
                    $(targetSelector).removeClass('d-none');
                }
            }

            toggleVatCalcOption();
            selectVatType();

            $('#exclude_product_price, #include_product_price').on('change', toggleVatCalcOption);
            $('#select_vat_type').on('change', selectVatType);

            $('.setup-vat-tax-config-form').on('reset', function () {
                requestAnimationFrame(() => {
                    toggleVatCalcOption();
                    selectVatType();
                });
            });
        });

    </script>
@endpush
