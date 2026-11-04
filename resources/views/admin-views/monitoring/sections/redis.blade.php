{{--
    Redis and cache: what the server is doing, and the fact that decides whether any of it matters
    — what on this deployment is actually pointed at it.

    Here the cache is on disk, the queue is in MySQL and sessions are files, so a green server on
    this page says nothing about how the shop caches, queues or logs anybody in. The note and the
    table at the top state that before a single chart can imply otherwise, and the keys this server
    does hold are attributed to the one thing that writes them: monitoring's own request buffer.
--}}

@php
    // A measured value below the two decimals this page shows is rendered as "< 0.01" rather than
    // as "0.00". Sub-microsecond percentiles are real readings, and on a page whose whole argument
    // is that zero means zero, rounding one down to it is the same lie as inventing it.
    $count = static function ($value) {
        if ($value === null) {
            return '—';
        }

        $value = (float) $value;

        return match (true) {
            fmod($value, 1) == 0 => number_format($value),
            $value > 0 && $value < 0.01 => '< 0.01',
            $value < 0 && $value > -0.01 => '> -0.01',
            default => number_format($value, 2),
        };
    };

    $microseconds = static fn (int $value) => $value >= 1000
        ? number_format($value / 1000, 1) . ' ms'
        : number_format($value) . ' µs';

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $chart) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $chart['points'],
        ),
    ];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        default => translate('no_data'),
    };

    $usage = $panel['usage'];

    // Only worth saying about a server that answered: "nothing points at Redis" is a useful fact
    // about a running one and a distraction from an unreachable one.
    $nothingUsesIt = ($panel['server']['reachable'] ?? false) && ($usage['serves_shop'] ?? null) === false;

    // The two gauges the eye should be drawn to. The rest are stored just the same and are listed
    // underneath with their latest reading rather than as five more flat lines.
    $lineCharts = ['hit_ratio', 'used_memory_mb'];
@endphp

{{-- Said once, at the top. A collector that could not reach the server produces forty identical
     unavailable rows underneath, and forty copies of one fact reads as forty faults. --}}
@if (($panel['server']['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $panel['server']['state'] === 'failed' ? 'critical' : 'info' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ ($panel['server']['reachable'] ?? false)
                        ? translate('this_server_answered_but_would_not_report_on_itself')
                        : translate('the_redis_collector_could_not_reach_a_server') }}
                </strong>
                <small>{{ $panel['server']['note'] }}</small>
                @if (!empty($panel['server']['remedy']))
                    <code>{{ $panel['server']['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The point of the page. Everything below describes a server this shop does not currently
     depend on, and saying so once, prominently, is what stops a 90% hit ratio being read as a
     fast storefront. --}}
@if ($nothingUsesIt)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('redis_is_running_but_no_part_of_the_shop_is_pointed_at_it') }}</strong>
                <small>{{ $usage['note'] }}</small>
                @if ($usage['serves_monitoring'])
                    <small>{{ translate('the_keys_and_lookups_on_this_page_belong_to_the_monitoring_buffer_rather_than_to_shop_traffic') }}.</small>
                @endif
                @if (!empty($usage['env_lines']))
                    <small>{{ translate('these_lines_would_change_that_in') }} <code>.env</code></small>
                    @foreach ($usage['env_lines'] as $line)
                        <code>{{ $line }}</code>
                    @endforeach
                    <code>{{ $usage['apply_command'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The drivers as they are configured right now, read from the live config rather than from a
     picture of what a deployment usually looks like. --}}
<x-k.card :title="translate('what_uses_redis_on_this_deployment')">
    {{-- Beside the table, never instead of it. Monitoring's own row is always present, so the
         table can never be empty — and a table listing only the monitoring buffer, with nothing
         saying the shop's three drivers could not be read, reads as a shop that has no cache, no
         queue and no session store at all. --}}
    @if (($usage['state'] ?? 'ok') !== 'ok')
        <p class="mon-note mon-note--critical">
            {{ translate('the_cache_queue_and_session_drivers_could_not_be_read') }}: {{ $usage['reason'] }}
        </p>
    @endif

    @if (!empty($usage['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('subsystem') }}</th>
                    <th>{{ translate('setting') }}</th>
                    <th>{{ translate('configured') }}</th>
                    <th>{{ translate('driver') }}</th>
                    <th>{{ translate('served_by_redis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($usage['rows'] as $row)
                    <tr>
                        <td>{{ translate($row['subsystem']) }}</td>
                        <td><code>{{ $row['setting'] }}</code></td>
                        <td>{{ $row['configured'] }}</td>
                        <td>
                            {{ $row['driver'] }}
                            @if (!empty($row['note']))
                                <small class="mon-metric__note" style="display:block">{{ $row['note'] }}</small>
                            @endif
                        </td>
                        <td>
                            <span class="mon-pill mon-pill--{{ $row['uses_redis'] ? 'ok' : 'info' }}">
                                {{ $row['uses_redis'] ? translate('yes') : translate('no') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($usage['state'] ?? 'no_data')" :text="$usage['reason'] ?? ''" />
    @endif

    @if ($nothingUsesIt && !empty($usage['caveats']))
        <p class="mon-note">{{ translate('before_moving_any_of_them_onto_this_server') }}:</p>
        <ul class="mon-note">
            @foreach ($usage['caveats'] as $caveat)
                <li>{{ $caveat }}</li>
            @endforeach
        </ul>
    @endif

    @if (!empty($usage['source']))
        <p class="mon-note">{{ $usage['source'] }}</p>
    @endif
</x-k.card>

{{-- Hit ratio and memory over time. The lifetime hit ratio in the cards below barely moves on a
     server that has been up for weeks; this line is the one that shows an afternoon of misses. --}}
@foreach ($lineCharts as $chartKey)
    @php($chart = $panel['charts'][$chartKey] ?? null)
    @continue($chart === null)
    <x-k.card :title="translate($chart['title'])">
        @if ($chart['state'] === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            <p class="mon-note">
                {{ translate('latest') }}: {{ $count($chart['latest']) }} {{ $chart['unit'] }} —
                <code>{{ $chart['metric'] }}</code>, {{ translate('window') }}:
                {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} ({{ $panel['window']['timezone'] }})
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

{{-- The server's own readings, grouped the way somebody diagnoses a cache server. Each renders its
     own state, so a figure this server would not report can never be drawn as a zero. --}}
@foreach ($panel['groups'] as $group)
    <x-k.card :title="translate($group['key'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    </x-k.card>
@endforeach

{{-- The rest of the stored gauges. Sampled every minute like the two charted above, listed here
     with their latest reading so that a gauge nobody drew a line for is still visibly being kept. --}}
<x-k.card :title="translate('other_stored_gauges')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('gauge') }}</th>
                <th>{{ translate('series') }}</th>
                <th class="k-table__num">{{ translate('latest') }}</th>
                <th class="k-table__num">{{ translate('stored_points_in_window') }}</th>
                <th>{{ translate('state') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($panel['charts'] as $chartKey => $chart)
                @continue(in_array($chartKey, $lineCharts, true))
                <tr>
                    <td>{{ translate($chartKey) }}</td>
                    <td><code>{{ $chart['metric'] }}</code></td>
                    <td class="k-table__num k-num">
                        {{ $chart['latest'] === null ? '—' : $count($chart['latest']) . ' ' . $chart['unit'] }}
                    </td>
                    <td class="k-table__num k-num">
                        {{ $chart['stored_points'] === null ? '—' : number_format($chart['stored_points']) }}
                    </td>
                    <td>
                        @if ($chart['state'] === 'ok')
                            <span class="mon-pill mon-pill--ok">{{ translate('recorded') }}</span>
                        @else
                            <span class="mon-metric__state">{{ $stateTitle($chart['state']) }}</span>
                            <small class="mon-metric__note" style="display:block">{{ $chart['note'] ?? '' }}</small>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mon-note">
        {{ translate('every_one_of_these_gauges_is_sampled_every_minute_and_stored_in') }}
        <code>monitoring_series</code>. {{ translate('any_of_them_can_be_charted_over_any_window_on_this_page') }}.
    </p>
</x-k.card>

{{-- Keys per database. Which numbered database holds them matters the moment two applications
     share one server, and how many carry an expiry says whether they are a cache at all. --}}
@php($databases = $panel['tables']['databases'])
<x-k.card :title="translate('keys_per_database')">
    @if ($databases['state'] === 'ok' && !empty($databases['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('database') }}</th>
                    <th class="k-table__num">{{ translate('keys') }}</th>
                    <th class="k-table__num">{{ translate('with_an_expiry') }}</th>
                    <th class="k-table__num">{{ translate('average_ttl') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($databases['rows'] as $database)
                    <tr>
                        <td class="k-num">db{{ $database['database'] }}</td>
                        <td class="k-table__num k-num">{{ number_format($database['keys']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($database['expiring']) }}</td>
                        <td class="k-table__num k-num">
                            {{ $database['avg_ttl_seconds'] > 0 ? number_format($database['avg_ttl_seconds']) . ' s' : '—' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($databases['state'] === 'ok')
        <x-k.empty icon="reports" :title="translate('no_database_on_this_server_holds_a_key')"
                   :text="translate('that_is_a_reading_taken_from_the_server_not_a_missing_one')" />
    @else
        <x-k.empty icon="reports" :title="$stateTitle($databases['state'])" :text="$databases['note'] ?? ''" />
        @if (!empty($databases['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $databases['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Where this server spends its time. Totals since it started, never a rate — which the note
     under the table says out loud rather than letting somebody read them as per-second figures. --}}
@php($commands = $panel['tables']['top_commands'])
<x-k.card :title="translate('commands_this_server_spends_the_most_time_on')">
    @if ($commands['state'] === 'ok' && !empty($commands['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('command') }}</th>
                    <th class="k-table__num">{{ translate('calls') }}</th>
                    <th class="k-table__num">{{ translate('total_time') }}</th>
                    <th class="k-table__num">{{ translate('per_call') }}</th>
                    <th class="k-table__num">{{ translate('failed_calls') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($commands['rows'] as $command)
                    <tr>
                        <td class="k-num">{{ $command['command'] }}</td>
                        <td class="k-table__num k-num">{{ number_format($command['calls']) }}</td>
                        <td class="k-table__num k-num">{{ $count($command['total_ms']) }} ms</td>
                        <td class="k-table__num k-num">{{ $count($command['usec_per_call']) }} µs</td>
                        <td class="k-table__num k-num">
                            @if ($command['failed_calls'] > 0)
                                <span class="mon-pill mon-pill--warning">{{ number_format($command['failed_calls']) }}</span>
                            @else
                                {{ number_format($command['failed_calls']) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $commands['note'] }} — {{ $commands['source'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($commands['state'])" :text="$commands['note'] ?? ''" />
        @if (!empty($commands['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $commands['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Per-command percentiles, measured by Redis itself. They exclude the network between this host
     and the server, which is why the round-trip gauge above is not the same number. --}}
@php($percentiles = $panel['tables']['command_latency_percentiles'])
<x-k.card :title="translate('per_command_latency_percentiles')">
    @if ($percentiles['state'] === 'ok' && !empty($percentiles['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('command') }}</th>
                    <th class="k-table__num">p50</th>
                    <th class="k-table__num">p99</th>
                    <th class="k-table__num">p99.9</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($percentiles['rows'] as $percentile)
                    <tr>
                        <td class="k-num">{{ $percentile['command'] }}</td>
                        <td class="k-table__num k-num">{{ $count($percentile['p50_ms']) }} ms</td>
                        <td class="k-table__num k-num">{{ $count($percentile['p99_ms']) }} ms</td>
                        <td class="k-table__num k-num">{{ $count($percentile['p99_9_ms']) }} ms</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $percentiles['note'] }} — {{ $percentiles['source'] }}</p>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($percentiles['state'])" :text="$percentiles['note'] ?? ''" />
        @if (!empty($percentiles['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $percentiles['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The slow log, with everything each command was called with removed: the argument list is where
     the cache key, the session payload and the password-reset token live. --}}
@php($slow = $panel['tables']['slow_commands'])
<x-k.card :title="translate('slow_commands')">
    @if ($slow['state'] === 'ok' && !empty($slow['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('command') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th class="k-table__num">{{ translate('arguments_redacted') }}</th>
                    <th>{{ translate('recorded_at') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($slow['rows'] as $entry)
                    <tr>
                        <td class="k-num">{{ $entry['command'] }}</td>
                        <td class="k-table__num k-num">{{ $microseconds($entry['microseconds']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($entry['arguments_redacted']) }}</td>
                        <td class="k-num">{{ $entry['at'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $slow['note'] }} {{ translate('times_are_shown_in') }} {{ $panel['window']['timezone'] }}.
        </p>
    @elseif ($slow['state'] === 'ok')
        <x-k.empty icon="clock" :title="translate('no_command_has_been_slow_enough_to_be_logged')"
                   :text="$slow['note'] ?? ''" />
    @else
        <x-k.empty icon="clock" :title="$stateTitle($slow['state'])" :text="$slow['note'] ?? ''" />
        @if (!empty($slow['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $slow['remedy'] }}</code>
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
            <code>{{ $reading['metric'] }}</code> ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('the_server_readings_above_are_taken_live_from') }}
    <code>INFO</code>, <code>PING</code>, <code>SLOWLOG</code>, <code>INFO commandstats</code>.
    {{ translate('the_charts_are_read_from_the_stored_series_in') }} <code>monitoring_series</code>
    ({{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} {{ $panel['window']['timezone'] }}).
    {{ translate('the_driver_table_is_read_from_the_live_configuration') }}:
    <code>cache.default</code>, <code>queue.default</code>, <code>session.driver</code>,
    <code>monitoring.buffer</code>.
</p>
