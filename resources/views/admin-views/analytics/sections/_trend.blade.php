{{-- The day-by-day series. Drawn as an inline SVG rather than with a charting library: this is a
     handful of points, and adding a JS dependency to a legacy Bootstrap 4 panel to draw a
     polyline is a poor trade. Every day in the window is present even when it had no traffic, so
     a quiet day reads as a dip rather than being skipped and drawn through. --}}
@php
    $points = collect($trend);
    // An empty series is not automatically a quiet week: the tables can be absent and the rollup
    // can never have run, and those say something different to a merchant.
    $emptyReason = $trendState ?? 'no_traffic';
    $maxSessions = max(1, $points->max('sessions'));
    $maxRevenue = max(0.01, $points->max('revenue'));
    $width = 1000;
    $height = 180;
    $step = $points->count() > 1 ? $width / ($points->count() - 1) : $width;
    $line = $points->values()->map(fn ($p, $i) => round($i * $step, 1) . ',' . round($height - ($p['sessions'] / $maxSessions) * ($height - 12), 1))->implode(' ');
    $revenueLine = $points->values()->map(fn ($p, $i) => round($i * $step, 1) . ',' . round($height - ($p['revenue'] / $maxRevenue) * ($height - 12), 1))->implode(' ');
    $hasRevenue = $points->sum('revenue') > 0;
@endphp

<x-k.card :title="translate('over_time')">
    @if ($points->sum('sessions') === 0 && !$hasRevenue)
        @include('admin-views.analytics.sections._empty', ['state' => $emptyReason])
    @else
        <div class="ana-chart">
            <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img"
                 aria-label="{{ translate('visits_per_day') }}">
                <polyline class="ana-chart__line" points="{{ $line }}" />
                @if ($hasRevenue)
                    <polyline class="ana-chart__line ana-chart__line--revenue" points="{{ $revenueLine }}" />
                @endif
            </svg>
            <div class="ana-chart__axis">
                <span>{{ $points->first()['date'] ?? '' }}</span>
                <span>{{ $points->last()['date'] ?? '' }}</span>
            </div>
            <div class="ana-chart__legend">
                <span class="ana-chart__key"><i></i>{{ translate('visits') }} ({{ translate('peak') }} {{ number_format($maxSessions) }})</span>
                @if ($hasRevenue)
                    <span class="ana-chart__key ana-chart__key--revenue"><i></i>{{ translate('revenue') }} ({{ translate('peak') }} {{ number_format($maxRevenue, 2) }})</span>
                @endif
            </div>
        </div>

        <details class="ana-details">
            <summary>{{ translate('show_the_numbers') }}</summary>
            <table class="ana-table">
                <thead><tr>
                    <th>{{ translate('date') }}</th><th class="ana-num">{{ translate('visits') }}</th>
                    <th class="ana-num">{{ translate('visitors') }}</th><th class="ana-num">{{ translate('pageviews') }}</th>
                    <th class="ana-num">{{ translate('orders') }}</th><th class="ana-num">{{ translate('revenue') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($points as $point)
                    <tr>
                        <td>{{ $point['date'] }}</td>
                        <td class="ana-num">{{ number_format($point['sessions']) }}</td>
                        <td class="ana-num">{{ number_format($point['visitors']) }}</td>
                        <td class="ana-num">{{ number_format($point['pageviews']) }}</td>
                        <td class="ana-num">{{ number_format($point['orders']) }}</td>
                        <td class="ana-num">{{ $point['revenue'] > 0 ? number_format($point['revenue'], 2) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </details>
    @endif
</x-k.card>
