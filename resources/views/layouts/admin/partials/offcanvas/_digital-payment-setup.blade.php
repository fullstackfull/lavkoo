@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Digital_Payment') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDPS_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('available_digital_payment_method') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseDPS_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_lists_all_digital_payment_methods_integrated_into_the_system.') }}
                        {{ translate('each_method_must_be_configured_before_it_can_be_used_for_payments.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDPS_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('digital_payment_methods_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseDPS_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('configuring_a_digital_payment_method_from_the_available_list_involves_these_steps:') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                            {{ translate('enable_the_status_of_the_payment_method.') }}
                        </li>
                        <li>
                            {{ translate('provide_the_required_credentials,_which_you_will_receive_from_the_digital_payment_service_provider.') }}
                        </li>
                        <li>
                            {{ translate('save_the_filled_form_to_activate_the_payment_method_for_use') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>
