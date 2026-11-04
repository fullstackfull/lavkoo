@include('layouts.vendor.partials.offcanvas._view-guideline-button')

<div class="offcanvas-sidebar guide-offcanvas" id="offcanvasSetupGuide"
    data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">
    <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

    <div class="offcanvas-content bg-white shadow d-flex flex-column">
        <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
            <h3 class="text-capitalize m-0">{{ translate('Payment_Information') }}</h3>
            <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
            <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
                <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                    <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0"
                        type="button" data-toggle="collapse" data-target="#paymentInfoSetting01" aria-expanded="true">
                        <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                            <i class="fi fi-sr-angle-right"></i>
                        </div>
                        <span class="fw-bold text-start">{{ translate('Benefit') }}</span>
                    </button>

                </div>

                <div class="collapse mt-3 show" id="paymentInfoSetting01">
                    <div class="card card-body">
                        <p class="fs-12">
                            -{{ translate('save_your_withdrawal_methods_securely_for_faster_and_smoother_payouts') }}
                        </p>
                        <p class="fs-12">
                            -{{ translate('view_and_manage_your_saved_withdrawal_methods_with_account_details') }},{{ translate('status_and_quick_actions_like_edit_or_delete') }}.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
