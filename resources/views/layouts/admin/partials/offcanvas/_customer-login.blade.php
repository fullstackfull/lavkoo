@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Customer_Login') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCustomerLogin_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Login_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseCustomerLogin_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('within_this_login_section,_you_can_specify_the_login_methods_available_to_customers.') }}
                        {{ translate('due_to_the_dependencies_of_some_options_on_third-party_configurations,_it_is_recommended_to_verify_these_configurations_before_making_your_choices.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCustomerLogin_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Verification') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCustomerLogin_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('when_logging_in_for_the_first_time,_customers_will_need_to_verify_their_phone_number_or_email_address.') }}
                        {{ translate('in_this_setup,_you_can_choose_the_method_they_will_use_to_complete_this_verification.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
