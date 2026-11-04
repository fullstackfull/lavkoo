@extends('layouts.admin.app')

@section('title', translate('Setup_Calculation'))

@push('css_or_js')
    <style>
        /*css Code*/
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="fs-20 mb-0">
                 {{ translate('Setup_Calculation') }}
            </h2>
        </div>
        <div class="card card-body mb-3 mb-sm-4">
            <div class="row align-items-center">
                <div class="col-md-8 col-xl-9">
                    <h3 class="fs-18 mb-1">{{ translate('allow_vat_calculation_for_vendor') }} ?</h3>
                    <p class="mb-0 fs-12">
                        {{ translate('to_active_vat_calculation_turn_on_the_status.') }}
                    </p>
                </div>

                <div class="col-md-4 col-xl-3">
                    <div class="mt-3 mt-md-0">
                        <label
                            class="d-flex justify-content-between align-items-center gap-3 border rounded px-20 py-3 user-select-none">
                            <span class="fw-medium text-dark">{{ translate('Status') }}</span>
                            <label class="switcher">
                            <input type="checkbox" class="switcher_input"
                                    id="vat_calc_status">

                                <span class="switcher_control"></span>
                            </label>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <form action="" method="post">
            <div class="card card-body d-flex flex-column gap-4 mt-4 vat_calc_details">
                <div class="bg-section rounded p-12 p-sm-20">
                    <h3 class="mb-20">{{ translate('vat_calculation_based_on_product_price') }}</h3>
                    <div class="bg-white p-3 rounded border">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="form-check d-flex gap-3">
                                    <input class="form-check-input radio--input radio--input_lg" type="radio"
                                        name="vat_calc_product_price" id="include_product_ptice" value="" checked>
                                    <div class="flex-grow-1">
                                        <label for="" class="form-label text-dark fw-semibold mb-1">
                                            {{ translate('calculate_vat_include_product_price') }}
                                        </label>
                                        <p class="fs-12 mb-2">
                                            {{ translate('by_selecting_this_option_you_will_need_to_setup_same_tax_rate_for_all_types_of_income_source.') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check d-flex gap-3">
                                    <input class="form-check-input radio--input radio--input_lg" type="radio" value=""
                                        name="vat_calc_product_price" id="exclude_product_ptice">
                                    <div class="flex-grow-1">
                                        <label for="" class="form-label text-dark fw-semibold mb-1">
                                            {{ translate('calculate_vat_exclude_product_price') }}
                                        </label>
                                        <p class="fs-12 mb-2">
                                            {{ translate('by_selecting_this_option_you_will_need_to_setup_individual_vat_rate_for_different_types_of_income_source.') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-section rounded p-12 p-sm-20 d-none exclude_product_price_details">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div>
                                <h3 class="mb-1">{{ translate('Basic_Setup') }}</h3>
                                <p class="fs-12 mb-0">{{ translate('here_you_can_setup_your_vat_type_&_vat_rate_for_the_vat_type.') }}</p>
                            </div>
                            <div class="product_wise_content mt-2">
                                <div class="bg-danger bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-triangle-warning text-danger"></i>
                                    <span>
                                       {{ translate(' when_you_change') }}
                                        <span class="fw-semibold">{{ translate('vat_type') }}</span>
                                        {{ translate('to_product_wise.') }}
                                        {{ translate('vendors_will_have_control_to_setup_the_taxes_of_their_products.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="">
                                <label class="form-label" for="">{{ translate('select_vat_type') }}</label>
                                <div class="select-wrapper">
                                    <select class="form-select" id="select_vat_type" name="">
                                        <option value="order" data-target=".order_wise_content" selected>{{ translate('order_wise') }}</option>
                                        <option value="category" data-target=".category_wise_content">{{ translate('category_wise') }}</option>
                                        <option value="product" data-target=".product_wise_content">{{ translate('product') }}</option>
                                    </select>

                                </div>
                            </div>
                            <div class="order_wise_content mt-4">
                                <label class="form-label" for="">{{ translate('select_vat_rate') }}</label>
                                <select class="custom-select multiple-select2" name="options[]" multiple="multiple" data-max-length="5" data-placeholder="{{ translate('type_&_select_vat_rate') }}">
                                    <option value="1">Option 01</option>
                                    <option value="2">Option 02</option>
                                    <option value="3">Option 03</option>
                                    <option value="4">Option 04</option>
                                    <option value="5">Option 05</option>
                                    <option value="6">Option 06</option>
                                    <option value="7">Option 07</option>
                                </select>
                            </div>
                            <div class="category_wise_content mt-2">
                                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                                    <span>
                                        {{ translate('please_specify_the_vat_rate_while_creating_a_category_from') }}
                                        <a href="#" class="text-decoration-underline fw-semibold">{{ translate('category_list') }}</a>.
                                        {{ translate('if_you_already_created_category_without_vat_then_go_to_category_edit_&_update_vat.') }}
                                    </span>
                                </div>
                            </div>
                            <div class="product_wise_content mt-2">
                                <div class="bg-info bg-opacity-10 fs-12 px-12 py-10 text-dark rounded d-flex gap-2 align-items-center">
                                    <i class="fi fi-sr-lightbulb-on text-info"></i>
                                    <span>
                                        {{ translate('please_specify_the_tax_rate_while_creating_a_item_from') }}
                                        <a href="#" class="text-decoration-underline fw-semibold">{{ translate('item_list') }}</a>.
                                        {{ translate('if_you_already_created_items_without_tax_then_go_to_edit_item_and_update_tax.') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-section rounded p-12 p-sm-20 d-none exclude_product_price_details">
                    <div class="row g-4">
                        <div class="col-lg-6">
                            <div>
                                <h3 class="mb-1">{{ translate('Additional_Setup') }}</h3>
                                <p class="fs-12 mb-0">{{ translate('here_you_can_setup_vat_for_additional_charges.') }}</p>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="">
                                <label class="form-label d-flex gap-2 justify-content-between align-items-center" for="">
                                    <span>{{ translate('vat_on_delivery_charge') }}</span>
                                    <label class="switcher">
                                        <input type="checkbox" class="switcher_input" value="" checked="" id="" name="">
                                        <span class="switcher_control"></span>
                                    </label>
                                </label>
                                <select class="custom-select multiple-select2" name="options[]" multiple="multiple" data-max-length="5" data-placeholder="{{ translate('type_&_select_vat_rate') }}">
                                    <option value="1">VAT (5%)</option>
                                    <option value="2">GST (7%)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-end gap-3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('Reset') }}</button>
                    <button type="button" class="btn btn-primary px-3 px-sm-4">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('Save_information') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('script')
    <script>
        'use strict';
        $(document).ready(function () {

            function toggleVatCalcStatus() {
                if ($('#vat_calc_status').prop('checked')) {
                    $('.vat_calc_details').removeClass('disabled');
                } else {
                    $('.vat_calc_details').addClass('disabled');
                }
            }

            toggleVatCalcStatus();
            $('#vat_calc_status').on('change', toggleVatCalcStatus);

            function toggleVatCalcOption() {
                if ($('#exclude_product_ptice').prop('checked')) {
                    $('.exclude_product_price_details').removeClass('d-none');
                } else {
                    $('.exclude_product_price_details').addClass('d-none');
                }
            }

            toggleVatCalcOption();
            $('#exclude_product_ptice, #include_product_ptice').on('change', toggleVatCalcOption);

            function selectVatType() {
                $('.order_wise_content, .category_wise_content, .product_wise_content').addClass('d-none');

                let $selectedOption = $('#select_vat_type option:selected');
                let targetSelector = $selectedOption.data('target');

                if (targetSelector) {
                    $(targetSelector).removeClass('d-none');
                }
            }
            selectVatType();
            $('#select_vat_type').on('change', selectVatType);

        });
    </script>


@endpush
