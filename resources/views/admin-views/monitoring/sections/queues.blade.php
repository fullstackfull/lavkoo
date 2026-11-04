{{--
    Queues: what is waiting, how long it has waited, and whether anything is taking it.

    The oldest-waiting column is the one to read first, which is why it is the only one drawn as a
    scored pill and why the table is sorted by it. Three jobs stuck for an hour is an outage; three
    thousand flowing through is a busy evening, and a table ordered by depth shows them the wrong
    way round.

    Worker status is rendered with three answers, not two. "No worker is running" and "whether a
    worker is running cannot be determined" send an operator in opposite directions, and a page that
    prints an unreadable process list as "0 workers" is how a healthy deployment gets restarted and
    a dead one gets ignored.
--}}

@php
    $ms = static fn ($value) => $value === null ? null : number_format((float) $value, 1) . ' ms';
    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // A rate that rounds to zero on a long window is not a stopped queue: three jobs across a day
    // is 0.002/min, and printing "0.00" beside "3 processed" contradicts the number next to it.
    $rate = static function ($value) {
        if ($value === null) {
            return null;
        }
        $value = (float) $value;

        return $value > 0 && $value < 0.01 ? '< 0.01' : number_format($value, 2);
    };

    // Units stay as symbols, in line with every other monitoring section: the figure is read as a
    // duration, not as a sentence.
    $age = static function ($seconds) {
        if ($seconds === null) {
            return null;
        }
        $seconds = (int) $seconds;
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        // The smaller unit is only shown when it carries something: "5 m" reads as a duration,
        // "5 m 0 s" reads as a machine that has not been told to stop talking.
        $parts = static fn (int $whole, int $rest, string $big, string $small) => $whole . ' ' . $big
            . ($rest > 0 ? ' ' . $rest . ' ' . $small : '');

        if ($seconds < 3600) {
            return $parts(intdiv($seconds, 60), $seconds % 60, 'm', 's');
        }
        if ($seconds < 86400) {
            return $parts(intdiv($seconds, 3600), intdiv($seconds % 3600, 60), 'h', 'm');
        }

        return $parts(intdiv($seconds, 86400), intdiv($seconds % 86400, 3600), 'd', 'h');
    };

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $lagTone = static fn (?string $state) => match ($state) {
        'critical' => 'critical',
        'degraded' => 'warning',
        'healthy' => 'ok',
        default => 'info',
    };

    $queues = $panel['queues'];
    $workers = $panel['workers'];
    $failures = $panel['failures'];

    /* Putting a job back on the queue is a write, and this area is otherwise read-only. An operator
       who may read the page but not change it sees the failures and no buttons — the enforcement is
       on the route, this only avoids offering what would be refused. */
    $mayRetry = $permissions->can(\App\Services\Monitoring\MonitoringPermissionService::SETTINGS);
    $throughput = $panel['throughput'];

    // Column gaps that share a reason are stated once between them. Four columns blank because the
    // workers recorded nothing is one fact about this deployment, and four identical paragraphs
    // under the table read as four separate faults — the same trap the backend banner avoids.
    $columnGaps = [];
    foreach ($queues['columns'] as $column => $gap) {
        $key = $gap['state'] . '|' . ($gap['note'] ?? '') . '|' . ($gap['remedy'] ?? '');
        $columnGaps[$key]['gap'] = $gap;
        $columnGaps[$key]['columns'][] = translate($column);
    }

    $verdictTone = match ($workers['verdict']) {
        'consuming' => 'ok',
        'stalled' => 'critical',
        'not_processing' => 'warning',
        default => 'info',
    };
    $verdictTitle = match ($workers['verdict']) {
        'consuming' => translate('a_worker_is_taking_jobs_from_this_queue'),
        'stalled' => translate('nothing_is_draining_this_queue'),
        'not_processing' => translate('job_processing_is_not_running_on_this_connection'),
        default => translate('whether_a_worker_is_running_cannot_be_determined'),
    };
@endphp

{{-- Said once, at the top. On sync or null every reading below is unavailable for the same single
     reason, and forty copies of one fact read as forty faults. --}}
@if (($panel['backend']['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $panel['backend']['state'] === 'failed' ? 'critical' : 'info' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('this_deployment_has_no_readable_queue') }}</strong>
                <small>{{ $panel['backend']['note'] }}</small>
                @if (!empty($panel['backend']['remedy']))
                    <code>{{ $panel['backend']['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The totals, each carrying its own state. A figure this driver cannot answer renders as the
     state and the remedy that would make it real, never as a zero. --}}
<x-k.card :title="translate('queue_totals')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="orders" :title="$stateTitle($panel['backend']['state'] ?? 'no_data')"
                   :text="$panel['backend']['note'] ?? ''" />
    @endif
    <p class="mon-note">
        {{ translate('connection') }}: <code>{{ $panel['backend']['connection'] ?? '—' }}</code>,
        {{ translate('driver') }}: <code>{{ $panel['backend']['driver'] ?? '—' }}</code>.
        {{ translate('depth_and_lag_are_read_from_the_backend') }};
        {{ translate('throughput_and_runtime_from_what_the_workers_recorded') }}.
    </p>
</x-k.card>

{{-- The table this section exists for. Ordered by how long the oldest job has waited, because that
     is the column that separates a stalled queue from a busy one. --}}
<x-k.card :title="translate('queues')">
    @if (!empty($queues['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('queue') }}</th>
                    <th class="k-table__num">{{ translate('pending') }}</th>
                    <th class="k-table__num">{{ translate('reserved') }}</th>
                    <th class="k-table__num">{{ translate('oldest_waiting') }}</th>
                    <th class="k-table__num">{{ translate('processed_per_minute') }}</th>
                    <th class="k-table__num">{{ translate('average_runtime') }}</th>
                    <th class="k-table__num">{{ translate('failed_24h') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($queues['rows'] as $row)
                    <tr>
                        <td>
                            <span class="k-num">{{ $row['queue'] }}</span>
                            @if ($row['paused'] === true)
                                <span class="mon-pill mon-pill--warning">{{ translate('paused') }}</span>
                            @endif
                            @if (!$row['in_backend'])
                                {{-- Jobs demonstrably ran on it, yet the backend does not list it:
                                     it drained between the two reads, or it lives on a connection
                                     this collector cannot see. Both are worth knowing. --}}
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('seen_only_in_the_recorded_job_outcomes_not_in_the_queue_backend') }}
                                </small>
                            @endif
                        </td>

                        <td class="k-table__num k-num">
                            @if ($row['pending'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['pending']['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $count($row['pending']) }}
                                @if (($row['delayed'] ?? 0) > 0)
                                    <small class="mon-metric__note" style="display:block">
                                        +{{ $count($row['delayed']) }} {{ translate('delayed') }}
                                    </small>
                                @endif
                            @endif
                        </td>

                        <td class="k-table__num k-num">
                            @if ($row['reserved'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['reserved']['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $count($row['reserved']) }}
                                @if (($row['stuck_reserved'] ?? 0) > 0)
                                    {{-- A reservation past retry_after means the worker holding it
                                         is gone, which is a worker failure rather than progress. --}}
                                    <span class="mon-pill mon-pill--critical">
                                        {{ $count($row['stuck_reserved']) }} {{ translate('stuck') }}
                                    </span>
                                @endif
                            @endif
                        </td>

                        {{-- The emphasised column. Scored against the lag thresholds and drawn as a
                             pill so the eye lands here before it lands on depth. --}}
                        <td class="k-table__num">
                            @if ($row['oldest_wait_seconds'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['oldest_wait_seconds']['state'] ?? 'no_data') }}</span>
                            @else
                                <span class="mon-pill mon-pill--{{ $lagTone($row['lag_state']) }}">
                                    {{ $age($row['oldest_wait_seconds']) }}
                                </span>
                            @endif
                        </td>

                        <td class="k-table__num k-num">
                            @if ($row['per_minute'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['per_minute']['state'] ?? 'no_data') }}</span>
                            @else
                                {{ $rate($row['per_minute']) }}
                                <small class="mon-metric__note" style="display:block">
                                    {{-- The failures the workers recorded inside this window, which
                                         is a different question from the backend's rolling 24h
                                         count beside it. Computed either way, so drawing it
                                         nowhere would make a measured number look unmeasured. --}}
                                    {{ $count($row['processed']) }} {{ translate('in_this_window') }}@if ($row['failed_in_window'] !== null), {{ $count($row['failed_in_window']) }} {{ translate('of_them_failed') }}@endif
                                </small>
                            @endif
                        </td>

                        <td class="k-table__num k-num">
                            @if ($row['avg_runtime_ms'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['avg_runtime_ms']['state'] ?? 'no_data') }}</span>
                                @if (($row['processed'] ?? null) === 0)
                                    {{-- Not a runtime of zero: nothing finished, so there is
                                         nothing to average. --}}
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('no_job_completed_so_there_is_no_runtime_to_average') }}
                                    </small>
                                @endif
                            @else
                                {{ $ms($row['avg_runtime_ms']) }}
                            @endif
                        </td>

                        <td class="k-table__num k-num">
                            @if ($row['failed_24h'] === null)
                                <span class="mon-metric__state">{{ $stateTitle($queues['columns']['failed_24h']['state'] ?? 'no_data') }}</span>
                            @elseif ($row['failed_24h'] > 0)
                                <span class="mon-pill mon-pill--critical">{{ $count($row['failed_24h']) }}</span>
                                <small class="mon-metric__note" style="display:block">
                                    {{ $count($row['failed_total']) }} {{ translate('in_total') }}
                                </small>
                            @else
                                {{ $count($row['failed_24h']) }}
                                <small class="mon-metric__note" style="display:block">
                                    {{ $count($row['failed_total']) }} {{ translate('in_total') }}
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- A column blank on every row is a fact about the driver, not about a queue — so it says
             which reading is missing and what would make it real. --}}
        @foreach ($columnGaps as $group)
            <p class="mon-note">
                <strong>{{ implode(', ', $group['columns']) }}</strong>:
                {{ $stateTitle($group['gap']['state']) }} — {{ $group['gap']['note'] }}
            </p>
            @if (!empty($group['gap']['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $group['gap']['remedy'] }}</code>
                </details>
            @endif
        @endforeach

        <p class="mon-note">
            {{ translate('rows_are_ordered_by_how_long_the_oldest_job_has_waited_because_a_small_stalled_queue_matters_more_than_a_large_moving_one') }}.
            {{ translate('warning_above') }} {{ $age($panel['thresholds']['lag_warning_seconds']) }},
            {{ translate('critical_above') }} {{ $age($panel['thresholds']['lag_critical_seconds']) }}.
            {{ translate('throughput_covers') }} {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }}
            ({{ $panel['window']['timezone'] }}).
        </p>

        @if (!empty($throughput['truncated']))
            <p class="mon-note mon-note--critical">
                {{ translate('more_queue_names_are_stored_than_this_page_reads_so_the_recorded_totals_below_are_partial') }}
            </p>
        @endif
    @else
        <x-k.empty icon="orders" :title="$stateTitle($queues['state'])" :text="$queues['note'] ?? ''" />
        @if (!empty($queues['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $queues['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Worker status. The verdict and the process count are two separate readings on purpose: a
     process count of zero is evidence, a process count that could not be taken is not, and the
     two lead to opposite actions. --}}
<x-k.card :title="translate('workers')">
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $verdictTone === 'ok' ? 'info' : ($verdictTone === 'critical' ? 'critical' : 'warning') }}">
            <x-k.icon :name="$workers['verdict'] === 'consuming' ? 'check' : 'alert'" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ $verdictTitle }}</strong>
                <small>{{ $workers['note'] }}</small>
                @if (!empty($workers['remedy']))
                    <code>{{ $workers['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>

    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $workers['consuming'],
            'label' => translate('is_anything_consuming_this_queue'),
        ])
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $workers['processes'],
            'label' => translate('worker_processes_on_this_host'),
        ])
    </div>

    @if ($workers['process_count_known'] && $workers['process_count'] === 0)
        <p class="mon-note mon-note--critical">
            {{ translate('the_process_list_was_read_and_it_contains_no_worker') }} —
            {{ translate('this_is_a_counted_zero_not_a_missing_reading') }}.
        </p>
    @elseif (!$workers['process_count_known'])
        <p class="mon-note">
            {{ translate('the_process_list_could_not_be_read_so_the_number_of_workers_is_unknown') }} —
            {{ translate('that_is_not_the_same_as_a_count_of_zero') }}.
        </p>
    @endif

    <p class="mon-note">
        {{ translate('workers_are_counted_on_this_host_only') }};
        {{ translate('a_worker_on_another_machine_is_invisible_here') }}.
    </p>
</x-k.card>

{{-- Completions over the window, every queue folded together: the per-queue split is the table
     above, and what a line is good for is showing whether the workers kept up throughout the window
     or stopped partway into it. --}}
<x-k.card :title="translate('jobs_completed_over_time')">
    @if (($panel['timeline']['state'] ?? '') === 'ok')
        <div class="mon-chart" data-mon-chart='@json(['points' => $panel['timeline']['points']])'></div>
        @if (!empty($panel['timeline']['truncated']))
            {{-- A line that stops before the window does reads as workers that stopped, so the cut
                 is named rather than drawn. --}}
            <p class="mon-note mon-note--critical">
                {{ translate('this_window_holds_more_buckets_than_the_chart_reads_so_the_line_ends_before_the_window_does') }}
            </p>
        @endif
        <p class="mon-note">
            {{ translate('the_line_is_completed_jobs_per_bucket_the_red_line_is_the_failures_among_them') }}
            <code>{{ $throughput['source'] }}</code>, {{ translate('resolution') }}:
            {{ translate($panel['window']['resolution']) }}.
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($panel['timeline']['state'] ?? 'no_data')"
                   :text="$panel['timeline']['note'] ?? ''" />
    @endif

    @if (($throughput['state'] ?? '') !== 'ok')
        <p class="mon-note">{{ $throughput['note'] }}</p>
        @if (!empty($throughput['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $throughput['remedy'] }}</code>
            </details>
        @endif
    @else
        <p class="mon-note">
            {{ translate('recorded_in_this_window') }}:
            {{ $count($throughput['totals']['processed']) }} {{ translate('jobs') }},
            {{ $count($throughput['totals']['failed']) }} {{ translate('of_them_failed') }},
            {{ $rate($throughput['totals']['per_minute']) }} {{ translate('per_minute') }},
            {{ $ms($throughput['totals']['avg_runtime_ms']) ?? translate('no_data') }} {{ translate('average') }}.
            {{ translate('a_failed_job_is_counted_as_processed_too_because_it_ran_and_finished') }}
        </p>
    @endif
</x-k.card>

{{-- The last handful of failures, first line of the exception only. The stack trace below it is
     pages of framework frames no table row can carry, and it is also where file paths and argument
     values live — so what is kept has already been through the redactor. --}}
<x-k.card :title="translate('recent_failed_jobs')">
    @if ($failures['state'] === 'ok' && !empty($failures['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('job') }}</th>
                    <th>{{ translate('queue') }}</th>
                    <th>{{ translate('failed_at') }}</th>
                    <th>{{ translate('exception') }}</th>
                    @if ($mayRetry)<th></th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($failures['rows'] as $failure)
                    <tr>
                        <td class="k-num">{{ $failure['job'] }}</td>
                        <td class="k-num">{{ $failure['queue'] }}</td>
                        <td class="k-num">{{ $failure['failed_at'] ?? '—' }}</td>
                        <td>{{ $failure['exception'] }}</td>
                        @if ($mayRetry)
                            <td class="k-num">
                                {{-- Absent rather than disabled when the row carries no identifier:
                                     some failed-job drivers only count failures, and a button that
                                     cannot address anything is worse than no button. --}}
                                @if (!empty($failure['uuid']))
                                    <div class="d-flex gap-1 justify-content-end">
                                        <form method="POST"
                                              action="{{ route('admin.monitoring.failed-job', ['action' => 'retry', 'uuid' => $failure['uuid']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn--primary btn-sm">{{ translate('retry') }}</button>
                                        </form>
                                        <form method="POST"
                                              action="{{ route('admin.monitoring.failed-job', ['action' => 'discard', 'uuid' => $failure['uuid']]) }}"
                                              onsubmit="return confirm('{{ translate('discard_this_failed_job_without_running_it_again') }}')">
                                            @csrf
                                            <button type="submit" class="btn btn--danger btn-sm">{{ translate('discard') }}</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('only_the_first_line_of_each_exception_is_kept_and_it_has_been_redacted') }}.
            {{ translate('times_are_shown_in') }} {{ $failures['timezone'] }},
            {{ translate('converted_from_the_timezone_the_worker_stamped_them_in') }}.
        </p>
    @elseif ($failures['state'] === 'ok' || $failures['state'] === 'no_data')
        <x-k.empty icon="alert" :title="translate('no_failed_job_is_recorded')" :text="$failures['note'] ?? ''" />
    @else
        <x-k.empty icon="alert" :title="$stateTitle($failures['state'])" :text="$failures['note'] ?? ''" />
        @if (!empty($failures['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $failures['remedy'] }}</code>
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
    {{ translate('depth_lag_and_failures_are_read_live_from') }}
    <code>{{ $queues['source'] ?? '—' }}</code>@if (!empty($failures['source'])), <code>{{ $failures['source'] }}</code>@endif.
    {{ translate('throughput_and_runtime_are_read_from_the_stored_series_in') }}
    <code>monitoring_series</code> (<code>queue.processed</code>, <code>queue.failed</code>),
    {{ translate('written_by_the_worker_process_itself_as_each_job_finishes') }}
    {{ translate('window') }}: {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }}
    ({{ $panel['window']['timezone'] }}).
</p>
