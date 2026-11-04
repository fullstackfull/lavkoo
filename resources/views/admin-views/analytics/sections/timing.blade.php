{{-- When this shop is busy, which is when to send the message and when to be staffed. --}}
@php($hours = collect($data['hours']['rows'] ?? [])->keyBy('key'))
@php($peak = max(1, (int) $hours->max('sessions')))

<x-k.card :title="translate('by_hour_of_day')">
    @if ($hours->isEmpty())
        @include('admin-views.analytics.sections._empty', ['state' => $data['hours']['state'] ?? 'no_traffic'])
    @else
        <div class="ana-hours">
            @for ($hour = 0; $hour < 24; $hour++)
                @php($key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT))
                @php($row = $hours->get($key))
                <div class="ana-hours__slot" title="{{ $key }}:00 — {{ number_format($row['sessions'] ?? 0) }} {{ translate('visits') }}">
                    <span class="ana-hours__bar" style="height: {{ max(2, round(100 * ($row['sessions'] ?? 0) / $peak)) }}%"></span>
                    <small>{{ $key }}</small>
                </div>
            @endfor
        </div>
    @endif
</x-k.card>

@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['weekdays'], 'title' => translate('by_day_of_week'), 'label' => translate('weekday'), 'dimension' => 'weekday', 'window' => $window, 'showEngagement' => false])
