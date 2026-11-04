@props([
    'variant' => 'secondary',   // primary | secondary | ghost | danger
    'size' => 'md',             // sm | md | lg
    'icon' => null,
    'href' => null,
    'type' => 'button',
    'loading' => false,
    'block' => false,
])
@php
    $classes = 'sc-btn sc-btn--' . $variant
        . ($size !== 'md' ? ' sc-btn--' . $size : '')
        . ($block ? ' sc-btn--block' : '')
        . ($loading ? ' is-loading' : '');
    $glyph = $size === 'sm' ? 12 : 13;
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)<span class="sc-spinner"></span>@elseif ($icon)<x-sc.icon :name="$icon" :size="$glyph" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)<span class="sc-spinner"></span>@elseif ($icon)<x-sc.icon :name="$icon" :size="$glyph" />@endif
        {{ $slot }}
    </button>
@endif
