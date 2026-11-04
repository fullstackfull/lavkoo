@php use App\Utils\Convert; @endphp
@extends('layouts.admin.app')

@section('title', translate('customer_settings'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3 mb-sm-20">
            <h2 class="h1 mb-0 text-capitalize d-flex align-items-center gap-2">
                {{ translate('business_Setup') }}
            </h2>
        </div>
        @include('admin-views.business-settings.business-setup-inline-menu')

        <form action="{{ route('admin.business-settings.customer-settings') }}" method="post" id="update-settings">
            @csrf

            <div class="d-flex flex-column gap-3">
                <div class="card">
                    <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            @php($walletStatus = getWebConfig(name: 'wallet_status'))
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <div>
                                    <h2>{{ translate('Customer_Wallet') }}</h2>
                                    <p class="mb-0">
                                        {{ translate('for_these_wallet_settings_customers_can_get_the_refund_to_the_wallet_and_also_can_use_their_wallet_money_to_pay_for_any_order') }}
                                    </p>
                                </div>
                                <div>
                                    <label class="switcher" for="customer-wallet-status">
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                            name="customer_wallet" id="customer-wallet-status"
                                            {{ $walletStatus == 1 ? 'checked' : '' }} data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-wallet-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/customer-wallet-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Customer_Wallet') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Customer_Wallet') }}"
                                            data-on-message="<p>{{ translate('if_enabled_customers_can_have_the_wallet_option_on_their_account_and_use_it_while_placing_orders_and_getting_refunds') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_customer_wallet_option_will_be_hidden_from_their_account') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4">
                                @php($addFundsToWallet = getWebConfig(name: 'add_funds_to_wallet'))
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="">
                                            {{ translate('add_funds_to_wallet') }}
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                data-bs-title="{{ translate('enabling_the_option_customers_will_be_able_to_add_funds_to_the_wallet_through_the_available_payment_method') }}.">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <label
                                            class="d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 bg-white user-select-none">
                                            <span class="fw-medium text-dark">{{ translate('status') }}</span>
                                            <label class="switcher" for="add-funds-to-wallet">
                                                <input class="switcher_input custom-modal-plugin" type="checkbox"
                                                    value="1" name="add_funds_to_wallet" id="add-funds-to-wallet"
                                                    {{ $walletStatus && $addFundsToWallet ? 'checked' : '' }}
                                                    {{ $walletStatus == 0 ? 'disabled' : '' }}
                                                    data-modal-type="input-change"
                                                    data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-on.png') }}"
                                                    data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/wallet-off.png') }}"
                                                    data-on-title="{{ translate('want_to_Turn_ON_Add_Fund_to_Wallet_option') }}"
                                                    data-off-title="{{ translate('want_to_Turn_OFF_Add_Fund_to_Wallet_option') }}"
                                                    data-on-message="<p>{{ translate('if_enabled_customers_can_add_money_to_their_wallet') }}</p>"
                                                    data-off-message="<p>{{ translate('if_disabled_customers_would_not_be_able_to_add_money_to_their_wallet') }}</p>">
                                                <span class="switcher_control"></span>
                                            </label>
                                        </label>
                                    </div>
                                </div>
                                @php($minimumAddFundAmount = getWebConfig(name: 'minimum_add_fund_amount'))
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="">
                                            {{ translate('minimum_Add_Amount') }}
                                            ({{ getCurrencySymbol(type: 'default') }})
                                        </label>
                                        <input type="text" class="form-control" name="minimum_add_fund_amount"
                                            id="minimum_add_fund_amount" placeholder="{{ translate('ex') . ': ' . '10' }}"
                                            value="{{ Convert::default($minimumAddFundAmount) ?? 0 }}"
                                            {{ $walletStatus == 0 ? 'disabled' : '' }}>
                                    </div>
                                </div>
                                @php($maximumAddFundAmount = getWebConfig(name: 'maximum_add_fund_amount'))
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="">
                                            {{ translate('maximum_Add_Amount') }}
                                            ({{ getCurrencySymbol(type: 'default') }})
                                        </label>
                                        <input type="text" class="form-control" name="maximum_add_fund_amount"
                                            id="maximum_add_fund_amount" placeholder="{{ translate('ex') . ': ' . '10' }}"
                                            value="{{ Convert::default($maximumAddFundAmount) ?? 0 }}"
                                            {{ $walletStatus == 0 ? 'disabled' : '' }}>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction'))
                <div class="card">
                    <div class="card-body">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <div>
                                    <h2>{{ translate('active_auction_for_customer') }}</h2>
                                    <p class="mb-0">
                                        {{ translate('if_enabled,_customers_can_create_auctions._once_this_feature_is_turned_off,_customer_cannot_create_new_auctions.') }}
                                    </p>
                                </div>
                                <div>
                                    @php($activeAuctionForCustomer = getWebConfig('active_auction_for_customer') ?? 0)
                                    <label class="switcher" for="auction-active-for-customer"
                                           @if(getWebConfig(name: 'auction_feature_status') != 1)
                                               data-bs-toggle="tooltip" data-bs-placement="left"
                                               data-bs-title="{{ translate('Auction_feature_is_currently_disabled._Go_to_Auction_Configuration_to_enable_it_first,_then_activate_this_setting.') }}"
                                            @endif
                                    >
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                            name="active_auction_for_customer" id="auction-active-for-customer"
                                            {{ getWebConfig(name: 'auction_feature_status') == 1 && $activeAuctionForCustomer == 1 ? 'checked' : '' }}

                                            @if(getWebConfig(name: 'auction_feature_status') != 1)
                                                disabled
                                            @endif

                                            data-modal-type="input-change"
                                            data-on-title="{{ translate('want_to_Turn_ON_Auction_for_Customer') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Auction_for_Customer') }}"
                                            data-on-message="<p>{{ translate('if_enabled,_customers_can_create_auctions.') }}</p>"
                                            data-off-message="<p>{{ translate('once_this_feature_is_turned_off,_customer_cannot_create_new_auctions.') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="card">
                    <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                        @php($loyaltyPointStatus = getWebConfig(name: 'loyalty_point_status'))
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <div>
                                    <h2 class="text-capitalize">
                                        {{ translate('Customer_Loyalty_Point') }}
                                    </h2>
                                    <p class="mb-0">
                                        {{ translate('in_this_settings_admin_can_set_the_rules_for_the_customers_for_earning_and_use_the_loyalty_points') }}
                                    </p>
                                </div>
                                <div>
                                    <label class="switcher" for="customer-loyalty-point">
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                            name="customer_loyalty_point" id="customer-loyalty-point"
                                            {{ $loyaltyPointStatus ? 'checked' : '' }} data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/loyalty-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/loyalty-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Loyalty_Point') }}"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Loyalty_Point') }}"
                                            data-on-message="<p>{{ translate('if_enabled_the_loyalty_point_option_will_be_available_to_the_customers_account') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_loyalty_point_option_will_be_hidden_from_the_customers_account') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4">
                                @php($loyaltyPointExchangeRate = getWebConfig(name: 'loyalty_point_exchange_rate'))
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="loyalty_point_exchange_rate">
                                            {{ translate('Equivalent_Points_Needed_to_Redeem') }}
                                            {{ setCurrencySymbol(amount: usdToDefaultCurrency(amount: 1), currencyCode: getCurrencyCode()) }}
                                        </label>
                                        <input type="text" class="form-control" name="loyalty_point_exchange_rate"
                                            {{ $loyaltyPointStatus == 0 ? '' : 'required' }}
                                            id="loyalty_point_exchange_rate"
                                            placeholder="{{ translate('ex') . ': ' . '10' }}"
                                            value="{{ $loyaltyPointExchangeRate ?? 0 }}" required>
                                    </div>
                                </div>
                                @php($loyaltyPointMinimumPoint = getWebConfig(name: 'loyalty_point_minimum_point'))
                                <div class="col-xl-4 col-md-6">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="minimum_transfer_point">
                                            {{ translate('minimum_Point_Required_To_Convert') }}
                                            <span class="tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="right"
                                                data-bs-title="{{ translate('this_point_is_the_required_amount_which_is_needed_to_convert_the_point_to_the_wallet_balance') }}">
                                                <i class="fi fi-sr-info"></i>
                                            </span>
                                        </label>
                                        <input type="text" class="form-control" name="minimum_transfer_point"
                                            id="minimum_transfer_point" placeholder="{{ translate('ex') . ': ' . '2' }}"
                                            {{ $loyaltyPointStatus == 0 ? '' : 'required' }}
                                            value="{{ $loyaltyPointMinimumPoint ?? 0 }}">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded">
                            @php($loyaltyPointEachOrder = getWebConfig(name: 'loyalty_point_for_each_order'))
                            <div class="row g-4 align-items-center">
                                <div class="d-flex justify-content-between align-items-center gap-3">
                                    <div>
                                        <h4 class="fw-medium text-capitalize">
                                            {{ translate('Earn_Loyalty_Point_on_Each_Order') }}
                                        </h4>
                                        <p class="mb-0">
                                            {{ translate('setup_loyalty_point_percentage_earned_by_customer_based_on_order_amount') }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="switcher" for="customer-loyalty-point-each-order">
                                            <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                                   name="loyalty_point_for_each_order" id="customer-loyalty-point-each-order"
                                                   {{ $loyaltyPointStatus && $loyaltyPointEachOrder ? 'checked' : '' }}
                                                   {{ $loyaltyPointStatus ? '' : 'disabled' }}
                                                   data-modal-type="input-change"
                                                   data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/loyalty-on.png') }}"
                                                   data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/loyalty-off.png') }}"
                                                   data-on-title="{{ translate('want_to_Turn_ON_Loyalty_Point_on_Each_Order') }}"
                                                   data-off-title="{{ translate('want_to_Turn_OFF_Loyalty_Point_on_Each_Order') }}"
                                                   data-on-message="<p>{{ translate('if_enabled_the_loyalty_point_option_will_be_available_to_the_customers_each_order_when_order_place') }}</p>"
                                                   data-off-message="<p>{{ translate('if_disabled_loyalty_point_option_will_be_hidden_from_the_customers_each_order_when_order_place') }}</p>">
                                            <span class="switcher_control"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    @php($loyaltyPointItemPurchasePoint = getWebConfig(name: 'loyalty_point_item_purchase_point'))
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="">
                                            {{ translate('Earning_Percentage') }} (%)
                                        </label>
                                        <input type="number" class="form-control" name="item_purchase_point"
                                            id="" placeholder="{{ translate('ex') . ': ' . '2' }}"
                                            value="{{ $loyaltyPointItemPurchasePoint ?? 1 }}" min="0" step="any"
                                            {{ $loyaltyPointStatus == 1 ? 'required' : '' }}>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body d-flex flex-column gap-3 gap-sm-20">
                        @php($refEarningStatus = getWebConfig(name: 'ref_earning_status'))
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <div>
                                    <h2 class="text-capitalize">
                                        {{ translate('Customer_Referral_Earning_Settings') }}
                                    </h2>
                                    <p class="mb-0">
                                        {{ translate('allow_customers_to_refer_your_business_to_friends_and_family_using_a_referral_code_and_earn_rewards.') }}
                                    </p>
                                </div>
                                <div>
                                    <label class="switcher" for="ref-earning-status">
                                        <input class="switcher_input custom-modal-plugin" type="checkbox" value="1"
                                            name="ref_earning_status" id="ref-earning-status"
                                            {{ $refEarningStatus ? 'checked' : '' }} data-modal-type="input-change"
                                            data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/referral-earning-on.png') }}"
                                            data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/referral-earning-off.png') }}"
                                            data-on-title="{{ translate('want_to_Turn_ON_Referral_And_Earning_option') }}?"
                                            data-off-title="{{ translate('want_to_Turn_OFF_Referral_And_Earning_option') }}?"
                                            data-on-message="<p>{{ translate('if_enabled_Customers_will_earn_rewards_when_someone_registers_using_their_referral_code.') }}</p>"
                                            data-off-message="<p>{{ translate('if_disabled_Customers_will_no_longer_earn_rewards_for_successful_referrals.') }}</p>">
                                        <span class="switcher_control"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4 align-items-center">
                                @php($refEarningExchangeRate = getWebConfig(name: 'ref_earning_exchange_rate'))

                                <div class="col-lg-4">
                                    <h2 class="text-capitalize">
                                        {{ translate('Who_share_the_Code') }}
                                    </h2>
                                    <p>
                                        {{ translate('set_the_reward_for_the_customer_who_is_sharing_the_code_with_friends_and_family_to_refer_the_app.') }}
                                    </p>
                                </div>
                                <div class="col-lg-8">
                                    <div class="form-group">
                                        <label class="form-label text-capitalize" for="ref_earning_exchange_rate">
                                            {{ translate('earnings_to_Each_Referral') }}
                                            ({{ getCurrencySymbol(type: 'default') }})
                                        </label>
                                        <input type="text" class="form-control" name="ref_earning_exchange_rate"
                                            id="ref_earning_exchange_rate"
                                            placeholder="{{ translate('ex') . ': ' . '10' }}"
                                            {{ $refEarningStatus == 0 ? '' : 'required' }}
                                            value="{{ Convert::default($refEarningExchangeRate) ?? 0 }}">
                                        <p class="text-danger mt-1 mb-0">
                                            {{ translate('must_turn_on_add_fund_to_wallet_option_otherwise_customer_can_not_receive_the_reward_amount') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @php($referralEarningDiscountData = getWebConfig('ref_earning_customer'))
                <div class="card">
                    <div class="card-body">
                        <div class="p-12 p-sm-20 bg-section rounded">
                            <div class="row g-4 align-items-center">
                                <div class="col-lg-4">
                                    <h2 class="text-capitalize">{{ translate('Who_use_the_code') }}</h2>
                                    <p class="mb-0">
                                        {{ translate('set_up_the_discount_that_the_customer_will_receive_when_using_the_refer_code_in_signup_and_taking_their_first_order.') }}
                                    </p>
                                </div>
                                <div class="col-lg-8">
                                    <div class="row g-4">
                                        <div class="col-12">
                                            <div class="form-group">
                                                <label class="form-label text-capitalize" for="">
                                                    {{ translate('customer_will_get_discount_on_first_order') }}
                                                    <span class="text-danger">*</span>
                                                </label>
                                                <label
                                                    class="d-flex justify-content-between align-items-center gap-3 border rounded px-3 py-10 bg-white user-select-none">
                                                    <span class="fw-medium text-dark">{{ translate('status') }}</span>
                                                    <label class="switcher" for="ref_earning_discount_status">

                                                        <input class="switcher_input custom-modal-plugin" type="checkbox"
                                                            @if (isset($referralEarningDiscountData['ref_earning_discount_status']) && $referralEarningDiscountData['ref_earning_discount_status'] == 1) checked @endif value="1"
                                                            name="ref_earning_discount_status"
                                                            id="ref_earning_discount_status"
                                                            data-modal-type="input-change"
                                                           data-on-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/referral-earning-on.png') }}"
                                                           data-off-image="{{ dynamicAsset(path: 'public/assets/new/back-end/img/modal/referral-earning-off.png') }}"
                                                            data-on-title="{{ translate('are_you_sure_to_turn_on_new_user_referral_reward') }}?"
                                                            data-off-title="{{ translate('are_you_sure_to_turn_off_new_user_referral_reward') }}?"
                                                            data-on-message="<p>{{ translate('customers_will_only_receive_referral_rewards_if_the_referral_and_referral_reward_options_are_enabled.') }}</p>"
                                                            data-off-message="<p>{{ translate('customers_will_only_receive_referral_rewards_if_the_referral_and_referral_reward_options_are_enabled.') }}</p>">
                                                        <span class="switcher_control"></span>
                                                    </label>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-lg-6">
                                            <div class="form-group">
                                                <label class="form-label text-capitalize" for="">
                                                    {{ translate('Discount_Amount') }}
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                        data-bs-placement="right" data-bs-title="{{ translate('the_value_of_the_discount_the_referred_customer_will_get_on_their_first_order_.') }} {{ translate('you_can_choose_a_fixed_amount_or_a_percentage_.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" name="discount_amount" id="discount_amount" min="0"
                                                        value="{{ $referralEarningDiscountData['discount_amount'] ?? '' }}"
                                                        class="form-control" placeholder="{{ translate('Ex: 10') }}">
                                                    <div class="input-group-append select-wrapper">
                                                        <select name="discount_type" class="form-select shadow-none">
                                                            <option value="percentage" {{ isset($referralEarningDiscountData['discount_type']) && $referralEarningDiscountData['discount_type'] == 'percentage' ? 'selected' : '' }}>%</option>
                                                            <option value="flat"{{ isset($referralEarningDiscountData['discount_type']) && $referralEarningDiscountData['discount_type'] == 'flat' ? 'selected' : '' }}>$</option>
                                                        </select>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6">
                                            <div class="form-group">
                                                <label class="form-label text-capitalize" for="">
                                                    {{ translate('Validity') }}
                                                    <span class="tooltip-icon" data-bs-toggle="tooltip"
                                                        data-bs-placement="right" data-bs-title="{{ translate('sets_how_long_the_referral_discount_will_remain_active_after_the_customer_signs_up_measured_in_days.') }}">
                                                        <i class="fi fi-sr-info"></i>
                                                    </span>
                                                </label>
                                                <div class="input-group">
                                                    <input type="number" name="validity" id="validity"
                                                        value="{{ $referralEarningDiscountData['validity'] ?? '' }}"
                                                        class="form-control" placeholder="{{ translate('Ex: 10') }}" min="1" >
                                                    <div class="input-group-append select-wrapper">
                                                        <?php
                                                            $validityTypes = ['day', 'week', 'month'];
                                                        ?>
                                                        <select class="form-select shadow-none" name="validity_type">
                                                            <option disabled value="">
                                                                {{ translate('select_Type') }}
                                                            </option>
                                                            @foreach ($validityTypes as $type)
                                                                <option value="{{ $type }}"
                                                                    {{ isset($referralEarningDiscountData['validity_type']) && $referralEarningDiscountData['validity_type'] == $type ? 'selected' : '' }}>
                                                                    {{ translate($type) }}
                                                                </option>
                                                            @endforeach
                                                        </select>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end trans3 mt-4 action-btn-wrapper-container">
                <div
                    class="d-flex justify-content-sm-end justify-content-center gap-3 flex-grow-1 flex-grow-sm-0 bg-white action-btn-wrapper trans3">
                    <button type="reset" class="btn btn-secondary px-3 px-sm-4 w-120">{{ translate('Reset') }}</button>
                    <button type="submit" class="btn btn-primary px-3 px-sm-4 text-capitalize">
                        <i class="fi fi-sr-disk"></i>
                        {{ translate('save_information') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
    @include('layouts.admin.partials.offcanvas._customer-settings')
@endsection

@push('script')
    <script src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/business-setting.js') }}"></script>
@endpush
