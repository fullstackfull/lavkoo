{{--
    Application: the runtime, and how this build is configured to behave.

    Every other section measures what happened. This one states what the process IS, because those
    facts are the frame the rest is read inside: a p95 on a deployment with no OPcache is not the
    same measurement as a p95 on one with a warm config cache, and a section that shows two traces
    on a busy afternoon may be showing the sample rate rather than the traffic.

    The findings at the top are the only settings on this page treated as verdicts rather than
    facts. Everything else is stated as it is — a local environment is not shouted at for its
    cold caches, because a page that cries wolf teaches the operator to stop reading it.
--}}

@php
    $groups = collect($panel['groups'] ?? [])->keyBy('key');
    $gauges = $panel['gauges'] ?? [];
    $deployment = $panel['deployment'] ?? ['state' => 'no_data'];

    // Card titles, in the order the page places them. Anything the panel grows later is picked up
    // by the loop at the end, so a new group cannot silently vanish from the page.
    $placedGroups = ['runtime', 'opcache', 'php_fpm', 'caches', 'release', 'monitoring'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $gauge) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $gauge['points'],
        ),
    ];

    $count = static fn ($value) => $value === null
        ? '—'
        : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2);

    $chartedGauges = collect($gauges)->filter(static fn (array $gauge) => $gauge['state'] === 'ok');
    $listedGauges = collect($gauges)->reject(static fn (array $gauge) => $gauge['state'] === 'ok');
@endphp

{{-- The settings that are a live risk rather than a preference, each with the command that ends
     it. Empty when there is nothing to do, which is the point. --}}
@if (!empty($panel['findings']))
    <div class="mon-attention">
        @foreach ($panel['findings'] as $finding)
            <div class="mon-attention__item mon-attention__item--{{ $finding['severity'] }}">
                <x-k.icon :name="$finding['severity'] === 'info' ? 'info' : 'alert'" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate($finding['title']) }}</strong>
                    <small>{{ translate($finding['detail']) }}</small>
                    @if (!empty($finding['remedy']))
                        <code>{{ $finding['remedy'] }}</code>
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endif

{{-- Said once, at the top. A collector that could not answer produces three dozen identical
     unavailable rows underneath, and three dozen copies of one fault reads as three dozen faults. --}}
@if (!empty($panel['collector']))
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $panel['collector']['state'] === 'failed' ? 'critical' : 'info' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('php') }} — {{ translate('this_collector_could_not_answer') }}</strong>
                <small>{{ $panel['collector']['note'] }}</small>
            </span>
        </div>
    </div>
@endif

{{-- The runtime as this process ended up configured, not as php.ini describes it: AppServiceProvider
     raises the memory limit at boot, so the file on disk and the live ceiling disagree. --}}
@php($runtime = $groups['runtime'] ?? null)
@if ($runtime)
    <x-k.card :title="translate('runtime')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($runtime['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($runtime['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('every_directive_here_is_read_with_ini_get_which_answers_for_the_running_process_rather_than_for_the_file_on_disk') }}.
            {{ translate('the_sapi_named_above_is_the_one_serving_this_page_and_the_once_a_minute_sample_runs_under_a_different_one') }}.
        </p>
    </x-k.card>
@endif

{{-- OPcache. An unreadable status is not an empty cache: the extension can be absent, switched off
     for this SAPI, or forbidden to this script by opcache.restrict_api, and each has its own fix.
     Any of them drawn as a 0% hit rate would paint a red number over a cache that is working. --}}
@php($opcache = $groups['opcache'] ?? null)
@if ($opcache)
    <x-k.card :title="translate('opcache')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($opcache['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($opcache['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('hits_and_misses_are_totals_since_the_last_restart_rather_than_rates_so_the_hit_rate_beside_them_is_a_ratio_and_not_a_speed') }}.
            {{ translate('wasted_memory_is_held_by_scripts_that_have_since_been_invalidated_and_is_only_returned_by_a_full_restart') }}.
        </p>
    </x-k.card>
@endif

{{-- The pool. Deliberately not inferred from php_sapi_name(): the scheduled sample runs under the
     CLI on servers whose web traffic is served by FPM, so "this process is not FPM" is evidence
     about this process and nothing else. --}}
@php($fpm = $groups['php_fpm'] ?? null)
@if ($fpm)
    <x-k.card :title="translate('php_fpm')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($fpm['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($fpm['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('the_pool_counters_come_from_the_fpm_status_page_which_is_the_only_place_they_exist') }}.
            {{ translate('a_pool_listening_on_a_unix_socket_cannot_report_its_queue_depth_at_all_and_that_is_stated_rather_than_drawn_as_an_empty_queue') }}.
        </p>
    </x-k.card>
@endif

{{-- What Laravel boots from, and what a request actually touches. Warmth is read as the compiled
     file on disk, so the answer can be checked from a shell rather than taken on trust. --}}
@php($caches = $groups['caches'] ?? null)
@if ($caches)
    <x-k.card :title="translate('caches')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($caches['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($caches['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('a_cold_cache_is_the_correct_state_while_developing_because_a_warm_one_hides_every_change_made_to_the_env_file_until_it_is_rebuilt') }}.
            {{ translate('in_production_it_is_the_difference_between_booting_from_one_file_and_parsing_the_whole_config_directory_on_every_request') }}.
        </p>
    </x-k.card>
@endif

{{-- Which build is running, and the last deploy anyone recorded. The version is what the merchant
     sees; the commit is what an engineer needs to tie an error to the code that produced it. --}}
@php($release = $groups['release'] ?? null)
@if ($release)
    <x-k.card :title="translate('release')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($release['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($release['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>

        <h3 class="mon-heading">{{ translate('last_recorded_deployment') }}</h3>
        @if ($deployment['state'] === 'ok')
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('release') }}</th>
                        <th>{{ translate('branch') }}</th>
                        <th>{{ translate('environment') }}</th>
                        <th>{{ translate('status') }}</th>
                        <th class="k-table__num">{{ translate('migrations') }}</th>
                        <th>{{ translate('deployed_by') }}</th>
                        <th>{{ translate('deployed_at') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td class="k-num">{{ $deployment['release'] }}</td>
                        <td>{{ $deployment['branch'] ?? '—' }}</td>
                        <td>{{ $deployment['environment'] ?? '—' }}</td>
                        <td><span class="mon-pill mon-pill--{{ $deployment['status'] === 'success' ? 'ok' : ($deployment['status'] === 'failed' ? 'critical' : 'unknown') }}">{{ translate($deployment['status'] ?? 'unknown') }}</span></td>
                        <td class="k-table__num k-num">{{ $deployment['migrations_run'] === null ? '—' : number_format($deployment['migrations_run']) }}</td>
                        <td>{{ $deployment['deployed_by'] ?? '—' }}</td>
                        <td class="k-num">{{ $deployment['deployed_at'] }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            {{-- Null is its own answer here: "the record does not match what is running" and "we
                 cannot read what is running" are different statements, and only one of them is a
                 reason to go looking. --}}
            <p class="mon-note">
                @if ($deployment['matches_running_release'] === null)
                    {{ translate('the_running_release_could_not_be_read_here_so_this_record_cannot_be_compared_against_it') }}.
                @elseif ($deployment['matches_running_release'])
                    {{ translate('this_is_the_build_that_is_running_right_now') }}.
                @else
                    {{ translate('the_running_build_is_not_the_one_this_record_describes_so_something_has_been_deployed_without_being_recorded') }}:
                    <code>{{ $deployment['running_release'] }}</code>.
                @endif
                {{ translate('shown_in') }} {{ $panel['window']['timezone'] }} — <code>{{ $deployment['source'] }}</code>.
            </p>
        @else
            <x-k.empty icon="clock" :title="$stateTitle($deployment['state'])" :text="$deployment['note'] ?? ''" />
            @if (!empty($deployment['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $deployment['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endif

{{-- What monitoring has been told to record. This is the honest ceiling on the whole dashboard:
     an operator who cannot see the sample rate reads a thin traces page as a quiet afternoon. --}}
@php($monitoring = $groups['monitoring'] ?? null)
@if ($monitoring)
    <x-k.card :title="translate('monitoring_configuration')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($monitoring['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($monitoring['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('the_retention_windows_are_why_a_ninety_day_chart_can_be_empty_while_last_nights_is_full') }}.
            {{ translate('everything_is_stored_in_utc_and_converted_once_for_this_page') }}.
        </p>
    </x-k.card>
@endif

{{-- Anything the panel grows that the order above does not name. Normally nothing. --}}
@foreach ($panel['groups'] ?? [] as $group)
    @continue(in_array($group['key'], $placedGroups, true))
    <x-k.card :title="translate($group['key'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    </x-k.card>
@endforeach

{{-- The runtime over time. The cards above are one instant, and an accelerator that restarts every
     afternoon or a pool that queues at eight o'clock is only visible as a line. --}}
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
    <x-k.card :title="translate('stored_runtime_gauges')">
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
                        <td class="k-table__num k-num">{{ number_format($gauge['samples']) }}</td>
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

{{-- Normally empty. A reading the collector produces and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_php_collector_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>php.{{ $reading['metric'] }}</code>
            ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('the_readings_above_are_taken_live_from') }}
    <code>ini_get()</code>, <code>opcache_get_status()</code>, {{ translate('the_php_fpm_status_page') }},
    <code>bootstrap/cache</code>, <code>version.json</code>, <code>.git/HEAD</code>,
    <code>config/monitoring.php</code>.
    {{ translate('the_charts_are_read_from_the_stored_series_in') }} <code>monitoring_series</code>
    ({{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} {{ $panel['window']['timezone'] }}).
    {{ translate('every_sample_is_stored_in_utc_and_converted_once_for_this_page') }};
    {{ translate('the_time_labels_drawn_on_the_charts_themselves_come_from_the_clock_of_the_browser_you_are_reading_this_in') }}.
</p>
