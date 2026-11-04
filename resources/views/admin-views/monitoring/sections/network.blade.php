{{--
    Network: what each interface is moving, what TCP makes of it, and whether names still resolve.

    Throughput belongs to an interface, never to a host, so every NIC gets its own card and nothing
    on this page is summed into one figure that would be wrong on all of them.

    Byte rates arrive from the panel as raw bytes per second and are scaled here. That split is
    deliberate: KB/s and MB/s are a rendering decision, and a pre-formatted string in the payload
    would be a number the JSON refresh could never re-scale and no threshold could ever compare.

    Errors and dropped frames stay counts rather than rates. One dropped frame a minute is a fact
    an operator can act on; 0.016 errors/s is the same fact made unreadable.
--}}

@php
    $count = static fn ($value) => $value === null
        ? '—'
        : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2);

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    // The divisor and the unit a byte rate reads best in. Binary multiples, which is what the rest
    // of the dashboard already uses for bytes.
    $rateScale = static fn ($bytesPerSecond) => match (true) {
        (float) $bytesPerSecond >= 1073741824 => [1073741824, 'GB/s'],
        (float) $bytesPerSecond >= 1048576 => [1048576, 'MB/s'],
        (float) $bytesPerSecond >= 1024 => [1024, 'KB/s'],
        default => [1, 'B/s'],
    };

    // Metric::map() carries the state, the provenance and the note across, so a rate that could not
    // be read stays unreadable here rather than becoming a confident 0 KB/s.
    $humanRate = static function ($metric) use ($rateScale) {
        if (!$metric instanceof \App\Services\Monitoring\Metric || !$metric->isOk() || !is_numeric($metric->value)) {
            return $metric;
        }

        [$divisor, $unit] = $rateScale($metric->value);

        return $metric->map(static fn ($value) => round((float) $value / $divisor, $divisor === 1 ? 1 : 2), $unit);
    };

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $chart) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $chart['points'],
        ),
    ];

    // A throughput line, scaled by its own peak so the axis reads in a unit a person can hold in
    // their head. The scale is arithmetic on the stored samples, not a substitute for them.
    $asRateChart = static function (array $chart) use ($rateScale) {
        $peak = 0.0;
        foreach ($chart['points'] as $point) {
            $peak = max($peak, (float) $point['v']);
        }

        [$divisor, $unit] = $rateScale($peak);

        return [
            'unit' => $unit,
            'payload' => [
                'points' => array_map(
                    static fn (array $point) => ['t' => $point['t'], 'hits' => round((float) $point['v'] / $divisor, 2)],
                    $chart['points'],
                ),
            ],
        ];
    };

    // A link the kernel calls down is the one state on this page that turns a calm card into a
    // finding. "unknown" is not a fault: virtual drivers carry traffic without carrier detection.
    $linkTone = static fn (string $state) => match ($state) {
        'up' => 'mon-pill--ok',
        'down', 'lowerlayerdown' => 'mon-pill--critical',
        default => 'mon-pill--info',
    };

    $rateLabels = ['rx_bytes_per_s' => 'received', 'tx_bytes_per_s' => 'transmitted'];

    $interfaces = $panel['interfaces'];
    $dns = $panel['dns'];
@endphp

{{-- Said once, at the top. A collector that could not answer produces an unavailable row on every
     card underneath, and a dozen copies of one fault reads as a dozen faults. --}}
@if (!empty($panel['collectors']))
    <div class="mon-attention">
        @foreach ($panel['collectors'] as $fault)
            <div class="mon-attention__item mon-attention__item--{{ $fault['state'] === 'failed' ? 'critical' : 'info' }}">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate($fault['collector']) }} — {{ translate('this_collector_could_not_answer') }}</strong>
                    <small>{{ $fault['note'] }}</small>
                </span>
            </div>
        @endforeach
    </div>
@endif

{{-- The stored series feed every line and every "latest" below. When the read itself fails, saying
     so once is the difference between "no history" and "the history could not be read". --}}
@if ($panel['series']['state'] !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_stored_history_for_this_section_could_not_be_read') }}</strong>
                <small>{{ $panel['series']['note'] }}</small>
            </span>
        </div>
    </div>
@endif

{{-- ── Interfaces ────────────────────────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('interfaces') }}</h3>

@forelse ($interfaces['cards'] as $interface)
    <x-k.card :title="$interface['label']">
        @if ($interface['link'] && $interface['link']->isOk())
            <x-slot:actions>
                <span class="mon-pill {{ $linkTone((string) $interface['link']->value) }}">
                    {{ translate((string) $interface['link']->value) }}
                </span>
            </x-slot:actions>
        @endif

        {{-- Throughput leads the card. Everything under it explains this pair or contradicts it. --}}
        <div class="mon-grid">
            @foreach ($interface['rates'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', [
                    'metric' => $humanRate($metric),
                    'label' => translate($rateLabels[$name] ?? $name),
                    'hint' => translate('measured_as_the_difference_between_two_readings_of_the_kernel_counters_rather_than_a_total_since_boot'),
                ])
            @endforeach
        </div>

        <div class="mon-grid">
            @foreach ($interface['metrics'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach

            {{-- The link state is a pill in the card header while it can be read. When it cannot,
                 it belongs here with its reason, because a card with no state at all reads as a
                 link nobody checked. --}}
            @if ($interface['link'] && !$interface['link']->isOk())
                @include('admin-views.monitoring.partials._metric', [
                    'metric' => $interface['link'],
                    'label' => translate('link_state'),
                ])
            @endif
        </div>

        <p class="mon-note">
            {{ translate('errors_are_frames_the_hardware_could_not_send_or_receive_at_all') }}.
            {{ translate('dropped_frames_are_usually_a_full_socket_queue_rather_than_a_bad_cable_which_is_why_they_climb_on_a_busy_host_while_errors_stay_at_zero') }}.
        </p>

        {{-- The cards above are one instant. A backup saturating the uplink every night is only
             ever visible as a line. --}}
        @foreach ($interface['charts'] as $chart)
            {{-- A heading rather than another note: the paragraph above it is prose about the
                 counters, and two muted lines running together read as one sentence. --}}
            <h4 class="mon-heading">{{ translate($chart['title']) }}</h4>
            @if ($chart['state'] === 'ok')
                @php($line = $asRateChart($chart))
                <div class="mon-chart" data-mon-chart='@json($line['payload'])'></div>
                @php([$latestDivisor, $latestUnit] = $rateScale($chart['latest']))
                <p class="mon-note">
                    {{ translate('latest') }}: {{ $count($chart['latest'] / $latestDivisor) }} {{ $latestUnit }} —
                    {{ translate('the_line_above_is_drawn_in') }} {{ $line['unit'] }} —
                    {{-- The interface belongs in the series name here. Three cards quoting the
                         same bare `server.network.rx_bytes_per_s` name three different numbers
                         under it, and none of them says which row it was read from. --}}
                    <code>{{ $chart['metric'] . ($chart['label'] === '' ? '' : '@' . $chart['label']) }}</code>
                </p>
            @else
                <x-k.empty icon="trend-up" :title="$stateTitle($chart['state'])" :text="$chart['note'] ?? ''" />
                @if (!empty($chart['remedy']))
                    <details class="mon-metric__remedy">
                        <summary>{{ translate('how_to_enable_this') }}</summary>
                        <code>{{ $chart['remedy'] }}</code>
                    </details>
                @endif
            @endif
        @endforeach
    </x-k.card>
@empty
    <x-k.card :title="translate('interfaces')">
        <x-k.empty icon="external" :title="$stateTitle($interfaces['state'])" :text="$interfaces['note'] ?? ''" />
        @if (!empty($interfaces['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $interfaces['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ $interfaces['source'] }}</p>
    </x-k.card>
@endforelse

@if ($interfaces['total'] > $interfaces['shown'])
    <p class="mon-note">
        {{ translate('this_host_lists_more_interfaces_than_this_page_draws_and_the_rest_are_left_off_rather_than_summed_into_the_ones_above') }}:
        {{ number_format($interfaces['shown']) }} / {{ number_format($interfaces['total']) }}.
    </p>
@endif

@if ($interfaces['state'] === 'ok')
    <p class="mon-note">
        {{ translate('the_loopback_is_deliberately_excluded_from_every_figure_above') }}.
        {{ translate('every_local_database_and_cache_round_trip_crosses_it_so_counting_it_would_paint_traffic_onto_a_host_whose_real_interface_is_idle') }}.
    </p>
@endif

{{-- ── TCP ───────────────────────────────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('tcp') }}</h3>

@foreach ($panel['tcp']['groups'] as $group)
    <x-k.card :title="translate($group['key'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>

        @if ($group['key'] === 'connections')
            <p class="mon-note">
                {{ translate('a_wall_of_time_wait_sockets_holds_an_ephemeral_port_each_for_a_minute_at_a_time') }}.
                {{ translate('the_host_starts_refusing_outbound_connections_while_every_other_number_on_this_page_still_looks_idle') }}.
            </p>
        @endif

        @if ($group['key'] === 'retransmissions')
            <p class="mon-note">
                {{ translate('a_retransmission_rate_means_nothing_without_the_traffic_it_happened_in') }}.
                {{ translate('fifty_segments_a_second_is_a_dying_link_on_a_quiet_server_and_background_noise_on_a_saturated_one_which_is_why_the_share_of_outbound_segments_is_beside_it') }}.
            </p>
        @endif
    </x-k.card>
@endforeach

{{-- The one thing a TCP card is expected to carry that nothing here measures. Leaving it off the
     page would be indistinguishable from measuring it and finding none. --}}
<x-k.card :title="translate('connection_errors')">
    <x-k.empty icon="alert"
               :title="$stateTitle($panel['tcp']['connection_errors']['state'])"
               :text="$panel['tcp']['connection_errors']['note']" />
    <details class="mon-metric__remedy">
        <summary>{{ translate('how_to_enable_this') }}</summary>
        <code>{{ $panel['tcp']['connection_errors']['remedy'] }}</code>
    </details>
    <p class="mon-note">{{ $panel['tcp']['connection_errors']['source'] }}</p>
</x-k.card>

@foreach ($panel['tcp']['charts'] as $chart)
    <x-k.card :title="translate($chart['title'])">
        @if ($chart['state'] === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            <p class="mon-note">
                {{ translate('latest') }}: {{ $count($chart['latest']) }} {{ $chart['unit'] }} —
                <code>{{ $chart['metric'] }}</code>
            </p>
        @else
            <x-k.empty icon="trend-up" :title="$stateTitle($chart['state'])" :text="$chart['note'] ?? ''" />
            @if (!empty($chart['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $chart['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endforeach

{{-- ── DNS ───────────────────────────────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('name_resolution') }}</h3>

<x-k.card :title="translate('dns')">
    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $dns['metric'],
            'label' => translate('dns_ms'),
            'hint' => translate('how_long_this_server_takes_to_resolve_the_hostname_in_app_url'),
        ])
    </div>
    <p class="mon-note">
        {{ translate('a_lookup_that_is_answered_from_etc_hosts_or_a_local_cache_is_not_dns_and_timing_it_would_put_a_reassuring_number_where_no_resolver_was_ever_measured') }}.
        {{ translate('neither_php_lookup_accepts_a_timeout_so_a_resolver_that_stops_answering_is_skipped_on_the_next_collection_rather_than_holding_this_page_behind_it') }}.
    </p>

    @if ($dns['chart']['state'] === 'ok')
        <div class="mon-chart" data-mon-chart='@json($asChart($dns['chart']))'></div>
        <p class="mon-note">
            {{ translate('latest') }}: {{ $count($dns['chart']['latest']) }} {{ $dns['chart']['unit'] }} —
            <code>{{ $dns['chart']['metric'] }}</code>
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($dns['chart']['state'])" :text="$dns['chart']['note'] ?? ''" />
        @if (!empty($dns['chart']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $dns['chart']['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Normally empty. A reading the collector produces and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_collector_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['metric'] }}{{ $reading['label'] === '' ? '' : '@' . $reading['label'] }}</code>
            ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('the_readings_above_are_taken_live_from') }}
    <code>/proc/net/dev</code>, <code>/proc/net/snmp</code>, <code>/proc/net/sockstat</code>,
    <code>/sys/class/net/&lt;interface&gt;/operstate</code>.
    {{ translate('the_charts_are_read_from_the_stored_series_in') }} <code>monitoring_series</code>
    ({{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} {{ $panel['window']['timezone'] }}).
    {{ translate('every_sample_is_stored_in_utc_and_converted_once_for_this_page') }};
    {{ translate('the_time_labels_drawn_on_the_charts_themselves_come_from_the_clock_of_the_browser_you_are_reading_this_in') }}.
    @if ($panel['host'])
        {{ translate('host') }}: {{ $panel['host'] }}.
    @endif
</p>
