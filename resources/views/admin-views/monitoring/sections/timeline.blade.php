@include('admin-views.monitoring.actions._annotate', ['permissions' => $permissions])

{{--
    Timeline: everything that happened to this system, in the order it happened.

    The axis is only worth reading if the page says what it can and cannot carry. Six of the eight
    event types have a producer in this build; config and annotation have none anywhere in the code.
    A page that drew all eight as one silent line would report a calm week where the truth is that
    nobody is writing, so the legend states it per type and the banner states it once at the top.

    The two tables underneath are a cross-check, not decoration. A release recorded in
    monitoring_deployments whose event never reached the axis would simply be missing from the
    timeline — indistinguishable from a week in which nothing shipped — so each row says whether it
    is on the axis, and "not checked" is kept apart from "not there".
--}}

@php
    $window = $panel['window'];
    $filters = $panel['filters'];
    $axis = $panel['axis'];
    $counts = $panel['counts'];
    $legend = $panel['legend'];
    $deployments = $panel['deployments'];
    $incidents = $panel['incidents'];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'not_supported' => translate('not_supported'),
        'permission_denied' => translate('permission_denied'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // Severity drives the tone of the row and of its pill. Both vocabularies are authored in
    // EventLog, so an unrecognised stored value falls through to the neutral tone rather than
    // being coloured by a match it does not belong to.
    $eventTone = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-events__item--critical',
        'warning' => 'mon-events__item--warning',
        'success' => 'mon-events__item--success',
        default => '',
    };

    $severityPill = static fn (string $severity) => match ($severity) {
        'critical' => 'mon-pill--critical',
        'warning' => 'mon-pill--warning',
        'success' => 'mon-pill--success',
        'info' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    $statusPill = static fn (string $status) => match ($status) {
        'success', 'resolved' => 'mon-pill--healthy',
        'failed' => 'mon-pill--critical',
        'open' => 'mon-pill--warning',
        'investigating', 'monitoring' => 'mon-pill--running',
        default => 'mon-pill--unknown',
    };

    $seconds = static fn ($value) => $value === null
        ? null
        : ((int) $value < 120 ? (int) $value . ' ' . translate('seconds') : number_format((int) $value / 60, 1) . ' ' . translate('minutes'));

    // Filter state lives in the URL: a filtered axis is something people paste to each other while
    // they are working out what broke.
    $carried = array_filter([
        'range' => $range,
        'type' => $filters['type'],
        'severity' => $filters['severity'],
    ], static fn ($value) => $value !== null && $value !== '');

    $linkTo = static fn (array $extra = []) => route(
        'admin.monitoring.section',
        array_merge(['section' => 'timeline'], $carried, $extra),
    );

    $clearUrl = route('admin.monitoring.section', ['section' => 'timeline', 'range' => $range]);
@endphp

{{-- Said once, at the top, and detailed once, in the legend at the foot. Two of the eight types on
     this axis have no producer anywhere in this build, and a reader who does not know that will
     read their absence as evidence that nothing of that kind happened. --}}
@if (!empty($legend['unproducible']))
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    {{ $legend['produced_count'] }} {{ translate('of') }} {{ $legend['total_count'] }}
                    {{ translate('event_types_on_this_axis_have_a_producer_in_this_build') }}
                </strong>
                <small>
                    {{ translate('these_have_none_so_their_absence_is_not_evidence_that_none_occurred') }}:
                    @foreach ($legend['unproducible'] as $type)
                        <code>{{ translate($type) }}</code>{{ $loop->last ? '' : ',' }}
                    @endforeach
                </small>
                <small>{{ translate('every_other_type_is_written_at_the_moment_the_thing_happened_by_the_producer_that_knew_about_it_see_the_legend_at_the_foot_of_this_page') }}</small>
            </span>
        </div>
    </div>
@endif

{{-- A type this build does not name, sitting on the axis. Either somebody wrote it by hand or a
     producer was added after this page was; both are worth saying out loud rather than dropping. --}}
@if (!empty($legend['foreign_types']))
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('this_window_holds_event_types_this_page_does_not_recognise') }}</strong>
                <small>
                    @foreach ($legend['foreign_types'] as $type)
                        <code>{{ $type }}</code>{{ $loop->last ? '' : ',' }}
                    @endforeach
                </small>
                <small>{{ translate('they_are_drawn_on_the_axis_untranslated_rather_than_hidden') }}</small>
            </span>
        </div>
    </div>
@endif

<x-k.card :padded="false">
    <form method="get" class="k-view__toolbar">
        <input type="hidden" name="range" value="{{ $range }}">

        <div class="k-view__toolbar-grow">
            <select name="type" class="k-select" aria-label="{{ translate('event_type') }}">
                <option value="">{{ translate('any_type') }}</option>
                @foreach ($filters['types'] as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>
                        {{ translate($type) }}@if ($counts['known']) ({{ number_format($counts['by_type'][$type] ?? 0) }})@endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-view__toolbar-grow">
            <select name="severity" class="k-select" aria-label="{{ translate('severity') }}">
                <option value="">{{ translate('any_severity') }}</option>
                @foreach ($filters['severities'] as $severity)
                    <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>
                        {{ translate($severity) }}@if ($counts['known']) ({{ number_format($counts['by_severity'][$severity] ?? 0) }})@endif
                    </option>
                @endforeach
            </select>
        </div>

        <div class="k-row">
            <x-k.button type="submit" variant="primary" size="sm" icon="filter">{{ translate('apply') }}</x-k.button>
            <x-k.button :href="$clearUrl" variant="ghost" size="sm">{{ translate('clear') }}</x-k.button>
        </div>

        <p class="mon-note" style="margin-block-end:0">
            @if ($counts['known'])
                {{-- A count of zero here was taken, not assumed, which is why it is printed. --}}
                {{ $count($counts['total']) }} {{ translate('events_recorded_in_this_window') }}
                — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            @else
                {{ translate('how_many_events_this_window_holds_could_not_be_read') }}: {{ $counts['note'] }}
            @endif
            @if (!empty($counts['truncated']))
                {{ translate('more_type_and_severity_combinations_exist_in_this_window_than_this_page_counts_so_the_totals_are_a_floor_not_a_sum') }}.
            @endif
        </p>
    </form>
</x-k.card>

{{-- The axis itself, newest first, cut into days. The date heading is what makes "what else
     happened around then" readable, which is the question this page is opened with. --}}
<x-k.card :title="translate('the_axis')">
    @if ($axis['state'] === 'ok' && !empty($axis['days']))
        @foreach ($axis['days'] as $day)
            <h3 class="mon-heading">
                {{ $day['date'] !== '' ? $day['date'] : translate('undated') }}
                @if ($day['is_today'])
                    <span class="mon-pill mon-pill--info">{{ translate('today') }}</span>
                @endif
                <span class="mon-metric__note">{{ $day['count'] }} {{ translate('entries') }}</span>
            </h3>

            <ul class="mon-events">
                @foreach ($day['entries'] as $entry)
                    <li class="mon-events__item {{ $eventTone($entry['severity']) }}">
                        <span class="mon-events__time k-num">{{ $entry['time'] ?? $entry['at'] ?? '—' }}</span>
                        <span class="mon-events__type">
                            {{-- Only a type this build authored may be translated: translate() writes
                                 an unseen key into the language file, so one stored value it does not
                                 recognise would mint a language key per row. --}}
                            {{ $entry['type_known'] ? translate($entry['type']) : $entry['type'] }}
                        </span>
                        <span>
                            <span class="mon-pill {{ $severityPill($entry['severity']) }}">
                                {{ $entry['severity_known'] ? translate($entry['severity']) : $entry['severity'] }}
                            </span>
                            {{ $entry['title'] }}

                            @if ($entry['description'])
                                <small class="mon-metric__note" style="display:block">{{ $entry['description'] }}</small>
                            @endif

                            @if (!empty($entry['context']))
                                <small class="mon-metric__note" style="display:block">
                                    @foreach ($entry['context'] as $pair)
                                        <code>{{ $pair['key'] }}</code>:
                                        @if ($pair['value'] === null)
                                            <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                        @elseif (is_bool($pair['value']))
                                            {{ $pair['value'] ? translate('yes') : translate('no') }}
                                        @else
                                            {{ $pair['value'] }}
                                        @endif
                                        {{ $loop->last ? '' : '·' }}
                                    @endforeach
                                    @if ($entry['context_truncated'])
                                        — {{ translate('the_stored_context_holds_more_keys_than_are_drawn_here') }}
                                    @endif
                                </small>
                            @endif

                            @if ($entry['key'] || $entry['related_table'])
                                <small class="mon-metric__source" style="display:block">
                                    @if ($entry['key'])<code>{{ $entry['key'] }}</code>@endif
                                    @if ($entry['related_table'])
                                        <code>{{ $entry['related_table'] }} #{{ $entry['related_id'] }}</code>
                                    @endif
                                </small>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endforeach

        <p class="mon-note">
            {{ $axis['entries'] }}
            @if ($axis['truncated'])
                {{ translate('most_recent_entries_this_window_holds_more_than_are_drawn') }}
            @else
                {{ translate('entries_recorded_in_this_window') }}
            @endif
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('each_entry_was_written_when_the_thing_happened_nothing_on_this_page_is_measured_now') }}.
        </p>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($axis['state'])" :text="$axis['note'] ?? ''" />

        @if ($filters['active'])
            <p class="mon-note">
                {{ translate('the_axis_is_filtered') }}:
                @if ($filters['type'])<code>{{ translate($filters['type']) }}</code>@endif
                @if ($filters['severity'])<code>{{ translate($filters['severity']) }}</code>@endif
                — <a href="{{ $clearUrl }}">{{ translate('show_every_type_and_severity') }}</a>
            </p>
        @endif

        @if (!empty($axis['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $axis['remedy'] }}</code>
            </details>
        @endif

        @if ($axis['newest_ever'])
            <p class="mon-note">
                {{ translate('the_newest_entry_anywhere_on_this_axis') }}: {{ $axis['newest_ever'] }} ({{ $window['timezone'] }}).
            </p>
        @endif
    @endif
</x-k.card>

{{-- Laid against the axis rather than merged into it. The cross-check is the value: a release the
     axis never heard about is a hole in the timeline, and only this table can see it. --}}
<x-k.card :title="translate('releases_in_this_window')">
    @if ($deployments['state'] === 'ok' && !empty($deployments['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('deployed_at') }}</th>
                    <th>{{ translate('release') }}</th>
                    <th>{{ translate('environment') }}</th>
                    <th>{{ translate('deployed_by') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th class="k-table__num">{{ translate('migrations') }}</th>
                    <th>{{ translate('on_the_axis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($deployments['rows'] as $deployment)
                    <tr>
                        <td class="k-num">{{ $deployment['deployed_at'] ?? '—' }}</td>
                        <td>
                            <code>{{ $deployment['release'] ?? '—' }}</code>
                            @if ($deployment['branch'])
                                <small class="mon-metric__note" style="display:block"><code>{{ $deployment['branch'] }}</code></small>
                            @endif
                            @if ($deployment['notes'])
                                <small class="mon-metric__note" style="display:block">{{ $deployment['notes'] }}</small>
                            @endif
                        </td>
                        <td>{{ $deployment['environment'] ?? '—' }}</td>
                        <td>{{ $deployment['deployed_by'] ?? '—' }}</td>
                        <td>
                            <span class="mon-pill {{ $statusPill($deployment['status']) }}">
                                {{ $deployment['status_known'] ? translate($deployment['status']) : $deployment['status'] }}
                            </span>
                        </td>
                        <td class="k-table__num k-num">{{ $seconds($deployment['duration_seconds']) ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $count($deployment['migrations_run']) ?? '—' }}</td>
                        <td>
                            @if ($deployment['on_axis'] === true)
                                <span class="mon-pill mon-pill--healthy">{{ translate('recorded') }}</span>
                            @elseif ($deployment['on_axis'] === false)
                                {{-- Measured, not assumed: the lookup ran and found no entry pointing
                                     at this row, so the axis is short one release. --}}
                                <span class="mon-pill mon-pill--warning">{{ translate('missing') }}</span>
                            @else
                                <span class="mon-metric__state">{{ translate('not_checked') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('read_from') }} <code>{{ $deployments['source'] }}</code>.
            @if ($deployments['on_axis_known'])
                @if ($deployments['missing_from_axis'] > 0)
                    {{ $deployments['missing_from_axis'] }}
                    {{ translate('of_these_releases_have_no_entry_on_the_axis_the_deploy_happened_and_the_timeline_was_not_told') }}.
                @else
                    {{ translate('every_release_listed_here_also_has_an_entry_on_the_axis') }}.
                @endif
            @else
                {{ translate('whether_these_releases_reached_the_axis_could_not_be_checked') }}.
            @endif
            @if ($deployments['truncated'])
                {{ translate('this_window_holds_more_releases_than_are_listed') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="settings" :title="$stateTitle($deployments['state'])" :text="$deployments['note'] ?? ''" />
        @if (!empty($deployments['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $deployments['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

<x-k.card :title="translate('incidents_that_started_in_this_window')">
    @if ($incidents['state'] === 'ok' && !empty($incidents['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('started') }}</th>
                    <th>{{ translate('reference') }}</th>
                    <th>{{ translate('title') }}</th>
                    <th>{{ translate('severity') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('detected') }}</th>
                    <th>{{ translate('resolved') }}</th>
                    <th>{{ translate('on_the_axis') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($incidents['rows'] as $incident)
                    <tr>
                        <td class="k-num">{{ $incident['started_at'] ?? '—' }}</td>
                        <td><code>{{ $incident['reference'] ?? '—' }}</code></td>
                        <td>{{ $incident['title'] ?? '—' }}</td>
                        <td>
                            <span class="mon-pill {{ $severityPill($incident['severity']) }}">
                                {{ $incident['severity_known'] ? translate($incident['severity']) : $incident['severity'] }}
                            </span>
                        </td>
                        <td>
                            <span class="mon-pill {{ $statusPill($incident['status']) }}">
                                {{ $incident['status_known'] ? translate($incident['status']) : $incident['status'] }}
                            </span>
                        </td>
                        <td class="k-num">{{ $incident['detected_at'] ?? '—' }}</td>
                        <td class="k-num">
                            @if ($incident['resolved'])
                                {{ $incident['resolved_at'] }}
                            @else
                                <span class="mon-metric__state">{{ translate('still_open') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($incident['on_axis'] === true)
                                <span class="mon-pill mon-pill--healthy">{{ translate('recorded') }}</span>
                            @elseif ($incident['on_axis'] === false)
                                <span class="mon-pill mon-pill--warning">{{ translate('missing') }}</span>
                            @else
                                <span class="mon-metric__state">{{ translate('not_checked') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('read_from') }} <code>{{ $incidents['source'] }}</code>.
            {{ translate('detected_is_when_a_rule_fired_started_is_when_the_metric_first_left_its_range_the_gap_between_them_is_the_time_to_detect') }}.
            @if ($incidents['on_axis_known'] && $incidents['missing_from_axis'] > 0)
                {{ $incidents['missing_from_axis'] }}
                {{ translate('of_these_incidents_have_no_entry_on_the_axis') }}.
            @endif
            @if ($incidents['truncated'])
                {{ translate('this_window_holds_more_incidents_than_are_listed') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($incidents['state'])" :text="$incidents['note'] ?? ''" />
        @if (!empty($incidents['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $incidents['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The key to the whole page. Without it a type with no producer and a type with nothing to say
     draw the same blank, and those are opposite facts. --}}
<x-k.card :title="translate('what_this_axis_can_carry')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('type') }}</th>
                <th>{{ translate('recorded_by') }}</th>
                <th class="k-table__num">{{ translate('in_this_window') }}</th>
                <th>{{ translate('last_recorded') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($legend['types'] as $type)
                <tr class="{{ $type['produced'] ? '' : 'mon-row--muted' }}">
                    <td>
                        <span class="mon-events__type">{{ translate($type['type']) }}</span>
                        @if ($type['selected'])
                            <span class="mon-pill mon-pill--info">{{ translate('filtered_to_this') }}</span>
                        @endif
                        <small class="mon-metric__note" style="display:block">{{ $type['note'] }}</small>
                        @if ($type['remedy'])
                            <details class="mon-metric__remedy">
                                <summary>{{ translate('how_to_enable_this') }}</summary>
                                <code>{{ $type['remedy'] }}</code>
                            </details>
                        @endif
                    </td>
                    <td>
                        @if ($type['produced'])
                            <code>{{ $type['producer'] }}</code>
                        @else
                            {{-- Not "no data": no code in this build writes this type, which is not
                                 something a setting can turn on and not something an empty window
                                 could ever prove. --}}
                            <span class="mon-metric__state">{{ translate('nothing_in_this_build_produces_this') }}</span>
                        @endif
                    </td>
                    <td class="k-table__num k-num">
                        @if ($type['in_window_known'])
                            {{ $count($type['in_window']) }}
                        @else
                            <span class="mon-metric__state">{{ translate('could_not_be_read') }}</span>
                        @endif
                    </td>
                    <td class="k-num">
                        @if ($type['last_seen_at'])
                            {{ $type['last_seen_at'] }}
                        @elseif ($type['last_seen_known'])
                            {{-- The lookup ran across the whole retained axis and found none. --}}
                            <span class="mon-metric__state">{{ translate('never_recorded') }}</span>
                        @else
                            <span class="mon-metric__state">{{ translate('could_not_be_read') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <p class="mon-note">
        {{ translate('the_producer_column_is_read_from_the_code_not_from_the_table') }}
        (<code>App\Services\Monitoring\EventLog</code>) —
        {{ translate('a_type_with_a_producer_and_nothing_in_the_window_is_a_measured_zero_a_type_without_one_can_never_have_anything') }}.
        {{ translate('last_recorded_is_searched_across_the_whole_retained_axis_not_only_this_window') }}.
    </p>
</x-k.card>

{{-- Normally empty. A reading this page draws nowhere is indistinguishable from one nobody ever
     took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_collector_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['metric'] }}</code> ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('every_entry_is_read_from') }} <code>monitoring_events</code>,
    {{ translate('written_by') }} <code>App\Services\Monitoring\EventLog</code>
    {{ translate('from_the_seams_where_each_thing_happens_the_two_tables_above_are_read_from') }}
    <code>monitoring_deployments</code> {{ translate('and') }} <code>monitoring_incidents</code>.
    {{ translate('the_axis_is_pruned_after') }} {{ $panel['retention_days'] }} {{ translate('days') }}
    (<code>monitoring.retention.incident_days</code>),
    {{ translate('so_an_entry_older_than_that_was_deleted_which_is_not_the_same_as_never_written') }}.
    {{ translate('timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>
