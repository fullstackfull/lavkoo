{{--
    Integrations: every service this shop calls out to, and which of them anybody is watching.

    Two halves that must be read together. The top half reports what was measured; the bottom half
    names every outbound seam in the codebase whether or not anything measured it. Left to itself the
    top half is the more dangerous of the two — a page listing three integrations for a shop that
    calls out to thirteen looks complete, and the ten it omits are the ten nobody thinks to check.

    Silence leads, not failure rate. A gateway with no failures and a gateway that stopped being
    called produce the same two numbers, zero out of zero, and only one of them is a working
    checkout — so a service with no calls is never drawn as a healthy 0%, and the services that went
    quiet since the previous window are listed by name above the table rather than absent from it.

    The recorder banner is drawn before any count because on this build nothing writes the dependency
    table at all. Under that banner, "0 failed calls" is a reading of the instrumentation.
--}}

@php
    $window = $panel['window'];
    $recorder = $panel['recorder'];
    $services = $panel['services'];
    $filters = $panel['filters'];
    $operations = $panel['operations'];
    $timeline = $panel['timeline'];
    $catalogue = $panel['catalogue'];
    $probes = $panel['probes'];
    $unmeasured = $panel['unmeasured'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Trailing zeros removed so a latency of exactly two hundred milliseconds is not "200.0 ms".
    $trim = static fn (float $value, int $decimals) => rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');

    $ms = static fn ($value) => $value === null ? null : $trim((float) $value, 1) . ' ms';

    // A rate that rounds to zero is not a clean service: one failure in fifty thousand calls is
    // 0.002%, and printing "0%" beside "1 failed" contradicts the number next to it.
    $pct = static function ($value) use ($trim) {
        if ($value === null) {
            return null;
        }
        $value = (float) $value;

        return ($value > 0 && $value < 0.01 ? '< 0.01' : $trim($value, 2)) . '%';
    };

    $elapsed = static function ($seconds) {
        if ($seconds === null) {
            return null;
        }
        $seconds = (int) $seconds;
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' m';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h';
        }

        return intdiv($seconds, 86400) . ' d';
    };

    // Scored against the same two lines the Overview dependency cards use, so one service cannot be
    // green on one page and red on another.
    $failureTone = static fn ($rate) => match (true) {
        $rate === null => 'mon-pill--unknown',
        $rate >= 10 => 'mon-pill--critical',
        $rate >= 2 => 'mon-pill--warning',
        default => 'mon-pill--healthy',
    };

    $recorderTone = match ($recorder['state']) {
        'ok' => null,
        'failed', 'no_data' => 'mon-attention__item--warning',
        default => 'mon-attention__item--critical',
    };

    // Panel-authored vocabulary only. A value read out of a column must never reach translate():
    // it persists any key it has not seen into new-messages.php, so one unrecognised service name
    // would mint a language key per value.
    $categories = ['payments', 'shipping', 'messaging', 'auth', 'maps', 'ai', 'platform'];
    $category = static fn (string $value) => in_array($value, $categories, true) ? translate($value) : $value;

    // Both forms are fixed keys chosen here, never a sentence composed and then translated.
    $plural = static fn ($value, string $one, string $many) => (int) $value === 1 ? $one : $many;

    // Column gaps that share a reason are stated once between them. Three columns empty because one
    // line is missing from the writer is one fact, and three identical paragraphs under the table
    // read as three separate faults.
    $columnGaps = [];
    foreach ($services['columns'] as $column => $gap) {
        $key = $gap['state'] . '|' . ($gap['note'] ?? '') . '|' . ($gap['remedy'] ?? '');
        $columnGaps[$key]['gap'] = $gap;
        $columnGaps[$key]['columns'][] = $column;
    }

    $clearUrl = route('admin.monitoring.section', ['section' => 'integrations', 'range' => $range]);
@endphp

{{-- Before any count: whether an outbound call can be recorded at all. A zero underneath means two
     completely different things depending on this block, and only one of them is good news. --}}
<div class="mon-attention">
    @if ($recorderTone !== null)
        <div class="mon-attention__item {{ $recorderTone }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    @if ($recorder['ever_recorded'] === false)
                        {{ translate('no_outbound_call_has_ever_been_recorded_on_this_deployment') }}
                    @else
                        {{ translate('outbound_call_recording_is_not_currently_arriving') }}
                    @endif
                </strong>
                <small>{{ $recorder['note'] ?? $stateTitle($recorder['state']) }}</small>
                {{-- The pipeline reading is what turns "monitoring is broken" into "one producer is
                     missing", and those are two different files to open. --}}
                <small>{{ $recorder['pipeline']['note'] }}</small>
                <small>
                    {{ translate('newest_dependency_bucket') }}:
                    {{ $recorder['newest_bucket_at'] ?? translate('never') }}
                    @if ($recorder['newest_bucket_at'])
                        ({{ $window['timezone'] }})
                    @endif
                    — {{ translate('newest_request_bucket') }}:
                    {{ $recorder['pipeline']['newest_bucket_at'] ?? translate('never') }}
                </small>
                @if (!empty($recorder['remedy']))
                    <code>{{ $recorder['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    {{-- Drawn whatever the state, because it is the definition of the table below and not a fault. --}}
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="external" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_counts_as_an_integration_here') }}</strong>
            <small>{{ translate('the_table_below_reports_calls_this_application_made_outward_and_a_service_appears_in_it_only_once_something_records_those_calls') }}</small>
            <small>{{ translate('the_catalogue_further_down_names_every_outbound_seam_in_this_codebase_whether_or_not_it_is_recorded_so_a_service_missing_from_the_table_can_be_told_apart_from_a_service_nobody_measures') }}</small>
            <code>{{ $recorder['writer'] }}</code>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a number that could not be read is never a zero. --}}
<x-k.card :title="translate('integrations_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="external" :title="$stateTitle($services['state'])" :text="$services['note'] ?? ''" />
    @endif

    <p class="mon-note">
        {{ translate('counts_and_rates_cover') }} {{ $window['since'] }} → {{ $window['until'] }}
        ({{ $window['timezone'] }}), {{ translate('read_at') }} <code>{{ $window['resolution'] }}</code>
        {{ translate('resolution') }}.
        {{ translate('a_service_with_no_calls_has_no_failure_rate_the_cell_says_so_rather_than_showing_zero_per_cent') }}.
    </p>
</x-k.card>

{{-- The main table. Ordered worst-first, and the last-success column is the one to read before the
     failure rate: a service that stopped being called has neither failures nor a problem visible
     anywhere else on this dashboard. --}}
<x-k.card :title="translate('outbound_services_in_this_window')">
    @if ($services['state'] === 'ok' && !empty($services['rows']))
        @if (!empty($services['silent']))
            {{-- Listed above the table, not inside it: these services have no row in this window at
                 all, and leaving them out would make the page agree with the outage. --}}
            <div class="mon-attention">
                <div class="mon-attention__item mon-attention__item--critical">
                    <x-k.icon name="alert" :size="16" />
                    <span class="mon-attention__body">
                        <strong>{{ translate('these_services_were_being_called_and_have_gone_quiet') }}</strong>
                        <small>
                            @foreach ($services['silent'] as $silent)
                                <code>{{ $silent['service'] }}</code>
                                ({{ $count($silent['calls_previous']) }} {{ $plural($silent['calls_previous'], translate('call'), translate('calls')) }} {{ translate('in_the_previous_window') }}){{ $loop->last ? '' : ',' }}
                            @endforeach
                        </small>
                        <small>{{ translate('zero_calls_and_zero_failures_are_the_same_two_numbers_as_a_perfectly_healthy_service') }}</small>
                    </span>
                </div>
            </div>
        @endif

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('service') }}</th>
                    <th class="k-table__num">{{ translate('calls') }}</th>
                    <th class="k-table__num">{{ translate('failures') }}</th>
                    <th class="k-table__num">{{ translate('failure_rate') }}</th>
                    <th class="k-table__num">{{ translate('average') }}</th>
                    <th class="k-table__num">{{ translate('p95') }}</th>
                    <th class="k-table__num">{{ translate('slowest') }}</th>
                    <th>{{ translate('last_successful_call') }}</th>
                    <th class="k-table__num">{{ translate('previous_window') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($services['rows'] as $service)
                    <tr>
                        <td>
                            <code>{{ $service['service'] }}</code>
                            <small class="mon-metric__note" style="display:block">
                                {{ $count($service['operations']) }} {{ $plural($service['operations'], translate('operation'), translate('operations')) }}
                                @if ($service['timeouts'] > 0)
                                    — {{ $count($service['timeouts']) }} {{ translate('timed_out') }}
                                @endif
                                @if ($service['rate_limited'] > 0)
                                    — {{ $count($service['rate_limited']) }} {{ translate('rate_limited') }}
                                @endif
                            </small>
                        </td>
                        <td class="k-table__num k-num">{{ $count($service['calls']) }}</td>
                        <td class="k-table__num k-num">
                            {{ $count($service['failures']) }}
                            @if ($service['server_errors'] > 0 || $service['client_errors'] > 0)
                                <small class="mon-metric__note" style="display:block">
                                    {{ $count($service['server_errors']) }} {{ translate('server') }} /
                                    {{ $count($service['client_errors']) }} {{ translate('client') }}
                                </small>
                            @endif
                        </td>
                        <td class="k-table__num">
                            @if ($service['failure_rate'] === null)
                                {{-- Not 0%. No call means no denominator, and a rate over nothing is
                                     not a clean service. --}}
                                <span class="mon-metric__state">{{ translate('no_calls') }}</span>
                            @else
                                <span class="mon-pill {{ $failureTone($service['failure_rate']) }}">{{ $pct($service['failure_rate']) }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $ms($service['avg_ms']) ?? '—' }}</td>
                        <td class="k-table__num k-num">
                            @if ($service['p95_ms'] !== null)
                                {{ $ms($service['p95_ms']) }}
                            @else
                                <span class="mon-metric__state">{{ $stateTitle($service['latency_state']) }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $ms($service['max_ms']) ?? '—' }}</td>
                        <td class="k-num">
                            @if ($service['last_success_at'])
                                {{ $service['last_success_at'] }}
                            @elseif ($service['last_ok_bucket_at'])
                                {{-- The derived substitute for a column nothing writes. Marked as
                                     bucket-granular so it is never mistaken for the instant of a
                                     call: it is the start of a bucket at the window's resolution. --}}
                                {{ $service['last_ok_bucket_at'] }}
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('bucket_containing_a_successful_call') }},
                                    {{ $elapsed($service['last_ok_bucket_age_seconds']) }} {{ translate('ago') }}
                                </small>
                            @else
                                <span class="mon-metric__state">{{ translate('no_successful_call_in_this_window') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($service['calls_previous'] === null)
                                <span class="mon-metric__state">{{ translate('no_data') }}</span>
                            @else
                                {{ $count($service['calls_previous']) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        {{-- One gap, stated once. The same empty cells on every row are the same missing line in
             BucketWriter repeated, and repeated it reads as many faults instead of one. --}}
        @foreach ($columnGaps as $group)
            <p class="mon-note">
                @foreach ($group['columns'] as $column)
                    <code>{{ $column }}</code>{{ $loop->last ? '' : ', ' }}
                @endforeach
                — {{ $stateTitle($group['gap']['state']) }}: {{ $group['gap']['note'] }}
            </p>
            @if (!empty($group['gap']['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $group['gap']['remedy'] }}</code>
                </details>
            @endif
        @endforeach

        <p class="mon-note">
            {{ count($services['rows']) }}
            @if ($services['truncated'])
                {{ $plural(count($services['rows']), translate('service'), translate('services')) }} {{ translate('listed_this_window_holds_more_than_are_shown') }}
            @else
                {{ $plural(count($services['rows']), translate('service'), translate('services')) }} {{ translate('recorded_a_call_in_this_window') }}
            @endif
            — {{ $count($services['totals']['calls']) }} {{ translate('calls') }},
            {{ $count($services['totals']['failures']) }} {{ translate('failed') }},
            {{ $pct($services['totals']['failure_rate']) ?? translate('no_data') }} {{ translate('overall') }}.
            @if ($services['comparison']['state'] !== 'ok')
                {{ translate('the_previous_window_could_not_be_read_so_no_service_can_be_reported_as_having_gone_quiet') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="external" :title="$stateTitle($services['state'])" :text="$services['note'] ?? ''" />
        @if (!empty($services['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $services['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

<x-k.card :title="translate('outbound_calls_over_time')">
    @if ($timeline['state'] === 'ok')
        <div class="mon-chart" data-mon-chart='@json(['points' => $timeline['points']])'></div>
        <p class="mon-note">
            {{ count($timeline['points']) }} {{ $plural(count($timeline['points']), translate('bucket'), translate('buckets')) }} {{ translate('at') }} <code>{{ $window['resolution'] }}</code>
            {{ translate('resolution') }} — {{ translate('the_line_is_calls_the_second_line_is_failures') }}.
            @if ($timeline['truncated'])
                {{ translate('this_window_holds_more_buckets_than_the_chart_draws') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($timeline['state'])" :text="$timeline['note'] ?? ''" />
    @endif
</x-k.card>

{{-- Operation, not just service: "Stripe is failing" and "Stripe's refund endpoint is failing while
     charges are fine" are different things to page somebody about. --}}
<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="service" class="k-select" aria-label="{{ translate('service') }}">
                <option value="all" @selected($filters['service'] === 'all')>{{ translate('any_service') }}</option>
                @foreach ($filters['available'] as $available)
                    <option value="{{ $available }}" @selected($filters['service'] === $available)>{{ $available }}</option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    <div class="k-card__body">
        <h3 class="mon-heading">{{ translate('calls_by_operation') }}</h3>

        @if ($operations['state'] === 'ok' && !empty($operations['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('service') }}</th>
                        <th>{{ translate('operation') }}</th>
                        <th class="k-table__num">{{ translate('calls') }}</th>
                        <th class="k-table__num">{{ translate('failures') }}</th>
                        <th class="k-table__num">{{ translate('failure_rate') }}</th>
                        <th class="k-table__num">{{ translate('average') }}</th>
                        <th class="k-table__num">{{ translate('slowest') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($operations['rows'] as $operation)
                        <tr>
                            <td><code>{{ $operation['service'] }}</code></td>
                            <td>
                                @if ($operation['operation'] === null)
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    <code>{{ $operation['operation'] }}</code>
                                @endif
                                @if ($operation['folded'])
                                    <small class="mon-metric__note" style="display:block">{{ translate('the_writer_folds_an_unbounded_operation_tail_into_this_one_row') }}</small>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $count($operation['calls']) }}</td>
                            <td class="k-table__num k-num">{{ $count($operation['failures']) }}</td>
                            <td class="k-table__num">
                                @if ($operation['failure_rate'] === null)
                                    <span class="mon-metric__state">{{ translate('no_calls') }}</span>
                                @else
                                    <span class="mon-pill {{ $failureTone($operation['failure_rate']) }}">{{ $pct($operation['failure_rate']) }}</span>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $ms($operation['avg_ms']) ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $ms($operation['max_ms']) ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mon-note">
                {{ count($operations['rows']) }}
                @if ($operations['truncated'])
                    {{ $plural(count($operations['rows']), translate('operation'), translate('operations')) }} {{ translate('listed_this_window_holds_more_than_are_shown') }}
                @else
                    {{ $plural(count($operations['rows']), translate('operation'), translate('operations')) }} {{ translate('recorded_in_this_window') }}
                @endif
                — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            </p>
        @else
            <x-k.empty icon="orders" :title="$stateTitle($operations['state'])" :text="$operations['note'] ?? ''" />
            @if ($filters['narrowed'])
                <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
            @endif
        @endif
    </div>
</x-k.card>

{{-- The centre of the page while nothing is recorded, and still the honest frame once something is:
     a dependency that is never called cannot appear in a table of calls, so its absence there says
     nothing until this list says whether anybody was watching it. --}}
<x-k.card :title="translate('every_outbound_dependency_in_this_codebase')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('dependency') }}</th>
                <th>{{ translate('area') }}</th>
                <th>{{ translate('switched_on') }}</th>
                <th>{{ translate('measured') }}</th>
                <th>{{ translate('where_the_call_is_made') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($catalogue['rows'] as $dependency)
                <tr class="{{ $dependency['recorded'] ? '' : 'mon-row--muted' }}">
                    <td>
                        <strong>{{ $dependency['name'] }}</strong>
                        <small class="mon-metric__note" style="display:block">{{ $dependency['detail'] }}</small>
                    </td>
                    <td>{{ $category($dependency['category']) }}</td>
                    <td>
                        @if ($dependency['enabled'] === true)
                            <span class="mon-pill mon-pill--ok">{{ translate('yes') }}</span>
                        @elseif ($dependency['enabled'] === false)
                            <span class="mon-pill mon-pill--unknown">{{ translate('no') }}</span>
                        @else
                            {{-- Three-valued on purpose. "Switched off" and "whether it is switched
                                 on cannot be read" order the work differently. --}}
                            <span class="mon-metric__state">{{ translate('unknown') }}</span>
                        @endif
                        @if ($dependency['enabled_detail'])
                            <small class="mon-metric__note" style="display:block">{{ $dependency['enabled_detail'] }}</small>
                        @endif
                        @if ($dependency['enabled_source'])
                            <small class="mon-metric__source" style="display:block">{{ $dependency['enabled_source'] }}</small>
                        @endif
                    </td>
                    <td>
                        @if ($dependency['recorded'])
                            <span class="mon-pill mon-pill--healthy">{{ translate('yes') }}</span>
                            <small class="mon-metric__note" style="display:block">
                                @foreach ($dependency['recorded_as'] as $recordedAs)
                                    <code>{{ $recordedAs }}</code>{{ $loop->last ? '' : ', ' }}
                                @endforeach
                            </small>
                        @else
                            <span class="mon-pill mon-pill--critical">{{ translate('no') }}</span>
                            <small class="mon-metric__note" style="display:block">{{ $dependency['recorder'] }}</small>
                        @endif
                    </td>
                    <td>
                        <code>{{ $dependency['seam'] }}</code>
                        <small class="mon-metric__note" style="display:block">{{ $dependency['client'] }}</small>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <p class="mon-note {{ $catalogue['totals']['unmeasured'] > 0 ? 'mon-note--critical' : '' }}">
        {{ $count($catalogue['totals']['dependencies']) }} {{ translate('outbound_dependencies_are_known_to_this_page') }},
        {{ $count($catalogue['totals']['instrumented']) }} {{ translate('of_them_recorded_a_call_in_this_window') }},
        {{ $count($catalogue['totals']['unmeasured']) }} {{ translate('did_not') }}.
        @if ($catalogue['totals']['enabled_and_unmeasured'] > 0)
            {{ $count($catalogue['totals']['enabled_and_unmeasured']) }}
            {{ translate('of_the_unmeasured_ones_are_switched_on_in_the_shop_settings_and_are_therefore_being_called_right_now_with_nobody_watching') }}.
        @endif
    </p>

    @if (($catalogue['settings']['state'] ?? 'ok') !== 'ok')
        <p class="mon-note mon-note--critical">
            {{ translate('the_shop_settings_could_not_be_read_so_the_switched_on_column_is_unanswered') }}:
            {{ $catalogue['settings']['note'] ?? '' }}
        </p>
    @endif

    <p class="mon-note">{{ $catalogue['matching'] }}</p>

    @if (!empty($catalogue['remedy']))
        <details class="mon-metric__remedy">
            <summary>{{ translate('how_to_enable_this') }}</summary>
            <code>{{ $catalogue['remedy'] }}</code>
        </details>
    @endif
</x-k.card>

{{-- The one class of outbound call this deployment does record, and it is recorded elsewhere. Drawn
     so that "no outbound calls recorded" cannot be read as "this server makes no outbound calls". --}}
<x-k.card :title="translate('outbound_probes_recorded_elsewhere')">
    @if ($probes['state'] === 'ok' && !empty($probes['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th>{{ translate('kind') }}</th>
                    <th class="k-table__num">{{ translate('runs') }}</th>
                    <th class="k-table__num">{{ translate('failing') }}</th>
                    <th class="k-table__num">{{ translate('success_rate') }}</th>
                    <th class="k-table__num">{{ translate('average') }}</th>
                    <th>{{ translate('last_checked') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($probes['rows'] as $probe)
                    <tr class="{{ $probe['failing_runs'] > 0 ? '' : 'mon-row--muted' }}">
                        <td><code>{{ $probe['check_key'] }}</code></td>
                        <td><code>{{ $probe['kind'] }}</code></td>
                        <td class="k-table__num k-num">{{ $count($probe['runs']) }}</td>
                        <td class="k-table__num k-num">{{ $count($probe['failing_runs']) }}</td>
                        <td class="k-table__num k-num">{{ $pct($probe['success_rate']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $ms($probe['avg_ms']) ?? '—' }}</td>
                        <td class="k-num">{{ $probe['last_checked_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('these_are_monitorings_own_probes_they_are_outbound_calls_and_they_are_recorded_into') }}
            <code>monitoring_check_results</code>,
            {{ translate('not_into_the_dependency_buckets_the_rest_of_this_page_reads') }}.
            @if ($probes['truncated'])
                {{ translate('this_window_holds_more_checks_than_are_listed') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="check" :title="$stateTitle($probes['state'])" :text="$probes['note'] ?? ''" />
        @if (!empty($probes['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $probes['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Not measurements this page chose to leave out — measurements nothing on this deployment takes.
     Drawn as readings with their reason so each gap is a task rather than an empty chart somebody
     reads as a measurement that came back flat. --}}
<x-k.card :title="translate('what_this_build_does_not_measure_about_outbound_calls')">
    <div class="mon-grid">
        @foreach ($unmeasured['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $unmeasured['note'] }}</p>
</x-k.card>

<p class="mon-note">
    {{ translate('call_volume_failures_and_latency_are_read_from') }} <code>monitoring_dependency_buckets</code>,
    {{ translate('written_only_by') }} <code>{{ $recorder['writer'] }}</code>
    {{ translate('when_the_buffer_is_drained_by') }} <code>php artisan monitoring:flush</code>.
    {{ translate('percentiles_are_interpolated_from_the_stored_latency_histogram_rather_than_from_kept_samples') }}.
    {{ translate('the_switched_on_column_is_read_from') }} <code>addon_settings</code> {{ translate('and') }} <code>business_settings</code>;
    {{ translate('no_credential_value_is_ever_published_on_this_page') }}.
    {{ translate('all_timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>

{{-- The one outbound integration this shop owns rather than merely calls. It had no panel, no
     check, no series and no rule anywhere in monitoring, so a seller whose endpoint the platform
     had switched off after a day of failures was visible only if an admin opened another page. --}}
@php($webhooks = $panel['seller_webhooks'] ?? ['state' => 'not_installed'])
<x-k.card :title="translate('outbound_webhooks_to_sellers')">
    @if (($webhooks['state'] ?? '') === 'ok')
        <div class="k-stats">
            <x-k.stat :label="translate('endpoints')" :value="number_format($webhooks['endpoints'])" icon="plug" />
            <x-k.stat :label="translate('active')" :value="number_format($webhooks['active'])" icon="check" />
            <x-k.stat :label="translate('switched_off_by_us')" :value="number_format($webhooks['auto_disabled'])" icon="alert"
                      :caption="$webhooks['sellers_affected'] . ' ' . translate('sellers_affected')" />
            <x-k.stat :label="translate('waiting_to_retry')" :value="number_format($webhooks['waiting_to_retry'])" icon="clock" />
            <x-k.stat :label="translate('given_up_on')" :value="number_format($webhooks['failed'])" icon="warning-octagon" />
        </div>
        <p class="mon-note">
            {{ $webhooks['note'] }}
            {{ translate('the_retry_sweep_is') }} <code>{{ $webhooks['retry_sweep'] }}</code>.
            <code>{{ $webhooks['source'] }}</code>
        </p>
    @else
        <x-k.empty icon="plug" :title="translate('seller_webhook_delivery_could_not_be_read')"
                   :text="$webhooks['note'] ?? ''" />
    @endif
</x-k.card>
