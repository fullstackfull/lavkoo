@include("taxmodule::6valley.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <div>
            <h3 class="mb-1">{{ translate('Setup_Vat/Tax_Calculation') }}</h3>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('VAT/Tax_Settings') }} </span>
                </button>
            </div>

            <div class="collapse mt-3 show" id="collapseFirebaseConfig_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('This_page_allows_you_to_manage_your_entire_tax_configuration_for_the_vendors.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('accurately_calculate_charges_on_products.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('From_enabling_VAT/GST_to_choosing_whether_VAT/TAX_is_included_in_or_added_to_prices,_you_have_full_control.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('You_can_apply_VAT/Taxes_order-wise,_product-wise,_category-wise,_ensuring_accurate_and_compliant_billing_across_all_operations.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('VAT_Calculation_Method') }}</span>
                </button>
            </div>
            <div class="collapse mt-3" id="collapseFirebaseConfig_03">
                <div class="card card-body">
                    <p> {{ translate('Include_in_Product_Price_When_you_select_Calculate_VAT/Tax_Included_in_Product_Price:') }}</p>
                    <p class="fs-12">-{{ translate('The_vat/tax_is_already_built_into_the_product_price.') }}</p>
                    <p class="fs-12">-{{ translate('No_separate_vat/tax_line_will_be_shown_on_invoices_or_reports.') }}</p>
                    <p class="fs-12">-{{ translate('VAT/TAX_reports_will_not_be_generated_from_these_totals.') }}</p>
                    <p class="fs-12">-{{ translate('Orders_will_show_a_label_like:_VAT/Tax_Included.') }}</p>
                    <p> {{ translate('Exclude_from_Product_Price:') }}</p>
                    <p class="fs-12">-{{ translate('VAT/Tax_is_calculated_on_top_of_the_product_price_and_added_as_a_separate_amount.') }}</p>
                    <p class="fs-12">-{{ translate('The_VAT/TAX_appears_as_a_distinct_line_item_on_bills,_invoices,_and_order_summaries.') }}</p>
                    <p class="fs-12">-{{ translate('This_enables_accurate,_detailed_VAT/GST_reporting_for_compliance_and_accounting.') }}</p>
                </div>
            </div>
        </div>


        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('VAT/Tax_Effect_on_Order') }}</span>
                </button>
            </div>
            <div class="collapse mt-3" id="collapseFirebaseConfig_04">
                <div class="card card-body">
                    <p class="fw-bold"> 1. {{ translate('Before_Activating_VAT/TAX_Status:') }}</p>
                    <p class="fs-12">{{ translate('Orders_placed_before_the_VAT/Tax_status_is_turned_ON_will_be_calculated_without_VAT/Tax.') }}</p>
                    <p class="fs-12">{{ translate('The_system_will_not_apply_any_VAT/Tax_to_these_orders.') }}</p>
                    <p class="fw-bold"> 2. {{ translate('After_Activating_VAT/TAX_Status') }}</p>
                    <p class="fs-12">{{ translate('Once_the_VAT/Tax_status_is_activated_new_orders_placed_will_have_taxes_applied_based_on_your_VAT/Tax_setup.') }}</p>
                    <p class="fs-12">{{ translate('The_system_will_calculate_and_apply_the_correct_VAT/Tax_to_these_new_orders.') }}</p>
                    <p class="fw-bold"> 3. {{ translate('If_Deactivating_VAT/Tax_Status:') }}</p>
                    <p class="fs-12">{{ translate('If_the_VAT/Tax_status_is_deactivated_again_any_new_orders_placed_will_be_calculated_without_VAT/Tax.') }}</p>
                    <p class="fs-12">{{ translate('VAT/Taxes_will_not_be_applied_to_the_new_orders_until_the_VAT/Tax_status_is_reactivated.') }}</p>

                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_05" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Flexible_VAT/Tax_Application_Options') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseFirebaseConfig_05">
                <div class="card card-body">
                    <p>{{ translate('VAT/Tax_can_be_applied_with_flexibility_depending_on_business_needs.') }}</p>
                    <p>{{ translate('The_VAT/Tax_is_already_built_into_the_product_price.') }}</p>
                    <p class="fs-12">- {{ translate('Order_Wise_VAT/Tax:_One_VAT/TAX_rate_applied_to_the_entire_order_total.') }}</p>
                    <p class="fs-12">- {{ translate('Product_Wise_VAT/Tax:_Different_VAT/TAX_rates_applied_individually_per_product.') }}</p>
                    <p class="fs-12">- {{ translate('Category_Wise_VAT/Tax:_Different_VAT/Tax_rates_apply_by_product_category.') }}</p>

                    <p class="fw-bold">{{ translate('Basic_Setup:') }}</p>
                    <p class="fs-12">{{ translate('In_the_basic_setup_you_can_select_VAT_type_(Order/Product/Category)') }}</p>
                    <p class="fs-12">{{ translate('You_can_also_select_one_or_multiple_VAT_rates_that_you_have_previously_created._The_selected_VAT/Tax_rates_will_be_automatically_applied_to_the_corresponding_order_product_or_category_during_calculation.') }}</p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_06" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Additional_Setup') }}</span>
                </button>
            </div>
            <div class="collapse mt-3" id="collapseFirebaseConfig_06">
                <div class="card card-body">
                    <p class="fs-12">{{ translate('The_Additional_Setup_section_allows_you_to_manage_how_taxes_are_applied_to_extra_charges.') }}</p>
                    <p class="fs-12">{{ translate('You_can_choose_whether_VAT/Tax_should_be_added_to_additional_fees_such_as_delivery_or_packaging_charges_associated_with_an_order.') }}</p>
                </div>
            </div>
        </div>
    </div>
</div>


