@props(['icon', 'label', 'size' => 'md', 'href' => null, 'glyph' => null])
@php
    $classes = 'sc-icon-btn' . ($size === 'topbar' ? ' sc-icon-btn--topbar' : ($size === 'mobile' ? ' sc-icon-btn--mobile' : ''));
    $glyphSize = $glyph ?? ($size === 'topbar' ? 16 : 14);
@endphp
@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-sc.icon :name="$icon" :size="$glyphSize" />{{ $slot }}
    </a>
@else
    <button type="button" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->merge(['class' => $classes]) }}>
        <x-sc.icon :name="$icon" :size="$glyphSize" />{{ $slot }}
    </button>
@endif
