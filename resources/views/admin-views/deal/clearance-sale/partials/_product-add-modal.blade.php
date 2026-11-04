<div class="modal fade" id="product-add-modal">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-body">
                <div class="pb-3 d-flex justify-content-between align-items-start gap-3">
                   <div>
                        <h3>{{ translate('Add_Product') }}</h3>
                        <p>
                            {{ translate('search_product') }} & {{ translate('add_to_your_clearance_list') }}
                        </p>
                   </div>
                    <button type="button" class="btn btn-circle border-0 fs-12 text-body bg-section2 shadow-none" style="--size: 2rem;"  data-bs-dismiss="modal" aria-label="Close">
                        <i class="fi fi-sr-cross"></i>
                    </button>
                </div>
                <form action="{{route('admin.deal.clearance-sale.add-product')}}" method="post" class="clearance-add-product">
                    @csrf
                    <div class="bg-section p-3 p-sm-20 rounded-10">
                        <label class="form-label">{{ translate('Search_Products') }} <span class="text-danger">*</span></label>
                        {{-- Same shell as /admin/deal/day select-product implementation:
                             button-style dropdown toggle, search-form (magnifier + input) tucked
                             inside the dropdown-menu. Multi-select chip area below is preserved.
                             The .search-product-for-clearance-sale class on the input keeps the
                             existing JS hooks in
                             public/assets/back-end/js/search-and-select-multiple-product.js. --}}
                        <div class="dropdown select-clearance-product-search w-100 mb-20">
                            <button class="form-select bg-transparent shadow-none text-start dropdown-toggle line-1 word-break select-product-button"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                    aria-haspopup="true" aria-expanded="false" type="button">
                                {{ translate('Search_Products') }}
                            </button>
                            <div class="dropdown-menu w-100 px-2">
                                <div class="search-form mb-3">
                                    <button type="button" class="btn" aria-label="{{ translate('search') }}"><i class="fi fi-rr-search"></i></button>
                                    <input type="text" id="searchInput" autocomplete="off"
                                           class="form-control search-product-for-clearance-sale"
                                           placeholder="{{ translate('Search_Product') }}">
                                </div>
                                <div class="d-flex flex-column max-h-300 overflow-y-auto overflow-x-hidden child-border-bottom search-result-box">
                                    @include('admin-views.deal.clearance-sale.partials._search-product', ['products' => $products])
                                </div>
                            </div>
                        </div>
                        <div class="selected-products bg-white rounded-10 px-3 pb-4 row g-3 mt-0 clearance-selected-products" id="selected-products">
                            @include('admin-views.partials._select-product')
                        </div>
                    </div>
                    <div class="p-4 bg-section2 rounded text-center mt-3 search-and-add-product">
                        <img src="{{ dynamicAsset('public/assets/back-end/img/empty-product.png') }}" width="64"
                             alt="">
                        <div class="mx-auto my-3 max-w-360">
                            {{ translate('search') }} & {{ translate('and_add_product_from_the_list') }}
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-end mt-3">
                        <button class="btn btn-secondary fw-semibold min-w-120"
                                type="reset" data-bs-dismiss="modal">{{ translate('Cancel') }}</button>
                        <button class="btn btn-primary fw-semibold min-w-120 clearance-product-add-submit" id="add-products-btn"
                                type="button">{{ translate('Add_Products') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
{{-- Carries the localized "already selected" toast copy that
     search-and-select-multiple-product.js reads in showAlreadySelectedToast(). --}}
<span id="product-already-selected-message"
      data-message="{{ translate('product_is_already_selected') }}"></span>

{{-- The modal is included inside @section('content'), which renders
     inside .app-v2 > .v2-main. The v2 shell sets overflow: hidden on
     both <body> and .app-v2, so an inline modal gets clipped — the top
     of the dialog hides behind the header. Move it to <body> on first
     paint so it escapes the clip and overlays the whole viewport like
     any normal Bootstrap modal. --}}
<script>
    (function () {
        var modal = document.getElementById('product-add-modal');
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    })();
</script>
