@include('admin-views.monitoring.actions._backups', ['permissions' => $permissions])

{{--
    Backups: how old the newest good one is, how big it was, and whether anyone has restored one.

    The sentence this page has to say before anything else is that this application never takes a
    backup. Every row here was written by an operator's own script calling the recorder, so an empty
    table is a statement about that script and not about whether the data is safe — and the fix is a
    line of shell, printed on the page, rather than a setting anywhere in this admin.

    Two pairs of states are drawn deliberately far apart, because collapsing either of them turns
    this page into reassurance at the moment it should be an alarm. A backup that is old and a
    backup that FAILED are different faults with different fixes. And "never restore-tested" is an
    unopened box while "the last restore test failed" is a box that was opened and was empty — the
    first is a task, the second is an emergency, and they never share a pill, a colour or a card.
--}}

@php
    $window = $panel['window'];
    $recorder = $panel['recorder'];
    $freshness = $panel['freshness'];
    $check = $panel['check'];
    $history = $panel['history'];
    $trend = $panel['size_trend'];
    $restore = $panel['restore'];
    $thresholds = $panel['thresholds'];

    $historyDrawn = $history['state'] === 'ok' && !empty($history['rows']);

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

    // A size, never a placeholder: a null size is drawn as a state elsewhere, and zero bytes here
    // can only ever mean a backup that really was zero bytes.
    $bytes = static function ($value) {
        if ($value === null) {
            return null;
        }
        $value = (float) $value;
        if ($value >= 1073741824) {
            return number_format($value / 1073741824, 2) . ' GB';
        }
        if ($value >= 1048576) {
            return number_format($value / 1048576, 1) . ' MB';
        }
        if ($value >= 1024) {
            return number_format($value / 1024, 1) . ' KB';
        }

        return number_format($value) . ' B';
    };

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

    $seconds = static function (?int $value) {
        if ($value === null) {
            return null;
        }

        return $value >= 120
            ? number_format($value / 60, 1) . ' ' . translate('minutes')
            : $value . ' ' . translate('seconds');
    };

    $signed = static fn (?float $percent) => $percent === null
        ? null
        : ($percent > 0 ? '+' : '') . number_format($percent, 1) . '%';

    $statusPill = static fn (?string $status) => match ($status) {
        'success' => 'mon-pill--healthy',
        'failed' => 'mon-pill--critical',
        default => 'mon-pill--unknown',
    };

    $checkPill = static fn (?string $status) => match ($status) {
        'ok' => 'mon-pill--healthy',
        'degraded' => 'mon-pill--warning',
        'failing' => 'mon-pill--critical',
        'not_configured' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    // The panel's own vocabularies, translated because BackupsPanel authors every one of these
    // values; anything read out of a column is printed as it was stored, because translate() mints
    // a language key for each string it has not seen.
    $freshnessPill = static fn (?string $verdict) => match ($verdict) {
        'fresh' => 'mon-pill--healthy',
        'stale' => 'mon-pill--warning',
        'overdue', 'last_backup_failed' => 'mon-pill--critical',
        'never_recorded' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    $freshnessLabel = static fn (?string $verdict) => match ($verdict) {
        'fresh' => translate('within_the_expected_age'),
        'stale' => translate('older_than_the_warning_age'),
        'overdue' => translate('past_twice_the_warning_age'),
        'last_backup_failed' => translate('the_last_backup_failed'),
        'never_recorded' => translate('no_backup_has_ever_been_recorded'),
        default => translate('the_age_of_the_last_backup_could_not_be_read'),
    };

    $restorePill = static fn (?string $verdict) => match ($verdict) {
        'passed' => 'mon-pill--healthy',
        'failed' => 'mon-pill--critical',
        'never_restore_tested' => 'mon-pill--warning',
        'recorded_without_a_result' => 'mon-pill--info',
        default => 'mon-pill--unknown',
    };

    // The same four verdicts in two lengths: a sentence where the card explains itself, a word
    // where it has to fit in a table cell. Both come from the panel's own fixed vocabulary.
    $restoreShort = static fn (?string $verdict) => match ($verdict) {
        'passed' => translate('passed'),
        'failed' => translate('failed'),
        'recorded_without_a_result' => translate('no_result_recorded'),
        'never_restore_tested' => translate('never_tested'),
        'no_backup_to_test' => translate('no_backup'),
        default => translate('unknown'),
    };

    $restoreLabel = static fn (?string $verdict) => match ($verdict) {
        'passed' => translate('the_last_restore_test_passed'),
        'failed' => translate('the_last_restore_test_failed'),
        'recorded_without_a_result' => translate('a_restore_test_was_recorded_without_saying_what_it_found'),
        'never_restore_tested' => translate('no_backup_has_ever_been_restore_tested'),
        'no_backup_to_test' => translate('there_is_no_backup_to_restore_test'),
        default => translate('the_restore_test_record_could_not_be_read'),
    };

    // The shared chart renderer reads each point's `hits`, so the recorded size is handed to it
    // under that key. Only the field name and the unit are adapted — the value is the size as it
    // was recorded, in megabytes so the axis label is readable.
    $asChart = static fn (array $chart) => [
        'points' => array_values(array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => round($point['bytes'] / 1048576, 1)],
            array_filter($chart['points'], static fn (array $point) => $point['t'] !== null && $point['bytes'] !== null),
        )),
    ];
@endphp

{{-- Everything that is wrong with the backups, stated before any table. Each item is one fault
     with one fix; none of them is drawn from the same reading as another. --}}
@if (in_array($freshness['verdict'], ['overdue', 'last_backup_failed', 'stale', 'never_recorded'], true) || $freshness['state'] !== 'ok' || in_array($restore['verdict'], ['failed', 'never_restore_tested'], true) || !empty($trend['drops']))
    <div class="mon-attention">
        @if ($freshness['state'] === 'failed')
            <div class="mon-attention__item mon-attention__item--warning">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('the_backup_record_could_not_be_read') }}</strong>
                    <small>{{ $freshness['note'] ?? $stateTitle($freshness['state']) }}</small>
                    <small>{{ translate('this_is_not_a_reading_that_there_are_no_backups_it_is_this_page_failing_to_query_the_table_that_holds_them') }}</small>
                </span>
            </div>
        @elseif ($freshness['verdict'] === 'never_recorded')
            <div class="mon-attention__item mon-attention__item--info">
                <x-k.icon name="info" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('no_backup_has_ever_been_recorded_here') }}</strong>
                    <small>{{ $freshness['note'] }}</small>
                    @if (!empty($freshness['remedy']))
                        <code>{{ $freshness['remedy'] }}</code>
                    @endif
                </span>
            </div>
        @elseif ($freshness['verdict'] === 'last_backup_failed')
            <div class="mon-attention__item mon-attention__item--critical">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('the_most_recent_backup_failed') }}</strong>
                    <small>
                        {{ $freshness['newest']['started_at'] }} ({{ $window['timezone'] }})
                        @if (!empty($freshness['newest']['error'])) — {{ $freshness['newest']['error'] }}@endif
                    </small>
                    @if (!empty($freshness['newest_successful']))
                        <small>
                            {{ translate('the_newest_backup_that_succeeded_was_taken') }}
                            {{ $elapsed($freshness['age_minutes']) ?? $stateTitle('no_data') }} {{ translate('ago') }}
                            ({{ $freshness['newest_successful']['started_at'] }}) —
                            @if ($freshness['age_minutes'] !== null && $freshness['age_minutes'] <= $thresholds['backup_age_warning_hours'] * 60)
                                {{-- The dangerous case: the age card stays green off the older
                                     backup while the job that produces them is broken. --}}
                                {{ translate('so_the_age_above_is_still_green_while_the_job_that_produces_it_is_broken') }}
                            @else
                                {{ translate('and_that_one_is_already_past_the_warning_age_itself') }}
                            @endif
                        </small>
                    @else
                        <small>{{ translate('every_backup_on_record_failed_so_there_is_no_successful_backup_at_all') }}</small>
                    @endif
                </span>
            </div>
        @elseif ($freshness['verdict'] === 'overdue' || $freshness['verdict'] === 'stale')
            <div class="mon-attention__item mon-attention__item--{{ $freshness['verdict'] === 'overdue' ? 'critical' : 'warning' }}">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('the_newest_successful_backup_is_older_than_it_should_be') }}</strong>
                    <small>
                        {{ $elapsed($freshness['age_minutes']) }} {{ translate('ago') }}
                        ({{ $freshness['newest_successful']['started_at'] ?? '' }} {{ $window['timezone'] }}) —
                        {{ translate('the_warning_age_is') }} {{ $thresholds['backup_age_warning_hours'] }} {{ translate('hours') }},
                        {{ translate('and_twice_that_is_graded_as_failing') }}.
                    </small>
                    <small>{{ translate('nothing_in_this_application_takes_a_backup_so_a_growing_age_means_the_script_that_does_has_stopped_reporting_or_stopped_running') }}</small>
                    <code>{{ $recorder['record_command'] }}</code>
                </span>
            </div>
        @endif

        @if ($restore['verdict'] === 'failed')
            {{-- Deliberately its own item, its own colour and its own sentence. A failed restore
                 test and an untested backup are opposite findings: one was checked and did not
                 come back, the other was never checked at all. --}}
            <div class="mon-attention__item mon-attention__item--critical">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('the_last_restore_test_failed') }}</strong>
                    <small>
                        {{ $restore['tested_at'] }} ({{ $window['timezone'] }})
                        @if ($restore['result']) — {{ $restore['result'] }}@endif
                    </small>
                    <small>{{ translate('the_backups_may_still_be_arriving_on_schedule_none_of_them_has_been_shown_to_come_back') }}</small>
                    @if (!empty($restore['remedy']))
                        <code>{{ $restore['remedy'] }}</code>
                    @endif
                </span>
            </div>
        @elseif ($restore['verdict'] === 'never_restore_tested')
            <div class="mon-attention__item mon-attention__item--warning">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('no_backup_has_ever_been_restore_tested') }}</strong>
                    <small>{{ translate('an_untested_backup_is_a_hope_rather_than_a_backup_nothing_here_has_been_shown_to_restore') }}</small>
                    <small>{{ translate('this_is_not_a_failed_test_no_test_has_been_recorded_at_all') }}</small>
                    @if (!empty($restore['remedy']))
                        <code>{{ $restore['remedy'] }}</code>
                    @else
                        <code>{{ $restore['command'] }} --result="restored to staging"</code>
                    @endif
                </span>
            </div>
        @endif

        @if (!empty($trend['drops']))
            {{-- The quiet failure this section exists for: the job still exits zero, the row still
                 says success, the age is still green, and the artefact is half the size. --}}
            <div class="mon-attention__item mon-attention__item--warning">
                <x-k.icon name="alert" :size="16" />
                <span class="mon-attention__body">
                    <strong>{{ translate('a_backup_in_this_window_is_much_smaller_than_the_one_before_it') }}</strong>
                    @foreach ($trend['drops'] as $drop)
                        <small>
                            {{ $drop['started_at'] }}: {{ $bytes($drop['bytes']) }}
                            {{ translate('against') }} {{ $bytes($drop['previous_bytes']) }}
                            ({{ $drop['previous_started_at'] }}) — {{ $signed($drop['change_percent']) }}
                        </small>
                    @endforeach
                    <small>{{ translate('a_backup_that_suddenly_halves_still_reports_success_and_still_looks_fresh_the_size_is_the_only_reading_that_shows_it') }}</small>
                </span>
            </div>
        @endif
    </div>
@endif

{{-- The readings themselves, each rendering its own state so an age that could not be measured can
     never be drawn as a small number of hours. --}}
<x-k.card :title="translate('backups_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="catalog" :title="$stateTitle($freshness['state'])" :text="$freshness['note'] ?? ''" />
    @endif

    <p class="mon-note">
        <span class="mon-pill {{ $freshnessPill($freshness['verdict']) }}">{{ $freshnessLabel($freshness['verdict']) }}</span>
        {{ translate('a_successful_backup_older_than') }} {{ $thresholds['backup_age_warning_hours'] }}
        {{ translate('hours_is_graded_degraded_and_older_than') }} {{ $thresholds['backup_age_critical_hours'] }}
        {{ translate('hours_is_graded_failing') }}
        (<code>monitoring.thresholds.backup_age_warning_hours</code>@if ($thresholds['overridden']), {{ translate('overridden_in_settings_from') }} {{ $thresholds['config_default_hours'] }}@endif).
    </p>

    @if ($freshness['state'] === 'ok' && !empty($freshness['newest']))
        <p class="mon-note">
            {{ translate('the_newest_recorded_backup') }}:
            @if ($freshness['newest']['kind_known'])
                {{ translate($freshness['newest']['kind']) }}
            @else
                <code>{{ $freshness['newest']['kind'] }}</code>
            @endif
            —
            <span class="mon-pill {{ $statusPill($freshness['newest']['status']) }}">
                @if ($freshness['newest']['status_known']){{ translate($freshness['newest']['status']) }}@else{{ $freshness['newest']['status'] }}@endif
            </span>
            {{ $freshness['newest']['started_at'] }} ({{ $window['timezone'] }}),
            {{ $bytes($freshness['newest']['size_bytes']) ?? translate('size_not_recorded') }},
            {{ $seconds($freshness['newest']['duration_seconds']) ?? translate('duration_not_recorded') }}.
            @if ($freshness['newest']['destination'])
                <code>{{ $freshness['newest']['destination'] }}</code>
            @endif
        </p>
    @endif
</x-k.card>

{{-- The thesis of the section, drawn whether or not a single row exists: nothing here takes a
     backup, and this is the exact line that makes one appear on this page. --}}
<x-k.card :title="translate('how_a_backup_gets_onto_this_page')">
    <p class="mon-note mon-note--critical" style="margin-block-start:0">
        {{ translate('this_application_never_takes_a_backup') }}
    </p>
    <p class="mon-note">{{ $recorder['note'] }}</p>

    <p class="mon-note">{{ translate('add_this_to_the_end_of_whatever_script_takes_the_backup') }}:</p>
    <pre class="mon-pre">@foreach ($recorder['example'] as $line){{ $line }}
@endforeach</pre>

    <p class="mon-note">{{ translate('and_after_restoring_one_somewhere_to_prove_it_comes_back') }}:</p>
    <pre class="mon-pre">@foreach ($recorder['restore_example'] as $line){{ $line }}
@endforeach</pre>

    @if ($recorder['state'] !== 'ok')
        <x-k.empty icon="settings" :title="$stateTitle($recorder['state'])" :text="$recorder['note']" />
    @endif

    <p class="mon-note">
        {{ translate('whether_the_backup_script_actually_calls_these_cannot_be_read_from_here') }}.
        {{ translate('a_row_in_the_table_is_the_only_evidence_of_it') }}.
        {{ translate('read_from') }} <code>{{ $recorder['source'] }}</code>.
    </p>
    @if (!empty($recorder['remedy']))
        <details class="mon-metric__remedy">
            <summary>{{ translate('how_to_enable_this') }}</summary>
            <code>{{ $recorder['remedy'] }}</code>
        </details>
    @endif
</x-k.card>

{{-- The verdict the alert engine actually acts on, read rather than recomputed: a page that graded
     the age itself and disagreed with the check would leave two answers and no way to tell which
     one raised the alert. --}}
<x-k.card :title="translate('what_the_backup_check_graded')">
    @if ($check['state'] === 'ok')
        <p class="mon-note" style="margin-block-start:0">
            <span class="mon-pill {{ $checkPill($check['status']) }}">
                @if ($check['status_known']){{ translate($check['status']) }}@else{{ $check['status'] }}@endif
            </span>
            {{ $check['detail'] }}
        </p>
        <p class="mon-note">
            {{ translate('last_graded') }} {{ $check['checked_at'] }} ({{ $window['timezone'] }})
            @if ($check['age_minutes'] !== null)
                — {{ $elapsed($check['age_minutes']) }} {{ translate('ago') }}
            @endif
            . {{ translate('the_check_runs_every') }} {{ $check['cadence_minutes'] }}
            {{ translate('minutes_inside') }} <code>php artisan monitoring:check</code>.
        </p>

        @if (!empty($check['by_status']))
            <p class="mon-note">
                {{ translate('graded_in_this_window') }}:
                @foreach ($check['by_status'] as $status => $runs)
                    <span class="mon-pill {{ $checkPill($status) }}">@if (in_array($status, ['ok', 'degraded', 'failing', 'unknown', 'not_configured', 'not_supported'], true)){{ translate($status) }}@else{{ $status }}@endif</span>
                    {{ $count($runs) }}{{ $loop->last ? '' : ',' }}
                @endforeach
                — {{ $window['since'] }} → {{ $window['until'] }}.
            </p>
        @endif

        @if (!empty($check['context']))
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('what_the_check_saw') }}</th>
                        <th>{{ translate('value') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($check['context'] as $key => $value)
                        <tr>
                            {{-- Keys and values come out of a JSON column, so they are printed as
                                 stored rather than translated. --}}
                            <td><code>{{ $key }}</code></td>
                            <td class="k-num">
                                @if ($value === null)
                                    {{-- The check wrote a null here on purpose; a blank cell would
                                         read as a column it never looked at. --}}
                                    <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                                @elseif (is_bool($value))
                                    {{ $value ? translate('yes') : translate('no') }}
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <x-k.empty icon="check" :title="$stateTitle($check['state'])" :text="$check['note'] ?? ''" />
        @if (!empty($check['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $check['remedy'] }}</code>
            </details>
        @endif
    @endif
    <p class="mon-note">{{ translate('read_from') }} <code>{{ $check['source'] }}</code>.</p>
</x-k.card>

{{-- Restore testing, on its own card, because it is the only reading here that says a backup is
     worth having. Each verdict gets its own sentence: passed, failed, recorded without a result,
     and never tested are four different findings and only one of them is good news. --}}
<x-k.card :title="translate('restore_testing')">
    <p class="mon-note" style="margin-block-start:0">
        <span class="mon-pill {{ $restorePill($restore['verdict']) }}">{{ $restoreLabel($restore['verdict']) }}</span>
    </p>

    @if (in_array($restore['verdict'], ['passed', 'failed', 'recorded_without_a_result'], true))
        <p class="mon-note">
            {{ translate('recorded') }} {{ $restore['tested_at'] }} ({{ $window['timezone'] }})
            @if ($restore['age_minutes'] !== null)
                — {{ $elapsed($restore['age_minutes']) }} {{ translate('ago') }}
            @endif
            @if ($restore['result'])
                : {{ $restore['result'] }}
            @endif
        </p>
        @if (!empty($restore['backup']))
            <p class="mon-note">
                {{ translate('the_backup_that_was_tested_was_taken') }} {{ $restore['backup']['started_at'] }}
                ({{ $window['timezone'] }}), {{ $bytes($restore['backup']['size_bytes']) ?? translate('size_not_recorded') }}
                @if ($restore['backup']['destination'])
                    — <code>{{ $restore['backup']['destination'] }}</code>
                @endif
            </p>
        @endif
        @if ($restore['verdict'] === 'recorded_without_a_result')
            <p class="mon-note">{{ translate('a_test_was_recorded_with_no_result_text_so_whether_it_passed_cannot_be_read_from_the_row') }}.</p>
        @endif
    @else
        <x-k.empty icon="catalog" :title="$restoreLabel($restore['verdict'])" :text="$restore['note'] ?? ''" />
    @endif

    @if (!empty($restore['remedy']))
        <details class="mon-metric__remedy">
            <summary>{{ translate('how_to_enable_this') }}</summary>
            <code>{{ $restore['remedy'] }}</code>
        </details>
    @endif

    <p class="mon-note">
        @if ($restore['untested_successful_in_window'] === null)
            {{ translate('how_many_backups_in_this_window_carry_a_restore_test_could_not_be_counted') }}:
            {{ translate('the_history_below_could_not_be_read') }}.
        @elseif (!empty($history['rows']))
            {{ $count($restore['tested_in_window']) }}
            {{ translate('of_the_backups_recorded_in_this_window_carry_a_restore_test') }};
            {{ $count($restore['untested_successful_in_window']) }}
            {{ translate('successful_ones_do_not') }}.
        @endif
        {{ translate('the_recency_above_is_read_from_the_whole_table') }}:
        {{ translate('a_restore_test_older_than_this_window_still_counts') }}.
    </p>
</x-k.card>

{{-- The size trend. A backup that halves is the failure nothing else on this page can see: the job
     exits zero, the row says success and the age stays green. --}}
<x-k.card :title="translate('backup_size_trend')">
    @if ($trend['state'] === 'ok')
        <div class="mon-chart" data-mon-chart='@json($asChart($trend))'></div>
        <p class="mon-note">
            {{ count($trend['points']) }} {{ translate('successful_backups_with_a_recorded_size') }},
            {{ translate('drawn_in_megabytes') }} — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('a_drop_of') }} {{ $trend['drop_threshold_percent'] }}%
            {{ translate('or_more_against_the_backup_before_it_is_listed_above_the_page') }}.
            @if ($trend['unsized'] > 0)
                {{ $count($trend['unsized']) }}
                {{ translate('successful_backups_recorded_no_size_and_are_left_out_of_this_line_rather_than_drawn_as_zero_bytes') }}.
            @endif
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($trend['state'])" :text="$trend['note'] ?? ''" />
        @if (!empty($trend['points']))
            <p class="mon-note">
                {{ translate('the_one_size_on_record_in_this_window') }}:
                {{ $bytes($trend['points'][0]['bytes']) }} ({{ $trend['points'][0]['started_at'] }} {{ $window['timezone'] }}).
            </p>
        @endif
        @if (!empty($trend['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $trend['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Every backup recorded in the window, newest first, with what it produced and what it cost. --}}
<x-k.card :title="translate('recorded_backups')">
    @if ($historyDrawn)
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('started') }}</th>
                    <th>{{ translate('kind') }}</th>
                    <th>{{ translate('outcome') }}</th>
                    <th class="k-table__num">{{ translate('size') }}</th>
                    <th class="k-table__num">{{ translate('change') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th>{{ translate('restore_test') }}</th>
                    <th>{{ translate('destination') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($history['rows'] as $backup)
                    <tr>
                        <td class="k-num">
                            {{ $backup['started_at'] ?? '—' }}
                            @if ($backup['age_minutes'] !== null)
                                <small class="mon-metric__note" style="display:block"
                                       title="{{ $backup['age_minutes'] }} {{ translate('minutes') }}">
                                    {{ $elapsed($backup['age_minutes']) }} {{ translate('ago') }}
                                </small>
                            @endif
                            @if ($backup['error'])
                                <small class="mon-note mon-note--critical" style="display:block">{{ $backup['error'] }}</small>
                            @endif
                        </td>
                        <td>
                            @if ($backup['kind_known'])
                                {{ translate($backup['kind']) }}
                            @else
                                <code>{{ $backup['kind'] }}</code>
                            @endif
                        </td>
                        <td>
                            <span class="mon-pill {{ $statusPill($backup['status']) }}">
                                @if ($backup['status_known']){{ translate($backup['status']) }}@else{{ $backup['status'] }}@endif
                            </span>
                        </td>
                        <td class="k-table__num k-num">
                            @if ($backup['size_bytes'] !== null)
                                {{ $bytes($backup['size_bytes']) }}
                            @else
                                {{-- Not zero: a size the script never reported and a zero-byte
                                     artefact are opposite facts about the same column. --}}
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($backup['size_change_percent'] === null)
                                <span class="mon-metric__state">{{ translate('nothing_to_compare') }}</span>
                            @elseif ($backup['size_change_percent'] <= -$trend['drop_threshold_percent'])
                                <span class="mon-pill mon-pill--critical">{{ $signed($backup['size_change_percent']) }}</span>
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('against') }} {{ $bytes($backup['compared_with_size_bytes']) }}
                                </small>
                            @else
                                {{ $signed($backup['size_change_percent']) }}
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('against') }} {{ $bytes($backup['compared_with_size_bytes']) }}
                                </small>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $seconds($backup['duration_seconds']) ?? '—' }}</td>
                        <td>
                            @if ($backup['restore_verdict'] === 'never_restore_tested')
                                <span class="mon-metric__state">{{ translate('never_tested') }}</span>
                            @else
                                <span class="mon-pill {{ $restorePill($backup['restore_verdict']) }}">{{ $restoreShort($backup['restore_verdict']) }}</span>
                                <small class="mon-metric__note" style="display:block">{{ $backup['restore_tested_at'] }}</small>
                                @if ($backup['restore_test_result'])
                                    <small class="mon-metric__note" style="display:block">{{ $backup['restore_test_result'] }}</small>
                                @endif
                            @endif
                        </td>
                        <td>
                            @if ($backup['destination'])
                                <code>{{ $backup['destination'] }}</code>
                            @else
                                <span class="mon-metric__state">{{ translate('not_recorded') }}</span>
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
                {{ translate('most_recent_backups_this_window_holds_more_than_are_listed') }}
            @else
                {{ translate('backups_recorded_in_this_window') }}
            @endif
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
        </p>
    @else
        <x-k.empty icon="catalog" :title="$stateTitle($history['state'])" :text="$history['note'] ?? ''" />
        @if (!empty($history['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $history['remedy'] }}</code>
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
    {{ translate('every_row_on_this_page_is_read_from') }} <code>monitoring_backups</code>,
    {{ translate('written_only_by') }} <code>{{ $recorder['record_command'] }}</code>
    {{ translate('and') }} <code>{{ $recorder['restore_command'] }}</code>.
    {{ translate('the_grade_beside_them_is_read_from') }} <code>{{ $check['source'] }}</code>,
    {{ translate('written_by') }} <code>php artisan monitoring:check</code>.
    {{ translate('nothing_in_this_application_takes_reads_or_verifies_a_backup_itself') }}.
</p>
