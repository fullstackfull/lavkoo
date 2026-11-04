{{-- Tiles sit still, 'rail' scrolls when there are more banners than fit,
     and 'overlap' staggers them so the row reads as a composition rather
     than a table. The partial takes the style and does the rest. --}}
@include('theme-sections.partials.banner-grid', [
    'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder,
    'columns' => max(1, (int) ($s['columns'] ?? 2)), 'gap' => $gap,
    'style' => $s['style'] ?? 'tiles',
])
