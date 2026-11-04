<div class="auction-brand-filter-wrapper">
    <h6 class="fs-13 fw-semibold mb-15">{{ translate('Brands') }}</h6>
    <div class="w-100 form-auction overflow-hidden rounded border d-flex align-items-center position-relative mb-15">
        <input class="form-control min-h-40 rounded-0 border-0 auction-brand-search-input" type="text" placeholder="{{ translate('Search_by_name') }}" name="" value="" autocomplete="off">
        <button class="btn btn-primary bg-white text-primary border-0 min-h-40 w-50px rounded-0 d-center" type="button">
            <i class="fi fi-rr-search"></i>
        </button>
    </div>
    <ul class="for-brand webkit-scrolling-custom d-flex flex-column gap-10px list-group auction-brand-filter-list p-0">
        @forelse($productBrands ?? [] as $brand)
            <li class="d-flex align-items-center gap-1 justify-content-between cursor--pointer px-2 auction-brand-item {{ request('brand_id') == $brand->id ? 'fw-semibold' : '' }}"
                data-brand-id="{{ $brand->id }}">
                <div class="text-start fs-13 line--limit-1 auction-brand-item-name {{ request('brand_id') == $brand->id ? 'fw-semibold text-primary' : 'title-clr textclr' }}">
                    {{ $brand->name }}
                </div>
                <div class="badge bg-light fs-12 fw-normal rounded-pill title-clr py-2px px-2 flex-shrink-0">
                    <span>{{ $brand->auction_product_count }}</span>
                </div>
            </li>
        @empty
            <li class="fs-12 title-clr text-center py-10 list-unstyled">{{ translate('No_brands_found') }}</li>
        @endforelse
        <li class="auction-brand-no-result fs-12 title-clr text-center py-10 list-unstyled" style="display:none">{{ translate('No_brands_found') }}</li>
    </ul>
</div>

<script>
    (function () {
        function bindBrandSearch(wrapper) {
            if (!wrapper || wrapper.dataset.brandSearchBound === '1') return;
            wrapper.dataset.brandSearchBound = '1';

            var input = wrapper.querySelector('.auction-brand-search-input');
            if (!input) return;

            var handler = function () {
                var term = (input.value || '').toLowerCase().trim();
                var found = false;
                wrapper.querySelectorAll('.auction-brand-item').forEach(function (li) {
                    var nameEl = li.querySelector('.auction-brand-item-name');
                    var text = (nameEl ? nameEl.textContent : li.textContent).toLowerCase();
                    var visible = text.indexOf(term) !== -1;
                    li.classList.toggle('d-none', !visible);
                    if (visible) found = true;
                });
                var empty = wrapper.querySelector('.auction-brand-no-result');
                if (empty) empty.style.display = (term === '' || found) ? 'none' : '';
            };

            input.addEventListener('input', handler);
            input.addEventListener('keyup', handler);
        }

        function bindAll() {
            document.querySelectorAll('.auction-brand-filter-wrapper').forEach(bindBrandSearch);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindAll);
        } else {
            bindAll();
        }
    })();
</script>
