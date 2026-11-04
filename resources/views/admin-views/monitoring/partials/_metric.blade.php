{{--
    One reading, rendered honestly.

    This partial is the UI half of the Metric value object, and it exists so that "we could not
    measure this" can never be drawn as a number. A metric that is not OK renders its state, its
    reason and — where there is one — the exact thing an operator must do to make it real. It never
    falls back to 0, and never to a dash that could mean either.

    Expects: $metric (App\Services\Monitoring\Metric), $label, and optionally $hint.
--}}
@php
    $monState = $metric?->state ?? 'no_data';
    $monTone = match ($monState) {
        'ok' => '',
        'not_supported' => 'mon-metric--muted',
        'permission_denied', 'collector_offline', 'failed' => 'mon-metric--warning',
        default => 'mon-metric--muted',
    };
    $monStateLabel = match ($monState) {
        'no_data' => translate('no_data'),
        'not_configured' => translate('not_configured'),
        'not_supported' => translate('not_supported'),
        'permission_denied' => translate('permission_denied'),
        'collector_offline' => translate('collector_offline'),
        'failed' => translate('collector_failed'),
        default => '',
    };
@endphp

<div class="mon-metric {{ $monTone }}">
    <span class="mon-metric__label">
        {{ $label }}
        @isset($hint)
            <span class="mon-metric__hint" title="{{ $hint }}">?</span>
        @endisset
    </span>

    @if ($metric && $metric->isOk())
        <span class="mon-metric__value k-num">
            @if (is_bool($metric->value))
                {{ $metric->value ? translate('yes') : translate('no') }}
            @elseif (is_numeric($metric->value))
                {{ is_float($metric->value) ? rtrim(rtrim(number_format($metric->value, 2, '.', ','), '0'), '.') : number_format($metric->value) }}
            @else
                {{ \Illuminate\Support\Str::limit((string) $metric->value, 48) }}
            @endif
            @if ($metric->unit)<i>{{ $metric->unit }}</i>@endif
        </span>
        @if ($metric->note)
            <span class="mon-metric__note">{{ $metric->note }}</span>
        @endif
    @else
        <span class="mon-metric__state">{{ $monStateLabel }}</span>
        @if ($metric?->note)
            <span class="mon-metric__note">{{ $metric->note }}</span>
        @endif
        @if ($metric?->remedy)
            {{-- The remedy is the difference between a dead row and a task somebody can do. --}}
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $metric->remedy }}</code>
            </details>
        @endif
    @endif

    @if ($metric?->source)
        <span class="mon-metric__source" title="{{ translate('where_this_number_came_from') }}">{{ $metric->source }}</span>
    @endif
</div>
