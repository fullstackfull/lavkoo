@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('why_sell_with_us') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorRegWithUs_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('why_sell_with_us_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseVendorRegWithUs_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_section_highlights_the_benefits_of_vendors_joining_the_system.') }}
                         {{ translate('it_offers_two_setup_options:') }}
                    </p>
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>
                            {{ translate('one_for_the_section_title,_subtitle_&_image,_and_another_to_describe_the_reasons.') }}
                        </li>
                        <li>
                            {{ translate('all_reasons_with_their_title,_subtitle_&_image_will_be_displayed_on_the_vendor_registration_page_and_listed_in_the_reason_list.') }}
                        </li>
                    </ul>
                    <p class="fs-12">
                         {{ translate('this_is_aimed_at_attracting_vendors.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorRegWithUs_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Reason_List') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseVendorRegWithUs_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_reason_list_allows_you_to_manage_all_the_reasons_configured_in_the_reason_why_vendor_will_sell_business_with_you_section.') }}
                        {{ translate('from_this_list,_you_can_update_information,_change_priority,_update_status,_and_delete_reasons_as_needed.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
