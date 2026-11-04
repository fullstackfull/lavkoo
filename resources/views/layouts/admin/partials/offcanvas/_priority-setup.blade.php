@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Priority_Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePrioritySetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Priority_Setup_List_Sorting') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapsePrioritySetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_priority_setup_list_allows_you_to_determine_the_display_sequence_of_products,_categories,_brands,_and_stores_according_to_the_selected_criteria.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePrioritySetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('default_sorting_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapsePrioritySetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('during_the_list_arrangement_process,_one_option_will_be_automatically_selected.') }}
                         {{ translate('the_default_display_order_of_products,_categories,_brands,_and_stores_will_then_be_determined_by_this_selection.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePrioritySetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('custom_sorting_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapsePrioritySetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('to_customize_the_display_order_of_products,_categories,_brands,_and_stores_on_the_customer_website,_select_the_custom_sorting_list_option_and_arrange_them_as_per_your_choice.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
