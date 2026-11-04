{{-- The brand half of category_showcase: one brand, its mark, its products. --}}

@php
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $brandRail = ($s['style'] ?? 'rail') !== 'grid';
@endphp
<div class="ml-sec-head ml-reveal">
    <div class="d-flex align-items-center gap-3">
        @if (($s['logo'] ?? true) && !empty($brandShowcase['brand']->image_full_url))
            <span class="ml-brand-mark">
                <img src="{{ getStorageImages(path: $brandShowcase['brand']->image_full_url, type: 'brand') }}"
                     alt="{{ $brandShowcase['brand']->name }}" loading="lazy">
            </span>
        @endif
        <div>
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            <h2>{{ $s['title'] ?: $brandShowcase['brand']->name }}</h2>
        </div>
    </div>
    @if ($s['view_all'] ?? true)
        <a class="ml-viewall" href="{{ $viewAllUrl ?: route('products', ['brand_id' => $brandShowcase['brand']->id]) }}">{{ translate('view_all') }}</a>
    @endif
</div>
<div class="{{ $brandRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
    @foreach ($brandShowcase['products'] as $product)
        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
    @endforeach
</div>
