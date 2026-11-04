<div class="card mt-2 brand">
    <div class="card-body">
        <div class="mb-3 mb-sm-20">
            <h3>{{ translate('brand_List_Sorting') }}</h3>
            <p class="mb-0 fs-12">
                {{ translate('the_product_brand_list_groups_similar_items_together_arranged_with_the_latest_brand_first_and_in_alphabetical_order') }}
            </p>
        </div>
        <div class="p-12 p-sm-20 bg-section rounded">
            <div class="d-flex flex-column gap-20">
                <div class="bg-white p-3 rounded">
                    <div class="row g-4">
                        <div class="col-xl-6 col-md-6">
                            <div class="form-check d-flex gap-2 gap-sm-3">
                                <input class="form-check-input radio--input radio--input_lg switcher-input-js"
                                       type="radio" name="brand_list_priority[custom_sorting_status]" value="0"
                                       data-parent-class="brand"
                                       data-from="default-sorting" {{ $brandPriority?->custom_sorting_status == 1 ? '' : 'checked' }}>
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
                                       type="radio" name="brand_list_priority[custom_sorting_status]" value="1"
                                       data-parent-class="brand" data-from="custom-sorting"
                                    {{ isset($brandPriority?->custom_sorting_status) && $brandPriority?->custom_sorting_status == 1 ? 'checked' : ''}}>
                                <div class="flex-grow-1">
                                    <label for="" class="form-label text-dark fw-semibold mb-1">
                                        {{ translate('use_Custom_Sorting_List') }}
                                    </label>
                                    <p class="fs-12 mb-3">
                                        {{ translate('currently_sorting_this_section_by_priority') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    class="custom-sorting-radio-list {{ isset($brandPriority?->custom_sorting_status) && $brandPriority?->custom_sorting_status == 1 ? '' : 'd--none'}}">
                    <div class="d-flex flex-column gap-20">
                        <div class="d-flex flex-column gap-3 gap-sm-4 border rounded-10 bg-white p-12 p-sm-20">

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="brand_list_priority[sort_by]"
                                       value="latest_created" id="brand-sort-by-latest-created"
                                    {{ isset($brandPriority?->sort_by) && $brandPriority?->sort_by == 'latest_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="brand-sort-by-latest-created">
                                    {{ translate('sort_By_Latest_Created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="brand_list_priority[sort_by]"
                                       value="first_created" id="brand-sort-by-first-created"
                                    {{ isset($brandPriority?->sort_by) && $brandPriority?->sort_by == 'first_created' ? 'checked' : ''}}>
                                <label class="form-check-label" for="brand-sort-by-first-created">
                                    {{ translate('sort_By_First_Created') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="brand_list_priority[sort_by]"
                                       value="most_order" id="brand-sort-by-most-order"
                                    {{ isset($brandPriority?->sort_by) ? ($brandPriority?->sort_by == 'most_order' ? 'checked' : '') : 'checked'}}>
                                <label class="form-check-label" for="brand-sort-by-most-order">
                                    {{ translate('sort_By_Most_Order') }}
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="brand_list_priority[sort_by]"
                                       value="a_to_z" id="brand-alphabetic-order"
                                    {{ isset($brandPriority?->sort_by) && $brandPriority?->sort_by == 'a_to_z' ? 'checked' : ''}}>
                                <label class="form-check-label" for="brand-alphabetic-order">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'A ' . translate('to') . ' Z' }})
                                </label>
                            </div>

                            <div class="form-check d-flex gap-1">
                                <input type="radio" class="form-check-input radio--input show" name="brand_list_priority[sort_by]"
                                       value="z_to_a" id="brand-alphabetic-order-reverse"
                                    {{ isset($brandPriority?->sort_by) && $brandPriority?->sort_by == 'z_to_a' ? 'checked' : ''}}>
                                <label class="form-check-label" for="brand-alphabetic-order-reverse">
                                    {{ translate('sort_By_Alphabetical') }}
                                    ({{ 'Z ' . translate('to') . ' A' }})
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
