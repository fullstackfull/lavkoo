@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Our_Commitments') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCompanyReliability_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Company_Reliability') }}</span>
                </button>
            </div>

            <div class="collapse mt-3 show" id="collapseCompanyReliability_01">
                <div class="card card-body">
                    <h5> {{ translate('Company_Reliability') }}</h5>
                    <p class="fs-12">
                         {{ translate('this_section_allows_you_to_add_promotional_content_intended_to_inform_customers_about_your_company_strengths_and_values.') }}
                         {{ translate('by_incorporating_icons_and_relevant_text,_you_can_effectively_highlight_key_aspects_of_your_companys_reliability_to_your_customer.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCompanyReliability_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Section_Preview') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCompanyReliability_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_information_configured_in_the_various_cards_to_highlight_key_aspects_of_the_company_can_be_reviewed_in_this_section_preview.') }}
                    </p>

                </div>
            </div>
        </div>

    </div>
</div>
