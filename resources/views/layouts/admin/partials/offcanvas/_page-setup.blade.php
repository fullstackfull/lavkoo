@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Page_Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePageSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('business_page_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapsePageSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('this_section_provides_information_regarding_the_existing_business_pages_within_the_system.') }}
                         {{ translate('furthermore,_you_have_the_option_to_create_additional_pages_to_meet_specific_system_requirements.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('please_note_that_certain_essential_system_pages_cannot_be_deactivated_or_deleted,_and_only_their_content_is_editable.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapsePageSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('new_business_page_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapsePageSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_create_new_business_pages_that_meet_specific_system_requirements,_follow_the_below_procedure:') }}
                    </p>
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>
                            {{ translate('go_to_the_add_new_page_section.') }}
                        </li>
                        <li>
                            {{ translate('set_the_page_availability_status_to_on.') }}
                        </li>
                        <li>
                            {{ translate('upload_the_desired_title_background_image.') }}
                        </li>
                        <li>
                            {{ translate('input_the_page_title_and_description_content_using_the_provided_text_editor.') }}
                        </li>
                        <li>
                            {{ translate('finally,_save_all_the_information_to_add_the_new_page_to_the_list,_making_it_accessible_on_the_website.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>
