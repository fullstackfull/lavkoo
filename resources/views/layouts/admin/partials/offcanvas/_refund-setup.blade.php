@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Refund') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseRefundSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Refund_Order') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseRefundSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('when_you_refund_an_order_for_a_customer_in_dhaka,_the_money_will_automatically_go_back_into_their_customer_wallet.') }}
                         {{ translate('if_you_had_like_to_manage_these_settings_or_explore_different_refunds,_go_for_“click_here”.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('refund_order_validity_(days)_means_you_can_set_how_many_days_after_ordering_that_customers_can_ask_for_a_refund.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('add_refund_amount_to_wallet_status_indicates_whether_the_system_is_currently_set_to_automatically_credit_refunded_amounts_to_the_customers_wallet.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
