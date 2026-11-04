@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Offline_Payment') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOffinePaymentSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('offline_payment_methods_list') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseOffinePaymentSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_displays_all_offline_payment_methods_available_for_use.') }}
                        {{ translate('for_each_method,_you_will_find_the_payment_account_information_and_details_on_what_the_customer_needs_to_provide_during_payment.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOffinePaymentSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('offline_payment_method_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseOffinePaymentSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_configure_an_offline_payment_method,_follow_these_steps:') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('payment_information') }}: {{ translate('enter_the_payment_method_name_and_account_details_in_the_provided_fields.') }}
                        </li>
                        <li>
                            {{ translate('information_required_from_customer') }}: {{ translate('select_the_specific_information_that_customers_must_provide_for_verification_of_this_offline_payment_method.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOffinePaymentSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('edit_payment_method') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseOffinePaymentSetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_update_the_payment_information_and_required_customer_information_for_a_specific_offline_payment_method,_find_it_in_the_list_and_click_the_edit_button.') }}
                        {{ translate('you_can_then_modify_its_details.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
