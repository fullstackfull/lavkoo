@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('SMS_Configuration') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse3rdPartySMS_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('SMS_Configuration') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapse3rdPartySMS_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('sms_configuration_refers_to_the_process_of_setting_up_and_managing_the_parameters_required_for_a_system,_application,_or_device_to_send_and_often_receive_messages.') }}
                        {{ translate('it_involves_specifying_the_necessary_api_keys,_ids_&_other_credentials,_that_enable_the_proper_functioning_of_sms.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('for_sms_functionality,_this_system_currently_offers_some_3rd_party_sms_modules_&_firebase_as_options.') }}
                        {{ translate('as_3rd_party_sms_module_these_options_are_available_to_the_system') }}
                    </p>
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>{{ translate('2_Factor') }}</li>
                        <li>{{ translate('MSG91') }}</li>
                        <li>{{ translate('Twillo') }}</li>
                        <li>{{ translate('Alphanet_SMS') }}</li>
                        <li>{{ translate('Releans') }}</li>
                        <li>{{ translate('Nexmo') }}</li>
                    </ul>
                    <p class="fs-12">
                        {{ translate('please_note_that_only_one_of_these_methods_can_be_enabled_for_sending_sms_at_any_given_time.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
