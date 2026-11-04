<div class="card mt-2 best-selling-product">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('best_Selling_Products') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('best_selling_products_are_those_items_that_are_purchased_by_customers_mostly_compared_to_other_products_within_a_specific_period') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" data-parent-class="best-selling-product"
                                       data-from="default-sorting" name="best_selling_product_list_priority[custom_sorting_status]" value="0"
                                    {{ $bestSellingProductListPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Default_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('currently_sorting_this_section_based_on_order_count') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="best_selling_product_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="best-selling-product" data-from="custom-sorting"
                                    {{ isset($bestSellingProductListPriority?->custom_sorting_status) && $bestSellingProductListPriority?->custom_sorting_status == 1 ? 'checked' : '' }}>
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
                    class="custom-sorting-radio-list {{ isset($bestSellingProductListPriority?->custom_sorting_status) && $bestSellingProductListPriority?->custom_sorting_status == 1 ? '' : 'd--none' }}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="best_selling_product_list_priority[sort_by]"
                                       value="most_order" id="best-selling-product-sort-by-most-order"
                                    {{ isset($bestSellingProductListPriority?->sort_by) ? ($bestSellingProductListPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked' }}>
                                <label class="form-check-label" for="best-selling-product-sort-by-most-order">
                                    {{ translate('sort_By_Most_Order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="best_selling_product_list_priority[sort_by]"
                                       value="reviews_count" id="best-selling-product-sort-by-reviews-count"
                                    {{ isset($bestSellingProductListPriority?->sort_by) && $bestSellingProductListPriority?->sort_by == 'reviews_count' ? 'checked' : '' }}>
                                <label class="form-check-label" for="best-selling-product-sort-by-reviews-count">
                                    {{ translate('sort_By_Reviews_Count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="best_selling_product_list_priority[sort_by]"
                                       value="rating" id="best-selling-product-sort-by-ratings"
                                    {{ isset($bestSellingProductListPriority?->sort_by) && $bestSellingProductListPriority?->sort_by == 'rating' ? 'checked' : '' }}>
                                <label class="form-check-label" for="best-selling-product-sort-by-ratings">
                                    {{ translate('sort_By_Average_Ratings') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[out_of_stock_product]" value="desc"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-stock-out-remove"
                                    {{ isset($bestSellingProductListPriority?->out_of_stock_product) && $bestSellingProductListPriority?->out_of_stock_product == 'desc' ? 'checked' : '' }}>
                                <label class="form-check-label" for="best-selling-product-stock-out-remove">
                                    {{ translate('show_Stock_Out_Products_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[out_of_stock_product]" value="hide"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-stock-out-last"
                                    {{ isset($bestSellingProductListPriority?->out_of_stock_product) && $bestSellingProductListPriority?->out_of_stock_product == 'hide' ? 'checked' : '' }}>
                                <label class="form-check-label" for="best-selling-product-stock-out-last">
                                    {{ translate('remove_Stock_Out_Products_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[out_of_stock_product]" value="default"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-stock-out-default"
                                    {{ isset($bestSellingProductListPriority?->out_of_stock_product) ? ($bestSellingProductListPriority?->out_of_stock_product == 'default' ? 'checked' : '') :'checked' }}>
                                <label class="form-check-label" for="best-selling-product-stock-out-default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[temporary_close_sorting]" value="desc"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-temporary-close-last"
                                    {{ isset($bestSellingProductListPriority?->temporary_close_sorting) && $bestSellingProductListPriority?->temporary_close_sorting == 'desc' ? 'checked' : '' }}>
                                <label class="form-check-label" for="best-selling-product-temporary-close-last">
                                    {{ translate('show_Product_In_The_Last_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[temporary_close_sorting]" value="hide"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-temporary-close-remove"
                                    {{ isset($bestSellingProductListPriority?->temporary_close_sorting) ? ($bestSellingProductListPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked' }}>
                                <label class="form-check-label" for="best-selling-product-temporary-close-remove">
                                    {{ translate('remove_Product_From_The_List_If_Store_Is_Temporarily_Off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="best_selling_product_list_priority[temporary_close_sorting]" value="default"
                                       data-parent-class="best-selling-product"
                                       id="best-selling-product-temporary-close-default"
                                    {{ isset($bestSellingProductListPriority?->temporary_close_sorting) ?($bestSellingProductListPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked' }}>
                                <label class="form-check-label" for="best-selling-product-temporary-close-default">
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
