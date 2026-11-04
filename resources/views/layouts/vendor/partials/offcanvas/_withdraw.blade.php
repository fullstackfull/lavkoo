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
                            data-target="#withdrawSettings_01"
                            aria-expanded="true">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Current_Balance') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3 show" id="withdrawSettings_01">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('total_available_balance_for_withdrawal') }}.
                            
                        </p>
                    </div>
                </div>
            </div>

     
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                            type="button"
                            data-toggle="collapse"
                            data-target="#withdrawSettings_02"
                            aria-expanded="false">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Requested_Balance') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3" id="withdrawSettings_02">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('amount_currently_requested_but_not_yet_approved') }}.
                        </p>
                    </div>
                </div>
            </div>

            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                            type="button"
                            data-toggle="collapse"
                            data-target="#withdrawSettings_03"
                            aria-expanded="false">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Withdraw_Balance') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3" id="withdrawSettings_03">
                    <div class="card card-body">
                        <p class="fs-12">
                            {{ translate('amount_already_withdrawn_successfully') }}.
                        </p>
                    </div>
                </div>
            </div>
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                            type="button"
                            data-toggle="collapse"
                            data-target="#withdrawSettings_04"
                            aria-expanded="false">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('All_Request_List_Table') }}</span>
                    </button>
                </div>

                <div class="collapse mt-3" id="withdrawSettings_04">
                    <div class="card card-body">
                        <p class="fs-12">
                           -{{ translate('amount') }}: {{ translate('requested_withdrawal_amount') }}.
                        </p>
                        <p class="fs-12">
                           -{{ translate('Request_Timeount') }}: {{ translate('date_and_time_of_the_request') }}.
                        </p>
                        <p class="fs-12">
                           -{{ translate('Status') }}: {{ translate('shows_if_the_request_is') }},{{ translate('approved') }},{{ translate('pending') }},{{ translate('or_rejected') }}
                        </p>
                          <p class="fs-12">
                           -{{ translate('Action') }}: {{ translate('use_the_delete_button_to_cancel_pending_requests') }}.
                        </p>
                    </div>
                </div>
            </div>

           
        </div>
    </div>
</div>
