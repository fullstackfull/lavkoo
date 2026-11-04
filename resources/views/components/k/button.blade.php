@props([
    'variant' => 'secondary',   // primary | secondary | ghost | danger
    'size' => null,             // sm
    'icon' => null,
    'iconEnd' => null,
    'href' => null,
    'type' => 'button',
    'block' => false,
])
@php
    $classes = collect([
        'k-btn',
        'k-btn--' . $variant,
        $size ? 'k-btn--' . $size : null,
        $block ? 'k-btn--block' : null,
        ($icon && trim($slot) === '') ? 'k-btn--icon' : null,
    ])->filter()->implode(' ');
    $tag = $href ? 'a' : 'button';
@endphp
<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)<x-k.icon :name="$icon" :size="$size === 'sm' ? 14 : 16" />@endif
    {{ $slot }}
    @if ($iconEnd)<x-k.icon :name="$iconEnd" :size="$size === 'sm' ? 14 : 16" />@endif
</{{ $tag }}>
