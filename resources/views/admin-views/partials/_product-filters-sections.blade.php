@if(isset($filterSection) && in_array('sorting', $filterSection))
    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
        <label for="" class="form-label">{{ translate('Sorting') }}</label>
        <div class="bg-white rounded p-3">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input radio--input" type="radio" name="filter_sort_by"
                               id="productSortBy1" value="latest"
                            {{ empty(request('filter_sort_by')) || request('filter_sort_by') == 'latest' ? 'checked' : '' }}>
                        <label class="form-check-label fs-12"
                               for="productSortBy1">
                            {{ translate('Default') }} ({{ translate('Recent_Created') }})
                        </label>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input radio--input" type="radio" name="filter_sort_by"
                               id="productSortBy2" value="oldest" {{ request('filter_sort_by') == 'oldest' ? 'checked' : '' }}>
                        <label class="form-check-label fs-12"
                               for="productSortBy2">
                            {{ translate('Show_Older_First') }}
                        </label>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input radio--input" type="radio" name="filter_sort_by"
                               id="productSortBy3" value="best-selling" {{ request('filter_sort_by') == 'best-selling' ? 'checked' : '' }}>
                        <label class="form-check-label fs-12"
                               for="productSortBy3">
                            {{ translate('Top_Selling_Products') }}
                        </label>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input radio--input" type="radio" name="filter_sort_by"
                               id="productSortBy4" value="most-favorite" {{ request('filter_sort_by') == 'most-favorite' ? 'checked' : '' }}>
                        <label class="form-check-label fs-12" for="productSortBy4">
                            {{ translate('Most_Popular_Products') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

@if(isset($filterSection) && in_array('product_type', $filterSection))
    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
        <label for="" class="form-label">{{ translate('Product_Type') }}</label>
        <div class="bg-white rounded p-3">
            <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_types[]"
                               id="productType1" value="physical" {{ in_array('physical', request('filter_product_types', [])) ? 'checked' : '' }}>
                        <label class="form-check-label fs-12" for="productType1">
                            {{ translate('Physical') }}
                        </label>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_product_types[]"
                               id="productType2" value="digital" {{ in_array('digital', request('filter_product_types', [])) ? 'checked' : '' }}>
                        <label class="form-check-label fs-12" for="productType2">
                            {{ translate('Digital') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

@if(isset($filterSection) && in_array('product_status', $filterSection))
    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
        <label for="" class="form-label">{{ translate('Status') }}</label>
        <div class="bg-white rounded p-3">
            <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input checkbox--input mt-0" type="checkbox" name="product_status[]"
                               id="productStatus1" value="1" {{ in_array('1', request('product_status', [])) ? 'checked' : '' }}>
                        <label class="form-check-label fs-12" for="productStatus1">
                            {{ translate('Active') }}
                        </label>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="d-flex gap-2">
                        <input class="form-check-input checkbox--input mt-0" type="checkbox" name="product_status[]"
                               id="productStatus2" value="0" {{ in_array('0', request('product_status', [])) ? 'checked' : '' }}>
                        <label class="form-check-label fs-12" for="productStatus2">
                            {{ translate('Inactive') }}
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

@if(isset($filterSection) && in_array('store', $filterSection) && isset($productStores) && count($productStores) > 0)
    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
        <label for="" class="form-label">{{ translate('Store') }}</label>
        <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
            <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;" id="load-more-filter-stores-view">
                @foreach($productStores as $productStore)
                    <div class="col-sm-6">
                        <div class="d-flex gap-2">
                            <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_shop_ids[]"
                                   id="productStoreId{{ $productStore['id'] }}" value="{{ $productStore['id'] }}"
                                {{ in_array($productStore['id'], request('filter_shop_ids', [])) ? 'checked' : '' }}>
                            <label class="form-check-label fs-12" for="productStoreId{{ $productStore['id'] }}">
                                {{ $productStore['name'] }}
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div>
            <?php
                $visibleLimit = $productStores->perPage();
                $totalCategories = $productStores->total();
                $hiddenCount = $totalCategories - $visibleLimit;
            ?>
            @if($hiddenCount > 0)
                <div class="col-12 text-center load-more-product-stores-container">
                    <div class="my-2 mt-3">
                        <div class="spinner-border my-2 load-more-product-stores-spinner d-none" role="status">
                            <span class="visually-hidden">{{ translate('Loading') }}...</span>
                        </div>
                    </div>
                    <a href="javascript:" class="text-info-dark fw-semibold load-more-product-stores"
                       data-route="{{ route('admin.vendors.load-more-stores') }}">
                        {{ translate('See_More') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif

@if(isset($filterSection) && in_array('brand', $filterSection) && isset($productBrands) && count($productBrands) > 0)
    <div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
        <label for="" class="form-label">{{ translate('Brand') }}</label>
        <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
            <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;" id="load-more-filter-brands-view">
                @php($brandCount=0)
                @foreach($productBrands as $productBrand)
                    @if($brandCount < 8)
                        @php($brandCount++)
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <input class="form-check-input checkbox--input mt-0" type="checkbox" name="filter_brand_ids[]"
                                       id="productBrandId{{ $productBrand['id'] }}" value="{{ $productBrand['id'] }}"
                                    {{ in_array($productBrand['id'], request('filter_brand_ids', [])) ? 'checked' : '' }}>
                                <label class="form-check-label fs-12" for="productBrandId{{ $productBrand['id'] }}">
                                    {{ $productBrand['defaultname'] }}
                                </label>
                            </div>
                        </div>
                    @endif
                @endforeach

                <input type="hidden" name="filter_brand_old_ids" value="{{ json_encode(request('filter_brand_ids', [])) }}">
            </div>
        </div>
        <div>
            @if(count($productBrands) > 8)
                <div class="col-12 text-center load-more-product-brands-container">
                    <div class="my-2 mt-3">
                        <div class="spinner-border my-2 load-more-product-brands-spinner d-none" role="status">
                            <span class="visually-hidden">{{ translate('Loading') }}...</span>
                        </div>
                    </div>
                    <a href="javascript:" class="text-info-dark fw-semibold load-more-product-brands"
                       data-route="{{ route('admin.brand.load-more-brands') }}">
                        {{ translate('See_More') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif

@if(isset($filterSection) && in_array('category', $filterSection) && isset($productCategories) && count($productCategories) > 0)
<div class="p-12 p-sm-20 bg-section rounded mb-3 mb-sm-20 overflow-wrap-anywhere">
    <label for="" class="form-label">{{ translate('Category') }}</label>
    <div class="bg-white rounded p-3 pb-30 max-h-300 overflow-x-hidden overflow-y-auto">
        <div class="row gx-3 gy-4" style="--bs-gutter-y: 2rem;">
            <div class="col-sm-12">
                <ul class="category-collapse-wrap d-flex flex-column gap-4 list-inline">
                    @foreach($productCategories as $productCategory)
                        <li>
                            <?php
                                $isParentCategoryChecked = (bool)in_array($productCategory['id'], request('filter_category_ids', []));
                            ?>
                            <div class="d-flex align-items-center justify-content-between gap-1 category-header {{ in_array($productCategory['id'], request('filter_category_ids', [])) ? 'active' : '' }}">
                                <div class="d-flex gap-2">
                                    <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                           id="filter-category-id-{{ $productCategory['id'] }}"
                                           name="filter_category_ids[]" value="{{ $productCategory['id'] }}"
                                        {{ in_array($productCategory['id'], request('filter_category_ids', [])) ? 'checked' : '' }}>
                                    <label class="form-check-label text-dark fs-12 cursor-pointer line-1" for="filter-category-id-{{ $productCategory['id'] }}">
                                        {{ $productCategory['defaultname'] }}
                                    </label>
                                </div>
                                @if(!empty($productCategory['childes']) && count($productCategory['childes']) > 0)
                                    <label for="filter-category-id-{{ $productCategory['id'] }}"
                                           class="arrow-icon fs-20 lh-1 cursor-pointer"><i class="fi fi-rr-angle-small-right d-flex"></i>
                                    </label>
                                @endif
                            </div>

                            @if(!empty($productCategory['childes']) && count($productCategory['childes']) > 0)
                            <ul class="category-has-item list-inline ps-20" {!! in_array($productCategory['id'], request('filter_category_ids', [])) ? 'style="display: block"' : '' !!}>
                                @foreach($productCategory['childes'] as $productSubCategory)
                                    <li>
                                        <div class="d-flex align-items-center justify-content-between gap-1 category-header {{ in_array($productSubCategory['id'], request('filter_sub_category_ids', [])) ? 'active' : '' }}">
                                            <div class="d-flex gap-2">
                                                <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                                       id="filter-category-id-{{ $productSubCategory['id'] }}"
                                                       name="filter_sub_category_ids[]" value="{{ $productSubCategory['id'] }}"
                                                    {{ in_array($productSubCategory['id'], request('filter_sub_category_ids', [])) ? 'checked' : '' }}>
                                                <label class="form-check-label text-dark fs-12 cursor-pointer line-1" for="filter-category-id-{{ $productSubCategory['id'] }}">
                                                    {{ $productSubCategory['defaultname'] }}
                                                </label>
                                            </div>
                                            @if(!empty($productSubCategory['childes']) && count($productSubCategory['childes']) > 0)
                                                <label for="filter-category-id-{{ $productSubCategory['id'] }}" class="arrow-icon fs-20 lh-1 cursor-pointer">
                                                    <i class="fi fi-rr-angle-small-right d-flex"></i>
                                                </label>
                                            @endif
                                        </div>

                                        @if(!empty($productSubCategory['childes']) && count($productSubCategory['childes']) > 0)
                                        <ul class="category-has-sub list-inline ps-20" {!! in_array($productSubCategory['id'], request('filter_sub_category_ids', [])) ? 'style="display: block"' : '' !!}>
                                            @foreach($productSubCategory['childes'] as $productSubSubCategory)
                                                <li>
                                                    <div class="d-flex align-items-center justify-content-between gap-1">
                                                        <div class="d-flex gap-2">
                                                            <input class="form-check-input checkbox--input mt-0" type="checkbox"
                                                                   id="filter-category-id-{{ $productSubSubCategory['id'] }}"
                                                                   name="filter_sub_sub_category_ids[]" value="{{ $productSubSubCategory['id'] }}"
                                                                {{ in_array($productSubSubCategory['id'], request('filter_sub_sub_category_ids', [])) ? 'checked' : '' }}>
                                                            <label class="form-check-label text-dark fs-12 cursor-pointer line-1" for="filter-category-id-{{ $productSubSubCategory['id'] }}">
                                                                {{ $productSubSubCategory['defaultname'] }}
                                                            </label>
                                                        </div>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endif
