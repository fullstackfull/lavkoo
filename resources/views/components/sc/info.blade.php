@props(['label', 'value' => null, 'tone' => null])
<div {{ $attributes->merge(['class' => 'sc-info']) }}>
    <div class="sc-info__label">{{ $label }}</div>
    <div class="sc-info__value"{!! $tone ? ' style="color:var(--st-' . $tone . ')"' : '' !!}>{{ $slot->isEmpty() ? $value : $slot }}</div>
</div>
