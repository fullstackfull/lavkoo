<form action="{{ route('vendor.pos.index') }}" method="GET">
    <div class="offcanvas-sidebar" id="offcanvasPosFilter">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="text-capitalize m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                <input type="hidden" name="product_type" value="{{ requestString('product_type') }}">
                @include("vendor-views.partials._product-filters-sections", [
                    'filterSection' => ['sorting', 'brand', 'category'],
                    'productBrands' => $productBrands,
                    'productCategories' => $productCategories
                ])
            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup d-flex gap-3 bg-white px-3 px-sm-4 py-3">
                <a class="btn btn-secondary w-100" href="{{ route('vendor.pos.index') }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn--primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
