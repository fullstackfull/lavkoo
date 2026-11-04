{{-- Delta colour is by meaning, not by sign. A null comparison renders `—` (handoff 04 §28). --}}
@props(['label', 'value', 'unit' => null, 'delta' => null, 'improving' => null, 'suffix' => null])
<div {{ $attributes->merge(['class' => 'sc-kpi']) }}>
    <div class="sc-kpi__label">{{ $label }}</div>
    <div class="sc-kpi__value">{{ $value }}@if ($unit)<span class="sc-kpi__unit">{{ $unit }}</span>@endif</div>
    @if ($delta === null)
        <div class="sc-kpi__delta sc-kpi__delta--none" title="{{ translate('no_comparable_data_in_the_previous_period') }}">—</div>
    @else
        @php($tone = $improving === null ? 'none' : ($improving ? 'good' : 'bad'))
        <div class="sc-kpi__delta sc-kpi__delta--{{ $tone }}">
            <x-sc.icon :name="str_starts_with((string) $delta, '-') || str_starts_with((string) $delta, '−') ? 'arrow-down-right' : 'arrow-up-right'" :size="12" />
            <span class="sc-num">{{ $delta }}{{ $suffix }}</span>
            <span class="sc-muted">{{ translate('vs_prev') }}</span>
        </div>
    @endif
</div>
