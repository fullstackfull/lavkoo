@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('reCAPTCHA') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse3rdPartyRecapcha_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('reCAPTCHA') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapse3rdPartyRecapcha_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('to_configure_and_enable_reCAPTCHA_for_this_system,_please_follow_the_instructions_below:') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                             {{ translate('first,_activate_the_reCAPTCHA_status.') }}
                        </li>
                        <li>
                             {{ translate('next,_provide_the_necessary_credentials_for_the_specific_reCAPTCHA_version_you_are_using.') }}
                             {{ translate('refer_to_the_how_to_get_credentials_guide_for_instructions_on_obtaining_these.') }}
                        </li>
                        <li>
                             {{ translate('finally,_save_the_entered_credentials_to_activate_reCAPTCHA.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>
