{{--
    Server: the machine underneath, and the two things a server page is usually wrong about.

    Load is not utilisation — it is a count of tasks waiting, so it gets its own card, never sits
    beside the percentages, and is always drawn next to the core count that makes it comparable.

    An absent reading is not a calm one. Pressure Stall Information is a kernel build option this
    kernel does not carry, so the pressure card renders "not supported" and the boot flag that
    would fix it. That card being empty is the correct output, not a hole in the page.
--}}

@php
    $count = static fn ($value) => $value === null
        ? '—'
        : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 2);

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
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $groups = collect($panel['groups'])->keyBy('key');
    $cores = $panel['cores'];

    // A core is coloured against the same cpu_warning / cpu_critical thresholds the health score
    // uses, so the same percentage never reads as two different states on two pages.
    $coreFill = static fn (float $usage) => match (true) {
        $usage >= $cores['critical_pct'] => ' mon-cores__fill--saturated',
        $usage >= $cores['warning_pct'] => ' mon-cores__fill--busy',
        default => '',
    };

    // The six gauges this page draws as lines. The other four are stored identically and are
    // listed underneath with their latest reading rather than as four more flat charts.
    $cpuCharts = ['cpu_usage_pct', 'cpu_load_1m', 'cpu_iowait_pct', 'cpu_steal_pct'];
    $memoryCharts = ['memory_used_pct', 'memory_available_mb'];
    $lineCharts = array_merge($cpuCharts, $memoryCharts);

    // Cards drawn in a deliberate order below. Anything the panel grows later is picked up by the
    // loop at the end, so a new group cannot silently vanish from the page.
    $placedGroups = ['processor', 'load', 'memory', 'swap', 'paging', 'pressure', 'kernel_activity'];
@endphp

{{-- Said once, at the top. A collector that could not answer produces two dozen identical
     unavailable rows underneath, and two dozen copies of one fault reads as two dozen faults. --}}
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

{{-- The processor itself. Utilisation is the difference between this reading of /proc/stat and
     the previous one — /proc holds monotonic counters since boot, so "usage now" only exists as a
     delta and the first sample on a host says so rather than publishing a lifetime average. --}}
@php($processor = $groups['processor'] ?? null)
@if ($processor)
    <x-k.card :title="translate('processor')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($processor['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($processor['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('utilisation_is_a_difference_between_two_readings_of_the_kernel_counters_rather_than_an_instantaneous_snapshot') }}.
            {{ translate('io_wait_is_the_processor_blocked_on_a_slow_disk') }}.
            {{ translate('steal_is_time_the_hypervisor_handed_to_somebody_else_on_the_same_machine') }}.
        </p>
        <p class="mon-note">{{ translate('host') }}: {{ $panel['host']['description'] }}</p>
    </x-k.card>
@endif

{{-- Per-core, because one pinned core is invisible in the average above: four cores with one at
     100% and three idle reads as a comfortable 25%. --}}
<x-k.card :title="translate('utilisation_per_core')">
    @if ($cores['state'] === 'ok')
        <div class="mon-cores">
            @foreach ($cores['rows'] as $core)
                <div class="mon-cores__row">
                    <span class="mon-cores__label">{{ translate('core') }} {{ $core['core'] }}</span>
                    <span class="mon-cores__track">
                        <span class="mon-cores__fill{{ $coreFill($core['usage_pct']) }}"
                              style="inline-size: {{ min(100, max(0, $core['usage_pct'])) }}%"></span>
                    </span>
                    <span class="mon-cores__value k-num">{{ number_format($core['usage_pct'], 1) }}%</span>
                </div>
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('busiest_core') }}: {{ number_format($cores['busiest_pct'], 1) }}% —
            {{ translate('mean_across_the_cores_read') }}: {{ number_format($cores['average_pct'], 1) }}%.
            {{ translate('a_wide_gap_between_the_two_is_work_pinned_to_one_core_rather_than_a_busy_machine') }}.
        </p>
        <p class="mon-note">{{ $cores['source'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($cores['state'])" :text="$cores['note'] ?? ''" />
        @if (!empty($cores['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $cores['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Load, on its own, with the sentence that stops it being read as a percentage. Conflating the
     two is the single most common server-monitoring mistake: 4.0 is a saturated four-core box and
     an idle thirty-two-core one, and the number carries no meaning without the core count. --}}
@php($load = $groups['load'] ?? null)
@if ($load)
    <x-k.card :title="translate('load_average')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($load['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($load['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note mon-note--critical">
            {{ translate('load_is_not_utilisation') }}.
            {{ translate('it_counts_the_tasks_running_or_waiting_to_run_including_those_blocked_on_disk_so_it_has_no_ceiling_and_is_never_a_percentage') }}.
        </p>
        {{-- Each sentence is its own translate() call and no figure sits inside one: the helper
             capitalises whatever it is handed, so a fragment wrapped around a variable comes back
             with a capital in the middle of the sentence. --}}
        <p class="mon-note">
            @if ($cores['count'] !== null)
                {{ translate('logical_cores_on_this_host') }}: {{ $cores['count'] }}.
                {{ translate('a_load_equal_to_that_number_is_exactly_saturated_and_anything_above_it_is_a_queue_of_tasks_waiting_their_turn') }}.
            @else
                {{ translate('the_core_count_could_not_be_read_on_this_host_so_a_raw_load_figure_cannot_be_turned_into_saturation_here') }}.
            @endif
            {{ translate('load_per_core_is_that_division_already_done_so_one_point_zero_means_exactly_saturated_on_any_size_of_machine') }}.
        </p>
    </x-k.card>
@endif

{{-- The processor over time. The cards above are one instant; an afternoon of steal or io wait is
     only visible as a line. --}}
<h3 class="mon-heading">{{ translate('processor_over_time') }}</h3>
@foreach ($cpuCharts as $chartKey)
    @php($chart = $panel['charts'][$chartKey] ?? null)
    @continue($chart === null)
    <x-k.card :title="translate($chart['title'])">
        @if ($chart['state'] === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            {{-- The window is stated once at the foot of the page rather than on every card: the
                 shared renderer labels its axis with the VIEWER's clock, so a dashboard timezone
                 printed directly under it reads as a contradiction on a browser set elsewhere. --}}
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

{{-- Memory. Used here is MemTotal minus MemAvailable, not MemTotal minus MemFree: Linux lends
     every idle page to the page cache, so by that older arithmetic a healthy server sits at 95%
     forever and nobody believes the number any more. --}}
@php($memory = $groups['memory'] ?? null)
@if ($memory)
    <x-k.card :title="translate('memory')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($memory['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($memory['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('cached_and_buffers_are_memory_linux_hands_straight_back_when_a_process_asks_for_it_which_is_why_they_are_not_counted_as_used') }}.
        </p>
        @if ($panel['host']['containerised'])
            <p class="mon-note">
                {{ translate('this_process_runs_in_a_container_so_the_total_above_is_the_cgroup_limit_the_oom_killer_enforces_and_the_hosts_own_ram_is_listed_beside_it') }}.
            </p>
        @endif
    </x-k.card>
@endif

<h3 class="mon-heading">{{ translate('memory_over_time') }}</h3>
@foreach ($memoryCharts as $chartKey)
    @php($chart = $panel['charts'][$chartKey] ?? null)
    @continue($chart === null)
    <x-k.card :title="translate($chart['title'])">
        @if ($chart['state'] === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            {{-- The window is stated once at the foot of the page rather than on every card: the
                 shared renderer labels its axis with the VIEWER's clock, so a dashboard timezone
                 printed directly under it reads as a contradiction on a browser set elsewhere. --}}
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

{{-- Swap and paging. A machine that is swapping is a machine paying disk latency for memory
     access, and the major-fault rate is where that shows up in a request rather than in a graph. --}}
@foreach (['swap', 'paging'] as $groupKey)
    @php($group = $groups[$groupKey] ?? null)
    @continue($group === null)
    <x-k.card :title="translate($groupKey)">
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    </x-k.card>
@endforeach

{{-- Pressure. On this kernel every reading below is "not supported", and that is the correct
     output rather than a gap: PSI is a build option, and a 0% drawn in its place would describe a
     perfectly calm machine. It is the measurement that separates a server using its RAM from one
     drowning in it, so its absence is a real limit on what this page can promise. --}}
@php($pressure = $groups['pressure'] ?? null)
@if ($pressure)
    <x-k.card :title="translate('pressure_stall_information')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($pressure['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($pressure['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        <p class="mon-note">
            {{ translate('some_is_the_share_of_time_in_which_at_least_one_task_was_stalled') }}.
            {{ translate('full_is_the_share_in_which_every_task_was_stalled_at_once') }}.
            {{ translate('high_utilisation_with_no_pressure_is_a_machine_being_used_well') }}.
            {{ translate('the_same_utilisation_with_rising_pressure_is_a_machine_thrashing') }}.
        </p>
    </x-k.card>
@endif

{{-- Since-boot totals. They are not rates and are never drawn as one; their use is the difference
     between two looks at this page. --}}
@php($kernel = $groups['kernel_activity'] ?? null)
@if ($kernel)
    <x-k.card :title="translate('kernel_activity')">
        <p class="mon-note" style="margin-block-start:0">{{ translate($kernel['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($kernel['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
    </x-k.card>
@endif

{{-- Anything the panel grows that the order above does not name. Normally nothing. --}}
@foreach ($panel['groups'] as $group)
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

{{-- The rest of the stored gauges. Sampled every minute exactly like the six charted above, listed
     here with their latest reading so that a gauge nobody drew a line for is still visibly kept. --}}
<x-k.card :title="translate('other_stored_gauges')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('gauge') }}</th>
                <th>{{ translate('series') }}</th>
                <th class="k-table__num">{{ translate('latest') }}</th>
                {{-- Stored points, not samples: at hour or day resolution one stored row is a
                     rollup of sixty or of fourteen hundred samples, so a sample count here would
                     understate a week's collection by two orders of magnitude. --}}
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

{{-- Normally empty. A reading the collectors produce and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_collectors_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['collector'] }}.{{ $reading['metric'] }}</code>
            ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('the_readings_above_are_taken_live_from') }}
    <code>/proc/stat</code>, <code>/proc/cpuinfo</code>, <code>/proc/loadavg</code>,
    <code>/proc/meminfo</code>, <code>/proc/vmstat</code>, <code>/proc/pressure</code>,
    <code>/sys/fs/cgroup</code>.
    {{ translate('the_charts_are_read_from_the_stored_series_in') }} <code>monitoring_series</code>
    ({{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} {{ $panel['window']['timezone'] }}).
    {{ translate('every_sample_is_stored_in_utc_and_converted_once_for_this_page') }};
    {{ translate('the_time_labels_drawn_on_the_charts_themselves_come_from_the_clock_of_the_browser_you_are_reading_this_in') }}.
</p>
