<div class="card mt-2 new-arrival-product">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('new_Arrival_Products') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('these_new_arrival_products_are_items_recently_added_to_the_list_within_a_specific_time_frame_and_have_positive_reviews_&_ratings') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       name="new_arrival_product_list_priority[custom_sorting_status]" value="0"
                                       type="radio" data-parent-class="new-arrival-product"
                                       data-from="default-sorting"
                                    {{ $newArrivalProductListPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Default_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('currently_sorting_this_section_based_on_latest_add') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="new_arrival_product_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="new-arrival-product" data-from="custom-sorting"
                                    {{ isset($newArrivalProductListPriority?->custom_sorting_status) && $newArrivalProductListPriority?->custom_sorting_status == 1 ? 'checked' : '' }}>
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
                    class="custom-sorting-radio-list {{ isset($newArrivalProductListPriority?->custom_sorting_status) && $newArrivalProductListPriority?->custom_sorting_status == 1 ? '' : 'd--none' }}">
                    <div class="d-flex flex-column gap-20">
                        <div>
                            <h3 class="mb-1">{{ translate('set_duration') }}</h3>
                            <p class="mb-0">{{ translate('products_are_considered_as') }} <span
                                    class="fw-semibold">{{ translate('New_Arrival') }}</span>, {{ translate('if_it_is_added_with_in_x_days_months') }}
                            </p>
                            <div class="input-group mt-2">
                                <input type="number" class="form-control" name="new_arrival_product_list_priority[duration]"
                                       min="1"
                                       placeholder="{{ translate('ex') . ': 5' }}"
                                       value="{{ isset($newArrivalProductListPriority?->duration) ? $newArrivalProductListPriority->duration : 1 }}"
                                       required>
                                <div class="input-group-append select-wrapper">
                                    <select class="form-select shadow-none"
                                            name="new_arrival_product_list_priority[duration_type]">
                                        <option value="days"
                                            {{ isset($newArrivalProductListPriority?->duration_type) && $newArrivalProductListPriority?->duration_type == 'days' ? 'selected' : '' }}>
                                            {{ translate('Days') }}</option>
                                        <option value="month"
                                            {{ isset($newArrivalProductListPriority?->duration_type) && $newArrivalProductListPriority?->duration_type == 'month' ? 'selected' : '' }}>
                                            {{ translate('Month') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="new_arrival_product_list_priority[sort_by]"
                                       value="latest_created" id="new-arrival-product-sort-by-latest-created"
                                    {{ isset($newArrivalProductListPriority?->sort_by) && $newArrivalProductListPriority?->sort_by == 'latest_created' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-sort-by-latest-created">
                                    {{ translate('sort_by_latest_created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="new_arrival_product_list_priority[sort_by]"
                                       value="reviews_count" id="new-arrival-product-sort-by-reviews-count"
                                    {{ isset($newArrivalProductListPriority?->sort_by) && $newArrivalProductListPriority?->sort_by == 'reviews_count' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-sort-by-reviews-count">
                                    {{ translate('sort_By_Reviews_Count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="new_arrival_product_list_priority[sort_by]"
                                       value="rating" id="new-arrival-product-sort-by-ratings"
                                    {{ isset($newArrivalProductListPriority?->sort_by) && $newArrivalProductListPriority?->sort_by == 'rating' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-sort-by-ratings">
                                    {{ translate('sort_By_Average_Ratings') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="new_arrival_product_list_priority[sort_by]"
                                       value="a_to_z" id="new-arrival-product-alphabetic-order"
                                    {{ isset($newArrivalProductListPriority?->sort_by) && $newArrivalProductListPriority?->sort_by == 'a_to_z' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-alphabetic-order">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'A ' . translate('to') . ' Z' }})
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="new_arrival_product_list_priority[sort_by]"
                                       value="z_to_a" id="new-arrival-product-alphabetic-order-reverse"
                                    {{ isset($newArrivalProductListPriority?->sort_by) && $newArrivalProductListPriority?->sort_by == 'z_to_a' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-alphabetic-order-reverse">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'Z ' . translate('to') . ' A' }})
                                </label>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">
                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[out_of_stock_product]" value="desc"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-stock-out-remove"
                                    {{ isset($newArrivalProductListPriority?->out_of_stock_product) && $newArrivalProductListPriority?->out_of_stock_product == 'desc' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-stock-out-remove">
                                    {{ translate('show_Stock_Out_Products_In_The_Last') }}
                                </label>
                            </div>
                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[out_of_stock_product]" value="hide"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-stock-out-last"
                                    {{ isset($newArrivalProductListPriority?->out_of_stock_product) && $newArrivalProductListPriority?->out_of_stock_product == 'hide' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-stock-out-last">
                                    {{ translate('remove_Stock_Out_Products_From_The_List') }}
                                </label>
                            </div>
                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[out_of_stock_product]"
                                       value="default"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-stock-out-default"
                                    {{ isset($newArrivalProductListPriority?->out_of_stock_product) ? ($newArrivalProductListPriority?->out_of_stock_product == 'default' ? 'checked' : '') : 'checked' }}>
                                <label class="form-check-label" for="new-arrival-product-stock-out-default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[temporary_close_sorting]"
                                       value="desc"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-temporary-close-last"
                                    {{ isset($newArrivalProductListPriority?->temporary_close_sorting) && $newArrivalProductListPriority?->temporary_close_sorting == 'desc' ? 'checked' : '' }}>
                                <label class="form-check-label" for="new-arrival-product-temporary-close-last">
                                    {{ translate('show_Product_In_The_Last_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>
                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[temporary_close_sorting]"
                                       value="hide"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-temporary-close-remove"
                                    {{ isset($newArrivalProductListPriority?->temporary_close_sorting) ? ($newArrivalProductListPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') : 'checked' }}>
                                <label class="form-check-label" for="new-arrival-product-temporary-close-remove">
                                    {{ translate('remove_Product_From_The_List_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>
                            <div class="form-check d-flex gap-1">
                                <input type="radio" name="new_arrival_product_list_priority[temporary_close_sorting]"
                                       value="default"
                                       class="form-check-input radio--input" data-parent-class="new-arrival-product"
                                       id="new-arrival-product-temporary-close-default"
                                    {{ isset($newArrivalProductListPriority?->temporary_close_sorting) ? ($newArrivalProductListPriority?->temporary_close_sorting == 'default' ? 'checked' : '') : 'checked' }}>
                                <label class="form-check-label" for="new-arrival-product-temporary-close-default">
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
