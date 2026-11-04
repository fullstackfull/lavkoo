<div class="card mt-2 featured-product">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('featured_Products') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('the_featured_product_means_the_product_list_which_are_mostly_ordered').' , '.translate('_customers_choice_and_have_good_reviews_and_ratings') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="featured_product_priority[custom_sorting_status]" value="0"
                                       data-parent-class="featured-product" data-from="default-sorting"
                                    {{ $featureProductPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
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
                                       type="radio" name="featured_product_priority[custom_sorting_status]" value="1"
                                       data-parent-class="featured-product" data-from="custom-sorting"
                                    {{ isset($featureProductPriority?->custom_sorting_status) && $featureProductPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
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
                    class="custom-sorting-radio-list {{ isset($featureProductPriority?->custom_sorting_status) && $featureProductPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="latest_created"
                                       id="featured-product-sort-by-latest-created"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'latest_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-sort-by-latest-created">
                                    {{ translate('sort_By_Latest_Created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="first_created"
                                       id="featured-product-sort-by-first-created"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'first_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-sort-by-first-created">
                                    {{ translate('sort_By_First_Created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="most_order"
                                       id="featured-product-sort-by-most-order"
                                    {{ isset($featureProductPriority?->sort_by) ? ($featureProductPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked'}}>
                                <label class="form-check-label" for="featured-product-sort-by-most-order">
                                    {{ translate('sort_By_Most_Order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="reviews_count"
                                       id="featured-product-sort-by-reviews-count"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'reviews_count' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-sort-by-reviews-count">
                                    {{ translate('sort_By_Reviews_Count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="rating"
                                       id="featured-product-sort-by-ratings"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'rating' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-sort-by-ratings">
                                    {{ translate('sort_By_Average_Ratings') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="a_to_z"
                                       id="featured-product-alphabetic-order"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'a_to_z' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-alphabetic-order">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'A ' . translate('to') . ' Z' }})
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[sort_by]" value="z_to_a"
                                       id="featured-product-alphabetic-order-reverse"
                                    {{ isset($featureProductPriority?->sort_by) && $featureProductPriority?->sort_by == 'z_to_a' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-alphabetic-order-reverse">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'Z ' . translate('to') . ' A' }})
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[out_of_stock_product]" value="desc"
                                       data-parent-class="featured-product" id="featured-product-stock-out-remove"
                                    {{ isset($featureProductPriority?->out_of_stock_product) && $featureProductPriority?->out_of_stock_product == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-stock-out-remove">
                                    {{ translate('show_Stock_Out_Products_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[out_of_stock_product]" value="hide"
                                       data-parent-class="featured-product" id="featured-product-stock-out-last"
                                    {{ isset($featureProductPriority?->out_of_stock_product) && $featureProductPriority?->out_of_stock_product == 'hide' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-stock-out-last">
                                    {{ translate('remove_Stock_Out_Products_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[out_of_stock_product]" value="default"
                                       data-parent-class="featured-product" id="featured-product-stock-out-default"
                                    {{ isset($featureProductPriority?->out_of_stock_product) ? ($featureProductPriority?->out_of_stock_product == 'default' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="featured-product-stock-out-default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[temporary_close_sorting]" value="desc"
                                       data-parent-class="featured-product" id="featured-product-temporary-close-last"
                                    {{ isset($featureProductPriority?->temporary_close_sorting) && $featureProductPriority?->temporary_close_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="featured-product-temporary-close-last">
                                    {{ translate('show_Product_In_The_Last_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[temporary_close_sorting]" value="hide"
                                       data-parent-class="featured-product" id="featured-product-temporary-close-remove"
                                    {{ isset($featureProductPriority?->temporary_close_sorting) ? ($featureProductPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="featured-product-temporary-close-remove">
                                    {{ translate('remove_Product_From_The_List_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="featured_product_priority[temporary_close_sorting]" value="default"
                                       data-parent-class="featured-product"
                                       id="featured-product-temporary-close-default"
                                    {{ isset($featureProductPriority?->temporary_close_sorting) ?($featureProductPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                <label class="form-check-label" for="featured-product-temporary-close-default">
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
