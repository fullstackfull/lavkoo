@include("layouts.vendor.partials.offcanvas._view-guideline-button")

<div class="offcanvas-sidebar guide-offcanvas" id="offcanvasSetupGuide" data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="text-capitalize m-0">{{ translate('Add_New_Product') }}</h3>
            <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapse3rdPartyMail_01" aria-expanded="true">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Add_New_Product') }}</span>
                    </button>

                </div>

                <div class="collapse mt-3 show" id="collapse3rdPartyMail_01">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('mail_configuration_refers_to_the_process_of_setting_up_and_managing_the_parameters_required_for_a_system,_application,_or_device_to_send_and_often_receive_emails.') }}
                            {{ translate('it_involves_specifying_the_necessary_server_details,_authentication_credentials,_and_other_settings_that_enable_the_proper_functioning_of_email_communication.') }}
                        </p>
                        <p class="fs-12">
                            {{ translate('for_email_functionality,_this_system_currently_offers_smtp_mail_and_sendGrid_as_options.') }}
                            {{ translate('please_note_that_only_one_of_these_methods_can_be_enabled_for_sending_emails_at_any_given_time.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
