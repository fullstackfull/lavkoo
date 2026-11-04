@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Authentication') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseFirebaseAuth_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Firebase_Authentication') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseFirebaseAuth_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('firebase_authentication_is_a_powerful,_secure,_and_simple_solution_for_user_authentication_in_applications.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('once_firebase_is_configured,_you_can_find_the_credentials_for_this_authentication_setup_in_your_firebase_project') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
