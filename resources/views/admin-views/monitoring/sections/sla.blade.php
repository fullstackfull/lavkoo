{{--
    SLA and uptime: availability, the error budget it spends, and how long incidents took.

    The definition comes before the number, and takes the full width, because this is the one page
    on the dashboard whose headline figure is routinely read as something it is not. "99.4%
    available" here means the probes that ran said the component answered. It does not mean
    customers could shop — unless a synthetic journey is configured, nothing on this deployment ever
    tries to. Printing the percentage above that sentence would be printing a claim.

    Three success rates live on this page and none of them is an average of the others: probe
    availability, the shop's own per-route error rate, and the per-dependency failure rate of calls
    this application made outward. They are drawn as three separate tables on purpose. A page that
    folded them into one number would be able to hide a payment gateway failing one call in twenty
    behind a database that answered every ping.
--}}

@php
    $basis = $panel['basis'];
    $window = $panel['window'];
    $availability = $panel['availability'];
    $excluded = $panel['excluded'];
    $objective = $panel['objective'];
    $incidents = $panel['incidents'];
    $routes = $panel['routes'];
    $dependencies = $panel['dependencies'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);
    $pct = static fn ($value) => $value === null ? null : rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.') . '%';
    $ms = static fn ($value) => $value === null ? null : number_format((float) $value, 1) . ' ms';

    // Bars are drawn inside a fixed track, so a value outside 0..100 would render past its own
    // container rather than being visibly wrong. Clamped for geometry only — the printed figure
    // beside it is always the measurement.
    $bar = static fn ($value) => $value === null ? 0 : max(0, min(100, (float) $value));

    $levelTone = static fn (?string $level) => match ($level) {
        'critical' => 'mon-usage__fill--critical',
        'degraded' => 'mon-usage__fill--degraded',
        'healthy' => 'mon-usage__fill--healthy',
        default => 'mon-usage__fill--unscored',
    };

    $levelPill = static fn (?string $level) => match ($level) {
        'critical' => 'mon-pill--critical',
        'degraded' => 'mon-pill--warning',
        'healthy' => 'mon-pill--healthy',
        default => 'mon-pill--unknown',
    };

    // Seconds are exact and unreadable past an hour. The stored figure is never rounded away — it
    // is the title on the cell, so the precise number is one hover from the summary.
    $elapsed = static function ($seconds) {
        if ($seconds === null) {
            return null;
        }
        $seconds = (float) $seconds;
        if ($seconds < 90) {
            return number_format($seconds, 1) . ' ' . translate('seconds');
        }
        if ($seconds < 5400) {
            return number_format($seconds / 60, 1) . ' ' . translate('minutes');
        }

        return number_format($seconds / 3600, 1) . ' ' . translate('hours');
    };

    $minutes = static function ($value) use ($count) {
        if ($value === null) {
            return null;
        }
        // A budget of a fifth of a probe is a real budget. Rounding it to a whole minute prints
        // "0 minutes" next to a figure that is not zero, which is the one thing this page may not do.
        if ($value < 10) {
            return rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . ' ' . translate('minutes');
        }
        if ($value < 120) {
            return $count($value) . ' ' . translate('minutes');
        }

        return number_format((float) $value / 60, 1) . ' ' . translate('hours');
    };

    // A stored status is a free string at the database level, and translate() persists any key it
    // has not already seen. Only values the panel published as its own vocabulary are translated.
    $vocabulary = static fn (?string $value, array $allowed) => $value === null
        ? '—'
        : (in_array($value, $allowed, true) ? translate($value) : $value);

    $scored = $objective['state'] === 'ok';
@endphp

{{-- The denominator, before the percentage that rests on it. Drawn first and always: an
     availability figure whose basis is a footnote is an availability figure that will be quoted
     without it. --}}
<div class="mon-attention">
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('availability_here_means_the_checks_that_ran_said_the_component_answered_it_does_not_mean_customers_could_shop') }}</strong>
            <small>
                {{ translate('one_probe_every') }} {{ $basis['probe_interval_minutes'] }}
                {{ translate('minutes_writes_a_1_when_the_check_returned') }}
                <code>{{ implode(', ', $basis['up_statuses']) }}</code>
                {{ translate('and_a_0_when_it_returned') }}
                <code>{{ implode(', ', $basis['down_statuses']) }}</code>.
            </small>
            <small>
                {{ translate('a_check_that_could_not_run_here') }}
                (<code>{{ implode(', ', $basis['excluded_statuses']) }}</code>)
                {{ translate('is_neither_up_nor_down_and_is_left_out_of_the_denominator_entirely_rather_than_counted_as_100_percent') }}
                — {{ translate('the_same_rule_the_probe_runner_applies_when_it_writes_the_series') }}
                (<code>{{ $basis['rule_source'] }}</code>).
            </small>
            @if ($basis['journey_in_denominator'] === true)
                <small>{{ translate('a_synthetic_customer_journey_is_among_the_checks_below_so_part_of_this_figure_does_describe_a_page_a_customer_would_load') }}.</small>
            @elseif ($basis['journey_in_denominator'] === false)
                <small>{{ translate('no_synthetic_customer_journey_contributed_to_this_window_so_this_figure_is_about_components_answering_probes_and_nothing_on_it_exercised_a_checkout') }}.</small>
            @endif
            @if ($basis['beyond_raw_history'])
                <small>
                    {{ translate('this_range_reaches_past_the_raw_probe_history_which_is_kept_for') }}
                    {{ $basis['raw_history_days'] }} {{ translate('days') }} —
                    {{ translate('figures_beyond_that_are_computed_from_the_daily_rollup_kept_for') }}
                    {{ $basis['rolled_history_days'] }} {{ translate('days') }}.
                </small>
            @endif
        </span>
    </div>

    @if ($availability['state'] !== 'ok')
        {{-- Said once, here, rather than repeated under every table it emptied. --}}
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('no_availability_could_be_computed_for_this_window') }}</strong>
                <small>{{ $availability['note'] ?? $stateTitle($availability['state']) }}</small>
                <small>{{ translate('this_is_the_absence_of_a_measurement_it_is_not_a_reading_of_zero_uptime_and_not_a_reading_of_full_uptime') }}.</small>
                @if (!empty($availability['remedy']))
                    <code>{{ $availability['remedy'] }}</code>
                @endif
            </span>
        </div>
    @elseif (!empty($availability['discrepancy']))
        {{-- Two independent records of the same probes disagreeing. An unexplained shortfall in an
             availability figure is indistinguishable from good news, so it is stated rather than
             absorbed. --}}
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_two_records_of_these_probes_do_not_agree') }}</strong>
                <small>
                    {{ translate('availability_series') }}: {{ $count($availability['discrepancy']['series_probes']) }}
                    {{ translate('probes') }} · {{ translate('check_history') }}:
                    {{ $count($availability['discrepancy']['recorded_results']) }} {{ translate('probes') }}.
                </small>
                <small>{{ $availability['discrepancy']['note'] }}</small>
                <code>{{ $availability['discrepancy']['remedy'] }}</code>
            </span>
        </div>
    @endif
</div>

{{-- The figures, each rendering its own state so a percentage that could not be computed is never
     drawn as a number. --}}
<x-k.card :title="translate('sla_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($availability['state'])" :text="$availability['note'] ?? ''" />
    @endif

    <p class="mon-note">
        {{ translate('window') }}: {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}),
        {{ translate('read_from') }} {{ $vocabulary($window['resolution'], ['minute', 'hour', 'day']) }} {{ translate('rows') }}.
        {{ translate('coverage_compares_the_probes_recorded_against_the') }}
        {{ $basis['probe_interval_minutes'] }} {{ translate('minute_cadence') }} —
        {{ translate('a_shortfall_is_a_gap_in_watching_not_a_gap_in_uptime') }}.
    </p>
    @if ($availability['state'] === 'ok' && $availability['folded'] && $availability['seam_at'])
        <p class="mon-note">
            {{ translate('buckets_before') }} {{ $availability['seam_at'] }} {{ translate('are_read_from_the_folded') }}
            {{ $vocabulary($availability['resolution'], ['minute', 'hour', 'day']) }} {{ translate('rows_and_everything_from_that_moment_on_is_read_from_the_minute_rows_directly_so_nothing_is_counted_twice_and_nothing_falls_between') }}
            @if ($availability['tail_probes'] !== null)
                — {{ $count($availability['tail_probes']) }} {{ translate('probes_came_from_that_tail') }}.
            @endif
        </p>
    @endif
</x-k.card>

{{-- Worst first. Somebody opens this page to find what was down, and a table in alphabetical order
     puts the healthy rows above the one that matters. --}}
<x-k.card :title="translate('availability_per_check')">
    @if ($availability['state'] === 'ok' && !empty($availability['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th>{{ translate('availability_in_window') }}</th>
                    <th class="k-table__num">{{ translate('probes') }}</th>
                    <th class="k-table__num">{{ translate('up') }}</th>
                    <th class="k-table__num">{{ translate('down') }}</th>
                    <th class="k-table__num">{{ translate('probe_coverage') }}</th>
                    <th>{{ translate('last_probe') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($availability['rows'] as $row)
                    <tr>
                        <td>
                            <code>{{ $row['check'] }}</code>
                            @if ($row['is_journey'])
                                <small class="mon-metric__note" style="display:block">{{ translate('a_scripted_customer_journey_not_a_component_probe') }}</small>
                            @endif
                        </td>
                        <td>
                            <span class="mon-usage__track">
                                <span class="mon-usage__fill {{ $levelTone($row['level']) }}"
                                      style="inline-size: {{ $bar($row['availability_pct']) }}%"></span>
                            </span>
                            <small class="mon-metric__note" style="display:block">
                                <span class="k-num">{{ $pct($row['availability_pct']) }}</span>
                                @if ($row['target_pct'] !== null)
                                    · {{ translate('target') }} <span class="k-num">{{ $pct($row['target_pct']) }}</span>
                                @endif
                                @if ($row['one_probe_pct'] !== null)
                                    · {{ translate('one_probe_is_worth') }} {{ $pct($row['one_probe_pct']) }}
                                @endif
                            </small>
                        </td>
                        <td class="k-table__num k-num">{{ $count($row['probes']) }}</td>
                        <td class="k-table__num k-num">{{ $count($row['up']) }}</td>
                        <td class="k-table__num k-num">
                            @if ($row['down'] > 0)
                                <span class="mon-pill mon-pill--critical">{{ $count($row['down']) }}</span>
                                <small class="mon-metric__note" style="display:block"
                                       title="{{ translate('probes_multiplied_by_the_probe_interval_not_observed_downtime') }}">
                                    ≈ {{ $minutes($row['down_minutes_at_probe_interval']) }}
                                </small>
                            @else
                                {{ $count($row['down']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            {{ $row['coverage_pct'] === null ? '—' : $pct($row['coverage_pct']) }}
                            <small class="mon-metric__note" style="display:block">
                                {{ $count($row['probes']) }} / {{ $count($row['expected_probes']) }}
                            </small>
                        </td>
                        <td class="k-num">
                            {{ $row['last_probe_at'] ?? '—' }}
                            @if ($row['first_probe_at'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('first') }}: {{ $row['first_probe_at'] }}
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($availability['totals']['samples']) }} {{ translate('probes_of') }}
            {{ $count($availability['totals']['checks']) }} {{ translate('checks') }},
            {{ $count($availability['totals']['up']) }} {{ translate('up_and') }}
            {{ $count($availability['totals']['down']) }} {{ translate('down_pooled_to') }}
            {{ $pct($availability['totals']['availability_pct']) }}.
            {{ translate('read_from') }} <code>{{ $availability['source'] }}</code>.
        </p>
        @unless ($scored)
            <p class="mon-note">{{ translate('no_objective_is_stored_so_every_bar_above_is_drawn_neutral_a_colour_here_would_be_a_verdict_against_a_target_this_page_invented') }}.</p>
        @endunless
        @if ($availability['truncated'])
            <p class="mon-note">{{ translate('more_checks_reported_probes_in_this_window_than_this_page_lists_so_the_pooled_figure_covers_only_the_checks_shown') }}.</p>
        @endif
    @else
        <x-k.empty icon="reports" :title="$stateTitle($availability['state'])" :text="$availability['note'] ?? ''" />
        @if (!empty($availability['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $availability['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($availability['resolutions_present']))
            <p class="mon-note">
                {{ translate('probes_recorded_in_this_window_by_resolution') }}:
                @foreach ($availability['resolutions_present'] as $resolution => $probes)
                    {{ $vocabulary($resolution, ['minute', 'hour', 'day']) }} {{ $count($probes) }}{{ $loop->last ? '' : ',' }}
                @endforeach
            </p>
        @endif
    @endif
</x-k.card>

{{-- The rule that makes the number above defensible, shown as the rows it removed. "Excluded" and
     "0% available" are opposite claims about a component, and a row that vanishes without being
     named reads as the second. --}}
<x-k.card :title="translate('checks_left_out_of_the_denominator')">
    @if ($excluded['state'] === 'ok' && !empty($excluded['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th>{{ translate('kind') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('runs_in_window') }}</th>
                    <th>{{ translate('last_run') }}</th>
                    <th>{{ translate('what_it_said') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($excluded['rows'] as $row)
                    <tr class="mon-row--muted">
                        <td><code>{{ $row['check'] }}</code></td>
                        <td>{{ $vocabulary($row['kind'], $basis['check_kinds']) }}</td>
                        <td><span class="mon-pill mon-pill--unknown">{{ $vocabulary($row['status'], $basis['check_statuses']) }}</span></td>
                        <td class="k-table__num k-num">{{ $count($row['runs']) }}</td>
                        <td class="k-num">{{ $row['last_checked_at'] ?? '—' }}</td>
                        <td>{{ $row['detail'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $count($excluded['runs']) }}
            {{ translate('probe_runs_were_excluded_from_the_availability_figure_above_a_check_that_cannot_run_here_is_neither_up_nor_down_folding_it_in_as_either_would_make_an_uptime_figure_that_is_not_about_uptime') }}.
            {{ translate('read_from') }} <code>{{ $excluded['source'] }}</code>.
        </p>
        @if ($excluded['truncated'])
            <p class="mon-note">{{ translate('more_check_and_status_combinations_were_recorded_than_this_page_reads_so_this_list_is_not_complete') }}.</p>
        @endif
    @else
        <x-k.empty icon="check" :title="$stateTitle($excluded['state'])" :text="$excluded['note'] ?? ''" />
        @if (!empty($excluded['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $excluded['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The budget is counted in probes, because probes are what was observed. Minutes between two
     probes are not watched, so a budget spent in minutes would be spending time nobody measured. --}}
<x-k.card :title="translate('error_budget')">
    @if ($objective['state'] === 'ok' && !empty($objective['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('check') }}</th>
                    <th class="k-table__num">{{ translate('objective') }}</th>
                    <th class="k-table__num">{{ translate('measured') }}</th>
                    <th>{{ translate('budget_spent') }}</th>
                    <th class="k-table__num">{{ translate('failed_probes_allowed') }}</th>
                    <th class="k-table__num">{{ translate('failed_probes_used') }}</th>
                    <th>{{ translate('verdict') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($objective['rows'] as $row)
                    <tr>
                        <td><code>{{ $row['check'] }}</code></td>
                        <td class="k-table__num k-num">{{ $pct($row['target_pct']) }}</td>
                        <td class="k-table__num k-num">{{ $pct($row['availability_pct']) }}</td>
                        <td>
                            <span class="mon-usage__track">
                                <span class="mon-usage__fill {{ $levelTone($row['level']) }}"
                                      style="inline-size: {{ $bar($row['burn_pct']) }}%"></span>
                            </span>
                            <small class="mon-metric__note" style="display:block">
                                {{ $row['burn_pct'] === null ? translate('no_budget_to_spend') : $pct($row['burn_pct']) . ' ' . translate('of_the_budget') }}
                            </small>
                        </td>
                        <td class="k-table__num k-num">
                            {{ number_format((float) $row['budget_probes'], 2) }}
                            <small class="mon-metric__note" style="display:block"
                                   title="{{ translate('probes_multiplied_by_the_probe_interval_not_observed_downtime') }}">
                                ≈ {{ $minutes($row['budget_minutes_at_probe_interval']) }}
                            </small>
                        </td>
                        <td class="k-table__num k-num">{{ $count($row['burned_probes']) }}</td>
                        <td>
                            <span class="mon-pill {{ $levelPill($row['level']) }}">
                                {{ $row['met'] ? translate('met') : translate('breached') }}
                            </span>
                            @if ($row['budget_below_one_probe'])
                                {{-- The window cannot test the objective at all: the first failure
                                     overspends it and the first success reads as perfect. --}}
                                <small class="mon-note mon-note--critical" style="display:block">
                                    {{ translate('this_window_is_too_short_to_test_this_objective_its_entire_budget_is_less_than_one_probe') }}
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('the_budget_is_the_number_of_failed_probes_the_objective_allows_over_the_probes_this_window_actually_recorded') }}.
            {{ translate('the_minute_equivalents_are_that_probe_count_multiplied_by_the') }}
            {{ $objective['probe_interval_minutes'] }} {{ translate('minute_cadence') }} —
            {{ translate('a_translation_of_the_probe_count_never_a_second_measurement_of_it') }}.
        </p>
        @if (!empty($objective['unmatched']))
            <p class="mon-note">
                {{ translate('an_objective_is_stored_for_checks_that_reported_no_probe_in_this_window_so_they_are_not_rated_here') }}:
                {{ implode(', ', $objective['unmatched']) }}.
            </p>
        @endif
    @else
        <x-k.empty icon="settings" :title="$stateTitle($objective['state'])" :text="$objective['note'] ?? ''" />
        @if (!empty($objective['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $objective['remedy'] }}</code>
            </details>
        @endif
    @endif
    @if (!empty($objective['invalid']))
        <p class="mon-note mon-note--critical">
            {{ translate('these_stored_objectives_could_not_be_read_as_a_percentage_and_are_rating_nothing') }}:
            {{ implode(', ', $objective['invalid']) }}.
        </p>
    @endif
</x-k.card>

{{-- Both means carry the count they were taken over. A mean with no n beside it is not a
     statistic, and a mean over zero incidents is the absence of a measurement rather than an
     instant recovery. --}}
<x-k.card :title="translate('time_to_detect_and_time_to_recover')">
    @if ($incidents['state'] === 'ok')
        <div class="mon-grid">
            <div class="mon-metric {{ $incidents['mttd_seconds'] === null ? 'mon-metric--muted' : '' }}">
                <span class="mon-metric__label">
                    {{ translate('mean_time_to_detect') }}
                    <span class="mon-metric__hint" title="{{ $incidents['mttd_caveat'] }}">?</span>
                </span>
                @if ($incidents['mttd_seconds'] === null)
                    <span class="mon-metric__state">{{ translate('no_data') }}</span>
                    <span class="mon-metric__note">{{ translate('no_incident_in_this_window_carries_both_a_start_and_a_detection_time') }}</span>
                @else
                    <span class="mon-metric__value k-num">{{ $elapsed($incidents['mttd_seconds']) }}</span>
                    <span class="mon-metric__note">
                        {{ translate('averaged_over') }} {{ $count($incidents['mttd_samples']) }}
                        {{ translate('incidents') }}
                    </span>
                @endif
                <span class="mon-metric__source" title="{{ translate('where_this_number_came_from') }}">{{ $incidents['source'] }}</span>
            </div>

            <div class="mon-metric {{ $incidents['mttr_seconds'] === null ? 'mon-metric--muted' : '' }}">
                <span class="mon-metric__label">
                    {{ translate('mean_time_to_recover') }}
                    <span class="mon-metric__hint" title="{{ $incidents['mttr_definition'] }}">?</span>
                </span>
                @if ($incidents['mttr_seconds'] === null)
                    <span class="mon-metric__state">{{ translate('no_data') }}</span>
                    <span class="mon-metric__note">{{ translate('no_incident_that_started_in_this_window_has_closed_yet') }}</span>
                @else
                    <span class="mon-metric__value k-num">{{ $elapsed($incidents['mttr_seconds']) }}</span>
                    <span class="mon-metric__note">
                        {{ translate('averaged_over') }} {{ $count($incidents['mttr_samples']) }}
                        {{ translate('incidents') }} · {{ translate('longest') }} {{ $elapsed($incidents['longest_seconds']) }}
                    </span>
                @endif
                <span class="mon-metric__source" title="{{ translate('where_this_number_came_from') }}">{{ $incidents['source'] }}</span>
            </div>
        </div>

        <p class="mon-note">
            {{ $count($incidents['started']) }} {{ translate('incidents_started_in_this_window') }},
            {{ $count($incidents['resolved']) }} {{ translate('have_closed') }},
            {{ $count($incidents['still_open']) }} {{ translate('are_still_open') }}.
            @if ($incidents['undetected'] > 0)
                {{ $count($incidents['undetected']) }} {{ translate('carry_no_detection_time_and_are_left_out_of_the_first_mean') }}.
            @endif
            @if ($incidents['out_of_order'] > 0)
                {{ $count($incidents['out_of_order']) }}
                {{ translate('carry_a_duration_that_runs_backwards_and_are_excluded_rather_than_dragging_a_mean_below_zero') }}.
            @endif
        </p>
        <p class="mon-note">{{ $incidents['mttd_caveat'] }}</p>
        <p class="mon-note">{{ $incidents['opened_by'] }}</p>

        @if (!empty($incidents['rows']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('reference') }}</th>
                        <th>{{ translate('title') }}</th>
                        <th>{{ translate('severity') }}</th>
                        <th>{{ translate('status') }}</th>
                        <th>{{ translate('started') }}</th>
                        <th class="k-table__num">{{ translate('time_to_detect') }}</th>
                        <th class="k-table__num">{{ translate('time_to_recover') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($incidents['rows'] as $incident)
                        <tr>
                            <td><code>{{ $incident['reference'] }}</code></td>
                            <td>{{ $incident['title'] ?? '—' }}</td>
                            <td><span class="mon-pill mon-pill--{{ in_array($incident['severity'], $incidents['severities'], true) ? $incident['severity'] : 'unknown' }}">{{ $vocabulary($incident['severity'], $incidents['severities']) }}</span></td>
                            <td>{{ $vocabulary($incident['status'], $incidents['statuses']) }}</td>
                            <td class="k-num">{{ $incident['started_at'] ?? '—' }}</td>
                            <td class="k-table__num k-num">{{ $elapsed($incident['detect_seconds']) ?? '—' }}</td>
                            <td class="k-table__num k-num">
                                @if ($incident['resolved'])
                                    {{ $elapsed($incident['resolve_seconds']) ?? '—' }}
                                @else
                                    <span class="mon-metric__state">{{ translate('still_open') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($incidents['truncated'])
                <p class="mon-note">{{ translate('more_incidents_started_in_this_window_than_the_means_above_were_taken_over') }}.</p>
            @endif
        @endif
    @else
        <x-k.empty icon="alert" :title="$stateTitle($incidents['state'])" :text="$incidents['note'] ?? ''" />
        @if (!empty($incidents['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_read_this') }}</summary>
                <code>{{ $incidents['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">{{ $incidents['opened_by'] }}</p>
    @endif
</x-k.card>

{{-- A different measurement from probe availability, and never folded into it: this is what
     happened to real requests, and it can be poor while every probe was green. --}}
<x-k.card :title="translate('success_rate_by_route')">
    @if ($routes['state'] === 'ok' && !empty($routes['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('route') }}</th>
                    <th class="k-table__num">{{ translate('requests') }}</th>
                    <th class="k-table__num">{{ translate('server_errors') }}</th>
                    <th class="k-table__num">{{ translate('client_errors') }}</th>
                    <th class="k-table__num">{{ translate('success_rate') }}</th>
                    <th class="k-table__num">{{ translate('p95') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($routes['rows'] as $route)
                    <tr>
                        <td>
                            <code>{{ $route['method'] }} {{ $route['route'] }}</code>
                            <small class="mon-metric__note" style="display:block">{{ $route['channel'] }}</small>
                        </td>
                        <td class="k-table__num k-num">{{ $count($route['hits']) }}</td>
                        <td class="k-table__num k-num">
                            @if ($route['errors'] > 0)
                                <span class="mon-pill mon-pill--critical">{{ $count($route['errors']) }}</span>
                            @else
                                {{ $count($route['errors']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $count($route['client_errors']) }}</td>
                        <td class="k-table__num k-num">{{ $pct($route['success_rate']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $ms($route['p95']) ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $count($routes['totals']['hits']) }} {{ translate('requests_in_this_window') }},
            {{ $count($routes['totals']['errors']) }} {{ translate('of_them_server_errors') }} —
            {{ $pct($routes['totals']['success_rate']) }} {{ translate('overall') }}.
            {{ $routes['definition'] }}
            {{ translate('read_from') }} <code>{{ $routes['source'] }}</code>.
        </p>
        <p class="mon-note">
            {{ translate('this_is_not_the_same_measurement_as_the_availability_above_and_the_two_are_never_averaged_together_a_component_can_answer_every_probe_while_a_route_fails_every_request') }}.
        </p>
        @if ($routes['truncated'])
            <p class="mon-note">{{ translate('more_routes_were_served_in_this_window_than_are_listed_the_worst_are_shown_first') }}.</p>
        @endif
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($routes['state'])" :text="$routes['note'] ?? ''" />
        @if (!empty($routes['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $routes['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The third measurement, and again a distinct one: a gateway failing one call in twenty is
     invisible to a liveness probe and invisible in the shop's own error rate until a customer
     complains. --}}
<x-k.card :title="translate('success_rate_by_dependency')">
    @if ($dependencies['state'] === 'ok' && !empty($dependencies['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('service') }}</th>
                    <th class="k-table__num">{{ translate('calls') }}</th>
                    <th class="k-table__num">{{ translate('failures') }}</th>
                    <th class="k-table__num">{{ translate('timeouts') }}</th>
                    <th class="k-table__num">{{ translate('success_rate') }}</th>
                    <th class="k-table__num">{{ translate('average') }}</th>
                    <th>{{ translate('last_success') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($dependencies['rows'] as $service)
                    <tr>
                        <td><code>{{ $service['service'] }}</code></td>
                        <td class="k-table__num k-num">{{ $count($service['calls']) }}</td>
                        <td class="k-table__num k-num">
                            @if ($service['failures'] > 0)
                                <span class="mon-pill mon-pill--critical">{{ $count($service['failures']) }}</span>
                            @else
                                {{ $count($service['failures']) }}
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $count($service['timeouts']) }}</td>
                        <td class="k-table__num k-num">{{ $pct($service['success_rate']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $ms($service['avg_ms']) ?? '—' }}</td>
                        <td class="k-num">
                            {{ $service['last_success_at'] ?? '—' }}
                            @if ($service['last_failure_at'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('last_failure') }}: {{ $service['last_failure_at'] }}
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ $count($dependencies['totals']['calls']) }} {{ translate('outbound_calls_to') }}
            {{ $count($dependencies['totals']['services']) }} {{ translate('services') }},
            {{ $count($dependencies['totals']['failures']) }} {{ translate('of_them_failed') }} —
            {{ $pct($dependencies['totals']['success_rate']) }} {{ translate('overall') }}.
            {{ translate('read_from') }} <code>{{ $dependencies['source'] }}</code>.
        </p>
        @if ($dependencies['truncated'])
            <p class="mon-note">{{ translate('more_services_were_called_in_this_window_than_are_listed') }}.</p>
        @endif
    @else
        <x-k.empty icon="external" :title="$stateTitle($dependencies['state'])" :text="$dependencies['note'] ?? ''" />
        @if (!empty($dependencies['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $dependencies['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Normally empty. A reading a collector produces and this page draws nowhere is
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
    {{ translate('nothing_stores_an_uptime_percentage_on_this_deployment_every_figure_on_this_page_is_recomputed_from_measurements_taken_elsewhere') }}:
    <code>{{ $basis['series_source'] }}</code> {{ translate('for_availability') }},
    <code>{{ $basis['results_source'] }}</code> {{ translate('for_what_each_probe_said') }},
    <code>{{ $incidents['source'] }}</code> {{ translate('for_the_timings') }},
    <code>{{ $routes['source'] }}</code> {{ translate('and') }} <code>{{ $dependencies['source'] }}</code>
    {{ translate('for_the_two_success_rates') }}.
    {{ translate('the_process_uptime_of_mysql_and_redis_is_a_different_quantity_entirely_and_is_deliberately_not_shown_here') }}.
</p>
