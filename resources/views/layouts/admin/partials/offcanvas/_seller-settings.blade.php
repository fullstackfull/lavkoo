@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('vendors') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSellerSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('general_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseSellerSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('active_pos_for_vendor_indicates_that_the_point-of-sale_feature_is_currently_working_as_a_seller.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('enable_vendor_registration_means_you_are_now_accepting_new_sellers_to_join_your_online_marketplace.') }}
                        {{ translate('interested_vendors_can_sign_up_and_start_listing_their_products_on_your_platform.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('minimum_order_amount_lets_you_set_a_required_minimum_total_value_for_a_customers_shopping_cart_before_they_can_complete_their_purchase.') }}
                        {{ translate('_this_means_customers_will_need_to_add_enough_items_to_reach_this_specified_amount_before_they_can_proceed_to_checkout.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('vendor_can_reply_on_review_activated,_you_have_the_power_to_engage_with_customer_feedback_directly_on_your_product_pages.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseSellerSettings_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('forget_password_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseSellerSettings_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('forget_password_setup_allows_users_who_have_forgotten_their_account_password_to_easily_reset_it_and_regain_access.') }}
                        {{ translate('this_setup_typically_involves_providing_a_registered_email_address_or_phone_number_to_receive_a_verification_code,_ensuring_a_secure_way_to_recover_their_account.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
