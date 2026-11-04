@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Delivery_Zone') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDeliveryRestriction_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('delivery_available_countries') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseDeliveryRestriction_01">
                <div class="card card-body">
                    <p class="fs-12">
                        <strong>{{ translate('Delivery_Available_Countries') }}:</strong>
                        {{ translate('if_enabled,_this_setting_allows_you_to_select_one_or_multiple_countries_where_your_products_can_be_shipped.') }}
                        {{ translate('admin_can_search_for_a_country_name.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDeliveryRestriction_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('delivery_available_zip_code_area') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseDeliveryRestriction_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('turning_on_delivery_available_zip_code_area_means_that_only_the_exact_postal_codes_you_have_entered_will_be_able_to_receive_deliveries.') }}
                         {{ translate('if_a_postal_code_is_not_listed,_you_will_not_deliver_there.') }}
                         {{ translate('admin_can_search_for_zip_codes.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
