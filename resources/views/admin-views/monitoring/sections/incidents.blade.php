{{--
    Incidents: the outages this system was able to notice.

    The word has a narrow meaning on this page and the page says so before it shows anything: an
    incident here is a correlation bucket for FIRING ALERT RULES inside a thirty-minute window.
    Nothing else opens one. An outage that broke no rule is not on the list, and an empty list is a
    statement about the alert rules rather than about the shop — which is why the detector banner is
    drawn above every count. Left implicit, "0 open incidents" is the most reassuring lie this
    dashboard could tell.

    The six columns nothing writes are drawn as unconfigured readings at the foot, never as empty
    cells in the tables: a blank "probable cause" reads as a cause that was looked for and not
    found, and no cause was ever looked for.
--}}

@php
    $window = $panel['window'];
    $definition = $panel['definition'];
    $detector = $panel['detector'];
    $filters = $panel['filters'];
    $options = $panel['options'];
    $open = $panel['open'];
    $history = $panel['history'];
    $resolution = $panel['resolution'];
    $events = $panel['events'];
    $unwritten = $panel['unwritten'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Trailing zeros removed so a mean of exactly two minutes is not printed as "2.0".
    $trim = static fn (float $value, int $decimals) => rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');

    $duration = static function ($seconds) use ($trim) {
        if ($seconds === null) {
            return null;
        }
        $seconds = (float) $seconds;
        if (abs($seconds) < 90) {
            return $trim($seconds, 1) . ' ' . translate('seconds');
        }
        if (abs($seconds) < 5400) {
            return $trim($seconds / 60, 1) . ' ' . translate('minutes');
        }
        if (abs($seconds) < 172800) {
            return $trim($seconds / 3600, 1) . ' ' . translate('hours');
        }

        return $trim($seconds / 86400, 1) . ' ' . translate('days');
    };

    $elapsed = static function ($minutes) {
        if ($minutes === null) {
            return null;
        }
        $minutes = (int) $minutes;
        if ($minutes < 60) {
            return $minutes . ' ' . translate('minutes');
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . ' ' . translate('hours');
        }

        return intdiv($minutes, 1440) . ' ' . translate('days');
    };

    $severityPill = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-pill--critical',
        'major', 'warning' => 'mon-pill--warning',
        'minor' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    $statusPill = static fn (string $status) => match ($status) {
        'open' => 'mon-pill--critical',
        'investigating' => 'mon-pill--warning',
        'monitoring' => 'mon-pill--running',
        'resolved' => 'mon-pill--healthy',
        default => 'mon-pill--unknown',
    };

    $eventTone = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-events__item--critical',
        'warning' => 'mon-events__item--warning',
        'success' => 'mon-events__item--success',
        default => '',
    };

    // A stored severity or status is only handed to translate() when it is one of the values this
    // build can write. translate() persists any key it has not seen into new-messages.php, so a
    // column that reached it would mint a language key per distinct value.
    $vocabulary = static fn (string $value, bool $known) => $known ? translate($value) : $value;

    // Values in the URL stay selectable even when the window holds none of them, so a shared link
    // does not quietly drop half its filter.
    $choices = static function (array $shipped, array $seen, string $current) {
        $all = array_merge($shipped, array_keys($seen));
        if ($current !== 'all') {
            $all[] = $current;
        }

        return array_values(array_unique(array_filter($all, static fn ($value) => $value !== 'open_only')));
    };

    $severityChoices = $choices($definition['severities_written'], $options['severities'], $filters['severity']);
    $statusChoices = $choices($definition['statuses_written'], $options['statuses'], $filters['status']);

    $clearUrl = route('admin.monitoring.section', ['section' => 'incidents', 'range' => $range]);

    $detectorTone = match ($detector['state']) {
        'ok' => null,
        'failed', 'no_data' => 'mon-attention__item--warning',
        default => 'mon-attention__item--critical',
    };
@endphp

{{-- Before any count: whether the machine that opens incidents is armed. A zero underneath means
     two completely different things depending on this block, and only one of them is good news. --}}
<div class="mon-attention">
    @if ($detectorTone !== null)
        <div class="mon-attention__item {{ $detectorTone }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    @if ($detector['can_open_incidents'] === false)
                        {{ translate('nothing_on_this_deployment_can_open_an_incident_right_now') }}
                    @else
                        {{ translate('whether_an_incident_could_be_opened_could_not_be_determined') }}
                    @endif
                </strong>
                <small>{{ $detector['note'] ?? $stateTitle($detector['state']) }}</small>
                <small>
                    {{ translate('alert_rules') }}:
                    @if ($detector['rules_enabled'] === null)
                        {{ translate('could_not_be_read') }}
                    @else
                        {{ $count($detector['rules_enabled']) }}/{{ $count($detector['rules_total']) }} {{ translate('enabled') }}
                    @endif
                    — {{ translate('last_evaluated') }}:
                    {{ $detector['last_evaluated_at'] ?? translate('never') }}
                    @if ($detector['last_evaluated_at']) ({{ $window['timezone'] }}) @endif
                </small>
                @if (!empty($detector['remedy']))
                    <code>{{ $detector['remedy'] }}</code>
                @endif
            </span>
        </div>
    @endif

    {{-- Drawn whatever the state, because it is the definition of the list below and not a fault. --}}
    <div class="mon-attention__item mon-attention__item--info">
        <x-k.icon name="info" :size="16" />
        <span class="mon-attention__body">
            <strong>{{ translate('what_counts_as_an_incident_here') }}</strong>
            <small>
                {{ translate('an_incident_is_a_correlation_bucket_of_firing_alert_rules') }}:
                {{ translate('a_rule_that_holds_past_its_hold_time_joins_an_incident_opened_in_the_last') }}
                {{ $definition['correlation_window_minutes'] }} {{ translate('minutes') }},
                {{ translate('or_opens_a_new_one') }}.
            </small>
            <small>{{ translate('nothing_else_in_this_build_can_open_one_an_outage_that_broke_no_alert_rule_is_not_on_this_list_and_its_absence_is_not_evidence_that_it_did_not_happen') }}</small>
            <small>{{ translate('an_incident_is_closed_automatically_once_no_rule_attached_to_it_is_still_firing_it_only_ever_goes_open_then_resolved') }}</small>
            <code>{{ $definition['writer'] }}</code>
        </span>
    </div>
</div>

{{-- The counts, each rendering its own state so a number that could not be read is never a zero. --}}
<x-k.card :title="translate('incidents_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($open['state'])" :text="$open['note'] ?? ''" />
    @endif

    <p class="mon-note">
        {{ translate('open_counts_are_current_and_ignore_the_selected_range_an_incident_that_opened_before_this_window_and_is_still_open_is_still_your_problem') }}.
        {{ translate('window_counts_and_mean_times_cover') }} {{ $window['since'] }} → {{ $window['until'] }}
        ({{ $window['timezone'] }}).
    </p>
</x-k.card>

{{-- Open incidents, each with the rules that opened it and the value each rule was carrying. One
     table per incident rather than one row: the signals ARE the incident, and a signal count in a
     cell says something happened without ever saying what. --}}
<x-k.card :title="translate('open_incidents')">
    @if ($open['state'] === 'ok' && !empty($open['rows']))
        @foreach ($open['rows'] as $incident)
            <h3 class="mon-heading">
                <code>{{ $incident['reference'] }}</code>
                <span class="mon-pill {{ $severityPill($incident['severity']) }}">{{ $vocabulary($incident['severity'], $incident['severity_known']) }}</span>
                <span class="mon-pill {{ $statusPill($incident['status']) }}">{{ $vocabulary($incident['status'], $incident['status_known']) }}</span>
                {{ $incident['title'] }}
            </h3>

            <p class="mon-note">
                {{ translate('started') }}: {{ $incident['started_at'] ?? translate('no_data') }}
                @if ($incident['age_minutes'] !== null)
                    ({{ $elapsed($incident['age_minutes']) }} {{ translate('ago') }})
                @endif
                — {{ translate('detected') }}: {{ $incident['detected_at'] ?? translate('no_data') }}
                @if ($incident['detect_seconds'] !== null)
                    ({{ translate('after') }} {{ $duration($incident['detect_seconds']) }})
                @endif
                @if ($incident['affected_services']['state'] === 'ok')
                    — {{ translate('affected') }}: {{ implode(', ', $incident['affected_services']['rows']) }}
                @elseif ($incident['affected_services']['state'] === 'failed')
                    — {{ translate('affected_services_could_not_be_read') }}
                @endif
                {{-- Nothing in this build writes either of these, which the card at the foot of the
                     page states. They are still drawn when a row holds one, because a value put
                     there by hand must not be hidden by a claim made about the code. --}}
                @if ($incident['acknowledged_at'])
                    — {{ translate('acknowledged') }}: {{ $incident['acknowledged_at'] }}
                @endif
                @if ($incident['probable_cause'])
                    — {{ translate('probable_cause') }}: {{ $incident['probable_cause'] }}
                @endif
            </p>

            @if ($incident['signals']['state'] === 'ok')
                <div class="k-table-wrap">
                    <table class="k-table k-table--compact">
                        <thead>
                        <tr>
                            <th>{{ translate('rule') }}</th>
                            <th>{{ translate('metric') }}</th>
                            <th class="k-table__num">{{ translate('value_when_it_fired') }}</th>
                            <th class="k-table__num">{{ translate('threshold') }}</th>
                            <th class="k-table__num">{{ translate('samples') }}</th>
                            <th>{{ translate('breaching_since') }}</th>
                            <th>{{ translate('still_firing') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($incident['signals']['rows'] as $signal)
                            <tr class="{{ $signal['still_firing'] === false ? 'mon-row--muted' : '' }}">
                                <td><code>{{ $signal['rule'] ?? '—' }}</code></td>
                                <td>
                                    <code>{{ $signal['metric'] ?? '—' }}</code>
                                    @if ($signal['label'])
                                        <small class="mon-metric__note" style="display:block"><code>{{ $signal['label'] }}</code></small>
                                    @endif
                                </td>
                                <td class="k-table__num k-num">{{ $signal['value'] === null ? '—' : $trim((float) $signal['value'], 3) }}</td>
                                <td class="k-table__num k-num">
                                    @if ($signal['threshold'] === null)
                                        <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                    @else
                                        {{ $signal['operator'] ? $signal['operator'] . ' ' : '' }}{{ $trim((float) $signal['threshold'], 3) }}
                                    @endif
                                </td>
                                <td class="k-table__num k-num">{{ $signal['samples'] === null ? '—' : $count($signal['samples']) }}</td>
                                <td class="k-num">{{ $signal['breached_since'] ?? '—' }}</td>
                                <td>
                                    @if ($signal['still_firing'] === true)
                                        <span class="mon-pill mon-pill--critical">{{ translate('yes') }}</span>
                                    @elseif ($signal['still_firing'] === false)
                                        <span class="mon-pill mon-pill--healthy">{{ translate('recovered') }}</span>
                                    @else
                                        {{-- Not "no". The rule's live state was unreadable, or the rule
                                             has been deleted since it fired; both are the absence of an
                                             answer rather than an answer of no. --}}
                                        <span class="mon-metric__state">{{ translate('unknown') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($incident['signals']['truncated'])
                    <p class="mon-note">{{ translate('this_incident_holds_more_signals_than_are_listed_the_writer_keeps_the_last_25') }}.</p>
                @endif
            @else
                <p class="mon-note {{ $incident['signals']['state'] === 'failed' ? 'mon-note--critical' : '' }}">
                    {{ $incident['signals']['note'] }}
                </p>
            @endif

            @if ($incident['holding_open']['state'] === 'ok')
                <p class="mon-note">
                    {{ translate('still_held_open_by') }}
                    @foreach ($incident['holding_open']['rows'] as $rule)
                        <code>{{ $rule['rule'] }}</code> ({{ $vocabulary($rule['rule_state'], in_array($rule['rule_state'], ['ok', 'pending', 'warning', 'critical'], true)) }}){{ $loop->last ? '' : ',' }}
                    @endforeach
                    — {{ translate('an_incident_closes_only_when_none_of_its_rules_is_firing') }}.
                </p>
            @else
                <p class="mon-note">{{ $incident['holding_open']['note'] ?? $stateTitle($incident['holding_open']['state']) }}</p>
            @endif

            @include('admin-views.monitoring.actions._incident', ['incident' => $incident, 'permissions' => $permissions])
        @endforeach

        @if ($open['truncated'])
            <p class="mon-note">
                {{ translate('more_incidents_are_open_than_this_page_lists') }}:
                {{ $count($open['total']) }} {{ translate('in_total') }}, {{ $open['limit'] }} {{ translate('shown') }}.
            </p>
        @endif
    @else
        <x-k.empty icon="info" :title="$stateTitle($open['state'])" :text="$open['note'] ?? ''" />
        @if (!empty($open['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $open['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- History, with the filter row in the URL: a filtered incident view is something people paste to
     each other while the incident is still running. --}}
<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="severity" class="k-select" aria-label="{{ translate('severity') }}">
                <option value="all" @selected($filters['severity'] === 'all')>{{ translate('any_severity') }}</option>
                @foreach ($severityChoices as $severity)
                    <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>
                        {{ $vocabulary($severity, in_array($severity, $definition['severities'], true)) }}
                        @if (isset($options['severities'][$severity]))({{ number_format($options['severities'][$severity]) }})@endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="status" class="k-select" aria-label="{{ translate('status') }}">
                <option value="all" @selected($filters['status'] === 'all')>{{ translate('any_status') }}</option>
                <option value="open_only" @selected($filters['status'] === 'open_only')>{{ translate('still_open') }}</option>
                @foreach ($statusChoices as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>
                        {{ $vocabulary($status, in_array($status, $definition['statuses'], true)) }}
                        @if (isset($options['statuses'][$status]))({{ number_format($options['statuses'][$status]) }})@endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>
    </form>

    <div class="k-card__body">
        <h3 class="mon-heading">{{ translate('incidents_that_started_in_this_window') }}</h3>

        @if ($history['state'] === 'ok' && !empty($history['rows']))
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
                        <th class="k-table__num">{{ translate('time_to_resolve') }}</th>
                        <th class="k-table__num">{{ translate('signals') }}</th>
                        <th>{{ translate('affected') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($history['rows'] as $incident)
                        <tr class="{{ $incident['is_open'] ? '' : 'mon-row--muted' }}">
                            <td><code>{{ $incident['reference'] }}</code></td>
                            <td>{{ $incident['title'] }}</td>
                            <td><span class="mon-pill {{ $severityPill($incident['severity']) }}">{{ $vocabulary($incident['severity'], $incident['severity_known']) }}</span></td>
                            <td><span class="mon-pill {{ $statusPill($incident['status']) }}">{{ $vocabulary($incident['status'], $incident['status_known']) }}</span></td>
                            <td class="k-num">{{ $incident['started_at'] ?? '—' }}</td>
                            <td class="k-table__num k-num">
                                @if ($incident['detect_seconds'] === null)
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @else
                                    {{ $duration($incident['detect_seconds']) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($incident['resolve_seconds'] !== null)
                                    {{ $duration($incident['resolve_seconds']) }}
                                @elseif ($incident['is_open'])
                                    {{-- Not a zero and not a dash: it has not finished, so it has no duration yet. --}}
                                    <span class="mon-metric__state">{{ translate('still_open') }}</span>
                                @else
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @endif
                            </td>
                            <td class="k-table__num k-num">
                                @if ($incident['signals']['state'] === 'ok')
                                    {{ $count(count($incident['signals']['rows'])) }}
                                @else
                                    <span class="mon-metric__state">{{ $stateTitle($incident['signals']['state']) }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($incident['affected_services']['state'] === 'ok')
                                    {{ implode(', ', $incident['affected_services']['rows']) }}
                                @else
                                    <span class="mon-metric__state">{{ $stateTitle($incident['affected_services']['state']) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mon-note">
                {{ count($history['rows']) }}
                @if ($history['truncated'])
                    {{ translate('most_recent_incidents_this_window_holds_more_than_are_listed') }}
                @else
                    {{ translate('incidents_started_in_this_window') }}
                @endif
                — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
                @if ($filters['narrowed'])
                    {{-- Said out loud, because a filtered table under unfiltered totals reads as two
                         numbers on one screen disagreeing with each other. --}}
                    {{ translate('the_filter_narrows_this_table_only_the_counts_and_mean_times_on_this_page_cover_every_incident_in_the_window') }}.
                @endif
            </p>
        @else
            <x-k.empty icon="alert" :title="$stateTitle($history['state'])" :text="$history['note'] ?? ''" />
            @if ($filters['narrowed'])
                <x-k.button :href="$clearUrl" variant="secondary" size="sm">{{ translate('clear_filters') }}</x-k.button>
            @endif
        @endif

        @if (($options['state'] ?? 'ok') === 'failed')
            <p class="mon-note mon-note--critical">
                {{ translate('the_filter_counts_for_this_window_could_not_be_read') }}: {{ $options['note'] }}
            </p>
        @endif

        <p class="mon-note">
            {{ translate('the_filter_lists_offer_only_the_values_this_build_can_write') }}:
            @foreach ($definition['severities_written'] as $severity)<code>{{ $severity }}</code>{{ $loop->last ? '' : ', ' }}@endforeach
            /
            @foreach ($definition['statuses_written'] as $status)<code>{{ $status }}</code>{{ $loop->last ? '' : ', ' }}@endforeach.
            {{ translate('the_schema_also_allows_major_warning_investigating_and_monitoring_and_no_code_path_writes_any_of_them_so_filtering_by_one_could_only_ever_return_nothing') }}.
        </p>
    </div>
</x-k.card>

{{-- The two durations everyone asks for, with the count each mean was taken over and the caveat
     that makes one of them readable at all. --}}
<x-k.card :title="translate('time_to_detect_and_time_to_resolve')">
    @if ($resolution['state'] === 'ok')
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('measurement') }}</th>
                    <th class="k-table__num">{{ translate('mean') }}</th>
                    <th class="k-table__num">{{ translate('incidents_counted') }}</th>
                    <th>{{ translate('what_it_measures') }}</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>{{ translate('mean_time_to_detect') }}</td>
                    <td class="k-table__num k-num">
                        @if ($resolution['mttd_seconds'] === null)
                            <span class="mon-metric__state">{{ translate('no_data') }}</span>
                        @else
                            {{ $duration($resolution['mttd_seconds']) }}
                        @endif
                    </td>
                    <td class="k-table__num k-num">{{ $count($resolution['mttd_samples']) }}</td>
                    <td><small class="mon-metric__note">{{ $resolution['mttd_caveat'] }}</small></td>
                </tr>
                <tr>
                    <td>{{ translate('mean_time_to_resolve') }}</td>
                    <td class="k-table__num k-num">
                        @if ($resolution['mttr_seconds'] === null)
                            <span class="mon-metric__state">{{ translate('no_data') }}</span>
                        @else
                            {{ $duration($resolution['mttr_seconds']) }}
                        @endif
                    </td>
                    <td class="k-table__num k-num">{{ $count($resolution['mttr_samples']) }}</td>
                    <td><small class="mon-metric__note">{{ $resolution['mttr_definition'] }}</small></td>
                </tr>
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ $count($resolution['started']) }} {{ translate('incidents_started_in_this_window') }},
            {{ $count($resolution['resolved']) }} {{ translate('of_them_have_closed') }},
            {{ $count($resolution['still_open']) }} {{ translate('are_still_open_and_are_not_in_the_mean') }}.
            @if ($resolution['undetected'] > 0)
                {{ $count($resolution['undetected']) }} {{ translate('carry_no_detection_time_and_are_excluded_rather_than_counted_as_instant') }}.
            @endif
            @if ($resolution['out_of_order'] > 0)
                {{ $count($resolution['out_of_order']) }} {{ translate('hold_a_timestamp_pair_that_runs_backwards_and_are_excluded_rather_than_folded_in_as_a_negative_duration') }}.
            @endif
            @if ($resolution['truncated'])
                {{ translate('this_window_holds_more_incidents_than_the_page_folds_so_these_means_cover_the_most_recent_of_them') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($resolution['state'])" :text="$resolution['note'] ?? ''" />
        <p class="mon-note">{{ $resolution['mttd_caveat'] }}</p>
        <p class="mon-note">{{ $resolution['mttr_definition'] }}</p>
    @endif
</x-k.card>

{{-- The same incidents as seen by the one axis. Two independent records of one event: where they
     disagree, the disagreement is the finding. --}}
<x-k.card :title="translate('incident_events_on_the_timeline')">
    @if ($events['state'] === 'ok' && !empty($events['rows']))
        <ul class="mon-events">
            @foreach ($events['rows'] as $event)
                <li class="mon-events__item {{ $eventTone($event['severity']) }}">
                    <span class="mon-events__time k-num">{{ $event['occurred_at'] ?? '—' }}</span>
                    <span class="mon-events__type">{{ $event['severity_known'] ? translate($event['severity']) : $event['severity'] }}</span>
                    <span>
                        {{ $event['title'] }}
                        @if ($event['description'])
                            <small class="mon-metric__note" style="display:block">{{ $event['description'] }}</small>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="mon-note">
            {{ count($events['rows']) }}
            @if ($events['truncated'])
                {{ translate('most_recent_incident_events_this_window_holds_more_than_are_listed') }}
            @else
                {{ translate('incident_events_recorded_in_this_window') }}
            @endif
            — {{ translate('written_by') }} <code>EventLog</code> {{ translate('when_an_incident_opens_and_when_it_closes') }}.
        </p>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($events['state'])" :text="$events['note'] ?? ''" />
        @if (!empty($events['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $events['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Not columns this page chose to leave out — columns nothing on this deployment fills. Drawn as
     readings with their reason so the gap is a task rather than an empty cell somebody reads as a
     finding of "none". --}}
<x-k.card :title="translate('what_this_build_does_not_record_about_an_incident')">
    <div class="mon-grid">
        @foreach ($unwritten['fields'] as $name => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
        @endforeach
    </div>
    <p class="mon-note">{{ $unwritten['note'] }}</p>
</x-k.card>

<p class="mon-note">
    {{ translate('incidents_their_signals_and_their_timestamps_are_read_from') }} <code>monitoring_incidents</code>,
    {{ translate('written_only_by') }} <code>{{ $definition['writer'] }}</code>
    {{ translate('when_an_alert_rule_fires_during') }} <code>php artisan monitoring:evaluate</code>.
    {{ translate('whether_each_signal_is_still_firing_is_read_from') }} <code>monitoring_alert_states</code>,
    {{ translate('and_the_lifecycle_rows_from') }} <code>monitoring_events</code> (<code>type=incident</code>).
    {{ translate('all_timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>
