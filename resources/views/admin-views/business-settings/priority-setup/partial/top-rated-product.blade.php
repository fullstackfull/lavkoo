<div class="card mt-2 top-rated-product">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('top_Rated_Products') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('top_rated_products_are_the_mostly_ordered_product_list_of_customer_choice_which_are_highly_rated_and_reviewed') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                    <div class="bg-white p-3 rounded">
                        <div class="row g-4">
                            <div class="col-xl-6 col-md-6">
                                <div class="form-check d-flex gap-2 gap-sm-3">
                                    <input class="form-check-input radio--input radio--input_lg switcher-input-js" type="radio" name="top_rated_product_list_priority[custom_sorting_status]" value="0" data-parent-class="top-rated-product" data-from="default-sorting"
                                        {{ $topRatedProductListPriority?->custom_sorting_status == 1 ? '' : 'checked'}}>
                                    <div class="flex-grow-1">
                                        <label for="" class="form-label text-dark fw-semibold mb-1">
                                            {{ translate('use_Default_Sorting_List') }}
                                        </label>
                                        <p class="fs-12 mb-3">
                                            {{ translate('currently_sorting_this_section_based_on_review_count') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xl-6 col-md-6">
                                <div class="form-check d-flex gap-2 gap-sm-3">
                                    <input class="form-check-input radio--input radio--input_lg switcher-input-js" type="radio" name="top_rated_product_list_priority[custom_sorting_status]" value="1" data-parent-class="top-rated-product" data-from="custom-sorting"
                                        {{ isset($topRatedProductListPriority?->custom_sorting_status) && $topRatedProductListPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
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
                    <div class="custom-sorting-radio-list {{ isset($topRatedProductListPriority?->custom_sorting_status) && $topRatedProductListPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                        <div class="d-flex flex-column gap-20">
                            <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[minimum_rating_point]" value="4" id="top-rated-product-minimum-rating-4"
                                        {{ isset($topRatedProductListPriority?->minimum_rating_point) ? ($topRatedProductListPriority?->minimum_rating_point == '4' ? 'checked' : '') : ''}}>
                                    <label class="form-check-label" for="top-rated-product-minimum-rating-4">
                                        {{ translate('show_4+_Rated_Products') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show"  name="top_rated_product_list_priority[minimum_rating_point]" value="3.5" id="top-rated-product-minimum-rating-3-5"
                                        {{ isset($topRatedProductListPriority?->minimum_rating_point) && $topRatedProductListPriority?->minimum_rating_point == '3.5' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-minimum-rating-3-5">
                                        {{ translate('show_3.5+_Rated_Sellers') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[minimum_rating_point]" id="top-rated-product-minimum-rating-2" value="2"
                                        {{ isset($topRatedProductListPriority?->minimum_rating_point) && $topRatedProductListPriority?->minimum_rating_point == '3' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-minimum-rating-2">
                                        {{ translate('show_3+_Rated_Products') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[minimum_rating_point]" id="top-rated-product-minimum-rating-0" value="default"
                                        {{ isset($topRatedProductListPriority?->minimum_rating_point) ? ($topRatedProductListPriority?->minimum_rating_point == 'default' ? 'checked' : '') : 'checked' }}>
                                    <label class="form-check-label" for="top-rated-product-minimum-rating-0">
                                        {{ translate('none') }}
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[sort_by]" value="most_order" id="top-rated-product-sort-by-most-order"
                                        {{ isset($topRatedProductListPriority?->sort_by) ? ($topRatedProductListPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked'}}>
                                    <label class="form-check-label" for="top-rated-product-sort-by-most-order">
                                        {{ translate('sort_By_Most_Order') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[sort_by]" value="reviews_count" id="top-rated-product-sort-by-reviews-count"
                                        {{ isset($topRatedProductListPriority?->sort_by) && $topRatedProductListPriority?->sort_by == 'reviews_count' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-sort-by-reviews-count">
                                        {{ translate('sort_By_Reviews_Count') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[sort_by]" value="rating" id="top-rated-product-sort-by-ratings"
                                        {{ isset($topRatedProductListPriority?->sort_by) && $topRatedProductListPriority?->sort_by == 'rating' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-sort-by-ratings">
                                        {{ translate('sort_By_Average_Ratings') }}
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[out_of_stock_product]" value="desc" data-parent-class="top-rated-product" id="top-rated-product-stock-out-remove"
                                        {{ isset($topRatedProductListPriority?->out_of_stock_product) && $topRatedProductListPriority?->out_of_stock_product == 'desc' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-stock-out-remove">
                                        {{ translate('show_Currently_Closed_Stores_In_The_Last') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[out_of_stock_product]" value="hide" data-parent-class="top-rated-product" id="top-rated-product-stock-out-last"
                                        {{ isset($topRatedProductListPriority?->out_of_stock_product) && $topRatedProductListPriority?->out_of_stock_product == 'hide' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-stock-out-last">
                                        {{ translate('remove_Stock_Out_Products_From_The_List') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[out_of_stock_product]" value="default" data-parent-class="top-rated-product" id="top-rated-product-stock-out-default"
                                        {{ isset($topRatedProductListPriority?->out_of_stock_product) ? ($topRatedProductListPriority?->out_of_stock_product == 'default' ? 'checked' : '') :'checked'}}>
                                    <label class="form-check-label" for="top-rated-product-stock-out-default">
                                        {{ translate('none') }}
                                    </label>
                                </div>
                            </div>
                            <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[temporary_close_sorting]" value="desc" data-parent-class="top-rated-product" id="top-rated-product-temporary-close-last"
                                        {{ isset($topRatedProductListPriority?->temporary_close_sorting) && $topRatedProductListPriority?->temporary_close_sorting == 'desc' ? 'checked' : ''}}>
                                    <label class="form-check-label" for="top-rated-product-temporary-close-last">
                                        {{ translate('show_Product_In_The_Last_If_Store_Is_Temporarily_Off') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[temporary_close_sorting]" value="hide" data-parent-class="top-rated-product" id="top-rated-product-temporary-close-remove"
                                        {{ isset($topRatedProductListPriority?->temporary_close_sorting) ? ($topRatedProductListPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                    <label class="form-check-label" for="top-rated-product-temporary-close-remove">
                                        {{ translate('remove_Product_From_The_List_If_Store_Is_Temporarily_Off') }}
                                    </label>
                                </div>

                                <div class="form-check d-flex gap-1">
                                    <input type="radio" class="form-check-input radio--input show" name="top_rated_product_list_priority[temporary_close_sorting]" value="default" data-parent-class="top-rated-product" id="top-rated-product-temporary-close-default"
                                        {{ isset($topRatedProductListPriority?->temporary_close_sorting) ?($topRatedProductListPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                    <label class="form-check-label" for="top-rated-product-temporary-close-default">
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
