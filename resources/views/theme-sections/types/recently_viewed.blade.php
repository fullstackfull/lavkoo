{{-- A visitor's own history, shown back to them. Scoped to their own first-party
     cookie — reading anyone else's would be both wrong and a privacy failure. --}}

@php
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $seenRail = ($s['style'] ?? 'rail') !== 'grid';
@endphp
<div class="ml-sec-head ml-reveal">
    <div>
        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
        <h2>{{ $s['title'] ?: translate('recently_viewed') }}</h2>
    </div>
</div>
<div class="{{ $seenRail ? 'ml-rail' : 'ml-grid' }} ml-reveal">
    @foreach ($seenProducts as $product)
        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
    @endforeach
</div>
