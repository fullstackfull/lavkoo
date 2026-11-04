@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Software_Update') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSoftwareUpdate_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('software_update_(upload_the_updated_file)') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseSoftwareUpdate_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('in_this_page,_you_will_get_the_information_of_which_version_of_the_system_you_are_using_currently_and_what_is_the_latest_version_available_for_update.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('when_the_latest_version_is_available_for_update,_you_can_get_the_option_to_upload_the_updated_file.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
