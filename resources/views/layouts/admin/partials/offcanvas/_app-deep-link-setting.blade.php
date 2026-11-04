@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('App_Deeplink_Settings') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <!-- General Deep Link Setup -->
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('app_deep_link_setup') }}</span>
                </button>
            </div>

            <div class="collapse mt-3 show" id="collapseAppSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_explains_how_to_configure_android_and_ios_app_identifiers_and_store_redirect_links_to_enable_deep_links_and_fallback_redirects') }}
                        {{ translate('filling_correct_information_ensures_users_can_open_the_app_or_be_redirected_to_the_store_if_not_installed') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Android Deep Link Setup -->
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
                        {{ translate('enter_your_android_app_package_name_and_sha256_fingerprint_and_add_the_play_store_redirect_url_to_enable_secure_deep_links_and_redirect_users_if_the_app_is_not_installed') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- iOS Deep Link Setup -->
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseAppSettings_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('for_ios') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseAppSettings_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('enter_your_ios_app_bundle_id_and_team_id_and_add_the_app_store_redirect_url_to_enable_secure_deep_links_and_redirect_users_if_the_app_is_not_installed') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
