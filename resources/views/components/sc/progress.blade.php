@props(['value' => 0, 'max' => 100, 'tone' => null, 'label' => null, 'indeterminate' => false])
@php($pct = $max > 0 ? max(0, min(100, ($value / $max) * 100)) : 0)
<div {{ $attributes->merge(['class' => 'sc-progress' . ($indeterminate ? ' sc-progress--indeterminate' : '')]) }}
     role="progressbar" aria-valuenow="{{ $indeterminate ? '' : (int) $pct }}" aria-valuemin="0" aria-valuemax="100">
    <div class="sc-progress__fill{{ $tone ? ' sc-progress__fill--' . $tone : '' }}" style="width:{{ $indeterminate ? 40 : $pct }}%"></div>
</div>
@if ($label)<div class="sc-muted" style="font-size:11px;margin-top:4px">{{ $label }}</div>@endif
