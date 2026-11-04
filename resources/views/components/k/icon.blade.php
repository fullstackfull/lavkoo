@props([
    'name',
    'size' => 18,
    'directional' => false,
    'label' => null,
])
{{--
    Kohl icon.

    One inline SVG sprite replaces 154 PNG files and three icon fonts. Inline
    rather than <use href> so it works inside the theme-builder preview iframe
    and in emailed/exported HTML, and so it inherits currentColor everywhere.

    Icons that carry direction (chevrons, arrows) get .k-icon--directional and
    are flipped once by the base layer under [dir="rtl"], never per usage.
--}}
@php
    $paths = [
        // navigation
        'home'        => '<path d="M3 11.4 12 4l9 7.4V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'catalog'     => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
        'orders'      => '<path d="M6 8h12l-1 11.2a1 1 0 0 1-1 .8H8a1 1 0 0 1-1-.8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'customers'   => '<circle cx="9" cy="9" r="3"/><path d="M3 20c0-3 2.5-5 6-5s6 2 6 5"/><circle cx="17" cy="10" r="2.5"/><path d="M15 20c.4-2.2 2-3.5 4-3.5s3.6 1.3 4 3.5"/>',
        'marketing'   => '<path d="M3 13V9l13-5v16L3 15Z"/><path d="M16 9v6"/><path d="M7 15v2a2 2 0 0 0 4 0v-1"/>',
        'store'       => '<path d="M4 9h16l-1 11H5z"/><path d="M4 9 5.5 4h13L20 9"/><path d="M9 13h6"/>',
        'reports'     => '<path d="M5 20V11M11 20V5M17 20v-6"/><path d="M3 20h18"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 7.5 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 7.5l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 3V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1.3Z"/>',
        // actions
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'filter'      => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'download'    => '<path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M5 20h14"/>',
        'trash'       => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1L18 7"/>',
        'edit'        => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 6.5 17.5 9.5"/>',
        'copy'        => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a1 1 0 0 1 1-1h9"/>',
        'refresh'     => '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v5h-5"/>',
        'check'       => '<path d="m5 13 4 4 10-10"/>',
        'close'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'eye'         => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/>',
        'eye-off'     => '<path d="M4 4l16 16"/><path d="M9.9 5.3A9.7 9.7 0 0 1 12 5c6.4 0 10 6 10 6a17 17 0 0 1-3.3 3.9"/><path d="M6.3 7.8A16.7 16.7 0 0 0 2 11s3.6 6 10 6a9.9 9.9 0 0 0 3.5-.6"/>',
        // direction
        'chevron-end'   => '<path d="m9 5 7 7-7 7"/>',
        'chevron-start' => '<path d="m15 5-7 7 7 7"/>',
        'chevron-down'  => '<path d="m5 9 7 7 7-7"/>',
        'chevron-up'    => '<path d="m5 15 7-7 7 7"/>',
        'arrow-end'     => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
        'external'      => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        // status & meta
        'alert'       => '<path d="M12 4 2.6 20h18.8z"/><path d="M12 10v4"/><path d="M12 17.2v.1"/>',
        'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8v.1"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/>',
        'image'       => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m4 18 5-4.5 4 3.2 3-2.4 4 3.7"/>',
        'grip'        => '<path d="M5 8h14M5 12h14M5 16h14"/>',
        'sparkles'    => '<path d="M12 4.5 13.6 9l4.4 1.6L13.6 12l-1.6 4.5L10.4 12 6 10.6 10.4 9z"/><path d="M18 4v3M19.5 5.5h-3"/>',
        'trend-up'    => '<path d="m4 15 5-5 3.5 3.5L20 7"/><path d="M15 7h5v5"/>',
        'trend-down'  => '<path d="m4 9 5 5 3.5-3.5L20 17"/><path d="M15 17h5v-5"/>',
    ];

    $path = $paths[$name] ?? $paths['info'];
    $classes = 'k-icon' . ($directional ? ' k-icon--directional' : '');
@endphp
<svg {{ $attributes->merge(['class' => $classes]) }}
     width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.7"
     stroke-linecap="round" stroke-linejoin="round"
     @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>
    {!! $path !!}
</svg>
