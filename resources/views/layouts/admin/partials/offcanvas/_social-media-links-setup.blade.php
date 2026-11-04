@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Social_Media') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSocialMediaLinks_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('setup_social_media_link') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseSocialMediaLinks_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('in_the_setup_social_media_link_you_can_select_social_media_name_and_add_social_media_link._save_the_social_media_link_and_name.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSocialMediaLinks_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('social_media_link_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseSocialMediaLinks_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_social_media_link_list_provides_a_clear_overview_of_all_the_social_media_profiles._for_each_link,_you_will_see:') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('name') }}: {{ translate('the_platform_name_(e.g.,_facebook,_instagram,_twitter).') }}
                        </li>
                        <li>
                             {{ translate('social_media_link') }}: {{ translate('he_direct_url_to_your_profile_on_that_platform.') }}
                        </li>
                        <li>
                             {{ translate('status') }}: {{ translate('indicates_whether_the_link_is_currently_enabled_or_disabled.') }}
                        </li>
                        <li>
                             {{ translate('actions') }}: {{ translate('options_to_edit_the_link_details_or_delete_the_link_from_your_list.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSocialMediaLinks_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('edit_social_media_link') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseSocialMediaLinks_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('after_clicking_the_edit_button,_you_can_change_the_Social_Media_name_and_Social_Media_Link_fields.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
