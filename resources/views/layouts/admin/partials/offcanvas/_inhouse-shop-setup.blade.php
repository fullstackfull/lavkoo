@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Shop_Settings') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInhouseSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('store_availability') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseInhouseSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_store_availability_option_allows_you_to_show_or_hide_your_store_and_its_products_from_customers.') }}
                        {{ translate('if_you_turn_the_status_off,_customers_will_no_longer_see_your_store_and_products_in_the_available_listings.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInhouseSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('shop_details') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseInhouseSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_shop_details_section_displays_key_information_about_your_stores,_such_as_their_current_availability,_the_date_they_were_created,_and_other_relevant_details.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInhouseSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('visit_website') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseInhouseSetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('selecting_the_visit_website_option_will_redirect_you_to_the_store_details_page_on_the_customer_website,_where_your_stores_information_can_be_viewed.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInhouseSetup_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('edit_shop') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseInhouseSetup_04">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('if_you_want_to_update_the_store_information,__you_can_go_to_the_edit_shop_page_to_modify_the_existing_information_as_per_your_choice.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
