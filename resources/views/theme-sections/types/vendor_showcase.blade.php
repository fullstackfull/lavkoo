{{-- One shop, featured: its cover and logo, what its buyers rate it, and the
     products it is selling right now. --}}

@php
    $shop = $vendorShowcase['shop'];
    $shopProducts = $vendorShowcase['products'];
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $shopStyle = $s['style'] ?? 'rail';
    $shopRail = $shopStyle !== 'grid';
    $shopUrl = \Illuminate\Support\Facades\Route::has('vendor-shop') && $shop->slug
        ? route('vendor-shop', ['slug' => $shop->slug])
        : route('products', ['seller_id' => $shop->seller_id]);
@endphp

<div class="ml-shop ml-reveal">
    @if ($s['cover'] ?? true)
        <a class="ml-shop__cover" href="{{ $shopUrl }}">
            <img src="{{ getStorageImages(path: $shop->banner_full_url, type: 'shop-banner') }}"
                 alt="{{ $shop->name }}" loading="lazy">
        </a>
    @endif
    <div class="ml-shop__bar">
        <a class="ml-shop__logo" href="{{ $shopUrl }}">
            <img src="{{ getStorageImages(path: $shop->image_full_url, type: 'shop') }}" alt="{{ $shop->name }}">
        </a>
        <div class="ml-shop__id">
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            <h3>{{ $s['title'] ?: $shop->name }}</h3>
            @if ($s['stats'] ?? true)
                <div class="ml-shop__stats">
                    @if ($shop->average_rating > 0)
                        <span><i class="fa fa-star"></i> {{ number_format($shop->average_rating, 1) }}
                            <small>({{ $shop->review_count }})</small></span>
                    @endif
                    <span>{{ $shop->products_count }} {{ translate('products') }}</span>
                    @if ($shop->is_vacation_mode_now ?? false)
                        <span class="ml-shop__closed">{{ translate('closed_now') }}</span>
                    @endif
                </div>
            @endif
        </div>
        @if ($s['view_all'] ?? true)
            <a class="ml-btn ml-btn-gold ml-shop__visit" href="{{ $shopUrl }}">{{ translate('visit_store') }}</a>
        @endif
    </div>
</div>

@if ($shopProducts->isNotEmpty())
    @if ($shopRail)
        <div class="ml-rail ml-reveal">
            @foreach ($shopProducts as $product)
                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
            @endforeach
        </div>
    @else
        <div class="ml-grid">
            @foreach ($shopProducts as $product)
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                </div>
            @endforeach
        </div>
    @endif
@endif
