<div class="">
    <h6 class="fs-13 fw-semibold mb-15">{{ translate('Categories') }}</h6>
    <div class="product-categories-list webkit-scrolling-custom">
        @forelse($productCategories ?? [] as $category)
            @if(($category->auction_product_count ?? 0) > 0)
                <div class="menu--caret-accordion">
                    <div class="card-header menu--caret-box py-10 border-bottom d-flex align-items-center gap-1 justify-content-between sub-header">
                        <a href="#0"
                           onclick="auctionSetCategoryFilter({{ $category->id }}); return false;"
                           data-category-id="{{ $category->id }}"
                           class="cursor--pointer d-flex gap-10px align-items-center {{ request('category_id') == $category->id ? 'fw-semibold text-primary' : 'title-clr' }}">
                            {{-- Category icon removed: image path not resolvable --}}
                            <span class="line--limit-1 fs-12 fw-normal">
                                {{ $category->name }}
                            </span>
                        </a>
                    </div>
                </div>
            @endif
        @empty
            <p class="fs-12 title-clr text-center py-10">{{ translate('No_categories_found') }}</p>
        @endforelse
    </div>
</div>

<script>
    function auctionSetCategoryFilter(categoryId) {
        var form = document.querySelector('.product-list-filter');
        if (!form) return;

        var input = form.querySelector('input[name="category_id"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'category_id';
            form.appendChild(input);
        }

        input.value = (input.value == categoryId) ? '' : categoryId;

        // Mutual exclusivity: category and brand cannot coexist
        var brandInput = form.querySelector('input[name="brand_id"]');
        if (brandInput) brandInput.value = '';

        var dataFromInput = form.querySelector('input[name="data_from"]');
        if (dataFromInput) {
            dataFromInput.value = input.value ? 'category' : '';
        }

        // Update category active states (single pass — no separate querySelector)
        var activeCategoryId = input.value ? String(input.value).trim() : '';
        document.querySelectorAll('a[data-category-id]').forEach(function (a) {
            var isActive = activeCategoryId !== '' && String(a.getAttribute('data-category-id')).trim() === activeCategoryId;
            if (isActive) {
                a.classList.remove('title-clr');
                a.classList.add('fw-semibold', 'text-primary');
            } else {
                a.classList.remove('fw-semibold', 'text-primary');
                a.classList.add('title-clr');
            }
        });

        // Clear all brand active states
        document.querySelectorAll('.auction-brand-item').forEach(function (li) {
            li.classList.remove('fw-semibold');
            var nameEl = li.querySelector('.auction-brand-item-name');
            if (nameEl) {
                nameEl.classList.remove('fw-semibold', 'text-primary');
                nameEl.classList.add('title-clr', 'textclr');
            }
        });

        var pageInput = form.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = 1;

        if (typeof window.auctionRenderProductsList === 'function') {
            window.auctionRenderProductsList();
            return;
        }

        form.submit();
    }
</script>
