@include("taxmodule::6valley.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <div>
            <h3 class="mb-1">{{ translate('Vendor_VAT_Report') }}</h3>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_07" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Vendor_VAT_Report') }}</span>
                </button>
            </div>
            <div class="collapse mt-3 show" id="collapseFirebaseConfig_07">
                <div class="card card-body">
                    <p> {{ translate('You_can_easily_filter_the_vendor_VAT_report_to_view_only_the_data_you_need._Select_an_individual_vendor_to_see_their_specific_VAT_records,_and_apply_a_date_range_to_narrow_the_report_to_a_particular_period.') }}</p>
                    <p> {{ translate('This_helps_you_analyze_vendor-wise_tax_details_more_accurately_and_generate_focused_reports_for_accounting_or_auditing_purposes.') }}</p>
                    <p>{{translate('This_section_displays_key_statistical_values_to_give_you_a_quick_overview_of_your_business_performance.')}}</p>
                    <p>{{translate('You_can_see_the_total_number_of_orders_placed,_the_overall_order_amount,_and_the_total_VAT_amount_collected_within_the_selected_period._These_insights_help_you_track_sales_and_tax_performance_at_a_glance')}}</p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_08" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('VAT_Detail_(for_specific_vendor)') }}</span>
                </button>
            </div>
            <div class="collapse mt-3" id="collapseFirebaseConfig_08">
                <div class="card card-body">
                    <p> {{ translate('This_section_provides_key_statistical_values_to_give_you_a_quick_overview_of_a_specific_vendor’s_business_performance.') }}</p>
                    <p> {{ translate('You_can_also_export_the_vendor_VAT_list_for_reporting_or_record-keeping_purposes.') }}</p>
                </div>
            </div>
        </div>

    </div>
</div>
