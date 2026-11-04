{{--
    Live traffic: who is on the shop in the last few minutes, and what they are hitting.

    The heading says "live" and the arithmetic cannot: every figure here is an average over a
    window of minutes. So the window is stated at the top, in the operator's own timezone, and each
    card names the recorder it came from — the inline per-request log is current to the second, the
    folded buckets are a minute or more behind, and during an incident that difference is the whole
    story rather than a footnote.
--}}

@php
    $window = $panel['window'] ?? [];
    $collection = $panel['collection'] ?? [];
    $rate = $panel['rate'] ?? [];
    $traffic = $panel['traffic'] ?? [];
    $visitors = $panel['visitors'] ?? [];
    $statuses = $panel['statuses'] ?? [];
    $routes = $panel['routes'] ?? [];

    $measured = static fn (array $part): bool => ($part['state'] ?? null) === 'ok';

    // A reading that was not taken renders as its state, never as a number. The dash below is only
    // ever reached by a value the panel deliberately left null.
    $ms = static fn ($value) => $value === null ? '—' : number_format((float) $value, 1) . ' ms';

    $stateTitle = static fn (?string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('this_is_not_being_recorded'),
        'stale' => translate('collection_has_fallen_behind'),
        default => translate('nothing_was_recorded_in_this_window'),
    };

    // The neutral ramp for splits that carry no verdict. Status classes take the state palette
    // instead, which is why they are not run through this.
    $tone = ['a', 'b', 'c', 'd', 'e'];
@endphp

{{-- Said before any number: on a five-minute window a stopped flush and a quiet shop produce the
     same empty payload, and only one of them is an incident. --}}
@if (($collection['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ ($collection['state'] ?? '') === 'stale' ? 'warning' : 'critical' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ ($collection['state'] ?? '') === 'stale'
                        ? translate('the_folded_buckets_are_behind_the_live_window')
                        : translate('requests_are_not_being_folded_into_buckets') }}
                </strong>
                <small>{{ $collection['note'] }}</small>
                @if (!empty($collection['remedy']))
                    <code>{{ $collection['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The live row. Each card carries the recorder it came from, because two of these numbers count
     the same requests and can legitimately disagree. --}}
<div class="k-stats mon-stats">
    <x-k.stat :label="translate('requests_per_second')"
              :value="$measured($traffic) ? $traffic['per_second'] : translate('no_data')"
              icon="trend-up" :caption="translate('per_request_log')" />
    <x-k.stat :label="translate('requests_per_minute')"
              :value="$measured($traffic) ? $traffic['per_minute'] : translate('no_data')"
              icon="trend-up" :caption="translate('per_request_log')" />
    <x-k.stat :label="translate('active_visitors')"
              :value="$measured($visitors) ? number_format($visitors['active']) : translate('no_data')"
              icon="customers" :caption="translate('web_sessions_only')" />
    <x-k.stat :label="translate('signed_in')"
              :value="$measured($visitors) ? number_format($visitors['authenticated']) : translate('no_data')"
              icon="customers" :caption="translate('sessions')" />
    <x-k.stat :label="translate('guests')"
              :value="$measured($visitors) ? number_format($visitors['guests']) : translate('no_data')"
              icon="customers" :caption="translate('sessions')" />
    <x-k.stat :label="translate('error_rate')"
              :value="$measured($traffic) ? $traffic['error_rate'] . '%' : translate('no_data')"
              icon="alert" :caption="translate('per_request_log')" />
    <x-k.stat :label="translate('response_time') . ' p95'"
              :value="$measured($rate) ? $ms($rate['p95']) : translate('no_data')"
              icon="clock" :caption="translate('folded_buckets')" />
</div>

{{-- The window, stated once and plainly. Without it every figure above reads as "right now". --}}
<p class="mon-note">
    {{ $window['note'] ?? '' }}
    {{ translate('window') }}: {{ $window['since'] ?? '' }} → {{ $window['until'] ?? '' }} ({{ $window['timezone'] ?? '' }})
</p>

@if (!$measured($traffic) || !$measured($rate))
    {{-- Why a card above says "no data" rather than zero. No traffic and no measurement are
         different facts, and only one of them has anything an operator can do about it. --}}
    <div class="mon-note {{ (($traffic['state'] ?? '') === 'failed' || ($rate['state'] ?? '') === 'failed') ? 'mon-note--critical' : '' }}">
        @foreach ([$traffic, $rate] as $part)
            @if (!$measured($part) && !empty($part['note']))
                <span class="k-truncate" style="display:block">{{ $part['source'] ?? '' }}: {{ $part['note'] }}</span>
                @if (!empty($part['remedy']))
                    <details class="mon-metric__remedy">
                        <summary>{{ translate('how_to_enable_this') }}</summary>
                        <code>{{ $part['remedy'] }}</code>
                    </details>
                @endif
            @endif
        @endforeach
    </div>
@endif

<x-k.card :title="translate('requests_per_minute')">
    @if (!empty($panel['timeline']['points']))
        {{-- Inline SVG rather than a charting library: the series is already aggregated
             server-side, so there is nothing for a library to do that costs less than loading it.
             The shape is the point — a five-minute average hides the minute the spike was in. --}}
        <div class="mon-chart"
             data-mon-chart='@json(['points' => $panel['timeline']['points'], 'resolution' => $panel['timeline']['resolution'] ?? null])'></div>
        <p class="mon-note">
            {{ translate('one_point_per_minute_from') }} <code>monitoring_request_buckets</code> —
            {{ translate('the_newest_minute_is_usually_still_in_the_buffer') }}
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($panel['timeline']['state'] ?? null)"
                   :text="$panel['timeline']['note'] ?? ''" />
        @if (!empty($panel['timeline']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $panel['timeline']['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- What the shop is answering most in this window. --}}
<x-k.card :title="translate('busiest_routes_in_this_window')">
    @if ($measured($routes))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('route') }}</th>
                    <th>{{ translate('method') }}</th>
                    <th>{{ translate('channel') }}</th>
                    <th class="k-table__num">{{ translate('count') }}</th>
                    <th class="k-table__num">{{ translate('per_minute') }}</th>
                    <th class="k-table__num">{{ translate('avg') }}</th>
                    <th class="k-table__num">p95</th>
                    <th class="k-table__num">{{ translate('errors') }}</th>
                    <th class="k-table__num">{{ translate('error_rate') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($routes['rows'] as $row)
                    <tr>
                        <td>
                            <span class="k-truncate" style="display:block;max-inline-size:280px"
                                  title="{{ $row['channel'] }} {{ $row['method'] }} {{ $row['route'] }}">{{ $row['route'] }}</span>
                        </td>
                        <td>{{ $row['method'] }}</td>
                        <td>{{ translate($row['channel']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($row['hits']) }}</td>
                        <td class="k-table__num k-num">{{ $row['per_minute'] }}</td>
                        <td class="k-table__num k-num">{{ $ms($row['avg']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($row['p95']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($row['errors']) }}</td>
                        <td class="k-table__num k-num">
                            @if (($row['severity'] ?? 'ok') === 'ok')
                                {{ $row['error_rate'] }}%
                            @else
                                <span class="mon-pill mon-pill--{{ $row['severity'] }}">{{ $row['error_rate'] }}%</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($routes['state'] ?? null)" :text="$routes['note'] ?? ''" />
        @if (!empty($routes['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $routes['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- What the shop actually answered with. One bar that adds up to the window, so the proportion is
     read rather than worked out from four numbers. --}}
<x-k.card :title="translate('what_the_shop_answered_with')">
    @if ($measured($statuses))
        <div class="mon-split" role="img" aria-label="{{ translate('share_of_responses_by_status_class') }}">
            @foreach ($statuses['classes'] as $class)
                @if ($class['share_pct'] > 0)
                    <span class="mon-split__part mon-split__part--{{ $class['class'] }}"
                          style="inline-size: {{ $class['share_pct'] }}%"
                          title="{{ $class['class'] }}: {{ number_format($class['hits']) }} ({{ $class['share_pct'] }}%)"></span>
                @endif
            @endforeach
        </div>

        <ul class="mon-split__legend">
            @foreach ($statuses['classes'] as $class)
                <li class="mon-split__key">
                    <span class="mon-split__swatch mon-split__part--{{ $class['class'] }}" aria-hidden="true"></span>
                    <span>{{ $class['class'] }}</span>
                    <span class="k-num">{{ number_format($class['hits']) }}<i>{{ $class['share_pct'] }}%</i></span>
                </li>
            @endforeach
        </ul>

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('count') }}</th>
                    <th class="k-table__num">{{ translate('share') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($statuses['top'] as $status)
                    <tr>
                        <td>
                            {{-- The pill reads the same colour the panel gave its class in the bar above. --}}
                            <span class="mon-pill mon-pill--{{ $status['severity'] }}">{{ $status['status'] }}</span>
                        </td>
                        <td class="k-table__num k-num">{{ number_format($status['hits']) }}</td>
                        <td class="k-table__num k-num">{{ $status['share_pct'] }}%</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('counted_from_the_per_request_log_which_records_every_response_as_it_is_sent_rather_than_a_minute_later') }}:
            <code>telemetry_requests</code>
        </p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($statuses['state'] ?? null)" :text="$statuses['note'] ?? ''" />
        @if (!empty($statuses['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $statuses['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Who the traffic is: the client it declared, and the device the browser session was opened on.
     Neither carries a verdict, so neither is coloured like one. --}}
@foreach ([
    ['key' => 'platforms', 'title' => 'traffic_by_platform', 'unit' => 'requests', 'why' => 'a_request_is_filed_under_the_platform_its_header_declares_or_the_one_its_user_agent_implies'],
    ['key' => 'devices', 'title' => 'traffic_by_device', 'unit' => 'sessions', 'why' => 'classified_from_the_user_agent_at_the_moment_the_session_opened_so_bot_is_a_pattern_match_rather_than_a_verified_identity'],
] as $split)
    @php($part = $panel[$split['key']] ?? [])
    <x-k.card :title="translate($split['title'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($split['why']) }}</p>

        @if ($measured($part))
            <div class="mon-split" role="img" aria-label="{{ translate($split['title']) }}">
                @foreach ($part['rows'] as $row)
                    @if ($row['share_pct'] > 0)
                        <span class="mon-split__part mon-split__part--{{ $tone[$loop->index % count($tone)] }}"
                              style="inline-size: {{ $row['share_pct'] }}%"
                              title="{{ $row['label'] }}: {{ number_format($row['hits']) }} ({{ $row['share_pct'] }}%)"></span>
                    @endif
                @endforeach
            </div>

            <ul class="mon-split__legend">
                @foreach ($part['rows'] as $row)
                    <li class="mon-split__key">
                        <span class="mon-split__swatch mon-split__part--{{ $tone[$loop->index % count($tone)] }}" aria-hidden="true"></span>
                        <span>
                            {{ translate($row['label']) }}
                            @isset($row['avg_ms'])
                                <i>{{ $ms($row['avg_ms']) }}</i>
                            @endisset
                        </span>
                        <span class="k-num">{{ number_format($row['hits']) }}<i>{{ $row['share_pct'] }}%</i></span>
                    </li>
                @endforeach
            </ul>
            <p class="mon-note">
                {{ translate($split['unit']) }}: {{ number_format($part['total']) }} —
                <code>{{ $part['source'] }}</code>
            </p>
        @else
            <x-k.empty icon="reports" :title="$stateTitle($part['state'] ?? null)" :text="$part['note'] ?? ''" />
            @if (!empty($part['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $part['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endforeach

{{-- Who is on the site, broken down by whether the shop knows their name. --}}
<x-k.card :title="translate('visitors_in_this_window')">
    @if ($measured($visitors) && $visitors['sessions'] > 0)
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('visitor_type') }}</th>
                    <th class="k-table__num">{{ translate('sessions') }}</th>
                    <th class="k-table__num">{{ translate('visitors') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($visitors['by_type'] as $type)
                    <tr>
                        <td>{{ translate($type['type']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($type['sessions']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($type['visitors']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('a_session_advances_only_on_a_real_page_view_on_the_storefront_so_app_and_api_users_are_not_counted_here') }}
            — {{ translate('last_activity') }}: {{ $visitors['last_activity_at'] ?? '' }} ({{ $window['timezone'] ?? '' }})
        </p>
    @else
        <x-k.empty icon="customers" :title="$stateTitle($visitors['state'] ?? null)"
                   :text="$visitors['note'] ?? translate('nobody_loaded_a_page_in_this_window')" />
        @if (!empty($visitors['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $visitors['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The two things this deployment cannot tell you about a visitor. Rendered as readings rather
     than left off the page: a missing row reads as "nobody is abroad", which is a claim, and the
     honest answer is that nothing was ever recorded to answer it with. --}}
<x-k.card :title="translate('what_this_section_cannot_tell_you')">
    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $panel['country'],
            'label' => translate('visitor_country'),
            'hint' => translate('where_this_number_came_from'),
        ])
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $panel['browsers'],
            'label' => translate('browser'),
            'hint' => translate('where_this_number_came_from'),
        ])
    </div>
</x-k.card>

<p class="mon-note">
    {{ translate('this_page_reads_two_recorders_of_the_same_traffic') }}.
    <code>telemetry_requests</code> — {{ translate('written_as_each_response_is_sent') }}.
    <code>monitoring_request_buckets</code> — {{ translate('folded_per_minute_by_the_scheduled_flush') }}.
    {{ translate('the_two_totals_can_differ_and_the_gap_between_them_is_itself_a_reading') }}
</p>
