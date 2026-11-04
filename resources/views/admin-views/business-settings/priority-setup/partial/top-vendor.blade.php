<div class="card mt-2 top-vendor">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('top_Vendor') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('top_vendor_list_refers_to_displaying_a_list_based_on_most_ordered_items_of_that_vendor_and_highly_rated').'.'}}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="top_vendor_list_priority[custom_sorting_status]" value="0"
                                       data-parent-class="top-vendor" data-from="default-sorting"
                                    {{ $topVendorPriority?->custom_sorting_status == 1 ? '' : 'checked'}}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Default_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('you_can_sorting_this_section_by_others_way') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="top_vendor_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="top-vendor" data-from="custom-sorting"
                                    {{ isset($topVendorPriority?->custom_sorting_status) && $topVendorPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Custom_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('you_can_sorting_this_section_by_others_way') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="custom-sorting-radio-list {{ isset($topVendorPriority?->custom_sorting_status) && $topVendorPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[minimum_rating_point]" value="4"
                                       id="top-vendor-minimum-rating-4"
                                    {{ isset($topVendorPriority?->minimum_rating_point) ? ($topVendorPriority?->minimum_rating_point == '4' ? 'checked' : '') : ''}}>
                                <label class="form-check-label" for="top-vendor-minimum-rating-4">
                                    {{ translate('show_4+_Rated_Sellers') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[minimum_rating_point]" value="3.5"
                                       id="top-vendor-minimum-rating-3-5"
                                    {{ isset($topVendorPriority?->minimum_rating_point) && $topVendorPriority?->minimum_rating_point == '3.5' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-minimum-rating-3-5">
                                    {{ translate('show_3.5+_Rated_Sellers') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[minimum_rating_point]" id="top-vendor-minimum-rating-2"
                                       value="2"
                                    {{ isset($topVendorPriority?->minimum_rating_point) && $topVendorPriority?->minimum_rating_point == '2' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-minimum-rating-2">
                                    {{ translate('show_2+_Rated_Sellers') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[minimum_rating_point]" id="top-vendor-minimum-rating-0"
                                       value="default"
                                    {{ isset($topVendorPriority?->minimum_rating_point) ? ($topVendorPriority?->minimum_rating_point == 'default' ? 'checked' : '') : 'checked' }}>
                                <label class="form-check-label" for="top-vendor-minimum-rating-0">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[sort_by]" value="order" id="top-vendor-sort-by-order"
                                    {{ isset($topVendorPriority?->sort_by) ? ($topVendorPriority?->sort_by == 'order' ? 'checked' : '') : 'checked'}}>
                                <label class="form-check-label" for="top-vendor-sort-by-order">
                                    {{ translate('sort_By_Order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[sort_by]" value="reviews_count"
                                       id="top-vendor-sort-by-reviews-count"
                                    {{ isset($topVendorPriority?->sort_by) && $topVendorPriority?->sort_by == 'reviews_count' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-sort-by-reviews-count">
                                    {{ translate('sort_By_Reviews_Count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[sort_by]" id="top-vendor-sort-by-ratings" value="default"
                                    {{ isset($topVendorPriority?->sort_by) && $topVendorPriority?->sort_by == 'rating' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-sort-by-ratings">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[vacation_mode_sorting]" value="desc"
                                       data-parent-class="top-vendor" id="top-vendor-vacation-mode-last"
                                    {{ isset($topVendorPriority?->vacation_mode_sorting) && $topVendorPriority?->vacation_mode_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-vacation-mode-last">
                                    {{ translate('show_Currently_Closed_Stores_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[vacation_mode_sorting]" value="hide"
                                       data-parent-class="top-vendor" id="top-vendor-vacation-mode-remove"
                                    {{ isset($topVendorPriority?->vacation_mode_sorting) ? ($topVendorPriority?->vacation_mode_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="top-vendor-vacation-mode-remove">
                                    {{ translate('remove_Currently_Closed_Stores_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[vacation_mode_sorting]" value="default"
                                       data-parent-class="top-vendor" id="top-vendor-vacation-mode-default"
                                    {{ isset($topVendorPriority?->vacation_mode_sorting) ?( $topVendorPriority?->vacation_mode_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                <label class="form-check-label" for="top-vendor-vacation-mode-default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[temporary_close_sorting]" value="desc"
                                       data-parent-class="top-vendor" id="top-vendor-temporary-close-last"
                                    {{ isset($topVendorPriority?->temporary_close_sorting) && $topVendorPriority?->temporary_close_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="top-vendor-temporary-close-last">
                                    {{ translate('show_Temporarily_Off_Stores_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[temporary_close_sorting]" value="hide"
                                       data-parent-class="top-vendor" id="top-vendor-temporary-close-remove"
                                    {{ isset($topVendorPriority?->temporary_close_sorting) ? ($topVendorPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="top-vendor-temporary-close-remove">
                                    {{ translate('remove_Temporarily_Off_Stores_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="top_vendor_list_priority[temporary_close_sorting]" value="default"
                                       data-parent-class="top-vendor" id="top-vendor-temporary-close-default"
                                    {{ isset($topVendorPriority?->temporary_close_sorting) ?( $topVendorPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                <label class="form-check-label" for="top-vendor-temporary-close-default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
