{{--
    Web server: the tier in front of PHP, and the pool behind it.

    Most of this page is legitimately unavailable on a deployment nobody has configured for it, and
    the page is built around saying so rather than around drawing numbers. Neither nginx's
    stub_status nor PHP-FPM's pm.status_path is enabled out of the box, so the connection and pool
    cards arrive as a single not_configured reading with the exact lines to paste — one card, not
    thirteen copies of one sentence.

    The two traffic sources are never mixed. The status endpoint counts everything the web server
    answered, static assets included; the request buckets count only what reached PHP. They live in
    separate cards under separate provenance, because folding one into the other would put a step
    change in every chart the day somebody exposes stub_status.
--}}

@php
    $server = $panel['server'];
    $connections = $panel['connections'];
    $traffic = $panel['traffic'];
    $statusMix = $panel['status_mix'];
    $fpm = $panel['fpm'];
    $opcache = $panel['opcache'];
    $window = $panel['window'];
    $elsewhere = $panel['elsewhere'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null
        ? '—'
        : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2);

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $gauge) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $gauge['points'],
        ),
    ];

    $chartedGauges = collect($panel['gauges'])->filter(static fn (array $gauge) => $gauge['state'] === 'ok');
    $listedGauges = collect($panel['gauges'])->reject(static fn (array $gauge) => $gauge['state'] === 'ok');
@endphp

{{-- Said once, at the top. A collector that could not answer produces three dozen identical
     unavailable rows underneath, and three dozen copies of one fault reads as three dozen faults. --}}
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

{{-- The one identity fact that changes how every card below should be read. A development server
     has no status endpoint, no worker pool and no connection counters, so the empty cards under
     this banner are the correct output rather than a broken probe. --}}
@if ($server['is_development'] === true)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('this_site_is_being_served_by_the_php_development_server') }}</strong>
                <small>{{ $server['development_note'] }}</small>
                <small>{{ translate('the_connection_and_pool_cards_below_are_empty_because_there_is_nothing_to_expose_not_because_a_probe_failed') }}</small>
            </span>
        </div>
    </div>
@elseif ($server['is_development'] === null)
    {{-- Not the same claim as "this is a production server". Nothing was identified, and a page
         that quietly assumed the reassuring half of that would be inventing the answer. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('what_serves_this_site_could_not_be_identified') }}</strong>
                <small>{{ $server['note'] ?? $stateTitle($server['state']) }}</small>
                @if (!empty($server['remedy']))
                    <code>{{ $server['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- Who is answering, and how that was determined. The provenance matters more here than anywhere
     else on the page: SERVER_SOFTWARE is the server's own word, the process table is a guess about
     the box, and the two can disagree on a host running more than one server. --}}
<x-k.card :title="translate('what_is_serving_this_site')">
    @if (!empty($server['metrics']))
        <div class="mon-grid">
            @foreach ($server['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="external" :title="$stateTitle($server['state'])" :text="$server['note'] ?? ''" />
        @if (!empty($server['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $server['remedy'] }}</code>
            </details>
        @endif
    @endif

    @if ($server['endpoint'])
        <p class="mon-note">
            {{ translate('connection_counters_are_read_from') }}: <code>{{ $server['endpoint'] }}</code>.
            {{ translate('any_credentials_in_that_url_are_removed_before_it_is_stored_or_drawn') }}.
        </p>
    @endif

    <p class="mon-note">
        {{ translate('the_server_is_identified_from_what_it_told_php_about_itself_then_from_this_sapi_and_only_then_from_the_process_table') }}.
        {{ translate('the_once_a_minute_sample_runs_under_the_command_line_where_the_first_two_do_not_exist_which_is_why_the_third_is_there') }}.
    </p>
</x-k.card>

{{-- The web server's own counters. Everything it answered, static assets included — which is why
     this card and the application traffic card below do not agree, and are not supposed to. --}}
<x-k.card :title="translate('connections_and_workers')">
    @if ($connections['state'] === 'ok' && !empty($connections['metrics']))
        <div class="mon-grid">
            @foreach ($connections['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('these_counters_come_from_the_web_servers_own_status_endpoint_and_count_every_response_it_served_including_the_static_files_php_never_saw') }}.
            {{ translate('the_since_start_totals_are_counters_rather_than_rates_the_per_second_figures_are_the_difference_between_this_reading_and_the_previous_one') }}.
        </p>
    @else
        <x-k.empty icon="external" :title="$stateTitle($connections['state'])" :text="$connections['note'] ?? ''" />
        @if (!empty($connections['remedy']))
            {{-- The remedy is the difference between a dead card and a task somebody can do. --}}
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $connections['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($connections['source']))
            <p class="mon-note">{{ translate('source') }}: <code>{{ $connections['source'] }}</code></p>
        @endif
    @endif
</x-k.card>

{{-- What reached PHP, from the shop's own per-minute buckets. This half needs no configuration at
     all, which is why it is the part of the page that works on every deployment. --}}
<x-k.card :title="translate('application_traffic_through_php')">
    @if ($statusMix['state'] === 'ok' && !empty($statusMix['classes']))
        <div class="mon-split" role="img" aria-label="{{ translate('share_of_responses_by_status_class') }}">
            @foreach ($statusMix['classes'] as $class)
                @if (($class['share_pct'] ?? 0) > 0)
                    <span class="mon-split__part mon-split__part--{{ $class['tone'] }}"
                          style="inline-size: {{ min(100, max(0, $class['share_pct'])) }}%"
                          title="{{ $class['class'] }}: {{ $count($class['requests']) }} ({{ $count($class['share_pct']) }}%)"></span>
                @endif
            @endforeach
        </div>
        <ul class="mon-split__legend">
            @foreach ($statusMix['classes'] as $class)
                <li class="mon-split__key">
                    <span class="mon-split__swatch mon-split__part--{{ $class['tone'] }}" aria-hidden="true"></span>
                    {{-- The class label is the collector's own string, not a translation key. --}}
                    <span>{{ $class['class'] }}</span>
                    <span class="k-num">{{ $count($class['requests']) }}<i>{{ $count($class['share_pct']) }}%</i></span>
                </li>
            @endforeach
        </ul>
        @if (!empty($statusMix['note']))
            <p class="mon-note">{{ $statusMix['note'] }}</p>
        @endif
    @else
        <x-k.empty icon="reports" :title="$stateTitle($statusMix['state'])" :text="$statusMix['note'] ?? ''" />
        @if (!empty($statusMix['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $statusMix['remedy'] }}</code>
            </details>
        @endif
    @endif

    @if ($traffic['state'] === 'ok' && !empty($traffic['metrics']))
        <div class="mon-grid">
            @foreach ($traffic['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    @endif

    <p class="mon-note">
        {{ translate('measured_on_the_way_out_of_php_so_a_static_file_the_web_server_answered_by_itself_is_not_in_it') }}.
        {{ translate('source') }}: <code>{{ $traffic['source'] ?? $statusMix['source'] ?? 'monitoring_request_buckets' }}</code>.
    </p>
</x-k.card>

{{-- The pool that runs the PHP. Nothing on the machine can be asked for these numbers — not /proc,
     not the SAPI — so the status page is the whole story, and its absence is stated as such. --}}
<x-k.card :title="translate('php_fpm_pool')">
    @if ($fpm['state'] === 'ok' && !empty($fpm['metrics']))
        <div class="mon-grid">
            @foreach ($fpm['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('idle_processes_reaching_zero_is_the_moment_requests_begin_waiting_on_the_socket_instead_of_being_served') }}.
            {{ translate('max_children_reached_counts_the_times_that_has_already_happened_since_the_pool_started_rather_than_a_rate') }}.
        </p>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($fpm['state'])" :text="$fpm['note'] ?? ''" />
        @if (!empty($fpm['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $fpm['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($fpm['source']))
            <p class="mon-note">{{ translate('source') }}: <code>{{ $fpm['source'] }}</code></p>
        @endif
    @endif
</x-k.card>

{{-- The accelerator. An unreadable OPcache is never drawn as an empty one: the extension being
     absent, switched off for this SAPI, or restricted by opcache.restrict_api are three different
     situations with three different fixes, and a 0% hit rate would misreport all three. --}}
<x-k.card :title="translate('opcache')">
    @if ($opcache['state'] === 'ok' && !empty($opcache['metrics']))
        <div class="mon-grid">
            @foreach ($opcache['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('hits_and_misses_are_totals_since_the_last_restart_rather_than_rates_so_a_low_hit_rate_immediately_after_a_deployment_is_a_cold_cache_and_not_a_fault') }}.
        </p>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($opcache['state'])" :text="$opcache['note'] ?? ''" />
        @if (!empty($opcache['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $opcache['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($opcache['source']))
            <p class="mon-note">{{ translate('source') }}: <code>{{ $opcache['source'] }}</code></p>
        @endif
    @endif
</x-k.card>

{{-- The same readings over the window. The cards above are one instant, and a pool that queues at
     eight o'clock or an accelerator that restarts every afternoon is only visible as a line. --}}
@foreach ($chartedGauges as $gauge)
    <x-k.card :title="translate($gauge['title'])">
        <div class="mon-chart" data-mon-chart='@json($asChart($gauge))'></div>
        {{-- The window is stated once at the foot of the page rather than on every card: the shared
             renderer labels its axis with the VIEWER's clock, so a dashboard timezone printed
             directly under it reads as a contradiction on a browser set elsewhere. --}}
        <p class="mon-note">
            {{ translate('latest') }}: {{ $count($gauge['latest']) }} {{ $gauge['unit'] }} —
            <code>{{ $gauge['metric'] }}</code>
        </p>
    </x-k.card>
@endforeach

{{-- The gauges with no line, and why each one has none. Four different silences — collection off,
     the reading missing on this host, a rolled-up range, an empty window — draw the same flat
     nothing, so the reason is named rather than left to be guessed at. --}}
@if ($listedGauges->isNotEmpty())
    <x-k.card :title="translate('stored_web_server_gauges')">
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('gauge') }}</th>
                    <th>{{ translate('series') }}</th>
                    <th class="k-table__num">{{ translate('latest') }}</th>
                    <th class="k-table__num">{{ translate('samples_in_window') }}</th>
                    <th>{{ translate('state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($listedGauges as $gaugeKey => $gauge)
                    <tr>
                        <td>{{ translate($gaugeKey) }}</td>
                        <td><code>{{ $gauge['metric'] }}</code></td>
                        <td class="k-table__num k-num">
                            {{ $gauge['latest'] === null ? '—' : $count($gauge['latest']) . ' ' . $gauge['unit'] }}
                        </td>
                        <td class="k-table__num k-num">
                            {{-- Null, not zero: a series read that failed did not find nothing, it
                                 did not look, and a 0 here would read as an empty window. --}}
                            {{ $gauge['samples'] === null ? '—' : number_format($gauge['samples']) }}
                        </td>
                        <td>
                            <span class="mon-metric__state">{{ $stateTitle($gauge['state']) }}</span>
                            <small class="mon-metric__note" style="display:block">{{ $gauge['note'] ?? '' }}</small>
                            @if (!empty($gauge['remedy']))
                                <details class="mon-metric__remedy">
                                    <summary>{{ translate('how_to_enable_this') }}</summary>
                                    <code>{{ $gauge['remedy'] }}</code>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('these_gauges_are_sampled_every_minute_and_stored_in') }} <code>monitoring_series</code>.
            {{ translate('a_reading_is_only_stored_while_it_is_available_so_a_gauge_with_no_line_on_this_host_is_a_missing_reading_rather_than_a_missing_sampler') }}.
        </p>
    </x-k.card>
@endif

{{-- Named rather than redrawn. The php collector reads more than this page is about, and the
     runtime half of it belongs to one section only — two pages printing the same memory limit
     under two provenances is a disagreement waiting to happen. --}}
@if (!empty($elsewhere['metrics']))
    <p class="mon-note">
        {{ translate('the_php_runtime_itself_its_version_limits_and_debug_state_is_drawn_on_the_application_section_rather_than_repeated_here') }} —
        <a href="{{ route('admin.monitoring.section', ['section' => $elsewhere['section'], 'range' => $range]) }}">{{ translate($elsewhere['section']) }}</a>:
        @foreach ($elsewhere['metrics'] as $metric)
            <code>{{ $metric }}</code>{{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

{{-- Normally empty. A reading the collector produces and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('these_collectors_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['collector'] }}.{{ $reading['metric'] }}</code> ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('connection_and_worker_counters_come_from_the_web_servers_own_status_endpoint') }}
    (<code>MONITORING_NGINX_STATUS_URL</code>);
    {{ translate('pool_state_comes_from_php_fpms_status_page') }}
    (<code>MONITORING_PHP_FPM_STATUS_URL</code>);
    {{ translate('application_traffic_comes_from') }} <code>monitoring_request_buckets</code>,
    {{ translate('which_every_deployment_writes_without_being_configured_for_it') }}.
    {{ translate('the_charts_cover') }} {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}),
    {{ translate('resolution') }}: {{ translate($window['resolution']) }}.
</p>
