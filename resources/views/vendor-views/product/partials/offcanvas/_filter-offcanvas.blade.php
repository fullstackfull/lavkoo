<form action="{{ route('vendor.products.list', ['type' => request('type')]) }}" method="GET">
    <div class="offcanvas-sidebar" id="offcanvasProductFilter">
        <div class="offcanvas-overlay" data-dismiss="offcanvas"></div>

        <div class="offcanvas-content bg-white shadow d-flex flex-column">
            <div class="offcanvas-header bg-light d-flex justify-content-between align-items-center p-3">
                <h3 class="text-capitalize m-0">{{ translate('Filter') }}</h3>
                <button type="button" class="close" data-dismiss="offcanvas" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            @php
                $filterSections = ['sorting', 'product_type', 'product_status', 'brand', 'category'];
                if (in_array(request('type', ''), ['new-request','denied'])) {
                    $filterSections = ['sorting', 'product_type', 'brand', 'category'];
                }
            @endphp

            <div class="offcanvas-body p-3 overflow-auto flex-grow-1">
                @include("vendor-views.partials._product-filters-sections", [
                    'filterSection' => $filterSections,
                    'productBrands' => $brands,
                    'productCategories' => $categories
                ])
            </div>

            <div class="offcanvas-footer offcanvas-footer-sticky shadow-popup d-flex gap-3 bg-white px-3 px-sm-4 py-3">
                <a class="btn btn-secondary w-100" href="{{ route('vendor.products.list', ['type' => request('type')]) }}">
                    {{ translate('Clear_Filter') }}
                </a>
                <button type="submit" class="btn btn--primary w-100">
                    {{ translate('Apply') }}
                </button>
            </div>
        </div>
    </div>
</form>
