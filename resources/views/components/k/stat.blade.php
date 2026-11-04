@props([
    'label',
    'value',
    'icon' => null,
    'delta' => null,          // signed percentage, e.g. 18.2 or -4.1
    'caption' => null,        // what the delta is measured against
    'href' => null,
])
@php
    // No baseline means no arrow: an unexplained "0%" reads as a real result.
    $direction = $delta === null ? null : ((float) $delta > 0 ? 'up' : ((float) $delta < 0 ? 'down' : 'flat'));
    $deltaIcon = ['up' => 'trend-up', 'down' => 'trend-down', 'flat' => 'arrow-end'][$direction] ?? null;
@endphp
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'k-stat' . ($href ? ' k-stat--link' : '')]) }}>
    <div class="k-stat__head">
        <span>{{ $label }}</span>
        @if ($icon)<span class="k-stat__icon"><x-k.icon :name="$icon" :size="15" /></span>@endif
    </div>
    <div class="k-stat__value k-num">{{ $value }}</div>
    @if ($direction || $caption)
        <div class="k-stat__foot">
            @if ($direction)
                <span class="k-stat__delta k-stat__delta--{{ $direction }}">
                    <x-k.icon :name="$deltaIcon" :size="13" />
                    <span class="k-num">{{ ($delta > 0 ? '+' : '') . $delta }}%</span>
                </span>
            @endif
            @if ($caption)<span class="k-stat__caption">{{ $caption }}</span>@endif
        </div>
    @endif
</{{ $href ? 'a' : 'div' }}>
