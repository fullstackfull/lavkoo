{{-- Banners created in Promotion -> Banners, rendered in whichever presentation
     the merchant picked. This is what makes a dashboard banner show up in the theme. --}}

@php
    $cards = $__resolver->dashboardBanners((string) ($s['banner_type'] ?? 'Main Banner'), (int) ($s['limit'] ?? 6));
    $layout = (string) ($s['layout'] ?? 'carousel');
@endphp
@if (count($cards))
    @if (!empty($s['title']) || !empty($s['subtitle']))
        <div class="ml-sec-head ml-reveal">
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            <div class="ml-rule"></div>
            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
        </div>
    @endif

    @if ($layout === 'carousel')
        @include('theme-sections.partials.hero', ['slides' => $cards, 'settings' => $s, 'index' => 'sb' . $loop->index, 'placeholder' => $__placeholder])
    @elseif ($layout === 'mosaic')
        @include('theme-sections.partials.banner-mosaic', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
    @elseif ($layout === 'split')
        @include('theme-sections.partials.banner-split', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
    @elseif ($layout === 'strip')
        @include('theme-sections.partials.banner-strip', ['card' => $cards[0], 'settings' => $s, 'placeholder' => $__placeholder])
    @else
        {{-- 'grid' and 'swipe' share the banner-grid partial, which reads
             the style; grid is also the safe landing for a value that is
             no longer offered. --}}
        @include('theme-sections.partials.banner-grid', [
            'cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder,
            'columns' => max(1, (int) ($s['columns'] ?? 3)), 'gap' => $gap,
            'style' => $layout === 'swipe' ? 'swipe' : 'tiles',
        ])
    @endif
@endif
