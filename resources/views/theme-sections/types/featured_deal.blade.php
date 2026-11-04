{{-- The running featured-deal campaign, and the clearance shelf: both are just
     product rows whose source is a dashboard screen. --}}

@php
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $offerStyle = $s['style'] ?? 'rail';
    $offerRail = $offerStyle !== 'grid';
@endphp
    <div class="ml-sec-head ml-reveal">
        <div>
            <span class="ml-eyebrow">{{ $s['eyebrow'] ?: ($type === 'featured_deal' ? translate('featured_deal') : translate('clearance_sale')) }}</span>
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
        </div>
    </div>
    <div class="{{ $offerRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
        @foreach ($offerProducts as $product)
            @if ($offerRail)
                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
            @else
                <div data-delay="{{ $loop->index % 6 }}">
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                </div>
            @endif
        @endforeach
    </div>
