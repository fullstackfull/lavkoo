@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Storage_Connection') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse3rdPartyMapApi_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('storage_connection_(s3_storage)') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapse3rdPartyMapApi_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('third-party_storage_refers_to_an_off-site_storage_facility_managed_by_a_commercial_provider.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('this_system_currently_offers_s3_storage_as_a_connection_option.') }}
                        {{ translate('to_enable_s3_storage_for_the_system,_you_will_need_to_provide_your_s3_credentials_and_save_all_the_necessary_information.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
