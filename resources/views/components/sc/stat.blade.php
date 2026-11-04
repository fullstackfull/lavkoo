@props(['label', 'value', 'note' => null, 'tone' => null])
<div {{ $attributes->merge(['class' => 'sc-stat']) }}>
    <div class="sc-stat__label">{{ $label }}</div>
    <div class="sc-stat__value{{ $tone ? ' sc-stat__value--' . $tone : '' }}">{{ $value }}</div>
    @if ($note)<div class="sc-stat__note">{{ $note }}</div>@endif
</div>
