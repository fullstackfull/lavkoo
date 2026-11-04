{{--
    Overview: triage, not detail.

    Every card either says "fine" or points at the section holding the evidence, so getting from
    "something is wrong" to "this query on this route" is a sequence of clicks rather than a search.
--}}

{{-- What to look at first. Empty when there is nothing to do, which is the point. --}}
@if (!empty($panel['attention']))
    <div class="mon-attention">
        @foreach ($panel['attention'] as $item)
            <a class="mon-attention__item mon-attention__item--{{ $item['severity'] }}"
               href="{{ route('admin.monitoring.section', ['section' => $item['section'], 'range' => $range]) }}">
                <x-k.icon :name="$item['severity'] === 'info' ? 'settings' : 'alert'" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate($item['title']) }}</strong>
                    {{-- Composed at runtime from counts and collector notes, so it is rendered as-is:
                         putting it through translate() minted a new language key per value. --}}
                    <small>{{ $item['detail'] }}</small>
                    @if (!empty($item['remedy']))
                        <code>{{ \Illuminate\Support\Str::limit($item['remedy'], 150) }}</code>
                    @endif
                </span>
            </a>
        @endforeach
    </div>
@endif

@if (!empty($panel['incidents']))
    <x-k.card :title="translate('open_incidents')">
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('reference') }}</th>
                    <th>{{ translate('title') }}</th>
                    <th>{{ translate('severity') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('started') }}</th>
                    <th>{{ translate('probable_cause') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($panel['incidents'] as $incident)
                    <tr>
                        <td class="k-num">{{ $incident['reference'] }}</td>
                        <td>{{ $incident['title'] }}</td>
                        <td><span class="mon-pill mon-pill--{{ $incident['severity'] }}">{{ translate($incident['severity']) }}</span></td>
                        <td>{{ translate($incident['status']) }}</td>
                        <td class="k-num">{{ $incident['started_at'] }}</td>
                        <td>{{ $incident['probable_cause'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-k.card>
@endif

{{-- Traffic for the selected window, each figure against the same window before it. A number
     without a baseline is not yet information. --}}
@php
    $traffic = $panel['traffic']['current'] ?? [];
    $delta = $panel['traffic']['delta'] ?? [];
    // With no previous window there is nothing to compare against, and a caption saying
    // "vs previous window" beside no arrow reads as "unchanged" — a claim we cannot make.
    $hasBaseline = collect($delta)->contains(fn ($value) => $value !== null);
    $comparisonCaption = $hasBaseline ? translate('vs_previous_window') : null;
@endphp
<div class="k-stats mon-stats">
    <x-k.stat :label="translate('requests')"
              :value="($traffic['has_data'] ?? false) ? number_format($traffic['hits']) : translate('no_data')"
              icon="trend-up" :delta="$delta['hits'] ?? null" :caption="$comparisonCaption" />
    <x-k.stat :label="translate('error_rate')"
              :value="($traffic['has_data'] ?? false) ? $traffic['error_rate'] . '%' : translate('no_data')"
              icon="alert" :delta="$delta['error_rate'] ?? null" :caption="$comparisonCaption" />
    <x-k.stat :label="translate('response_time') . ' p95'"
              :value="($traffic['has_data'] ?? false) ? round($traffic['p95']) . ' ms' : translate('no_data')"
              icon="clock" :delta="$delta['p95'] ?? null" :caption="$comparisonCaption" />
    <x-k.stat :label="translate('response_time') . ' p99'"
              :value="($traffic['has_data'] ?? false) ? round($traffic['p99']) . ' ms' : translate('no_data')"
              icon="clock" :delta="$delta['p99'] ?? null" :caption="$comparisonCaption" />
    <x-k.stat :label="translate('database_time_per_request')"
              :value="($traffic['has_data'] ?? false) ? $traffic['db_ms_avg'] . ' ms' : translate('no_data')"
              icon="reports" :delta="$delta['db_ms_avg'] ?? null" :caption="$comparisonCaption" />
</div>

<x-k.card :title="translate('requests_over_time')">
    @if (!empty($panel['timeline']['points']))
        {{-- Inline SVG rather than a charting library: this page auto-refreshes and the series is
             already aggregated server-side, so there is nothing for a library to do that costs
             less than loading it. --}}
        <div class="mon-chart" data-mon-chart='@json($panel['timeline'])'></div>
    @else
        <x-k.empty icon="trend-up" :title="translate('no_requests_recorded_in_this_window')"
                   :text="translate('traffic_appears_here_within_a_minute_of_the_first_request')" />
    @endif
</x-k.card>

{{-- Service health. Each card carries its own state and links to the section that explains it. --}}
<h3 class="mon-heading">{{ translate('services') }}</h3>
<div class="mon-cards">
    @foreach ($panel['services'] as $service)
        <a class="mon-card mon-card--{{ $service['state'] }}"
           href="{{ route('admin.monitoring.section', ['section' => $service['section'], 'range' => $range]) }}">
            <span class="mon-card__head">
                <span class="mon-card__label">{{ translate($service['label']) }}</span>
                <span class="mon-card__state">{{ translate($service['state']) }}</span>
            </span>

            @if (isset($service['value']))
                <span class="mon-card__value k-num">
                    {{ is_numeric($service['value']) ? number_format($service['value'], is_float($service['value']) ? 1 : 0) : $service['value'] }}
                    <i>{{ $service['unit'] ?? '' }}</i>
                </span>
                <span class="mon-card__metric">{{ $service['metric_label'] ?? '' }}</span>
            @endif

            <span class="mon-card__detail">{{ $service['detail'] ?? '' }}</span>

            @if (!empty($service['last_healthy']))
                <span class="mon-card__foot">{{ translate('last_healthy') }}: {{ $service['last_healthy'] }}</span>
            @endif
            @if (!empty($service['source']))
                <span class="mon-card__source">{{ $service['source'] }}</span>
            @endif
        </a>
    @endforeach
</div>

{{-- The dependency map. Knowing the checkout is failing is half an answer; seeing that the
     application and database are fine and the gateway is red is the half that decides who to call. --}}
<x-k.card :title="translate('service_map')">
    <div class="mon-map">
        @php($tiers = collect($panel['map']['nodes'])->groupBy('tier')->sortKeys())
        @foreach ($tiers as $tier => $nodes)
            <div class="mon-map__tier">
                @foreach ($nodes as $node)
                    <span class="mon-map__node mon-map__node--{{ $node['state'] }}"
                          title="{{ $node['detail'] ?? translate($node['state']) }}">
                        <span class="mon-map__dot" aria-hidden="true"></span>
                        {{ translate($node['label']) }}
                    </span>
                @endforeach
            </div>
            @if (!$loop->last)
                <span class="mon-map__arrow" aria-hidden="true"></span>
            @endif
        @endforeach
    </div>
    <p class="mon-note">{{ translate('grey_means_this_deployment_does_not_use_that_component_not_that_it_is_broken') }}</p>
</x-k.card>

{{-- The signals behind the score, so the number can always be argued with. --}}
<x-k.card :title="translate('what_the_health_score_is_made_of')">
    <div class="k-table-wrap">
        <table class="k-table">
            <thead>
            <tr>
                <th>{{ translate('signal') }}</th>
                <th>{{ translate('reading') }}</th>
                <th class="k-table__num">{{ translate('weight') }}</th>
                <th class="k-table__num">{{ translate('score') }}</th>
                <th>{{ translate('source') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($panel['health']['signals'] as $signal)
                <tr class="{{ $signal['measured'] ? '' : 'mon-row--muted' }}">
                    <td>{{ translate($signal['label']) }}</td>
                    <td>
                        {{ $signal['display'] }}
                        @if (!empty($signal['remedy']))
                            <details class="mon-metric__remedy">
                                <summary>{{ translate('how_to_enable_this') }}</summary>
                                <code>{{ $signal['remedy'] }}</code>
                            </details>
                        @endif
                    </td>
                    <td class="k-table__num k-num">{{ $signal['weight'] }}</td>
                    <td class="k-table__num k-num">
                        @if ($signal['score'] === null)
                            <span class="mon-metric__state">{{ translate('not_measured') }}</span>
                        @else
                            {{ $signal['score'] }}
                        @endif
                    </td>
                    <td class="mon-metric__source">{{ $signal['source'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mon-note">
        {{ translate('a_signal_that_cannot_be_measured_is_excluded_from_the_score_rather_than_counted_as_healthy') }}
    </p>
</x-k.card>

@if (!empty($panel['events']))
    <x-k.card :title="translate('recent_events')">
        <ul class="mon-events">
            @foreach ($panel['events'] as $event)
                <li class="mon-events__item mon-events__item--{{ $event['severity'] }}">
                    <span class="mon-events__time k-num">{{ $event['at'] }}</span>
                    <span class="mon-events__type">{{ $event['type_known'] ? translate($event['type']) : $event['type'] }}</span>
                    <span>{{ $event['title'] }}</span>
                </li>
            @endforeach
        </ul>
    </x-k.card>
@endif
