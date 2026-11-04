@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Invoice_Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInvoiceSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('general_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseInvoiceSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('keep_your_customers_informed_of_the_current_terms_&_conditions_by_showing_them_on_completed_invoices_through_this_settings.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInvoiceSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('business_identity') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseInvoiceSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('first,_activate_the_option_to_show_business_identity_details.') }}
                        {{ translate('next,_select_the_desired_business_identity_to_include_its_name_and_number_on_the_invoice.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseInvoiceSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('logo_on_invoice') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseInvoiceSetup_03">
                <div class="card card-body">
                    <p class="fs-12">
                       {{ translate('to_display_a_logo_on_your_invoices:_1._activate_the_logo_on_invoice_option._2._choose_an_existing_logo_from_the_selection_or_upload_a_new_logo_if_needed.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
