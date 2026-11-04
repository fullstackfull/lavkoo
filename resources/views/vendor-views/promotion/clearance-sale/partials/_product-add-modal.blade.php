<div class="modal fade" id="product-add-modal">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-body p-3">
                <div class="pb-3 d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <h3 class="mb-1">{{ translate('Add_Product') }}</h3>
                        <p class="mb-0">
                            {{ translate('search_product_and_add_to_your_clearance_list') }}
                        </p>
                    </div>
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none" style="--size: 2rem;"  data-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross"></i>
                    </button>
                </div>
                <form action="{{route('vendor.clearance-sale.add-product')}}" method="post" class="clearance-add-product">
                    @csrf
                    <div class="bg-section p-3 rounded-10">
                        <label class="form-label">{{ translate('Search_Products') }}</label>
                        <div class="dropdown select-clearance-product-search w-100 mb-20">
                            <div class="input-group" id="customSearchToggle">
                                <input type="text"  id="searchInput"  class="form-control search-product-for-clearance-sale" placeholder="{{ translate('Search_Product') }}" multiple>
                                <div class="input-group-append search-submit">
                                    <button type="submit">
                                        <i class="fi fi-rr-search"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="dropdown-menu w-100 px-2">
                                <div class="d-flex flex-column max-h-300 overflow-y-auto overflow-x-hidden child-border-bottom search-result-box">
                                    @include('vendor-views.promotion.clearance-sale.partials._search-product', ['products' => $products])
                                </div>
                            </div>
                        </div>
                        <div class="selected-products bg-white radius-10 p-2 mt-0 mx-0 mb-2 d-flex flex-wrap g-3 clearance-selected-products" id="selected-products">
                            @include('admin-views.partials._select-product')
                        </div>
                    </div>
                    <div class="p-4 bg-chat rounded text-center mt-3 search-and-add-product">
                        <img src="{{ dynamicAsset('public/assets/back-end/img/empty-product.png') }}" width="64"
                             alt="">
                        <div class="mx-auto my-3 max-w-353px">
                            {{ translate('search_and_add_product_from_the_list') }}
                        </div>
                    </div>
                    <div class="btn--container justify-content-end mt-3">
                        <button class="btn btn-secondary font-weight-semibold" data-dismiss="modal"
                                type="reset">{{ translate('Cancel') }}</button>
                        <button class="btn btn--primary font-weight-semibold clearance-product-add-submit" id="add-products-btn"
                                type="button">{{ translate('Add_Products') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
