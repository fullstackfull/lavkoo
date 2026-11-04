@include('admin-views.monitoring.actions._deployments', ['permissions' => $permissions])

{{--
    Deployments: which build started running, when, and what it carried.

    The running release comes first and is drawn whether or not a single row has ever been
    recorded, because it is the one fact this page can always state — it is read from version.json
    and .git, and it is the same string stamped onto every error and trace. An empty history under
    it is never drawn as "nothing shipped": monitoring_deployments is written by one command called
    from the deploy script, so an empty table is a statement about the deploy script.

    In the table, the migration figure that matters is the DIFFERENCE against the release before it
    — the total is a fact about the migrations table, the difference is a fact about the release.
    Where there is no difference to take, the reason is printed instead of a zero: "first release on
    record" and "ran no migrations" are opposite claims and must never share a cell.
--}}

@php
    $window = $panel['window'];
    $running = $panel['running'];
    $recorder = $panel['recorder'];
    $latest = $panel['latest'];
    $releases = $panel['releases'];
    $errorCounts = $panel['errors_by_release'];
    $comparisons = $panel['comparisons'];
    $releasesDrawn = $releases['state'] === 'ok' && !empty($releases['rows']);

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    $count = static fn ($value) => $value === null ? null : number_format((float) $value);

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

    $statusPill = static fn (?string $status) => match ($status) {
        'success' => 'mon-pill--healthy',
        'failed' => 'mon-pill--critical',
        default => 'mon-pill--unknown',
    };

    // The panel's own vocabulary for a missing migration difference. Translated only because these
    // three values are authored in DeploymentsPanel; anything else in the field is printed as it
    // was stored, because translate() mints a language key for every string it has not seen.
    $migrationReasons = [
        'no_earlier_release_is_recorded',
        'this_release_recorded_no_migration_count',
        'the_previous_release_recorded_no_migration_count',
    ];
@endphp

{{-- One banner, stating the single most consequential fact this page holds: whether the newest
     thing on record is the thing that is running. --}}
@if ($latest['state'] === 'ok' && $latest['matches_running_release'] === false)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_newest_recorded_release_is_not_the_build_that_is_running') }}</strong>
                <small>
                    {{ translate('recorded') }}: <code>{{ $latest['release'] }}</code>
                    ({{ $latest['deployed_at'] }} {{ $window['timezone'] }}) —
                    {{ translate('running') }}: <code>{{ $latest['running_release'] }}</code>.
                </small>
                <small>{{ translate('either_a_deploy_did_not_call_the_recorder_or_this_server_is_serving_a_different_build_from_the_one_that_was_last_recorded') }}</small>
                <code>{{ $recorder['remedy'] }}</code>
            </span>
        </div>
    </div>
@elseif ($latest['state'] === 'ok' && $latest['matches_running_release'] === null)
    {{-- Not the same claim as a mismatch, and it must not be drawn as one: the running release
         could not be read, so there is nothing to compare the record against. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('whether_the_newest_record_matches_the_running_build_cannot_be_determined') }}</strong>
                <small>{{ translate('the_running_release_could_not_be_read_so_the_record_below_is_neither_confirmed_nor_contradicted') }}</small>
            </span>
        </div>
    </div>
@elseif ($latest['state'] === 'no_data')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--info">
            <x-k.icon name="info" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('no_release_has_ever_been_recorded_here') }}</strong>
                <small>{{ $latest['note'] ?? $stateTitle($latest['state']) }}</small>
                @if ($running['release']->isOk())
                    <small>
                        {{ translate('the_build_serving_this_page_is') }}
                        <code>{{ $running['release']->value }}</code> —
                        {{ translate('read_from_version_json_and_git_head_and_stamped_onto_every_error_and_trace') }}
                    </small>
                @endif
                @if (!empty($latest['remedy']))
                    <code>{{ $latest['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@elseif ($latest['state'] !== 'ok')
    {{-- The record could not be read, which is not the same as an empty record: one of them is a
         deploy script that never calls the recorder, the other is a database this page cannot
         query, and reading the second as the first sends somebody to the wrong file. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_deployment_record_could_not_be_read') }}</strong>
                <small>{{ $latest['note'] ?? $stateTitle($latest['state']) }}</small>
                <small>{{ translate('nothing_on_this_page_can_say_which_release_was_last_recorded_until_this_is_resolved') }}</small>
            </span>
        </div>
    </div>
@endif

{{-- Always drawn, whatever the table below says. This is the only block on the page that does not
     depend on anything having been recorded. --}}
<x-k.card :title="translate('the_build_that_is_running')">
    <div class="mon-grid">
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $running['release'],
            'label' => translate('running_release'),
            'hint' => translate('version_json_plus_the_short_commit_sha_when_git_is_readable'),
        ])
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $running['commit'],
            'label' => translate('commit'),
        ])
        @include('admin-views.monitoring.partials._metric', [
            'metric' => $running['environment'],
            'label' => translate('environment'),
        ])
    </div>

    <p class="mon-note">
        {{ translate('deployment_rows_are_written_by_one_command_and_nothing_else') }}:
        <code>{{ $recorder['command'] }}</code>. {{ $recorder['note'] }}
    </p>
    @if (!empty($recorder['remedy']))
        <details class="mon-metric__remedy">
            <summary>{{ translate('how_to_enable_this') }}</summary>
            <code>{{ $recorder['remedy'] }}</code>
        </details>
    @endif
</x-k.card>

{{-- Every recorded release in the window, newest first, with what it carried. --}}
<x-k.card :title="translate('recorded_releases')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @endif

    @if ($releasesDrawn)
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('deployed') }}</th>
                    <th>{{ translate('release') }}</th>
                    <th>{{ translate('branch_and_commit') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th class="k-table__num">{{ translate('migrations_this_release_ran') }}</th>
                    <th class="k-table__num">{{ translate('deploy_duration') }}</th>
                    <th class="k-table__num">{{ translate('errors_tagged_with_this_release_in_window') }}</th>
                    <th class="k-table__num">{{ translate('distinct_bugs_first_seen_on_this_release') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($releases['rows'] as $release)
                    @php $counts = $errorCounts['by_release'][$release['release']] ?? null; @endphp
                    <tr>
                        <td class="k-num">
                            {{ $release['deployed_at'] ?? '—' }}
                            @if ($release['age_minutes'] !== null)
                                <small class="mon-metric__note" style="display:block"
                                       title="{{ $release['age_minutes'] }} {{ translate('minutes') }}">
                                    {{ $elapsed($release['age_minutes']) }} {{ translate('ago') }}
                                </small>
                            @endif
                            @if ($release['deployed_by'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('by') }} {{ $release['deployed_by'] }}
                                </small>
                            @endif
                        </td>
                        <td>
                            <code>{{ $release['release'] }}</code>
                            @if ($release['is_running'] === true)
                                <span class="mon-pill mon-pill--healthy">{{ translate('running_now') }}</span>
                            @elseif ($release['is_running'] === null)
                                {{-- Not "this is not running": the running release could not be read
                                     at all, and the two lead somewhere different. --}}
                                <small class="mon-metric__state" style="display:block">{{ translate('cannot_be_compared_with_the_running_build') }}</small>
                            @endif
                            @if ($release['environment'])
                                <small class="mon-metric__note" style="display:block">{{ $release['environment'] }}</small>
                            @endif
                            @if ($release['notes'])
                                <small class="mon-metric__note" style="display:block">{{ $release['notes'] }}</small>
                            @endif
                        </td>
                        <td>
                            {{ $release['branch'] ?? '—' }}
                            <small class="mon-metric__note" style="display:block">
                                @if ($release['commit_sha'])
                                    <code title="{{ $release['commit_sha_full'] }}">{{ $release['commit_sha'] }}</code>
                                @else
                                    <span class="mon-metric__state">{{ translate('no_commit_recorded') }}</span>
                                @endif
                            </small>
                        </td>
                        <td>
                            @if ($release['status_known'])
                                <span class="mon-pill {{ $statusPill($release['status']) }}">{{ translate($release['status']) }}</span>
                            @else
                                {{-- A value this build does not write. Shown as stored rather than
                                     translated, so an unknown status can never mint a language key. --}}
                                <span class="mon-pill mon-pill--unknown">{{ $release['status'] }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($release['migrations_delta'] !== null)
                                {{-- A negative difference means the migrations table shrank between
                                     two releases, which is a rollback or a different database, and
                                     is marked rather than printed as an ordinary count. --}}
                                <span class="{{ $release['migrations_delta'] < 0 ? 'mon-pill mon-pill--warning' : 'mon-pill' }}">
                                    {{ $release['migrations_delta'] > 0 ? '+' : '' }}{{ $count($release['migrations_delta']) }}
                                </span>
                                @if ($release['migrations_compared_with'])
                                    <small class="mon-metric__note" style="display:block">
                                        {{ translate('against') }} <code>{{ $release['migrations_compared_with'] }}</code>
                                    </small>
                                @endif
                            @else
                                {{-- Never a zero here. No difference to take is not a release that
                                     ran no migrations, and those two read as opposites. --}}
                                <span class="mon-metric__state">
                                    @if (in_array($release['migrations_delta_reason'], $migrationReasons, true))
                                        {{ translate($release['migrations_delta_reason']) }}
                                    @else
                                        {{ translate('no_data') }}
                                    @endif
                                </span>
                            @endif
                            <small class="mon-metric__note" style="display:block">
                                @if ($release['migrations_run'] !== null)
                                    {{ translate('migrations_table_held') }} {{ $count($release['migrations_run']) }}
                                @else
                                    <span class="mon-metric__state">{{ translate('no_total_recorded') }}</span>
                                @endif
                            </small>
                        </td>
                        <td class="k-table__num k-num">
                            @if ($release['duration_seconds'] !== null)
                                {{ $seconds($release['duration_seconds']) }}
                            @else
                                <span class="mon-metric__state">{{ translate('not_reported') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($errorCounts['state'] === 'ok' && $counts !== null)
                                {{ $count($counts['errors_in_window']) }}
                            @else
                                <span class="mon-metric__state">{{ $stateTitle($errorCounts['state']) }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($errorCounts['state'] === 'ok' && $counts !== null)
                                {{ $count($counts['groups_first_seen']) }}
                                <small class="mon-metric__note" style="display:block">
                                    {{ $count($counts['open_groups_first_seen']) }} {{ translate('still_open') }},
                                    {{ $count($counts['groups_last_seen']) }} {{ translate('last_seen_on_this_release') }}
                                </small>
                            @else
                                <span class="mon-metric__state">{{ $stateTitle($errorCounts['state']) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ count($releases['rows']) }}
            @if ($releases['truncated'])
                {{ translate('most_recent_releases_this_window_holds_more_than_are_listed') }}
            @else
                {{ translate('releases_recorded_in_this_window') }}
            @endif
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('the_migration_figure_is_the_difference_against_the_release_recorded_before_it_which_is_what_says_how_many_migrations_that_release_ran') }}.
            @if ($releases['baseline_release'])
                {{ translate('the_oldest_release_listed_is_compared_against') }}
                <code>{{ $releases['baseline_release'] }}</code> ({{ $releases['baseline_deployed_at'] }}),
                {{ translate('which_is_the_newest_release_recorded_before_this_window') }}.
            @endif
        </p>

        @if ($errorCounts['state'] === 'ok')
            <p class="mon-note">
                {{ translate('error_counts_come_from_the_release_column_already_stamped_on_every_error_and_error_group') }}.
                {{ translate('errors_are_occurrences_recorded_since') }} {{ $errorCounts['window_since'] }}
                ({{ $window['timezone'] }});
                {{ translate('distinct_bugs_are_counted_over_the_whole_history_not_this_window_because_a_bug_first_seen_on_a_release_was_first_seen_once') }}.
            </p>
        @else
            <p class="mon-note">
                {{ translate('per_release_error_counts_could_not_be_read') }}:
                {{ $errorCounts['note'] ?? $stateTitle($errorCounts['state']) }}
            </p>
        @endif
    @else
        <x-k.empty icon="settings" :title="$stateTitle($releases['state'])" :text="$releases['note'] ?? ''" />
        @if (!empty($releases['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $releases['remedy'] }}</code>
            </details>
        @endif

        @if ($latest['state'] === 'ok' && !$latest['in_window'])
            {{-- The table is empty because of the range, not because the table is empty. Those are
                 different facts and only one of them needs anybody to do anything. --}}
            <p class="mon-note">
                {{ translate('the_newest_release_on_record_is') }} <code>{{ $latest['release'] }}</code>,
                {{ translate('recorded') }} {{ $latest['deployed_at'] }} ({{ $window['timezone'] }}) —
                {{ translate('before_this_window_opened_widen_the_range_to_see_it') }}.
            </p>
        @endif
    @endif
</x-k.card>

{{-- The two JSON columns every deployment row carries and nothing fills in. Drawn as a named,
     unbuilt feature rather than as blank cells, which would read as "this release changed nothing". --}}
<x-k.card :title="translate('before_and_after_each_release')">
    @if ($comparisons['state'] === 'ok' && !empty($comparisons['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('release') }}</th>
                    <th>{{ translate('deployed') }}</th>
                    <th>{{ translate('before_metrics') }}</th>
                    <th>{{ translate('after_metrics') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($comparisons['rows'] as $comparison)
                    <tr>
                        <td><code>{{ $comparison['release'] }}</code></td>
                        <td class="k-num">{{ $comparison['deployed_at'] ?? '—' }}</td>
                        <td>{{ $comparison['has_before_metrics'] ? translate('recorded') : translate('not_recorded') }}</td>
                        <td>{{ $comparison['has_after_metrics'] ? translate('recorded') : translate('not_recorded') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $comparisons['note'] }}</p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($comparisons['state'])" :text="$comparisons['note'] ?? ''" />
        @if (!empty($comparisons['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('what_would_have_to_write_this') }}</summary>
                <code>{{ $comparisons['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">
            {{ $comparisons['checked'] }}
            {{ translate('releases_were_checked_for_a_stored_before_or_after_reading') }} —
            <code>{{ $comparisons['source'] }}</code>.
        </p>
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
    {{ translate('releases_are_read_from') }} <code>monitoring_deployments</code>,
    {{ translate('written_only_by') }} <code>php artisan monitoring:deploy-recorded</code>
    {{ translate('when_the_deploy_script_calls_it') }}.
    {{ translate('the_running_release_is_read_from') }} <code>version.json</code>
    {{ translate('and') }} <code>.git/HEAD</code>,
    {{ translate('and_error_counts_from_the_release_column_on') }}
    <code>monitoring_errors</code> {{ translate('and') }} <code>monitoring_error_groups</code>.
    {{ translate('timestamps_are_shown_in') }} {{ $window['timezone'] }}.
</p>
