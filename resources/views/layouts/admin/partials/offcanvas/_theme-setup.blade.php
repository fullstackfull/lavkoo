@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Theme_Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseThemeSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Theme_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseThemeSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_install_a_new_theme_on_the_system') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('you_must_first_purchase_the_desired_theme_and_then_upload_its_file_to_the_system_for_setup.') }}
                        </li>
                        <li>
                            {{ translate('after_the_theme_file_is_successfully_uploaded,_you_will_be_given_the_option_to_activate_it_immediately') }}
                        </li>
                        <li>
                            {{ translate('if_you_wish_to_activate_the_theme_later,_navigate_to_the_available_themes_list,_select_the_desired_theme,_and_click_activate') }}
                        </li>
                        <li>
                            {{ translate('theme_activation_requires_your_codecanyon_username_and_the_purchase_code_associated_with_that_theme.') }}
                        </li>
                        <li>
                            {{ translate('upon_successful_activation,_a_popup_message_will_inform_you_about_the_visual_changes_implemented_by_the_theme_in_the_relevant_sections_of_the_system.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseThemeSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Available_Themes') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseThemeSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_available_themes_list_displays_all_uploaded_themes.') }}
                        {{ translate('you_can_select_a_theme_from_this_list_to_apply_it_to_the_website.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseThemeSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('explore_other_themes') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseThemeSetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('if_you_had_like_to_browse_more_themes_specifically_designed_for_this_system,_the_view_themes_option_will_lead_you_to_a_page_showcasing_them') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseThemeSetup_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Theme_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseThemeSetup_04">
                <div class="card card-body">
                   <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('you_can_change_the_websites_theme_by_selecting_a_different_option_from_the_list_of_available_themes.') }}
                        </li>
                        <li>
                             {{ translate('if_a_theme_was_not_activated_upon_upload,_activation_is_required_after_selection.') }}
                        </li>
                        <li>
                             {{ translate('once_activated,_you_only_need_to_select_the_theme_to_apply_it_to_the_website.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>
