@include("layouts.vendor.partials.offcanvas._view-guideline-button")

<div class="offcanvas-sidebar guide-offcanvas" id="offcanvasSetupGuide" data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="text-capitalize m-0">{{ translate('Shop_Settings') }}</h3>
            <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">

            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                            type="button"
                            data-toggle="collapse"
                            data-target="#shopOtherSetupSettings_01"
                            aria-expanded="true">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Order_Setup') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3 show" id="shopOtherSetupSettings_01">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('configure_minimum_order_amount_free_delivery_threshold_and_product_reorder_level_to_optimize_order_flow_and_stock_management.') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                            type="button"
                            data-toggle="collapse"
                            data-target="#shopOtherSetupSettings_02"
                            aria-expanded="false">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Business_Tin') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3" id="shopOtherSetupSettings_02">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('add_your_tin_number_upload_the_certificate_and_set_the_expiry_date_to_stay_tax_compliant') }}.
                        </p>
                    </div>
                </div>
            </div>


        </div>
    </div>
</div>
