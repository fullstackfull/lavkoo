@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('products') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseProductSettings_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('general_setup') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseProductSettings_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('after_enabling_the_sell_digital_product_feature,_one_can_easily_offer_and_sell_downloadable_goods_like_ebooks,_software,_music,_videos,_or_templates_to_customers._') }}
                        {{ translate('expand_the_online_store_beyond_physical_items_and_tap_into_a_new_market_of_digital_products.') }}
                    </p>
                    <p class="fs-12">
                         {{ translate('showing_the_brand_name_on_your_product_pages_helps_customers_know_who_makes_the_item.') }}
                         {{ translate('this_builds_trust_and_provides_important_information_for_their_purchase_decisions.') }}

                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseProductSettings_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('need_product_approval') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseProductSettings_02">
                <div class="card card-body">
                    <h5>{{ translate('new_product_approval') }}</h5>
                    <p class="fs-12">
                        {{ translate('if_enabled,_vendors_must_get_approval_from_the_admin_before_their_new_products_are_shown_online.') }}
                    </p>
                    <h5>{{ translate('update_Product-wise_shipping_cost') }}</h5>
                    <p class="fs-12">
                        {{ translate('if_enabled,_vendors_must_get_admin_approval_before_their_updated_shipping_cost_is_applied.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseProductSettings_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('reorder_level') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseProductSettings_03">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_reorder_level_also_called_reorder_point_is_the_minimum_stock_level_for_a_product.') . ' ' . translate('when_inventory_goes_below_this_level,_the_system_will_show_an_alert_and_mark_the_product_as_Low_Stock.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('this_helps_make_sure_you_restock_before_running_out.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
