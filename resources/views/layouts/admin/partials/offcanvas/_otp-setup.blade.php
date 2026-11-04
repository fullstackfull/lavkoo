@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('OTP_&_login_attempts') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOTP_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('OTP_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseOTP_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_otp_setup_section_is_to_manage_customer_otp_attempts.') }}
                        {{ translate('use_this_section_to_set_the_maximum_number_of_tries,_the_time_before_a_resend_is_allowed,_and_the_duration_of_a_temporary_block_after_too_many_failed_attempts.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOTP_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Login_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseOTP_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_login_setup_section_is_to_manage_the_login_attempts.') }}
                        {{ translate('use_this_section_to_set_the_maximum_login_attempts_and_the_duration_of_a_temporary_block_after_too_many_failed_attempts.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
