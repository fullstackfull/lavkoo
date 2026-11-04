@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('delivery_men') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDMS_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Proof_of_Delivery') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseDMS_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_option_allows_your_delivery_man_to_upload_a_photo_as__proof_of_delivery_(POD)_when_an_order_is_successfully_handed_over_to_the_customer.') }}
                        <br>
                        {{ translate('it_makes_things_clearer_and_helps_if_there_are_any_questions_about_the_delivery.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseDMS_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('forget_Password_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseDMS_02">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('delivery_men_who_forget_their_password_should_click_forgot_password_on_the_login_page.') }}
                         {{ translate('they_will_then_follow_the_steps,_usually_using_their_phone_or_email,_to_create_a_new_password_and_get_back_to_delivering.') }}
                         {{ translate('this_setup_typically_involves_providing_a_registered_email_address_or_phone_number_to_receive_a_verification_code,_ensuring_a_secure_way_to_recover_their_account.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
