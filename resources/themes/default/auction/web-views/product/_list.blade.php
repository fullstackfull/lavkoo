@extends("auction.layouts.auction-app")

@section('title', $pageTitleContent)

@section('content')
    <div class="container">

        <form method="GET" action="{{ url()->current() }}" class="product-list-filter">
            <span id="auction-products-search-data-backup"
                  data-url="{{ url()->current() }}"
                  data-search="{{ request('search') }}">
            </span>
            <input hidden name="offer_type" value="{{ $data['offer_type'] }}">
            <input hidden name="data_from" value="{{ request('data_from') }}">
            <input hidden name="category_id" value="{{ request('category_id') }}">
            <input hidden name="brand_id" value="{{ request('brand_id') }}">
            <input hidden name="page" value="{{ request('page', 1) }}">

            @include("auction.web-views.partials._product-list-header")

            <div>
                <div class="row g-3">
                    <div class="col-lg-3">
                        <div class="search-filer-sidebar rounded bg-white border d-lg-block d-none">
                            <div class="d-flex align-items-center justify-content-between py-12px px-10px border-bottom mb-20">
                                <h5 class="fs-16 fw-semibold title-clr mb-0">{{ translate('Filter_By') }}</h5>
                                <a href="{{ $resetUrl ?? url()->current() }}" class="btn btn-link p-0 fs-12 text-dark text-decoration-none lh-1">{{ translate('Reset') }}</a>
                            </div>
                            <div class="p-10px pt-0">
                                <div class="d-flex flex-column gap-20px">
                                    @include('auction.web-views.product.partials._filter-ending-time')
                                    @include('auction.web-views.product.partials._filter-entry-fee')
                                    @include('auction.web-views.product.partials._filter-product-price')
                                    @if(!request()->routeIs('auction.ending-soon-products', 'auction.trending-products', 'auction.upcoming-products'))
                                        @include('auction.web-views.product.partials._filter-auction-istings')
                                    @endif
                                    @include('auction.web-views.product.partials._filter-product-categories', [
                                        'productCategories' => $categories,
                                        'dataFrom' => request('data_from'),
                                    ])
                                    @include('auction.web-views.product.partials._filter-product-brands', [
                                        'productBrands' => $activeBrands,
                                        'dataFrom' => request('data_from'),
                                    ])
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="col-lg-9">
                        <div class="row g-sm-3 g-2" id="ajax-products-view">
                            @include('auction.web-views.product._ajax-products', ['products' => $products])
                        </div>
                    </div>
                </div>
            </div>

        </form>

        @if(!empty($authorContext) && $authorContext['owner_type'] !== 'customer')
            @include('auction.web-views.product.details._chatting', [
                'seller'    => $authorContext['owner'] ?? null,
                'user_type' => $authorContext['owner_type'] === 'seller' ? 'seller' : 'admin',
            ])
        @endif
    </div>
@endsection

@push('script')
<script>
    "use strict";

    document.addEventListener('DOMContentLoaded', function () {
        const filterForm = document.querySelector('.product-list-filter');
        const searchInput = filterForm?.querySelector('input[name="search"]');
        const sortSelect = filterForm?.querySelector('select[name="sort_by"]');
        const listingTypeChecks = filterForm?.querySelectorAll('.auction-listing-filter');
        const searchSubmitButton = filterForm?.querySelector('.search-header-product button[type="submit"]');
        const productsContainer = document.getElementById('ajax-products-view');
        const stateBackup = document.getElementById('auction-products-search-data-backup');
        const baseUrl = stateBackup?.dataset?.url || window.location.pathname;

        if (!filterForm || !searchInput || !productsContainer) {
            return;
        }

        let activeAjaxRequest = null;

        const updateBrowserUrl = (params) => {
            const queryString = params.toString();
            const newUrl = queryString ? `${baseUrl}?${queryString}` : baseUrl;
            window.history.replaceState({}, '', newUrl);
        };

        const clearPrimaryFilterInputs = () => {
            ['category_id', 'brand_id', 'data_from'].forEach(function (name) {
                const el = filterForm.querySelector('input[name="' + name + '"]');
                if (el) el.value = '';
            });
        };

        const ensurePageInput = () => {
            let pageInput = filterForm.querySelector('input[name="page"]');
            if (!pageInput) {
                pageInput = document.createElement('input');
                pageInput.type = 'hidden';
                pageInput.name = 'page';
                pageInput.value = '1';
                filterForm.appendChild(pageInput);
            }
            return pageInput;
        };

        const renderProductsBySearch = (options = {}) => {
            const resetPage = options.resetPage === true;
            const forcedPage = options.page ? String(options.page) : null;
            const pageInput = ensurePageInput();

            if (forcedPage) {
                pageInput.value = forcedPage;
            } else if (resetPage) {
                pageInput.value = '1';
            }

            const formData = new FormData(filterForm);
            const searchValue = searchInput.value.trim();
            formData.set('search', searchValue);

            if (searchValue) {
                formData.set('name', searchValue);
                formData.set('product_name', searchValue);
            } else {
                formData.delete('name');
                formData.delete('product_name');
            }

            // Collect default values from range/select inputs tagged with data-default-value.
            // These are the "no filter applied" sentinel values — skip them to keep the URL clean.
            const rangeDefaults = new Map();
            filterForm.querySelectorAll('[data-default-value]').forEach(function (el) {
                const nm = el.getAttribute('name');
                if (nm) rangeDefaults.set(nm, String(el.getAttribute('data-default-value')).trim());
            });

            // Numeric range keys: compare as numbers so "10.00" and "10" are treated as equal.
            const numericRangeKeys = new Set([
                'min_price','max_price',
                'min_entry_fee','max_entry_fee',
                'ending_time_min','ending_time_max',
            ]);

            const params = new URLSearchParams();
            const seenArrayValues = new Map();

            for (const [key, rawValue] of formData.entries()) {
                const value = typeof rawValue === 'string' ? rawValue.trim() : rawValue;
                if (value === null || value === '') {
                    continue;
                }

                // Keep query clean: do not send stale mode flags from legacy flow.
                if (key === 'data_from' && value === 'search') {
                    continue;
                }

                // Skip range/select inputs whose value matches their initial default —
                // the user hasn't touched them so they carry no filter intent.
                if (rangeDefaults.has(key)) {
                    const def = rangeDefaults.get(key);
                    const equal = numericRangeKeys.has(key)
                        ? Number(value) === Number(def)
                        : String(value) === String(def);
                    if (equal) continue;
                }

                if (String(key).endsWith('[]')) {
                    // Deduplicate: the same checkbox group can appear in both the desktop
                    // sidebar and the offcanvas, producing duplicate FormData entries.
                    if (!seenArrayValues.has(key)) seenArrayValues.set(key, new Set());
                    const seen = seenArrayValues.get(key);
                    const strVal = String(value);
                    if (!seen.has(strVal)) {
                        params.append(key, value);
                        seen.add(strVal);
                    }
                } else {
                    params.set(key, value);
                }
            }

            updateBrowserUrl(params);

            if (activeAjaxRequest && typeof activeAjaxRequest.abort === 'function') {
                activeAjaxRequest.abort();
            }

            activeAjaxRequest = $.ajax({
                url: baseUrl,
                type: 'GET',
                data: params.toString(),
                dataType: 'json',
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (response) {
                    productsContainer.innerHTML = response?.html_products ?? '';

                    if (typeof window.initAuctionCountdowns === 'function') {
                        window.initAuctionCountdowns(productsContainer);
                    }

                    if (response?.total_product !== undefined) {
                        var numberEl = document.getElementById('auction-products-count-number');
                        var labelEl  = document.getElementById('auction-products-count-label');
                        if (numberEl && labelEl) {
                            var total = parseInt(response.total_product, 10);
                            numberEl.textContent = total;
                            labelEl.textContent  = total === 1 ? labelEl.dataset.singular : labelEl.dataset.plural;
                        }
                    }

                    if (response?.brand_counts) {
                        var counts = response.brand_counts;
                        document.querySelectorAll('.auction-brand-item').forEach(function (li) {
                            var id = li.getAttribute('data-brand-id');
                            if (id == null) return;
                            var countEl = li.querySelector('.badge span');
                            var count = counts.hasOwnProperty(id) ? counts[id] : 0;
                            if (countEl) countEl.textContent = count;
                        });
                    }
                },
                complete: function () {
                    $('#loading').hide();
                }
            });
        };
        window.auctionRenderProductsList = renderProductsBySearch;

        if (searchSubmitButton) {
            searchSubmitButton.addEventListener('click', function (event) {
                event.preventDefault();
                clearPrimaryFilterInputs();
                renderProductsBySearch({resetPage: true});
            });
        }

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                clearPrimaryFilterInputs();
                renderProductsBySearch({resetPage: true});
            }
        });

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                renderProductsBySearch({resetPage: true});
            });
        }

        if (listingTypeChecks) {
            listingTypeChecks.forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    // Sync every other checkbox with the same value (desktop ↔ offcanvas mirror).
                    const val     = this.value;
                    const checked = this.checked;
                    filterForm.querySelectorAll('.auction-listing-filter').forEach(function (cb) {
                        if (cb !== checkbox && cb.value === val) cb.checked = checked;
                    });
                    renderProductsBySearch({resetPage: true});
                });
            });
        }

        document.addEventListener('click', function (event) {
            const actionButton = event.target.closest('.action-search-products-by-price');
            if (!actionButton) {
                return;
            }
            event.preventDefault();
            renderProductsBySearch({resetPage: true});
        });

        const instantFilterNames = [
            'min_price',
            'max_price',
            'min_entry_fee',
            'max_entry_fee',
            'ending_time_min',
            'ending_time_max',
            'duration_time_type',
        ];

        filterForm.addEventListener('change', function (event) {
            const target = event.target;
            if (!target || !target.name) {
                return;
            }

            if (instantFilterNames.includes(target.name)) {
                renderProductsBySearch({resetPage: true});
            }
        });

        filterForm.addEventListener('keydown', function (event) {
            const target = event.target;
            if (!target || !target.name || event.key !== 'Enter') {
                return;
            }

            if (instantFilterNames.includes(target.name)) {
                event.preventDefault();
                renderProductsBySearch({resetPage: true});
            }
        });

        // Decimal-only filter: allow digits and a single dot; block negatives.
        // We use type="text" + inputmode="decimal" because <input type="number"> rejects
        // "." in locales where the decimal separator is "," (browsers reject the keystroke
        // entirely and the user can never enter a fractional value).
        const sanitizeDecimal = (raw) => {
            let s = String(raw ?? '').replace(/[^0-9.]/g, '');
            const firstDot = s.indexOf('.');
            if (firstDot !== -1) {
                s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '');
            }
            return s;
        };

        filterForm.addEventListener('input', function (event) {
            const target = event.target;
            if (!target || !target.classList || !target.classList.contains('auction-decimal-input')) {
                return;
            }
            const cleaned = sanitizeDecimal(target.value);
            if (cleaned !== target.value) {
                target.value = cleaned;
            }
        });

        filterForm.addEventListener('paste', function (event) {
            const target = event.target;
            if (!target || !target.classList || !target.classList.contains('auction-decimal-input')) {
                return;
            }
            const pasted = (event.clipboardData || window.clipboardData)?.getData('text') ?? '';
            event.preventDefault();
            const cleaned = sanitizeDecimal(target.value + pasted);
            target.value = cleaned;
            target.dispatchEvent(new Event('input', {bubbles: true}));
        });

        document.addEventListener('click', function (event) {
            const paginationLink = event.target.closest('#paginator-ajax a.page-link, #paginator-ajax a');
            if (!paginationLink) {
                return;
            }

            const href = paginationLink.getAttribute('href');
            if (!href || href === '#' || href.startsWith('javascript:')) {
                return;
            }

            event.preventDefault();

            let page = null;
            try {
                const parsedUrl = new URL(href, window.location.origin);
                page = parsedUrl.searchParams.get('page');
            } catch (e) {
                page = null;
            }

            renderProductsBySearch({page: page || '1'});
        });

        document.addEventListener('input', function (event) {
            if (!event.target.classList.contains('auction-brand-search-input')) return;
            const wrapper = event.target.closest('.auction-brand-filter-wrapper');
            if (!wrapper) return;
            const term = event.target.value.toLowerCase().trim();
            let found = false;
            wrapper.querySelectorAll('.auction-brand-item').forEach(function (li) {
                const nameEl = li.querySelector('.auction-brand-item-name');
                if (!nameEl) return;
                const visible = nameEl.textContent.toLowerCase().includes(term);
                li.style.display = visible ? '' : 'none';
                if (visible) found = true;
            });
            const emptyState = wrapper.querySelector('.auction-brand-no-result');
            if (emptyState) emptyState.style.display = found ? 'none' : '';
        });

        document.addEventListener('click', function (event) {
            const item = event.target.closest('.auction-brand-item');
            if (!item) return;

            const brandId    = item.getAttribute('data-brand-id');
            const brandInput = filterForm.querySelector('input[name="brand_id"]');
            const dataFrom   = filterForm.querySelector('input[name="data_from"]');
            const catInput   = filterForm.querySelector('input[name="category_id"]');

            if (brandInput) brandInput.value = brandInput.value == brandId ? '' : brandId;
            if (catInput)   catInput.value   = '';
            if (dataFrom)   dataFrom.value   = brandInput?.value ? 'brand' : '';
            ensurePageInput().value = 1;

            document.querySelectorAll('a[data-category-id]').forEach(function (a) {
                a.classList.remove('fw-semibold', 'text-primary');
                a.classList.add('title-clr');
            });

            const activeId = brandInput?.value ? String(brandInput.value).trim() : '';
            document.querySelectorAll('.auction-brand-item').forEach(function (li) {
                const active = activeId !== '' && String(li.getAttribute('data-brand-id')).trim() === activeId;
                const nameEl = li.querySelector('.auction-brand-item-name');
                li.classList.toggle('fw-semibold', active);
                if (nameEl) {
                    nameEl.classList.toggle('fw-semibold', active);
                    nameEl.classList.toggle('text-primary', active);
                    nameEl.classList.toggle('title-clr', !active);
                    nameEl.classList.toggle('textclr', !active);
                }
            });

            renderProductsBySearch({ resetPage: true });
        });
    });
</script>
@endpush
