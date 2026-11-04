{{--
    Alerts: what the engine watches, what it is saying, and whether it is still awake.

    The order on this page is deliberate. Every green pill below is a statement about the last time
    the evaluator ran, so the freshness of that run is settled first — a table of calm rules fed by
    a stopped engine is the most dangerous screen in the whole system.
--}}

@php
    $engine = $panel['engine'] ?? [];
    $summary = $panel['summary'] ?? [];
    $rules = $panel['rules'] ?? [];
    $firing = $panel['firing'] ?? [];
    $events = $panel['events'] ?? [];

    // Only tones that already exist in monitoring.scss. `pending` borrows the running blue (it is
    // in progress, not yet an alert) and everything unknowable takes the dashed outline, which is
    // the one pill that cannot be mistaken for a reading.
    $tone = static fn (?string $state) => match ($state) {
        'critical' => 'critical',
        'warning' => 'warning',
        'pending' => 'running',
        'ok' => 'ok',
        'disabled' => 'info',
        default => 'unknown',
    };

    $bannerTone = static fn (string $state) => match ($state) {
        'stale', 'never_run', 'failed' => 'critical',
        default => 'warning',
    };

    // A threshold is prose here, not arithmetic: "> 5" is the whole condition.
    $condition = static fn (array $rule, string $level) => $rule[$level . '_threshold'] === null
        ? null
        : $rule['operator'] . ' ' . $rule[$level . '_threshold'];

    $seconds = static fn (?int $value) => $value === null
        ? '—'
        : ($value >= 3600
            ? round($value / 3600, 1) . ' ' . translate('hours')
            : ($value >= 120 ? round($value / 60) . ' ' . translate('minutes') : $value . ' ' . translate('seconds')));

    // A stored double comes back with trailing noise; a reading of exactly zero must survive it.
    $reading = static fn (?float $value) => $value === null
        ? translate('no_reading')
        : rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');

    $secondsRange = static fn (?array $pair) => $pair === null
        ? '—'
        : ($pair['min'] === $pair['max'] ? $seconds($pair['min']) : $seconds($pair['min']) . ' – ' . $seconds($pair['max']));
@endphp

{{-- Said before anything else. "Nothing is firing" is only true as of the last evaluation, and
     this is where the page admits how long ago that was. --}}
@if (($engine['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $bannerTone($engine['state']) }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>
                    @switch($engine['state'])
                        @case('stale')       {{ translate('the_alert_engine_has_stopped_evaluating') }} @break
                        @case('never_run')   {{ translate('the_alert_engine_has_never_run_here') }} @break
                        @case('no_rules')    {{ translate('no_alert_rule_has_been_created_yet') }} @break
                        @case('failed')      {{ translate('the_alert_rules_could_not_be_read') }} @break
                        @default             {{ translate('the_alert_engine_is_not_evaluating_anything') }}
                    @endswitch
                </strong>
                <small>{{ $engine['note'] }}</small>
                @if (!empty($engine['remedy']))
                    <code>{{ $engine['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- Counts are only drawn once there is something to count. On a fresh install "0 firing" would be
     technically true and completely misleading: nothing is firing because nothing is watched. --}}
@if ($summary['watching'] ?? false)
    <div class="k-stats mon-stats">
        <x-k.stat :label="translate('rules_enabled')" :value="number_format($summary['enabled'])" icon="settings"
                  :caption="$summary['disabled'] > 0 ? $summary['disabled'] . ' ' . translate('switched_off') : null" />
        <x-k.stat :label="translate('firing_now')" :value="number_format($summary['firing'])" icon="alert"
                  :caption="($firing['as_of'] ?? null) ? translate('as_of') . ' ' . $firing['as_of'] : null" />
        <x-k.stat :label="translate('breaching_but_not_yet_fired')" :value="number_format($summary['pending'])" icon="clock" />
        <x-k.stat :label="translate('within_range')" :value="number_format($summary['ok'])" icon="check" />
        <x-k.stat :label="translate('never_evaluated')" :value="number_format($summary['never_evaluated'])" icon="info" />
    </div>
@endif

{{-- The engine's own facts, each able to say it has no reading rather than showing a zero. --}}
<x-k.card :title="translate('the_alert_engine')">
    <div class="mon-grid">
        @foreach ($panel['readings'] as $readingKey => $metric)
            @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($readingKey)])
        @endforeach
    </div>
    <p class="mon-note">
        {{ translate('rules_are_evaluated_by') }} <code>{{ $engine['schedule'] ?? '' }}</code>.
        {{ translate('times_are_shown_in') }} {{ $engine['timezone'] ?? '' }}.
    </p>
</x-k.card>

{{-- What is on fire, and what is on its way there. --}}
<x-k.card :title="translate('currently_firing')">
    @if (!empty($firing['rows']))
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    <th>{{ translate('rule') }}</th>
                    <th>{{ translate('state') }}</th>
                    <th class="k-table__num">{{ translate('last_value') }}</th>
                    <th>{{ translate('condition') }}</th>
                    <th>{{ translate('breaching_since') }}</th>
                    <th>{{ translate('fired_at') }}</th>
                    <th class="k-table__num">{{ translate('times_fired') }}</th>
                    <th>{{ translate('incident') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($firing['rows'] as $rule)
                    <tr>
                        <td>
                            <a href="{{ route('admin.monitoring.section', ['section' => $rule['section'], 'range' => $range]) }}">{{ $rule['name'] }}</a>
                            <small class="mon-metric__source" style="display:block">{{ $rule['metric'] }}{{ $rule['label'] !== '' ? '@' . $rule['label'] : '' }}</small>
                        </td>
                        <td><span class="mon-pill mon-pill--{{ $tone($rule['state']) }}">{{ translate($rule['state']) }}</span></td>
                        <td class="k-table__num k-num">{{ $reading($rule['last_value']) }}</td>
                        <td class="k-num">{{ $condition($rule, $rule['state'] === 'critical' ? 'critical' : 'warning') ?? '—' }}</td>
                        <td class="k-num">{{ $rule['breached_since'] ?? '—' }}</td>
                        <td class="k-num">{{ $rule['fired_at'] ?? '—' }}</td>
                        <td class="k-table__num k-num">{{ number_format($rule['fire_count']) }}</td>
                        <td>
                            @if ($rule['incident_id'])
                                <a href="{{ route('admin.monitoring.section', ['section' => 'incidents', 'range' => $range]) }}">#{{ $rule['incident_id'] }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($firing['trustworthy'] ?? false)
        <x-k.empty icon="check" :title="translate('nothing_is_firing')"
                   :text="translate('no_rule_was_past_its_threshold_at_the_last_evaluation') . ' ' . ($firing['as_of'] ?? '')" />
    @else
        {{-- The important distinction on this page: quiet because everything is fine, or quiet
             because nobody is listening. --}}
        <x-k.empty icon="info" :title="translate('nothing_can_be_said_about_what_is_firing')"
                   :text="translate('no_rule_was_firing_when_the_engine_last_ran_but_that_run_is_not_current_so_this_is_not_a_statement_about_now')" />
    @endif

    @if (!empty($firing['pending']))
        {{-- The only warning anyone gets before the pager: breaching, but not yet for long enough
             to count as an alert. --}}
        <h3 class="mon-heading">{{ translate('breaching_but_not_yet_fired') }}</h3>
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('rule') }}</th>
                    <th class="k-table__num">{{ translate('last_value') }}</th>
                    <th>{{ translate('condition') }}</th>
                    <th>{{ translate('breaching_since') }}</th>
                    <th>{{ translate('held_at_the_last_evaluation') }}</th>
                    <th>{{ translate('must_hold_for') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($firing['pending'] as $rule)
                    <tr>
                        <td>
                            <a href="{{ route('admin.monitoring.section', ['section' => $rule['section'], 'range' => $range]) }}">{{ $rule['name'] }}</a>
                            <small class="mon-metric__source" style="display:block">{{ $rule['metric'] }}</small>
                        </td>
                        <td class="k-table__num k-num">{{ $reading($rule['last_value']) }}</td>
                        <td class="k-num">{{ $condition($rule, 'warning') ?? $condition($rule, 'critical') ?? '—' }}</td>
                        <td class="k-num">{{ $rule['breached_since'] ?? '—' }}</td>
                        <td class="k-num">{{ $seconds($rule['breached_for_seconds']) }}</td>
                        <td class="k-num">{{ $seconds($rule['for_seconds']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (!empty($firing['blind']))
        {{-- A rule whose metric never arrives is silent for the same reason a healthy shop is
             silent, and the state column alone cannot tell the two apart. --}}
        <p class="mon-note mon-note--critical">
            {{ translate('these_enabled_rules_watch_a_metric_that_has_not_been_recorded_in_the_last_two_days_so_they_cannot_fire') }}:
            {{ collect($firing['blind'])->pluck('metric')->implode(', ') }}
        </p>
    @endif

    @if (!empty($firing['never_evaluated']))
        <p class="mon-note">
            {{ translate('these_rules_have_no_state_row_at_all_which_means_the_engine_has_never_reached_them') }}:
            {{ collect($firing['never_evaluated'])->pluck('key')->implode(', ') }}
        </p>
    @endif
</x-k.card>

{{-- Every rule, whether it exists yet or not. --}}
<x-k.card :title="($rules['mode'] ?? '') === 'not_yet_created' ? translate('the_rules_that_would_be_created') : translate('alert_rules')">
    @if (!empty($rules['note']))
        <div class="mon-note {{ ($rules['state'] ?? '') === 'failed' ? 'mon-note--critical' : '' }}">
            {{ $rules['note'] }}
            @if (!empty($rules['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $rules['remedy'] }}</code>
                </details>
            @endif
        </div>
    @endif

    @if (!empty($rules['rows']))
        <div class="k-table-wrap">
            <table class="k-table">
                <thead>
                <tr>
                    {{-- Nine columns of nowrap headers would otherwise squeeze the rule name to one
                         word per line; the name and its description are what the table is read by. --}}
                    <th style="min-inline-size:230px">{{ translate('rule') }}</th>
                    <th>{{ translate('state') }}</th>
                    <th class="k-table__num">{{ translate('last_reading') }}</th>
                    <th>{{ translate('metric') }}</th>
                    <th>{{ translate('warning') }}</th>
                    <th>{{ translate('critical') }}</th>
                    <th>{{ translate('recovers_below') }}</th>
                    <th>{{ translate('must_hold_for') }}</th>
                    <th>{{ translate('cooldown') }}</th>
                    @if ($permissions->canEditSettings())<th></th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($rules['rows'] as $rule)
                    <tr class="{{ $rule['exists'] && $rule['enabled'] ? '' : 'mon-row--muted' }}">
                        <td>
                            <strong>{{ $rule['name'] }}</strong>
                            @if ($rule['description'])
                                <small class="mon-metric__note" style="display:block">{{ $rule['description'] }}</small>
                            @endif
                            <small class="mon-metric__source" style="display:block">{{ $rule['key'] }}</small>
                        </td>
                        <td>
                            <span class="mon-pill mon-pill--{{ $tone($rule['state']) }}">{{ translate($rule['state']) }}</span>
                            @if ($rule['state'] === 'disabled' && in_array($rule['stored_state'], ['warning', 'critical'], true))
                                {{-- Switched off while it was on fire: it stopped being evaluated,
                                     it did not stop being true. --}}
                                <small class="mon-metric__note" style="display:block">{{ translate('last_known') }}: {{ translate($rule['stored_state']) }}</small>
                            @endif
                            @if ($rule['exists'] && $rule['notify_email'])
                                <small class="mon-metric__note" style="display:block">{{ translate('sends_email') }}</small>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            {{ $rule['evaluated'] ? $reading($rule['last_value']) : '—' }}
                            @if ($rule['checked_at'])
                                <small class="mon-metric__note" style="display:block">{{ $rule['checked_at'] }}</small>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.monitoring.section', ['section' => $rule['section'], 'range' => $range]) }}">{{ $rule['metric'] }}</a>
                            @if ($rule['label'] !== '')
                                <small class="mon-metric__note" style="display:block">{{ $rule['label'] }}</small>
                            @endif
                            @if ($rule['metric_seen'] === false)
                                <small style="display:block"><span class="mon-pill mon-pill--missed">{{ translate('metric_not_arriving') }}</span></small>
                            @endif
                        </td>
                        <td class="k-num">{{ $condition($rule, 'warning') ?? '—' }}</td>
                        <td class="k-num">{{ $condition($rule, 'critical') ?? '—' }}</td>
                        <td class="k-num">{{ $rule['recovery_threshold'] ?? '—' }}</td>
                        <td class="k-num">{{ $seconds($rule['for_seconds']) }}</td>
                        <td class="k-num">{{ $seconds($rule['cooldown_seconds']) }}</td>
                        @if ($permissions->canEditSettings())
                            <td class="text-end">
                                @if ($rule['exists'])
                                    {{-- Silencing keeps the rule. Deleting is the destructive one and
                                         says so, because a deleted rule is cover somebody thinks
                                         they still have. --}}
                                    <form action="{{ route('admin.monitoring.actions.alert-rule', ['action' => $rule['enabled'] ? 'silence' : 'enable']) }}"
                                          method="post" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="key" value="{{ $rule['key'] }}">
                                        <button class="btn btn-outline-secondary btn-sm">{{ translate($rule['enabled'] ? 'silence' : 'enable') }}</button>
                                    </form>
                                    <form action="{{ route('admin.monitoring.actions.alert-rule', ['action' => 'delete']) }}" method="post" class="d-inline"
                                          onsubmit="return confirm('{{ translate('delete_this_rule_nothing_will_watch_that_metric_afterwards') }}?')">
                                        @csrf
                                        <input type="hidden" name="key" value="{{ $rule['key'] }}">
                                        <button class="btn btn-outline-danger btn-sm">{{ translate('delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ translate('a_rule_with_no_label_covers_every_label_its_metric_carries_every_disk_every_queue_and_the_worst_one_decides') }}
            @if ($rules['truncated'] ?? false)
                {{ translate('only_the_first_rules_are_listed_here') }}
            @endif
        </p>
    @elseif (($rules['state'] ?? '') !== 'failed')
        <x-k.empty icon="settings" :title="translate('no_alert_rule_could_be_listed')" :text="$rules['note'] ?? ''" />
    @endif

    @if ($permissions->canEditSettings())
        <div class="mt-3 d-flex justify-content-end">
            <form action="{{ route('admin.monitoring.actions.alert-rule', ['action' => 'seed']) }}" method="post">
                @csrf
                <button class="btn btn-outline-primary btn-sm">{{ translate('reinstall_the_shipped_rules') }}</button>
            </form>
        </div>
    @endif
</x-k.card>

@include('admin-views.monitoring.actions._alert-rule', ['permissions' => $permissions, 'rules' => $rules])

{{-- Why this engine is quiet, in its own configured numbers rather than in general terms. --}}
<x-k.card :title="translate('how_the_engine_avoids_becoming_noise')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('mechanism') }}</th>
                <th>{{ translate('on_this_deployment') }}</th>
                <th>{{ translate('what_it_prevents') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ translate('the_condition_must_hold') }}</td>
                <td class="k-num">{{ $secondsRange($summary['hold_seconds'] ?? null) }}</td>
                <td>{{ translate('every_sample_in_the_window_must_breach_not_the_average_and_not_the_latest_one_so_one_unlucky_sample_is_not_an_outage') }}</td>
            </tr>
            <tr>
                <td>{{ translate('notification_cooldown') }}</td>
                <td class="k-num">{{ $secondsRange($summary['cooldown_seconds'] ?? null) }}</td>
                <td>{{ translate('the_cooldown_gates_the_message_never_the_state_this_page_always_shows_the_truth_what_is_suppressed_is_sending_it_again') }}</td>
            </tr>
            <tr>
                <td>{{ translate('recovery_threshold') }}</td>
                <td class="k-num">{{ ($summary['with_recovery_threshold'] ?? 0) }} / {{ $summary['total'] ?? 0 }} {{ translate('rules') }}</td>
                <td>{{ translate('recovery_sits_inside_the_firing_line_so_a_metric_resting_exactly_on_the_threshold_cannot_alternate_between_firing_and_recovering_every_minute') }}</td>
            </tr>
            <tr>
                <td>{{ translate('incident_grouping') }}</td>
                <td><a href="{{ route('admin.monitoring.section', ['section' => 'incidents', 'range' => $range]) }}">{{ translate('incidents') }}</a></td>
                <td>{{ translate('rules_that_fire_within_the_same_window_attach_to_one_incident_so_a_single_stall_arrives_as_one_problem_rather_than_six_alerts') }}</td>
            </tr>
            <tr>
                <td>{{ translate('no_data_is_not_zero') }}</td>
                <td class="k-num">{{ ($summary['metric_never_seen'] ?? 0) }} {{ translate('rules_without_a_recent_sample') }}</td>
                <td>{{ translate('a_metric_that_stopped_arriving_evaluates_to_nothing_rather_than_to_zero_so_a_stopped_collector_cannot_fire_every_rule_at_once') }}</td>
            </tr>
            </tbody>
        </table>
    </div>
</x-k.card>

{{-- The history, straight off the event timeline. --}}
<x-k.card :title="translate('alert_history')">
    @if (!empty($events['rows']))
        <ul class="mon-events">
            @foreach ($events['rows'] as $event)
                <li class="mon-events__item mon-events__item--{{ $event['severity'] }}">
                    <span class="mon-events__time k-num">{{ $event['at'] }}</span>
                    <span class="mon-events__type">{{ $event['rule'] ?? translate('alert') }}</span>
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
            {{ translate('newest_first_limited_to') }} {{ $events['limit'] }},
            {{ translate('window') }}: {{ $events['window_days'] }} {{ translate('days') }}
        </p>
    @else
        <x-k.empty icon="clock"
                   :title="($events['state'] ?? '') === 'failed' ? translate('the_alert_history_could_not_be_read') : translate('no_alert_has_been_recorded')"
                   :text="$events['note'] ?? ''" />
        {{-- Two independent records of the same firing: the lifetime counter on the rule, and the
             timeline. Which sentence is shown depends on whether they could be compared at all —
             a timeline that could not be READ is not a timeline that disagrees, and reporting it
             as one sends an operator hunting a lost firing instead of a broken query. --}}
        @if (($events['discrepancy'] ?? null) === 'missing_from_timeline')
            <p class="mon-note mon-note--critical">
                {{ translate('the_rule_state_table_records') }} {{ number_format($events['firings_recorded_in_state']) }}
                {{ translate('firings_in_total_and_none_of_them_appear_on_this_timeline') }}
                <small class="mon-metric__note" style="display:block">
                    {{ translate('either_they_are_older_than_the_window_above_or_the_timeline_is_not_being_written') }}
                </small>
            </p>
        @elseif (($events['discrepancy'] ?? null) === 'not_comparable')
            <p class="mon-note">
                {{ translate('the_rule_state_table_records') }} {{ number_format($events['firings_recorded_in_state']) }}
                {{ translate('firings_the_timeline_could_not_be_read_so_the_two_cannot_be_compared') }}
            </p>
        @endif
    @endif
</x-k.card>
