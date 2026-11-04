{{-- Zero renders no badge — never a `0` chip (handoff 06 §6). --}}
@props(['value' => null, 'tone' => 'neutral'])
@if ($value !== null && $value !== '' && (int) $value !== 0)
    <span {{ $attributes->merge(['class' => 'sc-count sc-count--' . $tone]) }}>{{ (int) $value > 99 ? '99+' : $value }}</span>
@endif
