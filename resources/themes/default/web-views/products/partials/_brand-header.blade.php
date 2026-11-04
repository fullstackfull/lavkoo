{{--
    Brand landing header: the brand's banner and the categories its products
    actually live in, as a filter strip. The categories come from the catalogue
    (BrandPageService), so a chip can never lead to an empty result. Selecting
    one narrows the list below through the page's own category_ids filter.
--}}
@php($brandBanner = $brandPageHeader['banner'] ?? null)
@php($brandCategories = $brandPageHeader['categories'] ?? collect())
@php($brandOfPage = $brandPageHeader['brand'] ?? null)
@php($activeBrandCategoryId = $brandPageHeader['activeCategoryId'] ?? null)

@if ($brandOfPage && ($brandBanner || $brandCategories->count() > 0))
    <div class="category-header mb-3 mb-md-4" dir="{{ session('direction') }}">
        @if ($brandBanner)
            {{-- Links to the brand page itself, not to the generic listing url the
                 banner CRUD generates: this banner heads that page, so sending the
                 visitor to a different listing of the same products is a dead end. --}}
            <a class="category-header__banner d-block"
               href="{{ route('brand-products', ['slug' => $brandOfPage['slug']]) }}">
                {{-- The header banner is the page's largest paint; lazy-loading it
                     delays the very thing the visitor is waiting for. --}}
                <img loading="eager" fetchpriority="high"
                     src="{{ getStorageImages(path: $brandBanner->photo_full_url, type: 'banner') }}"
                     alt="{{ $brandBanner['title'] ?: $brandOfPage['name'] }}">
            </a>
        @endif

        @if ($brandCategories->count() > 0)
            <nav class="brand-header__filters" aria-label="{{ translate('categories') }}">
                <a class="brand-header__chip {{ $activeBrandCategoryId ? '' : 'brand-header__chip--active' }}"
                   href="{{ route('brand-products', ['slug' => $brandOfPage['slug']]) }}">
                    {{ translate('all') }}
                </a>
                @foreach ($brandCategories as $brandCategory)
                    <a class="brand-header__chip {{ $activeBrandCategoryId === (int) $brandCategory['id'] ? 'brand-header__chip--active' : '' }}"
                       href="{{ route('brand-products', ['slug' => $brandOfPage['slug'], 'category_ids' => [$brandCategory['id']]]) }}">
                        {{ $brandCategory['name'] }}
                        <span class="brand-header__chip-count">{{ $brandCategory['products_count'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
@endif
