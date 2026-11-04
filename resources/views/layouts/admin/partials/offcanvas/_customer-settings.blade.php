@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Customer') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCustomerSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('customer_wallet') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseCustomerSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('when_the_wallet_feature_is_enabled,_customers_can_use_their_wallet_balance_to_pay_for_orders.') . ' ' .  translate('refunds_can_also_be_sent_directly_to_the_wallet_of_customer_for_easy_use_in_future_purchases.') }}
                    </p>
                    <h5>{{ translate('add_funds_to_wallet') }}</h5>
                    <p class="fs-12">
                        {{ translate('if_this_option_is_enabled,_customers_can_add_money_to_their_wallet_using_digital_payment_methods_like_bank_transfer,_mobile_wallets_etc.') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                            <strong>{{ translate('minimum_add_Amount:_') }}</strong>
                            {{ translate('the_smallest_amount_a_customer_can_add_to_their_wallet_at_one_time.') }}
                        </li>
                        <li>
                            <strong>{{ translate('maximum_add_Amount:_') }}</strong>
                            {{ translate('the_highest_amount_a_customer_can_add_in_one_transaction_or_within_a_set_time.') . ' ' . translate('this_helps_control_risk_and_manage_wallet_usage.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCustomerSettings_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('customer_loyalty_point') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCustomerSettings_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('this_setting_lets_the_admin_define_how_many_loyalty_points_are_equal_to_1_unit_currency') . ' ' . translate('(Ex:_$1_if_the_system_default_currency_is_dollar).') . ' ' . translate('it_helps_customers_understand_the_value_of_their_points_when_they_want_to_convert_them_to_wallet_money.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseCustomerSettings_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('customer_referral_earning_settings') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseCustomerSettings_03">
                <div class="card card-body">
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                            {{ translate('customer_referral_earning_settings_allow_you_to_specify_the_wallet_balance_reward_that_customers_will_receive_for_successfully_sharing_their_unique_referral_code_with_new_customers_who_then_make_a_purchase.') }}
                        </li>
                        <li>
                            {{ translate('this_setting_allows_the_admin_to_set_the_amount_(in_default_currency)_that_a_referring_customer_will_earn.') . ' ' . translate('the_reward_is_added_to_their_wallet_when_someone_they_refer_places_and_completes_their_first_order_on_the_platform.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
