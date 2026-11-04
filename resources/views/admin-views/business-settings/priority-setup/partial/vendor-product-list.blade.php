<div class="card mt-2 vendor-product-list">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3 class="text-capitalize">{{ translate('vendor_product_list') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('the_vendor_product_list_is_for_displaying_the_products_which_are_mostly_ordered').', '.translate('_have_good_reviews_&_sorted_alphabetically') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" data-parent-class="vendor-product-list"
                                       data-from="default-sorting" name="vendor_product_list_priority[custom_sorting_status]"
                                       value="0"
                                    {{ $vendorProductListPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
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
                                       type="radio" name="vendor_product_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="vendor-product-list" data-from="custom-sorting"
                                    {{ isset($vendorProductListPriority?->custom_sorting_status) && $vendorProductListPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
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
                    class="custom-sorting-radio-list {{ isset($vendorProductListPriority?->custom_sorting_status) && $vendorProductListPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">
                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[sort_by]"
                                       value="most_order" id="vendor-list-sort-by-most-order"
                                    {{ isset($vendorProductListPriority?->sort_by) ? ($vendorProductListPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked'}}>
                                <label class="form-check-label text-capitalize"
                                       for="vendor-list-sort-by-most-order">
                                    {{ translate('sort_by_most_order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[sort_by]"
                                       id="vendor-list-sort-by-reviews-count" value="reviews_count"
                                    {{ isset($vendorProductListPriority?->sort_by) && $vendorProductListPriority?->sort_by == 'reviews_count' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize"
                                       for="vendor-list-sort-by-reviews-count">
                                    {{ translate('sort_by_reviews_count') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[sort_by]"
                                       id="vendor-list-sort-by-ratings" value="rating"
                                    {{ isset($vendorProductListPriority?->sort_by) && $vendorProductListPriority?->sort_by == 'rating' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize" for="vendor-list-sort-by-ratings">
                                    {{ translate('sort_by_average_ratings') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[sort_by]"
                                       value="a_to_z" id="vendor-list-alphabetic-order"
                                    {{ isset($vendorProductListPriority?->sort_by) && $vendorProductListPriority?->sort_by == 'a_to_z' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize" for="vendor-list-alphabetic-order">
                                    {{ translate('sort_by_Alphabetical') }}
                                    ({{ 'A ' . translate('to') . ' Z' }})
                                </label>
                            </div>
                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[sort_by]"
                                       value="z_to_a" id="vendor-list-alphabetic-order-reverse"
                                    {{ isset($vendorProductListPriority?->sort_by) && $vendorProductListPriority?->sort_by == 'z_to_a' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize"
                                       for="vendor-list-alphabetic-order-reverse">
                                    {{ translate('sort_by_Alphabetical') }}
                                    ({{ 'Z ' . translate('to') . ' A' }})
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[out_of_stock_product]" value="desc"
                                       data-parent-class="vendor-product-list" id="vendor-list-stock-out-remove"
                                    {{ isset($vendorProductListPriority?->out_of_stock_product) && $vendorProductListPriority?->out_of_stock_product == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize" for="vendor-list-stock-out-remove">
                                    {{ translate('show_stock_out_products_in_the_last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[out_of_stock_product]" value="hide"
                                       data-parent-class="vendor-product-list" id="vendor-list-stock-out-show"
                                    {{ isset($vendorProductListPriority?->out_of_stock_product) && $vendorProductListPriority?->out_of_stock_product == 'hide' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize" for="vendor-list-stock-out-show">
                                    {{ translate('remove_stock_out_products_from_the_list') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="vendor_product_list_priority[out_of_stock_product]" value="default"
                                       data-parent-class="vendor-product-list" id="vendor-list-stock-out-none"
                                    {{ isset($vendorProductListPriority?->out_of_stock_product) ? ($vendorProductListPriority?->out_of_stock_product == 'default' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label text-capitalize" for="vendor-list-stock-out-none">
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
