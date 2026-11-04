@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Email_Template') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseET_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('get_Email') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseET_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_allows_you_to_activate_email_notifications_that_will_be_sent_to_users_when_designated_actions_are_performed_or_events_are_completed.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseET_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Editor') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseET_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_allows_you_to_create_custom_email_templates_that_are_triggered_by_specific_actions_or_events.') . ' ' . translate('use_the_editor_provided_to_fully_set_up_your_template.') . ' ' . translate('a_live_preview_of_your_setup_is_shown_next_to_the_editor.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseET_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Preview') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseET_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_preview_displays_the_email_templates_that_have_been_set_up_in_the_editor_section.') }}
                        {{ translate('all_the_modifications_you_have_made_can_be_seen_here.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
