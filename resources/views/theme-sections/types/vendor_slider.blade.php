{{-- The marketplace's sellers. A shop card carries what a buyer weighs before
     entering a store: its cover, its logo, its rating and how much it sells. --}}

@php
    $vendorStyle = $s['style'] ?? 'cards';
    $showStats = (bool) ($s['stats'] ?? true);
    // `storefront` and `logos` are strips a shopper swipes through, so they ride the same
    // rail track as `rail`; `cards` and `compact` lay out as a grid.
    $vendorRail = in_array($vendorStyle, ['rail', 'storefront', 'logos'], true);
@endphp
<div class="ml-sec-head ml-reveal">
    <div>
        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
        <h2>{{ $s['title'] ?: translate('our_vendors') }}</h2>
    </div>
    @if (($s['view_all'] ?? true) && $viewAllUrl)
        <a class="ml-viewall" href="{{ $viewAllUrl }}">{{ translate('view_all') }}</a>
    @endif
</div>

<div class="{{ $vendorRail ? 'ml-rail ml-vendor-rail' : 'ml-grid' }} ml-vendors--{{ $vendorStyle }} ml-reveal">
    @foreach ($vendors as $shop)
        @include('theme-sections.partials.vendor-card', [
            'shop' => $shop, 'variant' => $vendorStyle, 'stats' => $showStats,
        ])
    @endforeach
</div>
