{{--
    Category landing header: the category's merchandising banner (inherited from
    the nearest ancestor when the open category has none) and its sub-categories
    as an entry strip. Both come from CategoryPageService; either half renders on
    its own, and the whole block disappears when the category has neither.
--}}
@php($categoryBanner = $categoryPageHeader['banner'] ?? null)
@php($categorySubCategories = $categoryPageHeader['subCategories'] ?? collect())

@if ($categoryBanner || $categorySubCategories->count() > 0)
    <div class="category-header mb-3 mb-md-4" dir="{{ session('direction') }}">
        @if ($categoryBanner)
            {{-- Links to this category's own page rather than the generic listing url
                 the banner CRUD generates, which is a second route to the same
                 products with no header on it. --}}
            <a class="category-header__banner d-block"
               href="{{ route('category-products', ['slug' => $categoryPageHeader['category']['slug']]) }}">
                {{-- The header banner is the page's largest paint. --}}
                <img loading="eager" fetchpriority="high"
                     src="{{ getStorageImages(path: $categoryBanner->photo_full_url, type: 'banner') }}"
                     alt="{{ $categoryBanner['title'] ?? $categoryPageHeader['category']['name'] }}">
                @if (!empty($categoryBanner['title']) || !empty($categoryBanner['sub_title']))
                    <span class="category-header__banner-copy">
                        @if (!empty($categoryBanner['title']))
                            <span class="category-header__banner-title">{{ $categoryBanner['title'] }}</span>
                        @endif
                        @if (!empty($categoryBanner['sub_title']))
                            <span class="category-header__banner-subtitle">{{ $categoryBanner['sub_title'] }}</span>
                        @endif
                    </span>
                @endif
            </a>
        @endif

        @if ($categorySubCategories->count() > 0)
            <nav class="category-header__subs" aria-label="{{ translate('sub_categories') }}">
                @foreach ($categorySubCategories as $subCategory)
                    <a class="category-header__sub" href="{{ route('category-products', ['slug' => $subCategory['slug']]) }}">
                        {{-- Sub-categories often carry no artwork of their own; a row of identical
                             grey placeholders reads as broken, so those show a letter chip. --}}
                        @php($subCategoryIcon = category_icon_url($subCategory))
                        <span class="category-header__sub-img {{ $subCategoryIcon ? '' : 'is-letter' }}">
                            @if ($subCategoryIcon)
                                <img loading="lazy" src="{{ $subCategoryIcon }}" alt="{{ $subCategory['name'] }}">
                            @else
                                <span aria-hidden="true">{{ mb_substr(trim((string) $subCategory['name']), 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="category-header__sub-name">{{ $subCategory['name'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
@endif
