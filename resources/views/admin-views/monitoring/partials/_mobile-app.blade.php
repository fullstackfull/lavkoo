{{--
    Android and iOS, drawn from one partial.

    The two sections are the same page over a different platform. Two copies of this file would
    drift the first time one of them was fixed, and the drift would be silent — nobody opens both
    sections side by side.

    The page is arranged around a distinction that decides how every number on it should be read:
    the top half is MEASURED by the shop, because the shop is the other end of every request the
    app makes; the stability card is REPORTED by the app, because a crash sends nothing and absence
    of traffic is not evidence of anything. The version table lays the two against each other,
    which is the whole point — "4.1.9 is crashing and 4.2.1 is not" is the sentence worth having.

    Expects: $panel, $range.
--}}
@php
    $traffic = $panel['traffic'];
    $stability = $panel['stability'];
    $versions = $panel['versions'];
    $timeline = $panel['timeline'];
    $reporting = $panel['reporting'];
    $window = $panel['window'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        default => translate('no_data'),
    };

    $number = static fn ($value, int $decimals = 0) => $value === null ? '—' : number_format((float) $value, $decimals);

    // A crash-free share is only reassuring above the line; below it the row is the finding.
    // Two percent of sessions crashing is one shopper in fifty losing their basket.
    $crashTone = static fn (?float $percent) => match (true) {
        $percent === null => 'minor',
        $percent >= 99.5 => 'ok',
        $percent >= 98 => 'warning',
        default => 'critical',
    };
@endphp

{{-- Said before any number: which half of this page came from where. Without it a reader takes the
     crash-free figure for a measurement and the traffic figure for a claim, which is backwards. --}}
<div class="mon-attention">
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('two_sources_one_page') }}</strong>
            <small>{{ translate('traffic_latency_and_error_rates_are_measured_by_this_shop_on_every_request_the_app_makes_and_need_nothing_from_the_app') }}</small>
            <small>{{ translate('sessions_and_crashes_are_reported_by_the_app_itself_because_a_crash_sends_no_request_and_cannot_be_observed_from_here') }}</small>
        </span>
    </div>
</div>

<x-k.card :title="translate('what_this_app_sent')">
    @if ($traffic['state'] === 'ok')
        <div class="mon-grid">
            @foreach ($traffic['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="customers" :title="$stateTitle($traffic['state'])" :text="$traffic['note'] ?? ''" />
        @if (!empty($traffic['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('why_might_this_be_empty') }}</summary>
                <code>{{ $traffic['remedy'] }}</code>
            </details>
        @endif
    @endif
    <p class="mon-note">{{ translate('source') }}: <code>{{ $traffic['source'] }}</code></p>
</x-k.card>

@if ($timeline['state'] === 'ok')
    <x-k.card :title="translate('requests_from_this_app_over_time')">
        <div class="mon-chart" data-mon-chart='@json(['points' => $timeline['points'], 'resolution' => $timeline['resolution']])'></div>
        <p class="mon-note">
            {{ translate('one_point_per') }} {{ translate($timeline['resolution']) }}.
            {{ translate('a_gap_is_a_period_with_no_request_from_this_app_not_a_period_that_was_not_recorded') }}
        </p>
    </x-k.card>
@endif

{{-- The self-reported half. Kept in its own card with its own provenance line, never folded in
     with the measured figures above. --}}
<x-k.card :title="translate('did_the_app_stay_running')">
    @if ($stability['state'] === 'ok')
        <div class="mon-grid">
            @foreach ($stability['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('these_four_are_counted_by_the_app_and_posted_here_they_are_as_accurate_as_the_app_that_sent_them') }}
        </p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($stability['state'])" :text="$stability['note'] ?? ''" />
        @if (!empty($stability['remedy']))
            <details class="mon-metric__remedy" open>
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $stability['remedy'] }}</code>
            </details>
        @endif
    @endif
    <p class="mon-note">{{ translate('source') }}: <code>{{ $stability['source'] }}</code></p>
</x-k.card>

{{-- The payoff. Traffic and crashes side by side per release: an empty cell is a version that did
     not report that half, drawn as an em dash rather than as a zero that would read as "none". --}}
<x-k.card :title="translate('by_app_version')">
    @if ($versions['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('version') }}</th>
                    <th class="k-table__num">{{ translate('requests') }}</th>
                    <th class="k-table__num">{{ translate('mean_response_time') }}</th>
                    <th class="k-table__num">{{ translate('server_error_rate') }}</th>
                    <th class="k-table__num">{{ translate('sessions') }}</th>
                    <th class="k-table__num">{{ translate('crashes') }}</th>
                    <th class="k-table__num">{{ translate('crash_free_sessions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($versions['rows'] as $row)
                    <tr>
                        <td><code>{{ $row['version'] }}</code></td>
                        <td class="k-table__num k-num">{{ $number($row['requests']) }}</td>
                        <td class="k-table__num k-num">{{ $row['mean_ms'] === null ? '—' : $number($row['mean_ms'], 1) }}</td>
                        <td class="k-table__num k-num">{{ $row['error_rate'] === null ? '—' : $number($row['error_rate'], 2) . '%' }}</td>
                        <td class="k-table__num k-num">{{ $number($row['sessions']) }}</td>
                        <td class="k-table__num k-num">{{ $number($row['crashes']) }}</td>
                        <td class="k-table__num">
                            @if ($row['crash_free'] === null)
                                <span class="mon-metric__note">—</span>
                            @else
                                <span class="mon-pill mon-pill--{{ $crashTone($row['crash_free']) }}">{{ $number($row['crash_free'], 2) }}%</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($versions['truncated'])
            <p class="mon-note">{{ translate('showing_the_busiest') }} {{ $versions['limit'] }}.</p>
        @endif

        @if ($versions['folded_requests'] > 0)
            {{-- Said out loud, because otherwise the rows would not add up to the headline and the
                 reader would be left to wonder which of the two was wrong. --}}
            <p class="mon-note">
                {{ $number($versions['folded_requests']) }}
                {{ translate('requests_carried_a_version_beyond_the_per_minute_label_ceiling_and_were_folded_into_a_remainder_they_are_in_the_totals_above_but_not_in_this_table') }}
            </p>
        @endif

        <p class="mon-note">
            {{ translate('an_empty_cell_is_a_half_this_version_did_not_report_traffic_with_no_sessions_is_an_app_that_has_not_been_updated_to_send_them_sessions_with_no_traffic_is_an_app_that_crashed_before_its_first_call') }}
        </p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($versions['state'])" :text="$versions['note'] ?? ''" />
        @if (!empty($versions['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $versions['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The contract that decides whether any of the above exists, on the page rather than in a
     document somebody would have to go and find. --}}
<x-k.card :title="translate('what_the_app_has_to_send')">
    <p class="mon-note">{{ translate('to_be_counted_as_this_platform') }}: <code>{{ $reporting['platform_header'] }}</code></p>
    <p class="mon-note">{{ translate('to_be_attributed_to_a_release') }}: <code>{{ $reporting['version_header'] }}</code></p>
    <p class="mon-note">{{ translate('recognised_user_agents_when_no_header_is_sent') }}: <code>{{ $reporting['user_agent_fallback'] }}</code></p>
    <p class="mon-note">{{ translate('to_report_stability') }}: <code>{{ $reporting['health_endpoint'] }}</code></p>
    <pre class="mon-pre">{{ $reporting['health_body'] }}</pre>
    <p class="mon-note">
        {{ translate('counters_only_no_stack_traces_device_identifiers_or_user_ids_are_accepted_or_stored_the_endpoint_needs_no_token_and_always_answers_204_so_a_failure_to_report_can_never_affect_the_app') }}
    </p>
    <p class="mon-note">
        {{ translate('measured_by') }}: <code>{{ $reporting['measured_by'] }}</code> ·
        {{ translate('reported_to') }}: <code>{{ $reporting['reported_to'] }}</code>
    </p>
</x-k.card>

<p class="mon-note">
    {{ translate('this_page_covers') }} {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}),
    {{ translate('at_one_point_per') }} {{ translate($window['resolution']) }}.
</p>
