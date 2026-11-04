@include("layouts.admin.partials.offcanvas._view-guideline-button")

<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
     data-status="{{ request('offcanvasShow') && request('offcanvasShow') == 'offcanvasSetupGuide' ? 'show' : '' }}">

    <div class="offcanvas-header bg-body">
        <h3 class="mb-0">{{ translate('website_setup') }}</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body">

        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseWebsiteSetup_01" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('websites_&_panels') }}</span>
                </button>

            </div>

            <div class="collapse mt-3 show" id="collapseWebsiteSetup_01">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('website_is_like_a_storefront_–_its_what_customers_see_when_they_visit_online.') }} {{ translate('they_browse_products,_read_descriptions,_and_place_orders_there.') }}
                    </p>
                    <p class="fs-12">
                        {{ translate('the_panel_is_like_a_back_room_or_office.') }} {{ translate('its_where,_as_the_shop_owner,_they_manage_everything_behind_the_scenes_–_adding_new_products,_processing_orders,_checking_sales,_and_setting_up_things_like_shipping_and_payments.') }}
                    </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseWebsiteSetup_02" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('logo_&_loader') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseWebsiteSetup_02">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('a_logo_is_like_the_unique_sign_outside_the_physical_store_–_its_the_visual_symbol_that_helps_people_instantly_recognize_your_brand.') }}
                    </p>
                    <ul class="d-flex flex-column gap-12 fs-12">
                        <li>
                            <strong> {{ translate('header_logo:') }}</strong>
                            {{ translate('the_header_logo_is_the_small_version_of_the_brands_main_image_that_usually_sits_at_the_very_top_of_every_page_on_online_shops.') }}
                            {{ translate('think_of_it_as_a_shops_little_signature_that_is_always_visible,_helping_customers_remember_who_they_are_browsing_with_as_they_move_around_the_website.') }}
                        </li>
                        <li>
                            <strong> {{ translate('footer_logo:') }}</strong>
                            {{ translate('the_footer_logo_is_just_a_small_version_of_the_brands_picture_that_is_often_seen_down_there.') }}
                            {{ translate('It_like_a_little_final_stamp_at_the_end_of_the_page,_a_subtle_reminder_of_the_brand_as_customers_finish_browsing.') }}
                        </li>
                        <li>
                            <strong> {{ translate('favicon:') }}</strong>
                            {{ translate('the_favicon_is_that_tiny_little_picture_or_icon_that_appears_right_next_to_the_name_of_your_website_in_that_browser_tab.') }}
                        </li>
                        <li>
                            <strong> {{ translate('loading_GIF:') }}</strong>
                            {{ translate('the_loading_gif_is_that_little_moving_picture_or_animation_you_see_during_that_waiting_time.') }}
                            {{ translate('it_could_be_a_spinning_circle,_bouncing_dots,_or_something_else.') }}
                        </li>
                    </ul>
                    <p class="fs-12">
                        {{ translate('the_loader_is_that_little_spinning_animation_or_image_sometimes_seen_while_a_web_page_is_loading,_like_when_browsing_products.') }}
                   </p>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseWebsiteSetup_03" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('color_settings') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseWebsiteSetup_03">
                <div class="card card-body">
                    <ul class="d-flex flex-column gap-2 fs-12">
                        <li>
                            <strong>{{ translate('primary_color:') }}</strong>
                            {{ translate('the_primary_color_is_like_the_main_color_chosen_to_use_everywhere_–_on_buttons,_links,_and_important_parts_of_the_website.') }}
                            {{ translate('its_the_color_that_stands_out_the_most_and_helps_people_quickly_recognize_the_brands_style_and_what_is_important_on_the_page.') }}
                        </li>
                        <li>
                             <strong>{{ translate('secondary_color:') }}</strong>
                             {{ translate('the_secondary_color_is_like_its_helpful_supporting_actor.') }}
                             {{ translate('it_is_another_color_used_alongside_the_main_color_to_add_variety,_highlight_important_things_(like_buttons_or_special_offers),_and_make_the_website_look_more_interesting_and_visually_appealing_without_overpowering_primary_brand_identity.') }}
                             <br>
                             {{ translate('the_panel_sidebar_color_simply_refers_to_the_color_of_that_left-side_menu_area.') }}
                        </li>
                        <li>
                             <strong>{{ translate('primary_light_color:') }}</strong>
                             {{ translate('the_primary_light_color_is_a_brighter,_lighter_version_of_that_main_color.') }}
                             {{ translate('_its_often_used_for_things_that_should_stand_out_but_still_feel_connected_to_your_main_brand_color_–_like_buttons_you_want_people_to_click,_highlights,_or_accents_that_make_your_website_feel_lively_and_easy_to_navigate_for_customers.') }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20">
            <div class="d-flex gap-3 align-items-center justify-content-between overflow-hidden">
                <button class="btn-collapse d-flex gap-3 align-items-center bg-transparent border-0 p-0 collapsed" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapseWebsiteSetup_04" aria-expanded="true">
                    <div class="btn-collapse-icon border bg-light icon-btn rounded-circle text-dark collapsed">
                        <i class="fi fi-sr-angle-right"></i>
                    </div>
                    <span class="fw-bold text-start">{{ translate('footer_app_download_button') }}</span>
                </button>

            </div>

            <div class="collapse mt-3" id="collapseWebsiteSetup_04">
                <div class="card card-body">
                    <p class="fs-12">
                        {{ translate('the_footer_app_download_button_is_a_button_usually_found_at_the_very_bottom_of_every_page_on_the_website_(in_the_footer).') }}
                        {{ translate('it_is_a_clear_invitation_for_customers_to_download_the_app_from_places_like_the_google_play_store_or_apple_app_store.') }}
                    </p>
                </div>
            </div>
        </div>

    </div>
</div>
