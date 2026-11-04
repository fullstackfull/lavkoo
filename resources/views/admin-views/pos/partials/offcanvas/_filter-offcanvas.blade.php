<form action="{{ route('admin.pos.index') }}" method="GET">
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasPosFilter"
         aria-labelledby="offcanvasPosFilterLabel" style="--bs-offcanvas-width: 500px;">
        <div class="offcanvas-header bg-body">
            <h3 class="mb-0">{{ translate('Filter') }}</h3>
            <button type="button" class="btn btn-circle bg-white text-dark fs-10" style="--size: 1.5rem;" data-bs-dismiss="offcanvas" aria-label="Close">
                <i class="fi fi-rr-cross"></i>
            </button>
        </div>
        <div class="offcanvas-body">
            <input type="hidden" name="product_type" value="{{ requestString('product_type') }}">
            @include("admin-views.partials._product-filters-sections", [
                'filterSection' => ['sorting', 'brand', 'category'],
                'productBrands' => $productBrands,
                'productCategories' => $productCategories
            ])
        </div>
        <div class="offcanvas-footer shadow-popup">
            <div class="d-flex justify-content-center gap-3 bg-white px-3 py-2">
                <a class="btn btn-secondary w-100" href="{{ route('admin.pos.index') }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn-primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
