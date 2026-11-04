@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('Business Setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Maintenance_Mode') }}</span>
                </button>
            </div>

            <div class="collapse mt-3 show" id="collapseBS_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('maintenance_mode_temporarily_closes_your_online_store_to_customers_while_the_admin_performs_essential_maintenance_tasks.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Basic_Information') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseBS_02">
                <div class="card card-body">
                    <h5>{{ translate('Company_Name') }}</h5>
                    <p class="fs-12">
                        {{ translate('the_company_name_often_serves_as_the_primary_identifier_for_your_business_as_a_legal_entity.') }}
                    </p>

                    <h5>{{ translate('Email') }}</h5>
                    <p class="fs-12">
                        {{ translate('a_company_email_system_often_provides_centralized_management_and_archiving_of_business_communications.') }}
                    </p>

                    <h5>{{ translate('Phone') }}</h5>
                    <p class="fs-12">
                        {{ translate('a_phone_number_provides_customers_and_partners_with_a_direct_and_immediate_way_to_reach_your_business_for_urgent_inquiries,_support_needs,_or_quick_questions.') }}
                    </p>

                    <h5>{{ translate('Country') }}</h5>
                    <p class="fs-12">
                        {{ translate('country_name_field_when_setting_up_a_business_is_essential_for_a_multitude_of_reasons,_touching_upon_legal,_operational,_financial,_and_marketing_aspects.') }}
                    </p>

                    <h5>{{ translate('Address') }}</h5>
                    <p class="fs-12">
                        {{ translate('an_address_is_legally_required_in_every_country_and_builds_trust_with_your_customers_online.') }}
                    </p>

                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('General_Setup') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseBS_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('general_setup_is_the_foundational_step_where_you_configure_essential_business_details_like_your_address,_legal_information,_and_basic_operational_settings.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Currency_Setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseBS_04">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('currency_setup_lets_you_define_the_primary_currency_for_your_ecommerce_store.') }}
                        {{ translate('select_currency_dropdown_option_helps_to_choose_multiple_currencies.') }}
                    </p>

                    <p class="fs-12">
                        {{ translate('currency_position_helps_to_choose_the_symbol_position_left_or_right.') }}
                    </p>

                    <p class="fs-12">
                        {{ translate('digit_after_decimal_point_indicates_the_zeros_after_the_decimal_point.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_05" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Business_Model_Setup') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseBS_05">
                <div class="card card-body">
                    <h5>{{ translate('Single_Vendor') }}</h5>
                    <p class="fs-12">
                        {{ translate('a_single_vendor_e-commerce_setup_means_one_business_or_individual_is_selling_their_own_products_or_services_directly_to_customers_through_their_online_store.') }}
                    </p>

                    <h5>{{ translate('Multi_Vendor') }}</h5>
                    <p class="fs-12">
                        {{ translate('a_multi_vendor_e-commerce_setup_is_like_an_online_shopping_mall_where_multiple_independent_sellers_can_list_and_sell_their_products_or_services_all_on_the_same_website.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_06" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Payment_Options') }}</span>
                </button>
            </div>

            <div class="collapse mt-3" id="collapseBS_06">
                <div class="card card-body">
                    <h5>{{ translate('Cash_On_Delivery') }}</h5>
                    <p class="fs-12">
                        {{ translate('cash_on_delivery_means_customers_pay_for_their_online_order_with_cash_when_the_delivery_person_brings_it_to_their_address.') }}
                        {{ translate('the_delivery_service_then_collects_the_payment_and_forwards_it_to_your_business.') }}
                    </p>

                    <h5>{{ translate('Digital_Payment') }}</h5>
                    <p class="fs-12">
                        {{ translate('digital_payment_lets_customers_pay_online_for_their_orders_using_methods_like_mobile_wallets_(e.g.,_bkash,_nagad,_rocket),_credit/debit_cards,_or_internet_banking_before_the_product_is_shipped.') }}
                        {{ translate('the_payment_is_processed_electronically_and_transferred_to_your_business_account.') }}
                    </p>

                    <h5>{{ translate('Offline_Payment') }}</h5>
                    <p class="fs-12">
                        {{ translate('offline_payment_means_customers_complete_their_e-commerce_order_but_pay_for_it_through_a_method_outside_your_website,_like_direct_bank_transfer_or_in-person_cash_deposit.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseBS_07" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('Copyright_And_Cookies_Text') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseBS_07">
                <div class="card card-body">
                    <h5>{{ translate('Copyright_Text') }}</h5>
                    <p class="fs-12">
                        {{ translate('company_copyright_text_is_a_statement_that_claims_legal_ownership_and_protection_of_original_content,_typically_including_the_copyright_symbol_(©),_the_year_of_first_publication,_and_company_name.') }}
                        {{ translate('this_text_informs_others_that_the_work_is_protected_by_copyright_law_and_cannot_be_copied_or_used_without_permission.') }}
                    </p>

                    <h5>{{ translate('Cookies_Text') }}</h5>
                    <p class="fs-12">
                        {{ translate('a_company_cookies_text_is_a_short_notification_displayed_on_their_website,_informing_visitors_that_the_site_uses_cookies_to_collect_data_and_improve_their_experience.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
