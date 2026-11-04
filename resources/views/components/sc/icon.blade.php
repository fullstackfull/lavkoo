@props(['name', 'size' => 14, 'weight' => 1.7])
@php($paths = \App\Services\SellerCenter\Icons::paths($name))
@if ($paths !== '')
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="{{ $weight }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
         {{ $attributes->merge(['class' => 'sc-glyph']) }} style="flex:none;display:block">
        {!! $paths !!}
    </svg>
@endif
