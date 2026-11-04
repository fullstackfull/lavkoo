@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Download_App') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseVendorRegDownloadApp_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Download_App_Section') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseVendorRegDownloadApp_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('after_completing_the_registration_how_to_get_the_vendor_apps_are_described_in_this_section.') }}
                        {{ translate('here_with_the_section_content_vendors_will_get_the_download_links_of_android_&_ios_app_download.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('you_need_to_setup_the_section_title,_subtitle_&_image.') }}
                        {{ translate('and_also_need_to_add_the_url_of_the_android_&_ios_vendor_app_download.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('this_section_details_the_process_for_vendors_to_acquire_the_vendor_applications_following_registration.') }}
                        {{ translate('it_provides_the_download_links_for_both_android_and_ios_apps.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('to_configure_this_section,_you_will_need_to_specify_a_title,_subtitle,_and_image,_as_well_as_include_the_respective_urls_for_the_android_and_ios_vendor_app_downloads,_facilitating_access_for_vendors.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
