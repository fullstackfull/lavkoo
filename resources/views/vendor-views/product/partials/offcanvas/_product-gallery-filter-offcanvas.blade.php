<form action="{{ route('vendor.products.product-gallery') }}" method="GET">
    <div class="offcanvas-sidebar offcanvas-end" tabindex="-1" id="offcanvasProductGalleryFilter"
        aria-labelledby="offcanvasProductGalleryFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="mb-0">{{ translate('Filter') }}</h3>
                <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-dismiss="offcanvas" aria-label="Close">
                    <i class="fi fi-rr-cross"></i>
                </button>
            </div>
            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                @include("vendor-views.partials._product-filters-sections", [
                    'filterSection' => ['product_type', 'store', 'brand', 'category'],
                    'productBrands' => $brands,
                    'productCategories' => $categories,
                ])
            </div>
            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup d-flex gap-3 bg-white px-3 px-sm-4 py-3">
                <a class="btn btn-secondary w-100" href="{{ route('vendor.products.product-gallery') }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn--primary w-100">{{ translate('Apply') }}</button>
            </div>
        </div>
    </div>
</form>
