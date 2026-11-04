@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Header') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorRegHeader_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Header_section') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseVendorRegHeader_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('the_vendor_registration_page_highlights_key_information_to_encourage_vendors_to_join_our_system.') }}
                         {{ translate('different_setup_options_are_organized_within_separate_tabs.') }}
                    </p>
                    <h5> {{ translate('header_section_setup') }}</h5>
                    <p class="fs-12">
                         {{ translate('use_this_section_to_highlight_the_pages_purpose._to_complete_the_setup,_add_a_title,_subtitle,_and_image_that_clearly_define_the_page_objectives_for_users') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
