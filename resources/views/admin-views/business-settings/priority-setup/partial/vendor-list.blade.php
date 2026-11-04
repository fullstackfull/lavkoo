<div class="card mt-2 vendor-list">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('vendor_List') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('the_Vendor_list_arranges_all_vendors_based_on_the_latest_join_that_are_highly_rated_by_customer_choice_and_also_in_alphabetic_order') }}
                .
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="vendor_list_priority[custom_sorting_status]" value="0"
                                       data-parent-class="vendor-list" data-from="default-sorting"
                                    {{ $vendorListPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Default_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('currently_sorting_this_section_based_on_first_created') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="vendor_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="vendor-list" data-from="custom-sorting"
                                    {{ isset($vendorListPriority?->custom_sorting_status) && $vendorListPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
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
                    class="custom-sorting-radio-list {{ isset($vendorListPriority?->custom_sorting_status) && $vendorListPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       value="latest_created" id="vendor-list-sort-by-latest-created"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'latest_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-sort-by-latest-created">
                                    {{ translate('sort_By_Latest_Created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       value="first_created" id="vendor-list-sort-by-first-created"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'first_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-sort-by-first-created">
                                    {{ translate('sort_by_First_created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       value="most_order" id="vendor-list-sort-by-most-order"
                                    {{ isset($vendorListPriority?->sort_by) ? ($vendorListPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked'}}>
                                <label class="form-check-label" for="vendor-list-sort-by-most-order">
                                    {{ translate('sort_By_Most_Order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       id="vendor-list-sort-by-reviews-count" value="reviews_count"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'reviews_count' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-sort-by-reviews-count">
                                    {{ translate('sort_By_Reviews_Count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       id="vendor-list-sort-by-ratings" value="rating"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'rating' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-sort-by-ratings">
                                    {{ translate('sort_By_Average_Ratings') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       value="a_to_z" id="vendor-list-alphabetic-order"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'a_to_z' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-alphabetic-order">
                                    {{ translate('sort_by_Alphabetical') }}
                                    ({{ 'A ' . translate('to') . ' Z' }})
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[sort_by]"
                                       value="z_to_a" id="vendor-list-alphabetic-order-reverse"
                                    {{ isset($vendorListPriority?->sort_by) && $vendorListPriority?->sort_by == 'z_to_a' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-alphabetic-order-reverse">
                                    {{ translate('sort_by_Alphabetical') }}
                                    ({{ 'Z ' . translate('to') . ' A' }})
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[vacation_mode_sorting]" value="desc"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-vacation-mode-last"
                                    {{ isset($vendorListPriority?->vacation_mode_sorting) && $vendorListPriority?->vacation_mode_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-vacation-mode-last">
                                    {{ translate('show_Currently_Closed_Stores_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[vacation_mode_sorting]" value="hide"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-vacation-mode-remove"
                                    {{ isset($vendorListPriority?->vacation_mode_sorting) ? ($vendorListPriority?->vacation_mode_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="vendor-list-vacation-mode-remove">
                                    {{ translate('remove_Currently_Closed_Stores_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[vacation_mode_sorting]" value="hide"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-vacation-mode-remove"
                                    {{ isset($vendorListPriority?->vacation_mode_sorting) ? ($vendorListPriority?->vacation_mode_sorting == 'default' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="vendor-list-vacation-mode-remove">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[temporary_close_sorting]" value="desc"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-temporary-close-last"
                                    {{ isset($vendorListPriority?->temporary_close_sorting) && $vendorListPriority?->temporary_close_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="vendor-list-temporary-close-last">
                                    {{ translate('show_Temporarily_Off_Stores_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[temporary_close_sorting]" value="hide"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-temporary-close-remove"
                                    {{ isset($vendorListPriority?->temporary_close_sorting) ? ($vendorListPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="vendor-list-temporary-close-remove">
                                    {{ translate('remove_Temporarily_Off_Stores_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_list_priority[temporary_close_sorting]" value="default"
                                       data-parent-class="vendor-list"
                                       id="vendor-list-temporary-close-default"
                                    {{ isset($vendorListPriority?->temporary_close_sorting) ?( $vendorListPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                <label class="form-check-label" for="vendor-list-temporary-close-default">
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
