@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Business_Process') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorRegBusinessProcess_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Business_Process') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseVendorRegBusinessProcess_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_describes_the_setup_of_the_business_process_for_vendors.') }}
                    </p>
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>
                            {{ translate('to_configure_this_page,_begin_by_enabling_its_status_to_make_it_active.') }}
                        </li>
                        <li>
                            {{ translate('three_distinct_sections_are_provided_for_the_title,_subtitle,_and_image.') }}
                        </li>
                        <li>
                            {{ translate('please_add_the_relevant_content_and_upload_the_image_to_clearly_illustrate_the_business_process_to_prospective_vendors.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>
