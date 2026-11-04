<div class="card mt-2 searched-product-list">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3> {{ translate('products_List') }} ({{ translate('Search_Bar') }})</h3>
            <p class="mb-0 fs-12">
                {{ translate('the_product_list_(Search_Bar)_is_the_list_of_those_products_which_appear_during_search_based_on_product_availability') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" data-parent-class="searched-product-list"
                                       data-from="default-sorting" name="searched_product_list_priority[custom_sorting_status]"
                                       value="0"
                                    {{ $searchedProductListPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Default_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('currently_sorting_this_section_by_keyword_wise') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="searched_product_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="searched-product-list" data-from="custom-sorting"
                                    {{ isset($searchedProductListPriority?->custom_sorting_status) && $searchedProductListPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
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
                    class="custom-sorting-radio-list {{ isset($searchedProductListPriority?->custom_sorting_status) && $searchedProductListPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="searched_product_list_priority[out_of_stock_product]" value="desc" class="check-box"
                                       data-parent-class="searched-product-list" id="show-in-last"
                                    {{ isset($searchedProductListPriority?->out_of_stock_product) && $searchedProductListPriority?->out_of_stock_product == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label" for="show-in-last">
                                    {{ translate('show_Stock_Out_Products_In_The_Last') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="searched_product_list_priority[out_of_stock_product]" value="hide" class="check-box"
                                       data-parent-class="searched-product-list" id="remove-product"
                                    {{ isset($searchedProductListPriority?->out_of_stock_product) && $searchedProductListPriority?->out_of_stock_product == 'hide' ? 'checked' : ''}}>
                                <label class="form-check-label" for="remove-product">
                                    {{ translate('remove_Stock_Out_Products_From_The_List') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="searched_product_list_priority[out_of_stock_product]" value="default"
                                       data-parent-class="searched-product-list" id="default"
                                    {{ isset($searchedProductListPriority?->out_of_stock_product) ? ($searchedProductListPriority?->out_of_stock_product == 'default' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label" for="default">
                                    {{ translate('none') }}
                                </label>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="searched_product_list_priority[temporary_close_sorting]" value="desc"
                                       data-parent-class="searched-product-list"
                                       id="searched-product-list-temporary-close-last"
                                    {{ isset($searchedProductListPriority?->temporary_close_sorting) && $searchedProductListPriority?->temporary_close_sorting == 'desc' ? 'checked' : ''}}>
                                <label class="form-check-label text-capitalize"
                                       for="searched-product-list-temporary-close-last">
                                    {{ translate('show_product_in_the_last_is_store_is_temporarily_off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show"
                                       name="searched_product_list_priority[temporary_close_sorting]" value="hide"
                                       data-parent-class="searched-product-list"
                                       id="searched-product-list-temporary-close-remove"
                                    {{ isset($searchedProductListPriority?->temporary_close_sorting) ? ($searchedProductListPriority?->temporary_close_sorting == 'hide' ? 'checked' : '') :'checked'}}>
                                <label class="form-check-label text-capitalize"
                                       for="searched-product-list-temporary-close-remove">
                                    {{ translate('remove_product_from_the_list_if_store_is_temporarily_off') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show text-capitalize"
                                       name="searched_product_list_priority[temporary_close_sorting]" value="default"
                                       data-parent-class="searched-product-list"
                                       id="searched-product-list-temporary-close-default"
                                    {{ isset($searchedProductListPriority?->temporary_close_sorting) ?( $searchedProductListPriority?->temporary_close_sorting == 'default' ? 'checked' : '' ) : 'checked'}}>
                                <label class="form-check-label" for="searched-product-list-temporary-close-default">
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
