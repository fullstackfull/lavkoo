@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Orders') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOrderSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('general_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseOrderSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                         {{ translate('order_delivery_verification_means_that_after_you_order_something,_you_will_get_a_code.') }}
                         {{ translate('when_the_delivery_person_comes,_they_will_ask_for_this_code_to_make_sure_they_are_giving_the_order_to_you.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('vendor_can_set_minimum_order_amount_means_that_each_seller_can_decide_on_the_smallest_order_amount_a_customer_can_place_for_their_products.') }}
                         {{ translate('if_the_order_is_too_low,_the_customer_cant_buy_it.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('turning_on_"show_billing_address"_means_customers_will_be_asked_to_fill_in_their_billing_address_when_they_place_an_order.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseOrderSettings_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('setup_free_delivery') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseOrderSettings_02">
                <div class="card card-body">
                   <p class="fs-12">
                         {{ translate('turn_on/off_free_delivery"_lets_you_decide_if_customers_get_free_shipping.') }}
                         {{ translate('if_you_turn_it_on,_free_delivery_will_be_available_for_orders_over_a_specific_amount_that_you_choose.') }}

                   </p>
                   <p class="fs-12">
                         {{ translate('free_delivery_responsibility_setting_allows_you_to_determine_who_will_bear_the_cost_of_providing_free_delivery_to_customers_when_their_order_meets_the_specified_minimum_amount.') }}

                   </p>
                   <p class="fs-12">
                         {{ translate('free_delivery_over($):_admin_can_define_the_exact_dollar_amount_(usd)_that_an_order_needs_to_exceed_for_the_customer_to_automatically_receive_free_shipping.') }}
                   </p>
                </div>
            </div>
        </div>
    </div>
</div>
