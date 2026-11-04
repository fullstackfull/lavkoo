{{--
    Loading placeholder. Respects prefers-reduced-motion, and is aria-hidden so screen readers
    don't announce decorative shimmer.

    Usage: <x-ui.skeleton rows="3" />
--}}
@props(['rows' => 3, 'height' => 14])

<div {{ $attributes->merge(['class' => 'ui-skeleton']) }} aria-hidden="true">
    @for($i = 0; $i < (int) $rows; $i++)
        <div class="ui-skeleton__line" style="height:{{ (int) $height }}px"></div>
    @endfor
</div>

@once
    @push('css_or_js')
        <style>
            .ui-skeleton__line{background:linear-gradient(90deg,rgba(0,0,0,.06),rgba(0,0,0,.12),rgba(0,0,0,.06));
                background-size:200% 100%;border-radius:.25rem;margin-bottom:.5rem;animation:ui-skeleton-shimmer 1.2s infinite}
            @keyframes ui-skeleton-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
            @media (prefers-reduced-motion: reduce){.ui-skeleton__line{animation:none}}
        </style>
    @endpush
@endonce
