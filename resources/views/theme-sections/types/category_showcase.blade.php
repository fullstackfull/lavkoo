{{-- One category as its own block: its page banner, its sub-category chips and
     its products (from the category and everything filed under it). The banner
     is the same row the category page shows, so editing it in Banner Setup or
     on the category form updates both. --}}

@php
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $showcaseStyle = $s['style'] ?? 'rail';
    $showcaseRail = $showcaseStyle !== 'grid';
    $showcaseId = 'ml-showcase-' . ($__section['id'] ?? $loop->index);
@endphp
@if ($showcase)
    @php $categoryUrl = $viewAllUrl ?: route('products', ['category_id' => $showcase['category']->id]); @endphp

    @if ($showcase['banner'])
        <a class="ml-tile ml-showcase__banner ml-reveal"
           href="{{ $showcase['banner']['link'] ?: $categoryUrl }}">
            {{-- Shown whole, at the artwork's own proportions: a banner is
                 designed art, and cropping it to a fixed band cuts the
                 merchant's own text off. A phone image is used when the
                 banner carries one. --}}
            <picture>
                @if (!empty($showcase['banner']['image_mobile']))
                    <source media="(max-width:767.98px)" srcset="{{ $showcase['banner']['image_mobile'] }}">
                @endif
                <img src="{{ $showcase['banner']['image'] ?: $__placeholder }}"
                     alt="{{ $showcase['banner']['title'] ?? $showcase['category']->name }}" loading="lazy">
            </picture>
            @if (!empty($showcase['banner']['title']) || !empty($showcase['banner']['subtitle']))
                <span class="ml-tile__scrim"></span>
                <span class="ml-tile__body">
                    @if (!empty($showcase['banner']['title']))<h4>{{ $showcase['banner']['title'] }}</h4>@endif
                    @if (!empty($showcase['banner']['subtitle']))<p>{{ $showcase['banner']['subtitle'] }}</p>@endif
                    @if (!empty($showcase['banner']['button_text']))
                        <span class="ml-btn ml-btn-light">{{ $showcase['banner']['button_text'] }}</span>
                    @endif
                </span>
            @endif
        </a>
    @endif

    <div class="ml-sec-head ml-reveal">
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            <h2>{{ $s['title'] ?: $showcase['category']->name }}</h2>
        </div>
        @if ($s['view_all'] ?? true)
            <a class="ml-viewall" href="{{ $categoryUrl }}">{{ translate('view_all') }}</a>
        @endif
    </div>

    @if ($showcase['sub_categories']->isNotEmpty())
        <div class="ml-chips ml-reveal">
            @foreach ($showcase['sub_categories'] as $subCategory)
                <a href="{{ route('products', ['category_id' => $subCategory->id]) }}">{{ $subCategory->name }}</a>
            @endforeach
        </div>
    @endif

    @if ($showcase['products']->isNotEmpty())
        @if ($showcaseRail)
            <div class="ml-rail ml-reveal" id="{{ $showcaseId }}">
                @foreach ($showcase['products'] as $product)
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                @endforeach
            </div>
        @else
            <div class="ml-grid">
                @foreach ($showcase['products'] as $product)
                    <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endif
