@include('admin-views.monitoring.actions._synthetics', ['permissions' => $permissions])

{{--
    Synthetic tests: whether a page a customer opens still comes back, and comes back correct.

    The default state of this page is the one it has to get right. Nothing is probed until somebody
    defines a journey, so on a fresh install there is no target, no probe and no availability — and
    the two obvious ways to draw that are both lies. An empty green page claims a page was checked
    when nothing fetched it; a red one reports an outage that has not happened. So the banner comes
    first and says plainly that no journey is defined, that this is neither an outage nor an
    all-clear, and where a target is stored — and every figure below reports absence rather than
    filling in a zero or a hundred per cent.

    Two tables that must not be confused. The targets are what somebody asked to be watched; the
    journeys are what was actually measured. A target with no results and a result with no target
    are both real, both invisible in either table alone, and both worth a line of their own.
--}}

@php
    $window = $panel['window'];
    $targets = $panel['targets'];
    $runner = $panel['runner'];
    $journeys = $panel['journeys'];
    $series = $panel['series'];
    $timeline = $panel['timeline'];
    $failures = $panel['failures'];
    $shape = $panel['shape'];

    $journeysDrawn = $journeys['state'] === 'ok' && !empty($journeys['rows']);
    $undefined = $targets['state'] === 'not_configured';

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);
    $ms = static fn ($value) => $value === null ? null : number_format((float) $value, 1) . ' ms';
    $percent = static fn ($value) => $value === null ? null : rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') . '%';

    // Minutes are exact and unreadable past an hour. The stored figure is never rounded away — it
    // is the title on the cell, so the precise number is always one hover from the summary.
    $elapsed = static function (?int $minutes) {
        if ($minutes === null) {
            return null;
        }
        if ($minutes < 60) {
            return $minutes . ' ' . translate('minutes');
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . ' ' . translate('hours');
        }

        return intdiv($minutes, 1440) . ' ' . translate('days');
    };

    // check_key and status are free strings at the database level, and translate() writes any key
    // it has not seen into the language file. So a stored value is only ever translated when it is
    // one of the six this application authored, and echoed as stored otherwise.
    $knownStatuses = $panel['statuses'];

    $statusLabel = static fn (?string $status) => $status === null
        ? null
        : (in_array($status, $knownStatuses, true) ? translate($status) : $status);

    $statusPill = static fn (?string $status) => match ($status) {
        'ok' => 'mon-pill--ok',
        // Degraded answered — it was just too slow. That is a different fault from a page that did
        // not come back, and they must not share a pill.
        'degraded' => 'mon-pill--warning',
        'failing' => 'mon-pill--critical',
        default => 'mon-pill--unknown',
    };

    $rateTone = static fn (?float $rate) => $rate === null
        ? 'mon-pill--unknown'
        : ($rate >= 100 ? 'mon-pill--ok' : ($rate >= 95 ? 'mon-pill--warning' : 'mon-pill--critical'));
@endphp

{{-- First, and before any table: what an empty page here actually means. --}}
@if ($undefined)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('no_synthetic_journey_is_defined_so_none_is_being_probed') }}</strong>
                <small>{{ translate('this_is_neither_an_outage_nor_an_all_clear') }}.</small>
                <small>{{ translate('a_journey_that_has_never_run_has_no_availability_to_report') }}.</small>
                <small>{{ translate('a_journey_that_never_ran_is_deliberately_excluded_from_uptime_rather_than_counted_as_100_per_cent_up') }}.</small>
                <small>
                    {{ translate('where_targets_live') }}:
                    <code>{{ $shape['settings_table'] }}</code> &rarr; <code>{{ $shape['settings_key'] }}</code>
                </small>
                <small>
                    {{ translate('what_a_target_looks_like') }}:
                    <code>name</code>, <code>url</code>, <code>expect_status</code>,
                    <code>expect_text</code>, <code>max_ms</code>
                </small>
                <code>{{ $shape['remedy'] }}</code>
            </span>
        </div>
    </div>
@elseif ($targets['state'] === 'failed')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_list_of_synthetic_targets_could_not_be_read') }}</strong>
                <small>{{ $targets['note'] }}</small>
                <small>{{ translate('this_page_cannot_say_which_journeys_should_be_running_so_treat_an_absent_journey_below_as_unconfirmed_rather_than_removed') }}</small>
                @if (!empty($targets['remedy']))
                    <code>{{ $targets['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The probe reporting on itself. Separate from the journeys on purpose: "nothing is configured"
     and "nothing is running" are opposite operational facts with opposite fixes, and a page that
     showed only one of them would leave an empty setting looking like a stopped cron. --}}
@if ($runner['state'] === 'collector_offline')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_synthetic_check_has_not_run_in_this_window') }}</strong>
                <small>{{ $runner['note'] }}</small>
                <small>
                    {{ translate('cadence_in_minutes') }}: {{ $runner['cadence_minutes'] }} &mdash;
                    {{ translate('runs_this_window_should_have_held') }}: {{ $count($runner['expected_runs']) }}
                </small>
                @if (!empty($runner['remedy']))
                    <code>{{ $runner['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@elseif ($runner['state'] === 'failed')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_synthetic_probe_history_could_not_be_read') }}</strong>
                <small>{{ $runner['note'] }}</small>
            </span>
        </div>
    </div>
@elseif (!empty($runner['disagrees_with_targets']))
    {{-- Two independent records of the same fact, disagreeing. Named rather than resolved: an
         empty journey table under a full target list is otherwise unexplainable from the screen. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_target_list_and_the_last_run_of_the_check_do_not_agree') }}</strong>
                <small>{{ $runner['note'] }}</small>
                @if (!empty($runner['remedy']))
                    <code>{{ $runner['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

<x-k.card :title="translate('synthetic_tests_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($journeys['state'])" :text="$journeys['note'] ?? ''" />
    @endif

    @if ($undefined)
        <p class="mon-note">
            {{ translate('the_readings_that_depend_on_a_probe_are_left_off_this_card_rather_than_shown_as_zero_because_they_are_all_missing_for_the_one_reason_stated_above') }}.
        </p>
    @else
        <p class="mon-note">
            {{ translate('a_probe_passes_only_when_the_page_returned_the_expected_status_and_contained_the_expected_text_inside_its_time_budget') }};
            {{ translate('one_that_answered_too_slowly_is_counted_as_degraded_and_not_as_a_pass') }}.
            {{ translate('probes_that_could_not_run_are_excluded_from_the_rate_rather_than_counted_either_way') }}.
        </p>
    @endif
</x-k.card>

{{-- What somebody asked to be watched. Drawn whether or not anything was measured, because the
     gap between "configured" and "probed" is invisible on a table built from results alone. --}}
<x-k.card :title="translate('synthetic_targets')">
    @if ($targets['state'] === 'ok' && !empty($targets['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('journey') }}</th>
                    <th>{{ translate('url') }}</th>
                    <th>{{ translate('key') }}</th>
                    <th class="k-table__num">{{ translate('expected_status') }}</th>
                    <th>{{ translate('expected_text') }}</th>
                    <th class="k-table__num">{{ translate('time_budget') }}</th>
                    <th>{{ translate('probed') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($targets['rows'] as $target)
                    <tr class="{{ $target['probed'] ? '' : 'mon-row--muted' }}">
                        <td>
                            {{ $target['name'] ?? '—' }}
                            @if (!empty($target['ignored_fields']))
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('fields_the_probe_ignores') }}:
                                    @foreach ($target['ignored_fields'] as $field)<code>{{ $field }}</code>{{ $loop->last ? '' : ', ' }}@endforeach
                                </small>
                            @endif
                        </td>
                        <td><code>{{ $target['url'] ?? '—' }}</code></td>
                        <td><code>{{ $target['key'] ?? '—' }}</code>
                            @if ($target['key_collision'])
                                {{-- The slug of a name written in a script Str::slug cannot
                                     transliterate is empty, and the key collapses onto the one the
                                     runner uses for itself. Said before it happens, not after. --}}
                                <small class="mon-note mon-note--critical" style="display:block">
                                    {{ translate('this_name_produces_no_slug_so_its_results_would_be_recorded_under_the_same_key_the_check_uses_for_itself_give_it_a_latin_name') }}
                                </small>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $target['expect_status'] ?? '—' }}</td>
                        <td>{{ $target['expect_text'] ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ $target['max_ms'] === null ? '—' : $count($target['max_ms']) . ' ms' }}</td>
                        <td>
                            @if ($target['probed'])
                                <span class="mon-pill mon-pill--ok">{{ translate('yes') }}</span>
                            @else
                                <span class="mon-pill mon-pill--unknown">{{ translate('no') }}</span>
                                @if ($target['skip_reason'])
                                    <small class="mon-metric__note" style="display:block">{{ $target['skip_reason'] }}</small>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('targets_stored') }}: {{ $count($targets['defined']) }}.
            {{ translate('fetched_on_every_run') }}: {{ $count($targets['probed']) }}.
            {{ translate('journeys_probed_per_run_at_most') }}: {{ $targets['probe_limit'] }} —
            {{ translate('a_longer_list_is_stored_and_never_fetched') }}.
            {{ translate('read_from') }} <code>{{ $targets['source'] }}</code>.
        </p>
        @if (!empty($targets['truncated']))
            <p class="mon-note mon-note--critical">
                {{ translate('more_targets_are_stored_than_this_page_reads_so_some_are_not_listed_rather_than_absent') }}.
            </p>
        @endif
    @else
        <x-k.empty icon="settings" :title="$stateTitle($targets['state'])" :text="$targets['note'] ?? ''" />
    @endif

    {{-- The shape of a target, drawn whether or not one exists. There is no screen in this build
         that writes this key, so the page that reports it missing is the only place that can say
         what "it" is. --}}
    <p class="mon-heading">{{ translate('what_a_target_looks_like') }}</p>
    <p class="mon-note">{{ $shape['note'] }}</p>
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('field') }}</th>
                <th>{{ translate('required') }}</th>
                <th>{{ translate('example') }}</th>
                <th>{{ translate('what_it_does') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($shape['fields'] as $field)
                <tr>
                    <td><code>{{ $field['field'] }}</code></td>
                    <td>{{ $field['required'] ? translate('yes') : translate('no') }}</td>
                    <td><code>{{ $field['example'] }}</code></td>
                    <td>{{ $field['note'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <details class="mon-metric__remedy">
        <summary>{{ translate('how_to_enable_this') }}</summary>
        <code>{{ $shape['remedy'] }}</code>
    </details>
</x-k.card>

{{-- What was actually measured, worst first: an operator opens this page to find what is broken,
     and a table in alphabetical order buries the one journey that matters. --}}
<x-k.card :title="translate('journeys')">
    @if ($journeysDrawn)
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('journey') }}</th>
                    <th class="k-table__num">{{ translate('pass_rate_in_window') }}</th>
                    <th class="k-table__num">{{ translate('probes') }}</th>
                    <th class="k-table__num">{{ translate('latency') }}</th>
                    <th>{{ translate('last_probe') }}</th>
                    <th>{{ translate('last_outcome') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($journeys['rows'] as $journey)
                    <tr class="{{ $journey['defined'] ? '' : 'mon-row--muted' }}">
                        <td>
                            {{ $journey['name'] ?? $journey['key'] }}
                            <small class="mon-metric__note" style="display:block"><code>{{ $journey['key'] }}</code></small>
                            @if ($journey['url'])
                                <small class="mon-metric__note" style="display:block"><code>{{ $journey['url'] }}</code></small>
                            @endif
                            @unless ($journey['defined'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('no_target_defines_this_key_any_more_so_nothing_probes_it_now_and_this_history_will_stop_growing') }}
                                </small>
                            @endunless
                        </td>
                        <td class="k-table__num k-num">
                            @if ($journey['pass_rate'] !== null)
                                <span class="mon-pill {{ $rateTone($journey['pass_rate']) }}">{{ $percent($journey['pass_rate']) }}</span>
                                <small class="mon-metric__note" style="display:block">
                                    {{ $count($journey['passed']) }} / {{ $count($journey['graded']) }} {{ translate('probes') }}
                                </small>
                                @if ($journey['degraded'] > 0 || $journey['failing'] > 0)
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('over_budget') }} {{ $count($journey['degraded']) }},
                                        {{ translate('failed') }} {{ $count($journey['failing']) }}
                                    </small>
                                @endif
                            @else
                                {{-- Zero graded probes is not a zero per cent pass rate: it is the
                                     absence of anything to rate, and the two read as opposites. --}}
                                <span class="mon-metric__state">{{ translate('nothing_graded_yet') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            {{ $count($journey['runs']) }}
                            @if ($journey['ungraded'] > 0)
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('did_not_fetch_the_page') }}: {{ $count($journey['ungraded']) }}
                                </small>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($journey['avg_ms'] !== null)
                                {{ $ms($journey['avg_ms']) }}
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('fastest') }} {{ $count($journey['min_ms']) }} ms,
                                    {{ translate('slowest') }} {{ $count($journey['max_ms_observed']) }} ms
                                </small>
                                @if ($journey['max_ms'] !== null)
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('budget') }} {{ $count($journey['max_ms']) }} ms
                                    </small>
                                @endif
                            @else
                                {{-- A probe that never reached the page recorded no duration, which
                                     is a different fact from a page that answered in no time. --}}
                                <span class="mon-metric__state">{{ translate('not_timed') }}</span>
                            @endif
                            @if ($journey['series_avg_ms'] !== null)
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('series') }}: {{ $ms($journey['series_avg_ms']) }}
                                    ({{ translate('peak') }} {{ $ms($journey['series_max_ms']) }})
                                </small>
                            @endif
                        </td>
                        <td class="k-num">
                            @if ($journey['last_checked_at'])
                                {{ $journey['last_checked_at'] }}
                                @if ($journey['last_checked_minutes_ago'] !== null)
                                    <small class="mon-metric__note" style="display:block"
                                           title="{{ $journey['last_checked_minutes_ago'] }} {{ translate('minutes') }}">
                                        {{ $elapsed($journey['last_checked_minutes_ago']) }} {{ translate('ago') }}
                                    </small>
                                @endif
                            @else
                                <span class="mon-metric__state">{{ translate('never_recorded') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($journey['last_status'])
                                <span class="mon-pill {{ $statusPill($journey['last_status']) }}">{{ $statusLabel($journey['last_status']) }}</span>
                            @else
                                <span class="mon-metric__state">{{ translate('not_read') }}</span>
                            @endif
                            @if ($journey['last_detail'])
                                <small class="mon-metric__note" style="display:block">{{ $journey['last_detail'] }}</small>
                            @endif
                            @if ($journey['expect_status'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('expects_http') }} {{ $journey['expect_status'] }}@if ($journey['expect_text']),
                                        {{ translate('containing') }} "{{ $journey['expect_text'] }}"@endif
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('rates_and_latencies_cover') }} {{ $window['since'] }} → {{ $window['until'] }}
            ({{ $window['timezone'] }}), {{ translate('read_row_by_row_from') }}
            <code>{{ $journeys['source'] }}</code>.
            {{ translate('a_probe_counts_as_a_pass_only_when_it_returned_exactly_what_the_target_asked_for') }}.
        </p>

        @if (!empty($journeys['truncated']))
            <p class="mon-note mon-note--critical">
                {{ translate('more_journey_keys_were_recorded_in_this_window_than_this_page_reads_so_some_are_missing_rather_than_idle') }}.
            </p>
        @endif

        @if (!empty($journeys['never_probed']))
            {{-- Defined and never measured. The gap that reads as an all-clear if it is left off
                 the page: nothing is red, because nothing was ever fetched. --}}
            <p class="mon-note mon-note--critical">
                {{ translate('these_journeys_are_defined_and_the_check_would_fetch_them_but_none_recorded_a_probe_in_this_window') }}:
                @foreach ($journeys['never_probed'] as $missing)
                    <code>{{ $missing['key'] }}</code>{{ $loop->last ? '' : ',' }}
                @endforeach
            </p>
        @endif

        @if (!empty($journeys['retired']))
            <p class="mon-note">
                {{ translate('this_window_also_holds_probes_for_keys_no_stored_target_defines_any_more') }}:
                {{ implode(', ', $journeys['retired']) }}.
                {{ translate('their_history_is_real_and_counted_here_but_nothing_probes_them_now') }}.
            </p>
        @endif
    @else
        <x-k.empty icon="clock" :title="$stateTitle($journeys['state'])" :text="$journeys['note'] ?? ''" />
        @if (!empty($journeys['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $journeys['remedy'] }}</code>
            </details>
        @endif
        @if (!empty($journeys['never_probed']))
            <p class="mon-note mon-note--critical">
                {{ translate('these_journeys_are_defined_and_the_check_would_fetch_them_but_none_recorded_a_probe_in_this_window') }}:
                @foreach ($journeys['never_probed'] as $missing)
                    <code>{{ $missing['key'] }}</code>{{ $loop->last ? '' : ',' }}
                @endforeach
            </p>
        @endif
    @endif
</x-k.card>

{{-- Availability over the window, every journey folded together. The per-journey split is the
     table above; what a line is good for is showing whether the pages answered throughout the
     window or stopped partway into it. --}}
<x-k.card :title="translate('availability_over_time')">
    @if ($timeline['state'] === 'ok')
        <div class="mon-chart" data-mon-chart='@json(['points' => $timeline['points']])'></div>
        @if (!empty($timeline['truncated']))
            <p class="mon-note mon-note--critical">
                {{ translate('this_window_holds_more_buckets_than_the_chart_reads_so_the_line_ends_before_the_window_does') }}
            </p>
        @endif
        <p class="mon-note">
            {{ translate('the_line_is_probes_per_bucket_the_red_line_is_the_ones_that_did_not_pass') }}
            <code>{{ $series['source'] }}</code>, {{ translate('resolution') }}:
            {{ translate($window['resolution']) }}.
            {{ translate('the_availability_series_records_a_probe_that_answered_over_its_budget_as_down_so_this_line_can_sit_below_the_pass_rate_above_it') }}.
        </p>
        @if (!empty($series['seam_note']))
            <p class="mon-note">{{ $series['seam_note'] }}</p>
        @endif
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($timeline['state'])" :text="$timeline['note'] ?? ''" />
        @if (!empty($series['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $series['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The failures with what the probe actually saw. "The home page failed" is not actionable;
     "returned 503 where 200 was expected, after 14 seconds" is a fault somebody can go and find. --}}
<x-k.card :title="translate('failed_and_slow_probes')">
    @if ($failures['state'] === 'ok' && !empty($failures['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('when') }}</th>
                    <th>{{ translate('journey') }}</th>
                    <th>{{ translate('outcome') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th>{{ translate('what_the_probe_saw') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($failures['rows'] as $failure)
                    <tr>
                        <td class="k-num">{{ $failure['checked_at'] ?? '—' }}</td>
                        <td><code>{{ $failure['key'] }}</code></td>
                        <td><span class="mon-pill {{ $statusPill($failure['status']) }}">{{ $statusLabel($failure['status']) }}</span></td>
                        <td class="k-table__num k-num">
                            {{ $failure['duration_ms'] === null ? '—' : $count($failure['duration_ms']) . ' ms' }}
                        </td>
                        <td>
                            @if ($failure['detail'])
                                <span class="mon-note mon-note--critical" style="margin-block-start:0">{{ $failure['detail'] }}</span>
                            @endif
                            @if (!empty($failure['context']))
                                {{-- The context is the recorded fact, key by key. Both the key and
                                     the value come out of a database column, so neither is put
                                     through translate(). --}}
                                <small class="mon-metric__note" style="display:block">
                                    @foreach ($failure['context'] as $field => $value)
                                        <code>{{ $field }}</code>:
                                        {{ is_bool($value) ? ($value ? translate('yes') : translate('no')) : $value }}{{ $loop->last ? '' : ',' }}
                                    @endforeach
                                </small>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ count($failures['rows']) }}
            @if ($failures['truncated'])
                {{ translate('most_recent_failures_this_window_holds_more_than_are_listed') }}
            @else
                {{ translate('failures_recorded_in_this_window') }}
            @endif
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('detail_and_context_are_redacted_before_they_are_drawn_a_failing_page_and_its_url_are_a_reliable_place_to_find_a_token') }}.
        </p>
    @else
        <x-k.empty icon="alert" :title="$stateTitle($failures['state'])" :text="$failures['note'] ?? ''" />
    @endif
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
    {{ translate('where_targets_live') }}:
    <code>{{ $shape['settings_table'] }}</code> &rarr; <code>{{ $shape['settings_key'] }}</code>.
    {{ translate('probes_are_run_by') }} <code>App\Services\Monitoring\Checks\SyntheticCheck</code>,
    {{ translate('scheduled_as') }} <code>php artisan monitoring:check</code>.
    {{ translate('cadence_in_minutes') }}: {{ $runner['cadence_minutes'] }}.
    {{ translate('outcomes_are_recorded_in') }} <code>monitoring_check_results</code>
    (<code>kind</code> = <code>synthetic</code>)
    {{ translate('and_published_as_the_series') }} <code>check.up</code>, <code>check.duration_ms</code>.
    {{ translate('a_journey_is_fetched_read_only_so_a_synthetic_test_can_never_write_to_the_shop_it_watches') }}
    (<code>GET</code>).
</p>
