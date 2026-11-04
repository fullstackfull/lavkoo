@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Social_Media') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse3rdPartyLogin_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('social_media_chat') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapse3rdPartyLogin_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_allows_you_to_enable_customer_communication_through_social_media._currently,_whatsapp_chat_functionality_is_supported.') }}
                        {{ translate('to_make_it_available,_you_need_to_activate_the_status_and_enter_a_whatsapp_number.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse3rdPartyLogin_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('3rd-social_media_login') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapse3rdPartyLogin_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_section_allows_customers_to_log_in_using_their_social_media_accounts.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('currently,_google,_facebook,_and_apple_login_functionalities_are_supported.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('to_make_these_options_available,_you_need_to_configure_the_necessary_credentials,_which_can_be_obtained_from_the_respective_social_media_platforms.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
