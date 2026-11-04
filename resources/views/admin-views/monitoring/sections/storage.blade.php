{{--
    Storage: how full, how fast, and whether the application can still write.

    The page is three questions in three blocks, because they fail independently. A filesystem
    fills; a device saturates; a directory comes back from a deploy owned by root. Merging them
    into one "disk" section is what makes a storage page unable to answer any of the three.

    Fullness leads each filesystem card as a bar rather than a row of digits. 92% and 12% render as
    the same handful of characters in a metrics grid, and the entire purpose of this page is that
    those two are not the same news — so the bar is coloured against the configured disk_warning
    and disk_critical, with both thresholds marked on the track so a bar is read against the line
    it is about to cross.

    Device utilisation is drawn and deliberately not coloured. There is no utilisation threshold in
    Settings, and a disk at 100% during a backup is a machine doing its job, not an incident.
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

    // A level reads the same colour here as the same figure does anywhere else on the dashboard.
    $pillTone = static fn (string $level) => match ($level) {
        'critical' => 'mon-pill--critical',
        'degraded' => 'mon-pill--warning',
        'healthy' => 'mon-pill--ok',
        default => 'mon-pill--info',
    };

    $levelLabel = static fn (string $level) => match ($level) {
        'critical' => translate('critical'),
        'degraded' => translate('warning'),
        'healthy' => translate('healthy'),
        default => translate('measured'),
    };

    $bar = static fn ($value) => min(100, max(0, (float) $value));
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

{{-- The stored series feed every line and every "latest" on this page. When the read itself fails,
     saying so once is the difference between "no history" and "the history could not be read". --}}
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

{{-- ── Filesystems ───────────────────────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('filesystems') }}</h3>

@forelse ($panel['mounts'] as $mount)
    <x-k.card :title="$mount['label'] ?? translate('filesystem')">
        @if ($mount['space']['state'] === 'ok')
            <x-slot:actions>
                <span class="mon-pill {{ $pillTone($mount['space']['level']) }}">{{ $levelLabel($mount['space']['level']) }}</span>
            </x-slot:actions>
        @endif

        @if ($mount['note'])
            <p class="mon-note" style="margin-block-start:0">{{ $mount['note'] }}</p>
        @endif

        {{-- Space. The headline of the card, because "how full" is the finding and "how many bytes
             free" is only the arithmetic behind it. --}}
        @if ($mount['space']['state'] === 'ok')
            <div class="mon-usage mon-usage--{{ $mount['space']['level'] }}">
                <div class="mon-usage__head">
                    <span class="mon-usage__value k-num">{{ $count($mount['space']['pct']) }}<i>%</i></span>
                    <span class="mon-usage__caption">
                        {{ translate('space_used') }} · {{ translate('thresholds') }}
                        {{ $count($mount['space']['warning_pct']) }}% / {{ $count($mount['space']['critical_pct']) }}%
                    </span>
                </div>
                <span class="mon-usage__track">
                    <span class="mon-usage__fill mon-usage__fill--{{ $mount['space']['level'] }}"
                          style="inline-size: {{ $bar($mount['space']['pct']) }}%"></span>
                    <span class="mon-usage__mark" style="inset-inline-start: {{ $bar($mount['space']['warning_pct']) }}%"
                          title="{{ translate('warning_threshold') }}"></span>
                    <span class="mon-usage__mark mon-usage__mark--critical"
                          style="inset-inline-start: {{ $bar($mount['space']['critical_pct']) }}%"
                          title="{{ translate('critical_threshold') }}"></span>
                </span>
            </div>
            @if ($mount['space']['level'] === 'critical')
                <p class="mon-note mon-note--critical">
                    {{ translate('this_filesystem_is_past_the_critical_threshold') }}.
                    {{ translate('a_full_filesystem_stops_uploads_logging_sessions_and_the_database_at_the_same_moment_and_it_does_so_without_warning') }}.
                </p>
            @endif
        @else
            <x-k.empty icon="reports" :title="$stateTitle($mount['space']['state'])" :text="$mount['space']['note'] ?? ''" />
            @if (!empty($mount['space']['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $mount['space']['remedy'] }}</code>
                </details>
            @endif
        @endif

        {{-- Inodes, on the same footing as blocks. A filesystem can refuse to create a file with
             200 GB free, and that outage costs hours precisely because nothing hinted at it. --}}
        @if ($mount['inodes']['state'] === 'ok')
            <div class="mon-usage mon-usage--{{ $mount['inodes']['level'] }}">
                <div class="mon-usage__head">
                    <span class="mon-usage__value k-num">{{ $count($mount['inodes']['pct']) }}<i>%</i></span>
                    <span class="mon-usage__caption">
                        {{ translate('inodes_used') }} —
                        {{ translate('a_filesystem_out_of_inodes_refuses_new_files_however_much_space_is_left') }}
                    </span>
                </div>
                <span class="mon-usage__track">
                    <span class="mon-usage__fill mon-usage__fill--{{ $mount['inodes']['level'] }}"
                          style="inline-size: {{ $bar($mount['inodes']['pct']) }}%"></span>
                    <span class="mon-usage__mark" style="inset-inline-start: {{ $bar($mount['inodes']['warning_pct']) }}%"
                          title="{{ translate('warning_threshold') }}"></span>
                    <span class="mon-usage__mark mon-usage__mark--critical"
                          style="inset-inline-start: {{ $bar($mount['inodes']['critical_pct']) }}%"
                          title="{{ translate('critical_threshold') }}"></span>
                </span>
            </div>
        @else
            <p class="mon-note">
                {{ translate('inodes_used') }}: {{ $stateTitle($mount['inodes']['state']) }}
                @if (!empty($mount['inodes']['note'])) — {{ $mount['inodes']['note'] }} @endif
            </p>
            @if (!empty($mount['inodes']['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $mount['inodes']['remedy'] }}</code>
                </details>
            @endif
        @endif

        <div class="mon-grid">
            @foreach ($mount['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>

        <p class="mon-note">
            {{ translate('fullness_is_measured_against_the_blocks_the_filesystem_will_actually_hand_out_which_is_the_percentage_df_prints') }}.
            {{ translate('blocks_the_kernel_reserves_for_root_are_neither_used_nor_available_and_are_counted_as_neither') }}.
        </p>

        @foreach ($mount['charts'] as $chart)
            <h3 class="mon-heading">{{ translate($chart['title']) }}</h3>
            @if ($chart['state'] === 'ok')
                <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
                <p class="mon-note">
                    {{ translate('latest') }}: {{ $count($chart['latest']) }} {{ $chart['unit'] }} —
                    <code>{{ $chart['metric'] }}</code> ({{ $chart['label'] }}), {{ translate('window') }}:
                    {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} ({{ $panel['window']['timezone'] }})
                </p>
                @if ($chart['note'])
                    <p class="mon-note">{{ $chart['note'] }}</p>
                @endif
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
    <x-k.card>
        <x-k.empty icon="reports"
                   :title="translate('no_filesystem_could_be_read')"
                   :text="translate('the_disk_collector_resolved_none_of_the_application_paths_to_a_mount_point_on_this_host')" />
    </x-k.card>
@endforelse

{{-- ── Block devices ─────────────────────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('block_devices') }}</h3>

{{-- Absent device cards are never left to speak for themselves: an empty section reads as a
     machine doing no I/O, which is a very different claim from one that could not be measured. --}}
@if ($panel['device_io'])
    <x-k.card>
        <x-k.empty icon="reports" :title="$stateTitle($panel['device_io']['state'])" :text="$panel['device_io']['note']" />
        @if (!empty($panel['device_io']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $panel['device_io']['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($panel['device_io']['source']))
            <p class="mon-note">{{ $panel['device_io']['source'] }}</p>
        @endif
    </x-k.card>
@endif

@foreach ($panel['devices'] as $device)
    <x-k.card :title="$device['label']">
        @if ($device['utilisation']['state'] === 'ok')
            <div class="mon-usage mon-usage--{{ $device['utilisation']['level'] }}">
                <div class="mon-usage__head">
                    <span class="mon-usage__value k-num">{{ $count($device['utilisation']['pct']) }}<i>%</i></span>
                    <span class="mon-usage__caption">
                        {{ translate('of_the_interval_had_at_least_one_request_in_flight') }}
                    </span>
                </div>
                <span class="mon-usage__track">
                    <span class="mon-usage__fill mon-usage__fill--{{ $device['utilisation']['level'] }}"
                          style="inline-size: {{ $bar($device['utilisation']['pct']) }}%"></span>
                </span>
            </div>
        @else
            <p class="mon-note" style="margin-block-start:0">
                {{ translate('utilisation') }}: {{ $stateTitle($device['utilisation']['state']) }}
                @if (!empty($device['utilisation']['note'])) — {{ $device['utilisation']['note'] }} @endif
            </p>
        @endif

        <div class="mon-grid">
            @foreach ($device['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>

        <p class="mon-note">
            {{ translate('these_are_the_difference_between_this_reading_of_proc_diskstats_and_the_previous_one_not_totals_since_boot') }}.
            {{ translate('queue_depth_is_the_average_number_of_requests_outstanding_which_is_what_separates_a_busy_disk_from_one_everything_is_piling_up_behind') }}.
        </p>
    </x-k.card>
@endforeach

{{-- Every per-device gauge that is stored, with its latest reading. Eighteen flat lines would be
     noise on a host whose read-only images never move, so they are listed rather than drawn — and
     listed rather than dropped, because a stored series nobody shows is indistinguishable from one
     nobody ever took. --}}
@if (!empty($panel['devices']))
    <x-k.card :title="translate('stored_device_gauges')">
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('device') }}</th>
                    <th>{{ translate('series') }}</th>
                    <th class="k-table__num">{{ translate('latest') }}</th>
                    <th class="k-table__num">{{ translate('stored_points_in_window') }}</th>
                    <th>{{ translate('state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($panel['devices'] as $device)
                    @foreach ($device['charts'] as $chart)
                        <tr>
                            <td>{{ $device['label'] }}</td>
                            <td><code>{{ $chart['metric'] }}</code></td>
                            <td class="k-table__num k-num">
                                {{ $chart['latest'] === null ? '—' : $count($chart['latest']) . ' ' . $chart['unit'] }}
                            </td>
                            <td class="k-table__num k-num">{{ number_format($chart['stored_points']) }}</td>
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
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('every_one_of_these_gauges_is_sampled_every_minute_and_stored_in') }}
            <code>monitoring_series</code>.
            {{ translate('one_row_per_device_per_minute_and_any_of_them_can_be_charted_over_any_window') }}.
        </p>
    </x-k.card>
@endif

{{-- A device with history and no current reading is a disk that was removed, renamed, or dropped
     by a newer collector build. Naming it is the difference between "this disk is gone" and a
     chart quietly missing from the page. --}}
@if (!empty($panel['history_only']))
    <p class="mon-note">
        {{ translate('stored_history_also_exists_for_labels_this_host_no_longer_reports') }}:
        @foreach ($panel['history_only'] as $label)
            <code>{{ $label }}</code>{{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

{{-- ── The application's own storage ─────────────────────────────────────────────────────── --}}
<h3 class="mon-heading">{{ translate('application_storage') }}</h3>

@foreach ($panel['application'] as $group)
    <x-k.card :title="translate($group['key'])">
        @if (!empty($group['failing']))
            <x-slot:actions>
                <span class="mon-pill mon-pill--critical">{{ translate('not_writable') }}</span>
            </x-slot:actions>
        @endif
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $label => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($label)])
            @endforeach
        </div>
        @if (!empty($group['failing']))
            <p class="mon-note mon-note--critical">
                {{ translate('a_directory_here_refused_a_write_the_note_beside_it_carries_what_the_kernel_said_and_the_fix_for_that_particular_failure') }}.
            </p>
        @endif
    </x-k.card>
@endforeach

@foreach ($panel['application_charts'] as $chart)
    <x-k.card :title="translate($chart['title'])">
        @if ($chart['state'] === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            <p class="mon-note">
                {{ translate('latest') }}: {{ $count($chart['latest']) }} {{ $chart['unit'] }} —
                <code>{{ $chart['metric'] }}</code>, {{ translate('window') }}:
                {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} ({{ $panel['window']['timezone'] }})
            </p>
            @if ($chart['note'])
                <p class="mon-note">{{ $chart['note'] }}</p>
            @endif
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
    <code>df -Pk</code>, <code>df -iP</code>, <code>/proc/diskstats</code>, <code>/proc/mounts</code>.
    {{ translate('write_probes_run_in_every_directory_laravel_must_be_able_to_write_because_permission_bits_alone_still_read_as_writable_on_a_read_only_remount') }}.
    {{ translate('the_charts_are_read_from_the_stored_series_in') }} <code>monitoring_series</code>
    ({{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} {{ $panel['window']['timezone'] }}).
</p>
