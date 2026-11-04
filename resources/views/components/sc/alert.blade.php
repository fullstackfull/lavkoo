{{-- An alert always names the number, the deadline and one action (handoff 04 §25). --}}
@props(['tone' => 'medium', 'title' => null, 'glyph' => null, 'compact' => false])
@php($glyph = $glyph ?? ['critical' => 'warning-octagon', 'high' => 'clock-countdown', 'medium' => 'info', 'good' => 'check-circle', 'info' => 'info'][$tone] ?? 'info')
<div {{ $attributes->merge(['class' => 'sc-alert sc-alert--' . $tone . ($compact ? ' sc-alert--compact' : '')]) }}>
    <span class="sc-alert__glyph"><x-sc.icon :name="$glyph" :size="16" /></span>
    <div class="sc-alert__body">
        @if ($title)<div class="sc-alert__title">{{ $title }}</div>@endif
        <div class="sc-alert__text">{{ $slot }}</div>
    </div>
    @isset($action)<div style="flex:none">{{ $action }}</div>@endisset
</div>
