{{-- One headline figure with its comparison. A number with no baseline is not information, so the
     comparison is part of the component rather than an optional extra somebody forgets. --}}
@php($change = $metric['change_pct'] ?? $metric['change_points'] ?? null)
@php($isPoints = array_key_exists('change_points', $metric))
{{-- Some visits could only be told apart by their network address, so the figure is a floor. The
     "≥" is not decoration: printing it as an exact count would be the lie. --}}
@php($approximate = $metric['approximate'] ?? null)
<div class="ana-metric">
    <small>{{ translate($label) }}</small>
    <span class="k-num">
        @if ($approximate && $metric['value'] !== null)<i class="ana-metric__floor">&ge;</i>@endif
        @if ($metric['value'] === null)
            —
        @elseif (($suffix ?? '') === '%')
            {{ $metric['value'] }}<i>%</i>
        @elseif (($format ?? '') === 'money')
            {{ number_format((float) $metric['value'], 2) }}
        @elseif (($format ?? '') === 'duration')
            {{ gmdate((float) $metric['value'] >= 3600 ? 'H:i:s' : 'i:s', (int) $metric['value']) }}
        @elseif (($format ?? '') === 'decimal')
            {{ number_format((float) $metric['value'], 2) }}
        @else
            {{ number_format((float) $metric['value']) }}
        @endif
    </span>
    @if ($change !== null)
        <span class="ana-change ana-change--{{ $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat') }}">
            {{ $change > 0 ? '+' : '' }}{{ $change }}{{ $isPoints ? ' ' . translate('pts') : '%' }}
        </span>
    @else
        {{-- No baseline is a real answer: the previous period had nothing to compare against. --}}
        <span class="ana-change ana-change--none">{{ translate('no_baseline') }}</span>
    @endif

    @if ($approximate)
        <small class="ana-metric__caveat">
            {{ number_format($approximate['sessions']) }}
            {{ translate('app_visits_could_only_be_identified_by_network_so_devices_sharing_one_count_once') }}
            ({{ $approximate['share_pct'] }}%)
        </small>
    @endif
</div>
