@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('App_Settings') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('app_version_control') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseAppSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('the_app_version_control_setup_provides_the_functionality_to_manage_all_application_versions_with_the_latest_system_updates.') }}
                         {{ translate('this_section_facilitates_the_management_of_the_most_recent_system-compatible_app_version_and_assists_users_in_updating_accordingly.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('for_android') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseAppSettings_02">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_section_provides_the_functionality_to_manage_the_versions_for_which_forced_app_updates_will_be_implemented_and_to_configure_the_download_url_for_your_android_applications.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('For_iOS') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseAppSettings_03">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_section_provides_the_functionality_to_manage_the_versions_for_which_forced_app_updates_will_be_implemented_and_to_configure_the_download_url_for_your_ios_applications.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('minimum_version_for_force_update') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseAppSettings_04">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('a_minimum_version_for_force_update_refers_to_the_lowest_app_version_that_your_system_will_no_longer_support,_requiring_users_on_that_version_(or_older)_to_update_to_a_newer_version_to_continue_using_the_app.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
