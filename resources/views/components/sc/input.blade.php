@props(['type' => 'text', 'size' => 'md', 'num' => false, 'suffix' => null, 'invalid' => false])
@php($classes = 'sc-input' . ($size === 'lg' ? ' sc-input--lg' : '') . ($num ? ' sc-input--num' : '') . ($invalid ? ' is-invalid' : ''))
@if ($suffix)
    <span class="sc-input-group">
        <span class="sc-input-group__suffix">{{ $suffix }}</span>
        <input type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
    </span>
@else
    <input type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
@endif
