@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Configuration') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseConfig_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Firebase_Configuration') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseFirebaseConfig_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('firebase_configuration_for_push_notification_send_refers_to_the_process_of_setting_up_and_integrating_your_application_(web,_android,_ios,_or_other_supported_platforms)_with_firebase_cloud_messaging_(fcm)_to_enable_the_sending_of_push_notifications_to_users.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('completing_firebase_configuration_requires_obtaining_the_necessary_information_and_credentials_from_your_firebase_project_setup_and_associating_this_system_with_that_project.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
